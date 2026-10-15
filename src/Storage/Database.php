<?php

declare(strict_types=1);

namespace YouTubeMcp\Storage;

use YouTubeMcp\Data;
use PDO;
use PDOStatement;
use PDOException;
use Throwable;

/**
 * Provides SQLite queries, transactions and ordered schema migrations.
 *
 * Centralizes local persistence and migration handling for account data and operation state.
 */
final class Database
{
    /**
     * Never include raw driver messages: they can contain paths or stored values.
     *
     * @return array{
     *     database_reason: string,
     *     database_sqlstate: string,
     *     database_driver_code?: int,
     *     database_table?: string
     * }
     */
    public static function errorDetails(PDOException $error): array
    {
        $message = strtolower($error->getMessage());
        $reason = match (true) {
            str_contains($message, 'no such table:') => 'missing_table',
            str_contains($message, 'no such column:') => 'missing_column',
            str_contains($message, 'readonly database') => 'readonly_database',
            str_contains($message, 'database is locked') => 'database_locked',
            str_contains($message, 'database or disk is full') => 'storage_full',
            str_contains($message, 'constraint failed') => 'constraint_failed',
            str_contains($message, 'columns but') => 'column_count_mismatch',
            default => 'unclassified',
        };
        $details = [
            'database_reason' => $reason,
            'database_sqlstate' => Data::text($error->errorInfo[0] ?? $error->getCode()),
        ];
        if (isset($error->errorInfo[1]) && is_numeric($error->errorInfo[1])) {
            $details['database_driver_code'] = (int) $error->errorInfo[1];
        }
        if (
            $reason === 'missing_table'
            && preg_match('/no such table: ([a-z_]+)/', $message, $match)
            && in_array($match[1], ['google_connections', 'oauth_tokens', 'quota_windows', 'quota_requests'], true)
        ) {
            $details['database_table'] = $match[1];
        }

        return $details;
    }

    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $this->pdo = new PDO(
            'sqlite:' . $path,
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL');
    }

    /**
     * @param array<array-key, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return Data::objects($this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public function migrate(string $root): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY)');
        foreach (glob($root . '/migrations/*.sql') ?: [] as $file) {
            foreach ($this->migrationSections($file) as $name => $sql) {
                if ($this->one('SELECT name FROM migrations WHERE name=?', [$name])) {
                    continue;
                }

                $this->transaction(
                    function () use ($sql, $name) {
                        $this->pdo->exec($sql);
                        $this->execute('INSERT INTO migrations VALUES (?)', [$name]);
                    },
                );
            }
        }
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Data::object($row);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array<string, string> */
    private function migrationSections(string $file): array
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read migration SQL.');
        }
        // Bundled sections retain the original identities for existing databases.
        $parts = preg_split('/^-- Migration: ([a-zA-Z0-9_]+\.sql)\h*\r?\n/m', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('Cannot parse migration sections.');
        }
        if (count($parts) === 1) {
            return [basename($file) => $sql];
        }
        if (trim($parts[0]) !== '') {
            throw new \RuntimeException('SQL must follow a migration section marker.');
        }

        $sections = [];
        for ($index = 1; $index < count($parts); $index += 2) {
            $name = $parts[$index];
            if (isset($sections[$name])) {
                throw new \RuntimeException('Duplicate migration section.');
            }

            $sections[$name] = $parts[$index + 1];
        }

        return $sections;
    }
}
