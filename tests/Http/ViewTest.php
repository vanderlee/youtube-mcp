<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Http;

use YouTubeMcp\Http\View;

test('page templates escape text attributes and csrf fields', function (): void {
    $session = $_SESSION ?? [];
    $_SESSION = ['user' => 'fixture', 'csrf' => 'token"<&'];
    try {
        $view = new View(dirname(__DIR__, 2) . '/views');
        $response = $view->page('Authorize <script>alert("x")</script>', 'authorization', [
            'scopes' => 'read <img src=x onerror=alert(1)>',
        ]);
        $html = (string) $response->getBody();

        expect($response->getHeaderLine('Content-Type'))->toBe('text/html; charset=utf-8')
            ->and($html)->toContain('&lt;script&gt;', '&lt;img', 'value="token&quot;&lt;&amp;"')
            ->and($html)->not->toContain('<script>', '<img')
            ->and($html)->toContain('action="/logout"', 'name="decision" value="approve"', 'name="decision" value="deny"');

        $form = $view->form('/path?value="<&', '<Delete>', 'danger"', ['field"' => '<value>']);
        expect($form)->toContain('action="/path?value=&quot;&lt;&amp;"', '&lt;Delete&gt;', 'class="danger&quot;"', 'name="field&quot;" value="&lt;value&gt;"');
    } finally {
        $_SESSION = $session;
    }
});

test('rendering failures restore the callers output buffer and propagate the exception', function (): void {
    $directory = $this->tempDir();
    file_put_contents($directory . '/broken.php', '<?php echo "partial"; throw new RuntimeException("fixture failure");');
    $level = ob_get_level();
    ob_start();
    try {
        echo 'caller output';
        expect(fn() => (new View($directory))->render('broken'))->toThrow(\RuntimeException::class, 'fixture failure');
        expect(ob_get_level())->toBe($level + 1)
            ->and(ob_get_contents())->toBe('caller output');
    } finally {
        ob_end_clean();
        $this->removeDir($directory);
    }
});

test('template names cannot escape the views directory', function (): void {
    $view = new View(dirname(__DIR__, 2) . '/views');
    expect(fn() => $view->render('../layout'))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $view->render('missing'))->toThrow(\RuntimeException::class);
});
