<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Storage;

use PDOException;
use RuntimeException;
use YouTubeMcp\Storage\Database;

covers(Database::class);

test('database commits return values and rolls back changes on failures', function (): void {
    $db = new Database(':memory:');
    $db->execute('CREATE TABLE fixture (id INTEGER PRIMARY KEY, value TEXT)');
    $value = $db->transaction(function () use ($db): string {
        $db->execute('INSERT INTO fixture VALUES (?,?)', [1, 'committed']);
        return 'result';
    });
    expect($value)->toBe('result');
    expect(fn () => $db->transaction(function () use ($db): void {
        $db->execute('UPDATE fixture SET value=? WHERE id=?', ['rolled back', 1]);
        throw new RuntimeException('abort');
    }))->toThrow(RuntimeException::class, 'abort');
    expect($db->one('SELECT value FROM fixture WHERE id=?', [1]))->toBe(['value' => 'committed']);
    expect($db->one('SELECT * FROM fixture WHERE id=?', [2]))->toBeNull();
    expect($db->all('SELECT * FROM fixture'))->toHaveCount(1);
});

test('database diagnostics omit sensitive values', function (string $message, string $reason): void {
    $error = new PDOException($message . ' /private/dummy-path secret-value');
    $error->errorInfo = ['HY000', 1, $message];
    $details = Database::errorDetails($error);
    expect($details['database_reason'])->toBe($reason);
    expect($details['database_sqlstate'])->toBe('HY000');
    expect($details['database_driver_code'])->toBe(1);
    expect(json_encode($details))->not->toContain('secret-value', 'dummy-path');
})->with([
    ['no such table: oauth_tokens', 'missing_table'],
    ['no such column: secret', 'missing_column'],
    ['attempt to write a readonly database', 'readonly_database'],
    ['database is locked', 'database_locked'],
    ['database or disk is full', 'storage_full'],
    ['UNIQUE constraint failed', 'constraint_failed'],
    ['table has 2 columns but 3 values', 'column_count_mismatch'],
    ['unknown failure', 'unclassified'],
]);

test('database diagnostics name only explicitly permitted tables', function (): void {
    $permitted = Database::errorDetails(new PDOException('no such table: oauth_tokens'));
    $private = Database::errorDetails(new PDOException('no such table: private_customer_data'));
    expect($permitted['database_table'])->toBe('oauth_tokens');
    expect($private)->not->toHaveKey('database_table');
});
