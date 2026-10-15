<?php

declare(strict_types=1);

namespace YouTubeMcp\YouTube;

use YouTubeMcp\Data;
use Psr\Http\Message\UploadedFileInterface;
use YouTubeMcp\{Config, ServiceError};
use YouTubeMcp\Storage\Database;

/**
 * Stages browser-uploaded files outside the web root for later creator operations.
 *
 * Binds assets to users and connections, validates files and manages expiry and cleanup.
 */
final class MediaStore
{
    public const MAX_BYTES = 67_108_864;

    public function __construct(private Database $db, private Config $config) {}

    /**
     * @param array{channel_id: string, generation: string} $connection
     * @return array{asset_id: string, mime: string, bytes: int}
     */
    public function save(string $user, array $connection, UploadedFileInterface $upload): array
    {
        $size = $upload->getSize();
        $active = $this->db->one('SELECT COUNT(*) AS n FROM media_assets WHERE user_id=? AND created_at>?', [$user, time() - 86400]);
        if ($active === null) {
            throw new \RuntimeException('Cannot count active media assets.');
        }
        if ((int) Data::scalar($active['n']) >= 50) {
            throw new ServiceError('MEDIA_LIMIT', 'Delete an asset before staging more than 50 active files.');
        }
        if ($upload->getError() !== UPLOAD_ERR_OK || !is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw new ServiceError('INVALID_MEDIA', 'Upload must be a nonempty file of at most 64 MiB. Check PHP upload limits.');
        }

        $directory = $this->config->storage() . '/media';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new ServiceError('STORAGE_UNAVAILABLE', 'Private media storage is unavailable.', [], 503);
        }

