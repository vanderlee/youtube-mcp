<?php

declare(strict_types=1);

namespace YouTubeMcp\Mcp;

use YouTubeMcp\Data;
use Mcp\Schema\{Tool, ToolAnnotations};
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use stdClass;
use YouTubeMcp\Auth\OAuth;
use YouTubeMcp\ServiceError;

/**
 * Adapts tool schemas and service handlers to the MCP SDK.
 *
 * Combines tool families, rechecks OAuth authorization and presents Dispatcher results to clients.
 */
final class Tools
{
    /**
     * @return list<Tool>
     */
    public static function definitions(): array
    {
        $specs = self::specs();
        $result = [];
        foreach ($specs as $name => [$toolDescription, $properties, $required]) {
            $result[] = self::definition($name, $toolDescription, $properties, $required);
        }

        return $result;
    }

    /**
     * @param array{
     *     id: string,
     *     scopes: list<string>,
     *     token_id: string,
     *     expires_at: int,
     *     client_id: string,
     *     generation: string|null
     * } $identity
     */
    public static function handler(Dispatcher $service, array $identity, string $name, OAuth $oauth): ToolHandlerInterface
    {
        return new class ($service, $identity, $name, $oauth) implements ToolHandlerInterface {
            /**
             * @param array{
             *     id: string,
             *     scopes: list<string>,
             *     token_id: string,
             *     expires_at: int,
             *     client_id: string,
             *     generation: string|null
             * } $identity
             */
            public function __construct(
                private Dispatcher $service,
                private array $identity,
                private string $name,
                private OAuth $oauth,
            ) {}

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                try {
                    $detail = $arguments['response_detail'] ?? 'compact';
                    unset($arguments['response_detail']);
                    $result = $this->oauth->runAuthenticated(
                        $this->identity,
                        fn() => $this->service->execute(
                            $this->identity['id'],
                            $this->identity['scopes'],
                            $this->name,
                            $arguments,
                        ),
                    );
                    $result = $detail === 'full' ? $result : Presentation::compact($this->name, $result);
                    $status = $result['status'] ?? null;

                    return new CallToolResult(
                        [
                            new TextContent(
                                'Result in structuredContent' . ($status !== null ? ': ' . Data::text($status) : '.'),
                            ),
                        ],
                        $status !== null && $status !== 'completed',
                        $result,
                    );
                } catch (ServiceError $e) {
                    return new CallToolResult(
                        [new TextContent($e->reason . ': ' . $e->getMessage())],
                        true,
                        $e->payload(),
                    );
                }
            }
        };
    }

    /**
     * @return array<string, array{string, array<string, array<string, mixed>>, list<string>}>
     */
    private static function specs(): array
    {
        $string = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256];
        $playlist = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{10,100}$'];
        $item = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+$'];
        $page = ['page_token' => $string, 'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
        $operationProperties = [
            'operation_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,128}$'],
            'resume' => [
                'type' => 'boolean',
                'description' => 'Explicitly resume only pending steps of a prior partial receipt; never resumes uncertain changes.',
            ],
        ];
        $privacy = ['type' => 'string', 'enum' => ['private', 'unlisted', 'public']];
        $title = ['type' => 'string', 'minLength' => 1, 'maxLength' => 150];
        $description = ['type' => 'string', 'maxLength' => 5000];
        $array = fn($schema) => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $schema];
        $specs = [
            'list_liked_tracks' => [
                'List your liked YouTube videos, including non-music content, one page at a time.',
                $page,
                [],
            ],
            'get_track_ratings' => [
                'Read your ratings for up to 20 video IDs or HTTPS YouTube URLs.',
                ['video_ids' => $array(['type' => 'string', 'minLength' => 11, 'maxLength' => 500])],
                ['video_ids'],
            ],
            'rate_track' => [
                'Like or dislike a video, or remove your rating with none. This changes your YouTube rating.',
                [
                    'video_id' => ['type' => 'string', 'minLength' => 11, 'maxLength' => 500],
                    'rating' => ['type' => 'string', 'enum' => ['like', 'dislike', 'none']],
                ] + $operationProperties,
                ['video_id', 'rating', 'operation_key'],
            ],
            'list_subscriptions' => [
                'List your YouTube channel subscriptions, including non-artists, one page at a time.',
                $page,
                [],
            ],
            'subscribe_artist' => [
                'Subscribe to an artist YouTube channel using its UC channel ID; also supports other channels.',
                ['channel_id' => ['type' => 'string', 'pattern' => '^UC[A-Za-z0-9_-]{22}$']] + $operationProperties,
                ['channel_id', 'operation_key'],
            ],
            'unsubscribe_artist' => [
                'Unsubscribe from a YouTube channel using its UC channel ID.',
                ['channel_id' => ['type' => 'string', 'pattern' => '^UC[A-Za-z0-9_-]{22}$']] + $operationProperties,
                ['channel_id', 'operation_key'],
            ],
            'get_connection_status' => ['Show the connected YouTube channel.', [], []],
            'get_usage_status' => [
                'Show locally observed daily usage estimates. YouTube enforces quotas; remaining quota is not known to this service.',
                [],
                [],
            ],
            'get_operation' => [
                'Read a receipt from your current connection without YouTube requests. Requires the originating tool permission or its corresponding read permission.',
                ['operation_id' => $string],
                ['operation_id'],
            ],
            'list_playlists' => [
                'List your playlists one page at a time. Fetch further pages only when needed.',
                $page,
                [],
            ],
            'get_playlist' => [
                'Read playlist metadata and one page of entries, with occurrence IDs.',
                ['playlist_id' => $playlist] + $page,
                ['playlist_id'],
            ],
            'search_tracks' => [
                'Search video candidates; Music availability is uncertain. Prefer supplied IDs. One API call by default; include_details adds durations with another call.',
                [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'include_details' => [
                        'type' => 'boolean',
                        'description' => 'Fetch durations with one additional API call. Default false.',
                    ],
                    'region' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                    'page_token' => $string,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                ['query'],
            ],
            'create_playlist' => [
                'Create and optionally populate a playlist in one call; private by default. No search or read-back is needed for supplied video IDs.',
                [
                    'title' => $title,
                    'description' => $description,
                    'privacy' => $privacy,
                    'video_ids' => [
                        'type' => 'array',
                        'maxItems' => 20,
                        'items' => ['type' => 'string', 'minLength' => 11, 'maxLength' => 500],
                    ],
                    'duplicate_policy' => ['type' => 'string', 'enum' => ['skip', 'allow']],
                ] + $operationProperties,
                ['title', 'operation_key'],
            ],
            'update_playlist' => [
                'Change only supplied metadata fields of an owned playlist.',
                [
                    'playlist_id' => $playlist,
                    'title' => $title,
                    'description' => $description,
                    'privacy' => $privacy,
                ] + $operationProperties,
                ['playlist_id', 'operation_key'],
            ],
            'add_tracks' => [
                'Add up to 20 video IDs or HTTPS video URLs to an owned playlist. Writes cost 50 units each; batching is not a discount.',
                [
                    'playlist_id' => $playlist,
                    'video_ids' => $array(['type' => 'string', 'minLength' => 11, 'maxLength' => 500]),
                    'duplicate_policy' => ['type' => 'string', 'enum' => ['skip', 'allow']],
                ] + $operationProperties,
                ['playlist_id', 'video_ids', 'operation_key'],
            ],
            'remove_tracks' => [
                'Remove specific occurrences by playlist-item ID, not video ID.',
                ['playlist_id' => $playlist, 'playlist_item_ids' => $array($item) + ['uniqueItems' => true]] + $operationProperties,
                ['playlist_id', 'playlist_item_ids', 'operation_key'],
            ],
            'move_track' => [
                'Move one playlist entry to a zero-based position.',
                [
                    'playlist_id' => $playlist,
                    'playlist_item_id' => $item,
                    'position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
                ] + $operationProperties,
                ['playlist_id', 'playlist_item_id', 'position', 'operation_key'],
            ],
            'delete_playlist' => [
                'Permanently delete an owned playlist when requested.',
                ['playlist_id' => $playlist] + $operationProperties,
                ['playlist_id', 'operation_key'],
            ],
        ];
        $specs['search_tracks'][1] += \YouTubeMcp\YouTube\Tools::searchFilters();
        $specs += \YouTubeMcp\YouTube\Tools::specs() + \YouTubeMcp\Playlist\WorkflowTools::specs() + \YouTubeMcp\YouTube\CreatorTools::specs();

        return $specs;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     */
    private static function definition(string $name, string $toolDescription, array $properties, array $required): Tool
    {
        $write = ToolCatalog::isWrite($name);
        if (!$write) {
            $properties['response_detail'] = [
                'type' => 'string',
                'enum' => ['compact', 'full'],
                'description' => 'Compact by default; full includes available upstream details or the complete local receipt.',
            ];
        }

        return new Tool(
            $name,
            null,
            [
                'type' => 'object',
                'properties' => $properties ?: new stdClass(),
                'required' => $required,
                'additionalProperties' => false,
            ],
            $toolDescription,
            self::annotations($name, $write),
            meta: [
                'securitySchemes' => [
                    [
                        'type' => 'oauth2',
                        'scopes' => $name === 'get_operation' ? [] : [ToolCatalog::scope($name)],
                    ],
                ],
            ],
        );
    }

    private static function annotations(string $name, bool $write): ToolAnnotations
    {
        return ToolAnnotations::fromArray(
            [
                'readOnlyHint' => !$write,
                'destructiveHint' => in_array(
                    $name,
                    [
                        'remove_tracks',
                        'delete_playlist',
                        'update_playlist',
                        'move_track',
                        'rate_track',
                        'unsubscribe_artist',
                        'update_comment',
                        'delete_comment',
                        'moderate_comments',
                        'sort_playlist',
                        'remove_playlist_duplicates',
                        'reorder_playlist',
                        'transfer_tracks',
                        'update_video',
                        'delete_video',
                        'set_video_thumbnail',
                        'update_caption',
                        'delete_caption',
                        'update_channel_branding',
                        'set_channel_banner',
                        'update_channel_section',
                        'delete_channel_section',
                        'delete_media_asset',
                    ],
                    true,
                ),
                'idempotentHint' => !$write,
                'openWorldHint' => !in_array($name, ['get_usage_status', 'get_operation', 'list_media_assets', 'delete_media_asset'], true),
            ],
        );
    }
}
