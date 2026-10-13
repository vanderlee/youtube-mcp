<?php

declare(strict_types=1);

namespace YouTubeMcp\Operations;

use YouTubeMcp\{ServiceError};
use YouTubeMcp\Storage\{Database, Lock};
use Throwable;

final class Receipts
{
    public function __construct(private Database $db, private Lock $locks)
    {
    }

    public function get(string $user, string $id): array
    {
        $r = $this->db->one('SELECT receipt FROM operations WHERE id=? AND user_id=?', [$id, $user]);
        return $r ? json_decode($r['receipt'], true) : throw new ServiceError(
            'OPERATION_NOT_FOUND',
            'Operation was not found.',
            [],
            404
        );
    }

    public function run(string $user, string $generation, string $tool, array $args, callable $work): array
    {
        $key = $args['operation_key'] ?? '';
        $resume = $args['resume'] ?? false;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9._-]{8,128}$/', $key)) {
            throw new ServiceError(
                'INVALID_ARGUMENT',
                'operation_key must contain 8-128 letters, digits, dots, underscores or hyphens.'
            );
        }
        unset($args['resume']);
        $hash = hash('sha256', json_encode($this->normalized($args), JSON_THROW_ON_ERROR));
        return $this->locks->run(
            'operation:' . $user . ':' . $key,
            function () use ($user, $generation, $tool, $key, $hash, $resume, $work) {
                $row = $this->db->one(
                    'SELECT * FROM operations WHERE user_id=? AND generation=? AND operation_key=?',
                    [$user, $generation, $key]
                );
                if ($row) {
                    if ($row['request_hash'] !== $hash || $row['tool'] !== $tool) {
                        throw new ServiceError(
                            'OPERATION_KEY_CONFLICT',
                            'This operation key was used with different arguments.',
                            [],
                            409
                        );
                    }
                    $receipt = json_decode($row['receipt'], true);
                    if ($row['status'] === 'completed' || !$resume) {
                        return $receipt;
                    }
                    if (in_array($row['status'], ['running', 'uncertain'], true)) {
                        throw new ServiceError(
                            'RECONCILIATION_REQUIRED',
                            'A previous request may have changed YouTube. Inspect the playlist and receipt; automatic replay is disabled.',
                            ['operation_id' => $row['id']],
                            409
                        );
                    }
                } else {
                    $receipt = [
                        'operation_id' => bin2hex(random_bytes(16)),
                        'tool' => $tool,
                        'status' => 'running',
                        'completed' => []
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
                            time()
                        ]
                    );
                }
                $save = function () use (&$receipt) {
                    $this->db->execute(
                        'UPDATE operations SET status=?,receipt=?,updated_at=? WHERE id=?',
                        [
                            $receipt['status'],
                            json_encode($receipt, JSON_THROW_ON_ERROR),
                            time(),
                            $receipt['operation_id']
                        ]
                    );
                };
                $step = function (string $id, callable $fn) use (&$receipt, $save) {
                    if (array_key_exists($id, $receipt['completed'])) {
                        return $receipt['completed'][$id];
                    }
                    $receipt['status'] = 'running';
                    $receipt['pending_step'] = $id;
                    unset($receipt['error']);
                    $save();
                    $result = $fn();
                    $receipt['completed'][$id] = $result;
                    unset($receipt['pending_step']);
                    $save();
                    return $result;
                };
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
                        'message' => 'Operation interrupted. Inspect the receipt and playlist before retrying.'
                    ];
                    $save();
                    throw $e;
                }
                $save();
                $this->db->execute(
                    'INSERT INTO audit_events(user_id,action,target,outcome,created_at) VALUES (?,?,?,?,?)',
                    [$user, $tool, $receipt['operation_id'], $receipt['status'], time()]
                );
                return $receipt;
            }
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
        return array_map(fn($v) => $this->normalized($v), $value);
    }
}
