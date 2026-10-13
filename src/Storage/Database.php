<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Storage;

use PDO;
use PDOStatement;
use Throwable;

final class Database
{
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
