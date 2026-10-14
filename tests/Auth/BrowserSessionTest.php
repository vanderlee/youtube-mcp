<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use YouTubeMcp\Auth\BrowserSession;
use YouTubeMcp\ServiceError;

covers(BrowserSession::class);

beforeEach(function (): void {
    $this->previousSession = $_SESSION ?? [];
    $this->previousSessionPath = session_save_path();
    $this->previousSessionId = session_id();
    $this->previousSessionActive = session_status() === PHP_SESSION_ACTIVE;
    if ($this->previousSessionActive) {
        session_write_close();
    }
    $this->sessionDirectory = $this->tempDir();
    session_save_path($this->sessionDirectory);
    session_id('');
    session_start();
    $_SESSION = [];
});

afterEach(function (): void {
    session_write_close();
    session_save_path($this->previousSessionPath);
    session_id($this->previousSessionId);
    if ($this->previousSessionActive) {
        session_start();
    }
    $_SESSION = $this->previousSession;
    $this->removeDir($this->sessionDirectory);
});

test('browser sign in binds the account generation and never grants a different identity', function (): void {
    $db = $this->database();
    $this->user($db);
    $browser = new BrowserSession($db, $this->config());
    $browser->signIn('u1');
    $generation = $db->one('SELECT generation FROM browser_accounts WHERE user_id=?', ['u1'])['generation'];
    $browser->requireUser('u1');

    expect($_SESSION['user'])->toBe('u1');
    expect($_SESSION['user_generation'])->toBe($generation);
    expect($_SESSION['signed_in_at'])->toBeGreaterThan(0);
    expect(fn() => $browser->requireUser('u2'))->toThrow(ServiceError::class, 'Sign in again.');
    $db->execute('UPDATE browser_accounts SET generation=? WHERE user_id=?', ['replacement', 'u1']);
    $browser->validate();
    expect($_SESSION)->toBe([]);
});

test('unknown users cannot create a browser account', function (): void {
    $db = $this->database();
    $browser = new BrowserSession($db, $this->config());
    expect(fn() => $browser->signIn('missing'))->toThrow(ServiceError::class);
    expect($db->all('SELECT * FROM browser_accounts'))->toBe([]);
    expect($_SESSION)->toBe([]);
});
