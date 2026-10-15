<?php

declare(strict_types=1);

namespace YouTubeMcp\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use YouTubeMcp\Config;

/**
 * Renders browser pages and forms from PHP templates.
 *
 * Combines configured application context with response generation for dashboard and consent routes.
 */
final class View
{
    public function __construct(private string $directory, private Config $config) {}

    /** @param array<string, mixed> $data */
    public function page(string $title, string $template, array $data = []): ResponseInterface
    {
        $body = $this->render($template, $data);
        $html = $this->render('layout', [
            'title' => $title,
            'body' => $body,
            'signedIn' => !empty($_SESSION['user']),
            'brandName' => $this->config->get('BRAND_NAME', 'Toyls'),
            'brandUrl' => $this->config->get('BRAND_URL', 'https://toyls.com'),
            'appUrl' => $this->config->url(),
            'brandTagline' => $this->config->get('BRAND_TAGLINE', 'tools, toys & toils'),
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        // Template names come from application code, never request parameters.
        if (!preg_match('/\A[a-z][a-z0-9-]*\z/', $template)) {
            throw new \InvalidArgumentException('Invalid template name.');
        }

        $path = $this->directory . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = $_SESSION['csrf'] ?? '';
        ob_start();
        try {
            // A dedicated scope prevents template data from overwriting renderer state.
            (function () use ($path, $data, $escape, $csrf): void {
                extract($data, EXTR_SKIP);
                require $path;
            })();

            return (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    /** @param array<string, string> $fields */
    public function form(string $action, string $label, string $buttonClass = '', array $fields = []): string
    {
        return $this->render('form', compact('action', 'label', 'buttonClass', 'fields'));
    }
}
