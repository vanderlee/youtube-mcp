<?php

declare(strict_types=1);

namespace YouTubeMcp\Operations;

use YouTubeMcp\Data;
use Throwable;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\{Database, Lock};

/**
 * Records mutation steps so callers can inspect and explicitly resume partial operations.
 *
 * Uses Database and Lock to bind operation keys to arguments and connection generations without replaying completed writes.
 */
final class Receipts
{
    public function __construct(private Database $db, private Lock $locks) {}

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function get(string $user, string $id, string $generation, array $scopes): array
    {
        $row = $this->db->one(
            'SELECT receipt,tool FROM operations WHERE id=? AND user_id=? AND generation=?',
            [$id, $user, $generation],
        );
        if (!$row) {
            throw new ServiceError('OPERATION_NOT_FOUND', 'Operation was not found for this account connection.', [], 404);
        }
        $scope = \YouTubeMcp\Mcp\ToolCatalog::scope(Data::string($row['tool']));
        $readScope = explode('.', $scope, 2)[0] . '.read';
        if (!in_array($scope, $scopes, true) && !in_array($readScope, $scopes, true)) {
            throw new ServiceError(
                'INSUFFICIENT_SERVICE_SCOPE',
                'Authorize ' . $readScope . ' or ' . $scope . ' to read this receipt.',
                [],
                403,
            );
        }

        return $this->decodeReceipt(Data::string($row['receipt']));
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function run(string $user, string $generation, string $tool, array $args, callable $work): array
    {
        $key = $args['operation_key'] ?? '';
        $resume = $args['resume'] ?? false;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9._-]{8,128}$/', $key)) {
            throw new ServiceError(
                'INVALID_ARGUMENT',
                'operation_key must contain 8-128 letters, digits, dots, underscores or hyphens.',
            );
        }
        unset($args['resume']);
        $hash = hash('sha256', json_encode($this->normalized($args), JSON_THROW_ON_ERROR));

        return $this->locks->run(
            'operation:' . $user . ':' . $key,
            function () use ($user, $generation, $tool, $key, $hash, $resume, $work) {
                $row = $this->db->one(
                    'SELECT * FROM operations WHERE user_id=? AND generation=? AND operation_key=?',
                    [$user, $generation, $key],
                );
                if ($row) {
                    if ($row['request_hash'] !== $hash || $row['tool'] !== $tool) {
                        throw new ServiceError(
                            'OPERATION_KEY_CONFLICT',
                            'This operation key was used with different arguments.',
                            [],
                            409,
                        );
                    }

                    $receipt = $this->decodeReceipt(Data::string($row['receipt']));
                    if ($row['status'] === 'completed' || !$resume) {
                        return $receipt;
                    }
                    if (in_array($row['status'], ['running', 'uncertain'], true)) {
                        throw new ServiceError(
                            'RECONCILIATION_REQUIRED',
                            'A previous request may have changed YouTube. Inspect the playlist and receipt; automatic replay is disabled.',
                            ['operation_id' => $row['id']],
                            409,
                        );
                    }
                } else {
                    $receipt = $this->createReceipt($user, $generation, $tool, $key, $hash);
                }

                return $this->executeReceipt($user, $tool, $receipt, $work);
            },
        );
    }

    /** @return array<string, mixed> */
    private function decodeReceipt(string $json): array
    {
        $receipt = Data::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        Data::string($receipt['operation_id'] ?? null);
        Data::string($receipt['tool'] ?? null);
        Data::string($receipt['status'] ?? null);
        Data::array($receipt['completed'] ?? null);

        return $receipt;
    }

    /**
     * @param array<string, mixed> $receipt
     */
    private function saveReceipt(array $receipt): void
    {
        $this->db->execute(
            'UPDATE operations SET status=?,receipt=?,updated_at=? WHERE id=?',
            [
                $receipt['status'],
                json_encode($receipt, JSON_THROW_ON_ERROR),
                time(),
                $receipt['operation_id'],
            ],
        );
    }

    private function normalized(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn($item) => $this->normalized($item), $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function createReceipt(string $user, string $generation, string $tool, string $key, string $hash): array
    {
        $receipt = [
            'operation_id' => bin2hex(random_bytes(16)),
            'tool' => $tool,
            'status' => 'running',
            'completed' => [],
        ];
        $this->db->execute(
            'INSERT INTO operations VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $receipt['operation_id'],
                $user,
                $generation,
                $tool,
                $key,
                $hash,
                'running',
                json_encode($receipt),
                time(),
                time(),
            ],
        );

        return $receipt;
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function executeReceipt(string $user, string $tool, array $receipt, callable $work): array
    {
        $step = $this->receiptStep($receipt);
        try {
            $receipt['result'] = $work($step, $receipt['operation_id'], $receipt['completed']);
            $receipt['status'] = 'completed';
            unset($receipt['error'], $receipt['pending_step']);
        } catch (ServiceError $e) {
            $receipt['status'] = $e->reason === 'UPSTREAM_UNCERTAIN' ? 'uncertain' : 'partial';
            $receipt['error'] = $e->payload();
            if ($receipt['status'] !== 'uncertain') {
                unset($receipt['pending_step']);
            }
        } catch (Throwable $e) {
            $receipt['status'] = 'uncertain';
            $receipt['error'] = [
                'code' => 'INTERNAL_ERROR',
                'message' => 'Operation interrupted. Inspect the receipt and playlist before retrying.',
            ];
            $this->saveReceipt($receipt);
            throw $e;
        }

        $this->saveReceipt($receipt);
        $this->db->execute(
            'INSERT INTO audit_events(user_id,action,target,outcome,created_at) VALUES (?,?,?,?,?)',
            [$user, $tool, $receipt['operation_id'], $receipt['status'], time()],
        );

        return $receipt;
    }

    /**
     * The callback updates the current receipt and saves each step before and after its effect.
     *
     * @param array<string, mixed> $receipt
     */
    private function receiptStep(array &$receipt): callable
    {
        return function (string $id, callable $work) use (&$receipt) {
            $receipt['completed'] = Data::array($receipt['completed']);
            if (array_key_exists($id, $receipt['completed'])) {
                return $receipt['completed'][$id];
            }

            $receipt['status'] = 'running';
            $receipt['pending_step'] = $id;
            unset($receipt['error']);
            $this->saveReceipt($receipt);
            $result = $work();
            $receipt['completed'][$id] = $result;
            unset($receipt['pending_step']);
            $this->saveReceipt($receipt);

            return $result;
        };
    }
}
