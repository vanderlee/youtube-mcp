<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\YouTube;

use YouTubeMcp\Mcp\Arguments;
use YouTubeMcp\ServiceError;
use YouTubeMcp\YouTube\Tools;

covers(Tools::class);

test('comment mutations require receipts and reads retain explicit page bounds', function (): void {
    $specs = Tools::specs();
    foreach (Tools::WRITES as $name) {
        expect($specs[$name][2])->toContain('operation_key');
        expect($specs[$name][1]['resume']['type'])->toBe('boolean');
    }
    expect($specs['list_comments'][1]['page_size']['maximum'])->toBe(50);
    expect($specs['get_video_details'][1]['video_ids']['maxItems'])->toBe(50);
    expect($specs['get_video_details'][1]['video_ids']['uniqueItems'])->toBeTrue();
});

test('search filters accept documented values and reject invalid filters', function (): void {
    $filters = Tools::searchFilters();
    Arguments::validate(['duration' => 'short', 'order' => 'date', 'category_id' => '10'], $filters, []);
    expect(fn() => Arguments::validate(['duration' => 'unbounded'], $filters, []))->toThrow(ServiceError::class);
    expect(fn() => Arguments::validate(['channel_id' => 'video-id'], $filters, []))->toThrow(ServiceError::class);
});
