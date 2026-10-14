<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\MediaApi;

covers(MediaApi::class);

test('upload session locations allow only the official https upload endpoints', function (string $host): void {
    MediaApi::validateLocation('https://' . $host . '/upload/youtube/v3/videos?upload_id=dummy');
    expect($host)->toBeIn(['www.googleapis.com', 'youtube.googleapis.com']);
})->with(['www.googleapis.com', 'youtube.googleapis.com']);

test('upload locations reject unsafe URLs', function (string $location): void {
    expect(fn() => MediaApi::validateLocation($location))->toThrow(ServiceError::class, 'no file was transmitted');
})->with([
    'http://www.googleapis.com/upload/youtube/v3/videos',
    'https://attacker.test/upload/youtube/v3/videos',
    'https://www.googleapis.com.attacker.test/upload/youtube/v3/videos',
    'https://dummy@www.googleapis.com/upload/youtube/v3/videos',
    'https://www.googleapis.com:443/upload/youtube/v3/videos',
    'https://www.googleapis.com/upload/youtube/v3/videos#fragment',
    'https://www.googleapis.com/youtube/v3/videos',
    "https://www.googleapis.com/upload/youtube/v3/videos\n",
]);
