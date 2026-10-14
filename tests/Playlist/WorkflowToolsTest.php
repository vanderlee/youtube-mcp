<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Playlist;

use YouTubeMcp\Playlist\WorkflowTools;

covers(WorkflowTools::class);

test('workflow mutations require operation keys and explicit typed resume', function (): void {
    $specs = WorkflowTools::specs();
    foreach (WorkflowTools::WRITES as $name) {
        expect($specs[$name][2])->toContain('operation_key');
        expect($specs[$name][1]['resume']['type'])->toBe('boolean');
        expect($specs[$name][1]['operation_key']['pattern'])->toBe('^[A-Za-z0-9._-]{8,128}$');
    }
    expect($specs['export_playlist'][2])->not->toContain('operation_key');
});
