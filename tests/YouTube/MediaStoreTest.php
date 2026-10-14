<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use Nyholm\Psr7\{Stream, UploadedFile};
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\MediaStore;

covers(MediaStore::class);

test('SRT captions are staged independently of the platform MIME database', function (): void {
    $directory = $this->tempDir();
    try {
        $db = $this->database();
        $this->user($db);
        $store = new MediaStore($db, $this->config(['STORAGE_PATH' => $directory]));
        $connection = ['channel_id' => 'channel-id', 'generation' => 'generation'];
        $text = "1\n00:00:01,000 --> 00:00:02,000\nFixture caption\n";
        $upload = new UploadedFile(Stream::create($text), strlen($text), UPLOAD_ERR_OK, 'fixture.srt');
        $saved = $store->save('u1', $connection, $upload);
        $asset = $store->get('u1', $connection, $saved['asset_id']);

        expect($saved['mime'])->toBe('text/plain');
        expect(file_get_contents($asset['path']))->toBe($text);
    } finally {
        $this->removeDir($directory);
    }
});

test('caption validation retains content and size checks after MIME normalization', function (string $text): void {
    $directory = $this->tempDir();
    try {
        $db = $this->database();
        $this->user($db);
        $store = new MediaStore($db, $this->config(['STORAGE_PATH' => $directory]));
        $connection = ['channel_id' => 'channel-id', 'generation' => 'generation'];
        $upload = new UploadedFile(
            Stream::create($text),
            strlen($text),
            UPLOAD_ERR_OK,
            'fixture.srt',
            'application/x-subrip',
        );

        expect(fn() => $store->save('u1', $connection, $upload))->toThrow(ServiceError::class);
        expect($db->all('SELECT * FROM media_assets'))->toBe([]);
        expect(glob($directory . '/media/*'))->toBe([]);
    } finally {
        $this->removeDir($directory);
    }
})->with([
    'missing timestamps' => ['not a timed subtitle'],
    'caption exceeds one MiB' => ["1\n00:00:01,000 --> 00:00:02,000\n" . str_repeat('a', 1_048_576)],
    'disguised PHP' => ['<?php echo "00:00:01,000 --> 00:00:02,000";'],
]);

test('media cleanup deletes expired owned assets and orphans while preserving fresh files', function (): void {
    $directory = $this->tempDir();
    try {
        $db = $this->database();
        $this->user($db);
        $store = new MediaStore($db, $this->config(['STORAGE_PATH' => $directory]));
        $connection = ['channel_id' => 'channel-id', 'generation' => 'generation'];
        $text = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nFixture\n";
        $upload = fn() => new UploadedFile(
            Stream::create($text),
            strlen($text),
            UPLOAD_ERR_OK,
            'fixture.vtt',
            'text/vtt',
        );
        $old = $store->save('u1', $connection, $upload());
        $fresh = $store->save('u1', $connection, $upload());
        $db->execute('UPDATE media_assets SET created_at=? WHERE id=?', [time() - 86401, $old['asset_id']]);
        $orphan = $directory . '/media/' . str_repeat('a', 48);
        file_put_contents($orphan, 'orphan');
        touch($orphan, time() - 86401);
        $store->cleanup(new Lock($directory . '/locks'));

        expect(is_file($directory . '/media/' . $old['asset_id']))->toBeFalse();
        expect(is_file($orphan))->toBeFalse();
        expect($store->listing('u1', $connection))->toHaveCount(1);
        $asset = $store->get('u1', $connection, $fresh['asset_id']);
        expect((int)$asset['bytes'])->toBe(strlen($text));
        expect(file_get_contents($asset['path']))->toBe($text);
        expect(fn() => $store->get('u1', $connection, $old['asset_id']))->toThrow(ServiceError::class);
        $store->delete('u1', $connection, $fresh['asset_id']);
        expect($store->listing('u1', $connection))->toBe([]);
    } finally {
        $this->removeDir($directory);
    }
});
