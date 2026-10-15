<?php

declare(strict_types=1);

namespace YouTubeMcp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YouTubeMcp\Auth\Google;
use YouTubeMcp\Http\RequestChecks;
use YouTubeMcp\Http\View;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\MediaStore;

/**
 * Handles browser staging and deletion of media assets for creator tools.
 *
 * Combines CSRF checks, the current Google connection, account locks and MediaStore isolation.
 */
final class MediaRoute
{
    public function __construct(
        private Lock $locks,
        private Google $google,
        private MediaStore $mediaAssets,
        private View $view,
    ) {}

    public function __invoke(ServerRequestInterface $request, string $user): ResponseInterface
    {
        $method = $request->getMethod();

        if ($method === 'POST') {
            RequestChecks::csrf($request);
            $files = $request->getUploadedFiles();
            $file = $files['media'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                throw new ServiceError('INVALID_MEDIA', 'Choose one media file. Check PHP upload limits.');
            }

            $asset = $this->locks->run(
                'account:' . $user,
                fn() => $this->mediaAssets->save($user, $this->google->connection($user), $file),
            );

            return $this->view->page('Media staged', 'media-staged', ['assetId' => $asset['asset_id']]);
        }

        $this->google->connection($user);

        return $this->view->page('Stage media', 'media');
    }
}
