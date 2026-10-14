<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Mcp;

use YouTubeMcp\Auth\Scope;
use YouTubeMcp\Mcp\{ToolCatalog, Tools};
use YouTubeMcp\ServiceError;

covers(ToolCatalog::class);

test('catalog permissions agree with published schemas and write annotations for every tool', function (): void {
    foreach (Tools::definitions() as $tool) {
        expect(ToolCatalog::owner($tool->name))->toBeIn(['playlist', 'youtube', 'workflow', 'creator', 'receipts']);
        expect(ToolCatalog::scope($tool->name))->toBeIn(Scope::SUPPORTED);
        $published = json_decode(json_encode($tool, JSON_THROW_ON_ERROR), true);
        expect($published['annotations']['readOnlyHint'])->toBe(!ToolCatalog::isWrite($tool->name));
        expect(in_array('operation_key', $tool->inputSchema['required'], true))->toBe(ToolCatalog::isWrite($tool->name));
        expect($published['_meta']['securitySchemes'][0]['scopes'])->toBe(
            $tool->name === 'get_operation' ? [] : [ToolCatalog::scope($tool->name)],
        );
    }
    expect(fn() => ToolCatalog::scope('unknown_tool'))->toThrow(ServiceError::class, 'Unknown tool');
});
