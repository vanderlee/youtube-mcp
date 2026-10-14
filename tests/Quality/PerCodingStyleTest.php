<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Quality;

trait PerCodingStyleTestHelpers
{
    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function qaCommand(string $binary, array $arguments): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/' . $binary, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $error];
    }
}

uses(PerCodingStyleTestHelpers::class);

test('PER checks agree and codesniffer never writes the fixture', function (string $body): void {
    $directory = $this->tempDir();
    $path = $directory . '/fixture.php';
    $source = "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n";
    file_put_contents($path, $source);

    try {
        [$status, $output] = $this->qaCommand('phpcs', ['--standard=phpcs.xml.dist', $path]);
        expect($status)->toBe(2, $output)
            ->and($output)->toContain('PER formatting required')
            ->and(file_get_contents($path))->toBe($source);

        [$status, $output] = $this->qaCommand('php-cs-fixer', [
            'fix',
            '--config=.php-cs-fixer.dist.php',
            '--using-cache=no',
            '--sequential',
            $path,
        ]);
        expect($status)->toBe(0, $output)
            ->and(file_get_contents($path))->not->toBe($source);

        [$status, $output] = $this->qaCommand('phpcs', ['--standard=phpcs.xml.dist', $path]);
        expect($status)->toBe(0, $output);
        [$status, $output] = $this->qaCommand('php-cs-fixer', [
            'fix',
            '--config=.php-cs-fixer.dist.php',
            '--using-cache=no',
            '--sequential',
            '--dry-run',
            $path,
        ]);
        expect($status)->toBe(0, $output);
    } finally {
        $this->removeDir($directory);
    }
})->with([
    'empty body' => ["function example(): void\n{\n}"],
    'arrow spacing' => ['$callback = fn (int $value): int => $value + 1;'],
    'multiline trailing comma' => ["\$values = [\n    1,\n    2\n];"],
    'union type spacing' => ['function example(string | int $value): string | int { return $value; }'],
    'concatenation spacing' => ["\$value = 'a'.'b';"],
]);
