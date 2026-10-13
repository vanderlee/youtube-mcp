<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Tests;

trait DeploymentTestHelpers
{
    private string $dir;

    private function runHelper(array $env = []): array
    {
        $cmd = [
            PHP_BINARY,
            '-d',
            'extension=fileinfo',
            '-d',
            'extension=pdo_sqlite',
            '-d',
            'extension=sodium',
            $this->dir . '/bin/deploy-config.php',
            'prepare'
        ];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    private function inputs(): array
    {
        return [
            'DEPLOY_APP_URL' => 'https://music.example.test',
            'DEPLOY_ALLOWED_EMAILS' => 'owner@example.test',
            'DEPLOY_GOOGLE_CLIENT_ID' => 'client-test',
            'DEPLOY_GOOGLE_CLIENT_SECRET' => 'secret$with"quotes\\and#hash'
        ];
    }
}

uses(DeploymentTestHelpers::class);

test('preparation preserves keys and round trips special characters', function (): void {
    [$status, $out, $err] = $this->runHelper($this->inputs());
    $this->assertSame(0, $status, $err);
    $first = \Dotenv\Dotenv::parse(file_get_contents($this->dir . '/.env'));
    $this->assertSame($this->inputs()['DEPLOY_GOOGLE_CLIENT_SECRET'], $first['GOOGLE_CLIENT_SECRET']);
    $this->assertSame(32, strlen(base64_decode($first['APP_KEY'])));
    $this->assertStringNotContainsString($first['APP_KEY'], $out);
    $this->assertStringNotContainsString($first['GOOGLE_CLIENT_SECRET'], $out);
    [$status, , $err] = $this->runHelper();
    $this->assertSame(0, $status, $err);
    $this->assertSame($first, \Dotenv\Dotenv::parse(file_get_contents($this->dir . '/.env')));
});

test('existing state without configuration cannot generate replacement key', function (): void {
    mkdir($this->dir . '/var');
    file_put_contents($this->dir . '/var/service.sqlite', 'existing state');
    [$status, , $err] = $this->runHelper($this->inputs());
    $this->assertSame(1, $status);
    $this->assertStringContainsString('Existing state', $err);
    $this->assertFileDoesNotExist($this->dir . '/.env');
});

test('storage inside public is rejected without writing configuration', function (): void {
    [$status, , $err] = $this->runHelper(
        $this->inputs() + ['DEPLOY_STORAGE_PATH' => $this->dir . '/public/private']
    );
    $this->assertSame(1, $status);
    $this->assertStringContainsString('outside public', $err);
    $this->assertFileDoesNotExist($this->dir . '/.env');
});

beforeEach(function (): void {
    $this->dir = $this->tempDir();
    foreach (['bin', 'vendor', 'public'] as $d) {
        mkdir($this->dir . '/' . $d);
    }
    copy(dirname(__DIR__) . '/bin/deploy-config.php', $this->dir . '/bin/deploy-config.php');
    copy(dirname(__DIR__) . '/.env.example', $this->dir . '/.env.example');
    file_put_contents(
        $this->dir . '/vendor/autoload.php',
        '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
    );
});

afterEach(function (): void {
    $this->removeDir($this->dir);
});
