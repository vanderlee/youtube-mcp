<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

use stdClass;
use Mcp\Schema\{Tool, ToolAnnotations};
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\{ClientGateway};
use Mcp\Server\Handler\ToolHandlerInterface;
use YouTubeMcp\Playlist\Service;
use YouTubeMcp\ServiceError;

final class Tools
{
    public static function definitions(): array
    {
        $string = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256];
        $playlist = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{10,100}$'];
        $item = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+$'];
        $page = ['page_token' => $string, 'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
        $op = [
            'operation_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,128}$'],
            'resume' => [
                'type' => 'boolean',
                'description' => 'Explicitly resume only pending steps of a prior partial receipt; never resumes uncertain changes.'
            ]
        ];
        $privacy = ['type' => 'string', 'enum' => ['private', 'unlisted', 'public']];
        $title = ['type' => 'string', 'minLength' => 1, 'maxLength' => 150];
        $description = ['type' => 'string', 'maxLength' => 5000];
        $array = fn($schema) => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $schema];
        $specs = [
            'get_connection_status' => ['Show the connected YouTube channel.', [], []],
            'get_usage_status' => [
                'Show your estimated daily budget and service availability. Local only; use this before expensive tasks.',
                [],
                []
            ],
            'get_operation' => [
                'Read a local operation receipt without making YouTube requests.',
                ['operation_id' => $string],
                ['operation_id']
            ],
            'list_playlists' => [
                'List your playlists one page at a time. Fetch further pages only when needed.',
                $page,
                []
            ],
            'get_playlist' => [
                'Read playlist metadata and one page of entries, with occurrence IDs.',
                ['playlist_id' => $playlist] + $page,
                ['playlist_id']
            ],
            'search_tracks' => [
                'Search YouTube video candidates; Music availability is uncertain. Prefer supplied IDs/URLs and avoid equivalent repeat searches. Each uncached page consumes a search call.',
                [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'region' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                    'page_token' => $string,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]
                ],
                ['query']
            ],
            'create_playlist' => [
                'Create a playlist, private unless explicitly requested otherwise.',
                ['title' => $title, 'description' => $description, 'privacy' => $privacy] + $op,
                ['title', 'operation_key']
            ],
            'update_playlist' => [
                'Change only supplied metadata fields of an owned playlist.',
                [
                    'playlist_id' => $playlist,
                    'title' => $title,
                    'description' => $description,
                    'privacy' => $privacy
                ] + $op,
                ['playlist_id', 'operation_key']
            ],
            'add_tracks' => [
                'Add up to 20 video IDs or HTTPS video URLs to an owned playlist. Writes cost 50 units each; batching is not a discount.',
                [
                    'playlist_id' => $playlist,
                    'video_ids' => $array(['type' => 'string', 'minLength' => 11, 'maxLength' => 500]),
                    'duplicate_policy' => ['type' => 'string', 'enum' => ['skip', 'allow']]
                ] + $op,
                ['playlist_id', 'video_ids', 'operation_key']
            ],
            'remove_tracks' => [
                'Remove specific occurrences by playlist-item ID, not video ID.',
                ['playlist_id' => $playlist, 'playlist_item_ids' => $array($item) + ['uniqueItems' => true]] + $op,
                ['playlist_id', 'playlist_item_ids', 'operation_key']
            ],
            'move_track' => [
                'Move one playlist entry to a zero-based position.',
                [
                    'playlist_id' => $playlist,
                    'playlist_item_id' => $item,
                    'position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999]
                ] + $op,
                ['playlist_id', 'playlist_item_id', 'position', 'operation_key']
            ],
            'delete_playlist' => [
                'Permanently delete an owned playlist when requested.',
                ['playlist_id' => $playlist] + $op,
                ['playlist_id', 'operation_key']
            ],
        ];
        $result = [];
        foreach ($specs as $name => [$desc, $props, $required]) {
            $write = in_array($name, Service::WRITES, true);
            $result[] = new Tool(
                $name,
                null,
                [
                    'type' => 'object',
                    'properties' => $props ?: new stdClass(),
                    'required' => $required,
                    'additionalProperties' => false
                ],
                $desc,
                ToolAnnotations::fromArray(
                    [
                        'readOnlyHint' => !$write,
                        'destructiveHint' => in_array(
                            $name,
                            ['remove_tracks', 'delete_playlist', 'update_playlist', 'move_track'],
                            true
                        ),
                        'idempotentHint' => !$write,
                        'openWorldHint' => !in_array($name, ['get_usage_status', 'get_operation'], true)
                    ]
                ),
                meta: [
                'securitySchemes' => [
                    [
                        'type' => 'oauth2',
                        'scopes' => [$write ? 'playlists.write' : 'playlists.read']
                    ]
                ]
            ]
            );
        }
        return $result;
    }

    public static function handler(Service $service, array $identity, string $name): ToolHandlerInterface
    {
        return new class($service, $identity, $name) implements ToolHandlerInterface {
            public function __construct(private Service $service, private array $identity, private string $name)
            {
            }

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                try {
                    $r = $this->service->execute(
                        $this->identity['id'],
                        $this->identity['scopes'],
                        $this->name,
                        $arguments
                    );
                    return new CallToolResult(
                        [new TextContent(json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))],
                        isset($r['status']) && $r['status'] !== 'completed',
                        $r
                    );
                } catch (ServiceError $e) {
                    return new CallToolResult([new TextContent(json_encode($e->payload(), JSON_THROW_ON_ERROR))],
                        true,
                        $e->payload());
                }
            }
        };
    }
}
