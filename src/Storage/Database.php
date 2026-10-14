<?php

declare(strict_types=1);

namespace YouTubeMcp\Storage;

use PDO;
use PDOStatement;
use PDOException;
use Throwable;

final class Database
{
    /** Never include raw driver messages: they can contain paths or stored values. */
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
        $details = ['database_reason' => $reason, 'database_sqlstate' => (string)($error->errorInfo[0] ?? $error->getCode())];
        if (isset($error->errorInfo[1]) && is_numeric($error->errorInfo[1])) {
            $details['database_driver_code'] = (int)$error->errorInfo[1];
        }
        if ($reason === 'missing_table' && preg_match('/no such table: ([a-z_]+)/', $message, $match)
            && in_array($match[1], ['google_connections', 'oauth_tokens', 'quota_windows', 'quota_requests'], true)) {
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
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL');
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

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
            $name = basename($file);
            if (!$this->one('SELECT name FROM migrations WHERE name=?', [$name])) {
                $this->transaction(function () use ($file, $name) {
                    $this->pdo->exec(file_get_contents($file));
                    $this->execute('INSERT INTO migrations VALUES (?)', [$name]);
                });
            }
        }
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->execute($sql, $params)->fetch() ?: null;
    }

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
}