        $id = bin2hex(random_bytes(24));
        $path = $directory . '/' . $id;
        try {
            $upload->moveTo($path);
            chmod($path, 0600);
            if (filesize($path) !== $size) {
                throw new ServiceError('INVALID_MEDIA', 'Uploaded media size did not match.');
            }

            $mime = $this->validateFile($path, $size);

            $this->db->execute(
                'INSERT INTO media_assets VALUES (?,?,?,?,?,?,?,?)',
                [
                    $id,
                    $user,
                    $connection['channel_id'],
                    $connection['generation'],
                    $mime,
                    $size,
                    hash_file('sha256', $path),
                    time(),
                ],
            );

            return ['asset_id' => $id, 'mime' => $mime, 'bytes' => $size];
        } catch (\Throwable $error) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }
    }

    private function validateFile(string $path, int $size): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        // Linux libmagic identifies SRT separately; retain the caption text checks below.
        if ($mime === 'application/x-subrip') {
            $mime = 'text/plain';
        }
        $allowed = [
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-msvideo',
            'image/jpeg',
            'image/png',
            'text/plain',
            'text/vtt',
        ];
        if (!in_array($mime, $allowed, true)) {
            throw new ServiceError('INVALID_MEDIA', 'Use MP4, WebM, MOV, AVI, PNG, JPEG, SRT or VTT media.');
        }
        if (str_starts_with($mime, 'image/') && ($size > 2_097_152 || getimagesize($path) === false)) {
            throw new ServiceError('INVALID_MEDIA', 'Images must be valid PNG/JPEG files of at most 2 MiB.');
        }
        if (str_starts_with($mime, 'text/') && $size > 1_048_576) {
            throw new ServiceError('INVALID_MEDIA', 'Caption files are limited to 1 MiB.');
        }
        if (str_starts_with($mime, 'text/')) {
            $text = file_get_contents($path);
            if (
                $text === false
                || !mb_check_encoding($text, 'UTF-8')
                || (!str_starts_with(ltrim($text, "\xEF\xBB\xBF"), 'WEBVTT')
                    && !preg_match('/\d{2}:\d{2}:\d{2}[,.]\d{3}\s+-->\s+\d{2}:\d{2}:\d{2}[,.]\d{3}/', $text))
            ) {
                throw new ServiceError('INVALID_MEDIA', 'Caption assets must be UTF-8 timed SRT or VTT files.');
            }
        }

        return $mime;
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     * @return array<string, mixed>
     */
    public function get(string $user, array $connection, string $id): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $id)) {
            throw new ServiceError('INVALID_ARGUMENT', 'Invalid asset ID.');
        }

        $row = $this->db->one(
            'SELECT * FROM media_assets WHERE id=? AND user_id=? AND channel_id=? AND generation=? AND created_at>?',
            [$id, $user, $connection['channel_id'], $connection['generation'], time() - 86400],
        );
        $path = $this->config->storage() . '/media/' . $id;
        $hash = $row && is_file($path) && !is_link($path) && filesize($path) === (int) Data::scalar($row['bytes'])
            ? hash_file('sha256', $path)
            : false;
        if (
            !$row
            || $hash === false
            || !hash_equals(Data::string($row['sha256']), $hash)
        ) {
            throw new ServiceError(
                'MEDIA_NOT_FOUND',
                'Asset is missing, expired, changed or belongs to a different account connection.',
                [],
                404,
            );
        }

        return $row + ['path' => $path];
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     * @return list<array<string, mixed>>
     */
    public function listing(string $user, array $connection): array
    {
        return $this->db->all(
            'SELECT id AS asset_id,mime,bytes,created_at FROM media_assets WHERE user_id=? AND channel_id=? AND generation=? AND created_at>? ORDER BY created_at DESC LIMIT 51',
            [$user, $connection['channel_id'], $connection['generation'], time() - 86400],
        );
    }

    /**
     * @param array{channel_id: string, generation: string} $connection
     */
    public function delete(string $user, array $connection, string $id): void
    {
        $asset = $this->get($user, $connection, $id);
        if (!unlink(Data::string($asset['path']))) {
            throw new ServiceError('STORAGE_UNAVAILABLE', 'Media could not be removed.', [], 503);
        }

        $this->db->execute('DELETE FROM media_assets WHERE id=? AND user_id=?', [$id, $user]);
    }

    /** User deletion removes the user's own staged files before cascading their records. */
    public function purge(string $user): void
    {
        foreach ($this->db->all('SELECT id FROM media_assets WHERE user_id=?', [$user]) as $row) {
            if (!preg_match('/^[a-f0-9]{48}$/D', Data::string($row['id']))) {
                continue;
            }

            $path = $this->config->storage() . '/media/' . Data::string($row['id']);
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                throw new ServiceError('STORAGE_UNAVAILABLE', 'Owned media could not be removed.', [], 503);
            }
        }

        $this->db->execute('DELETE FROM media_assets WHERE user_id=?', [$user]);
    }

    /** Returns the number of expired assets deferred because their account is busy. */
    public function cleanup(\YouTubeMcp\Storage\Lock $locks): int
    {
        $deferred = 0;
        foreach ($this->db->all('SELECT id,user_id FROM media_assets WHERE created_at<=?', [time() - 86400]) as $row) {
            try {
                $locks->run('account:' . Data::text($row['user_id']), fn() => $this->removeExpiredAsset(Data::string($row['id'])));
            } catch (ServiceError $error) {
                if ($error->reason !== 'OPERATION_BUSY') {
                    throw $error;
                }
                $deferred++;
            }
        }

        $this->cleanupOrphans();

        return $deferred;
    }

    private function removeExpiredAsset(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $id)) {
            return;
        }

        $path = $this->config->storage() . '/media/' . $id;
        if (is_file($path) && !is_link($path) && !unlink($path)) {
            throw new ServiceError('STORAGE_UNAVAILABLE', 'Expired media could not be removed.', [], 503);
        }

        $this->db->execute('DELETE FROM media_assets WHERE id=? AND created_at<=?', [$id, time() - 86400]);
    }

    private function cleanupOrphans(): void
    {
        $directory = $this->config->storage() . '/media';
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $file) {
            if (!preg_match('/^[a-f0-9]{48}$/D', $file->getFilename())) {
                continue;
            }
            if ($file->isLink() || !$file->isFile() || $file->getMTime() > time() - 86400) {
                continue;
            }
            if (!$this->db->one('SELECT id FROM media_assets WHERE id=?', [$file->getFilename()])) {
                unlink($file->getPathname());
            }
        }
    }
}
