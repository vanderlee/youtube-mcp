<?php

declare(strict_types=1);

namespace YouTubeMcp\Playlist;

final class WorkflowTools
{
    public const WRITES = [
        'copy_playlist',
        'merge_playlists',
        'sort_playlist',
        'remove_playlist_duplicates',
        'reorder_playlist',
        'transfer_tracks'
    ];

    public static function specs(): array
    {
        $playlist = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{10,100}$'];
        $item = ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+$'];
        $op = [
            'operation_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,128}$'],
            'resume' => ['type' => 'boolean']
        ];
        $duplicates = ['type' => 'string', 'enum' => ['skip', 'allow']];
        return [
            'copy_playlist' => [
                'Copy an accessible playlist into a new private-by-default playlist. Full scans max 1000 entries; max 20 writes per call, explicit resume for remaining steps.',
                [
                    'source_playlist_id' => $playlist,
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'privacy' => ['type' => 'string', 'enum' => ['private', 'unlisted', 'public']],
                    'duplicate_policy' => $duplicates
                ] + $op,
                ['source_playlist_id', 'title', 'operation_key']
            ],
            'merge_playlists' => [
                'Append up to five accessible source playlists to an owned target; duplicates skipped by default. Max 1000 combined source entries and 20 writes per call.',
                [
                    'source_playlist_ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 5,
                        'uniqueItems' => true,
                        'items' => $playlist
                    ],
                    'playlist_id' => $playlist,
                    'duplicate_policy' => $duplicates
                ] + $op,
                ['source_playlist_ids', 'playlist_id', 'operation_key']
            ],
            'sort_playlist' => [
                'Sort an owned playlist by title or playlist-added date. Preserves occurrences. Max 1000 entries, 20 writes per call.',
                [
                    'playlist_id' => $playlist,
                    'order' => [
                        'type' => 'string',
                        'enum' => ['title_asc', 'title_desc', 'added_oldest', 'added_newest']
                    ]
                ] + $op,
                ['playlist_id', 'order', 'operation_key']
            ],
            'remove_playlist_duplicates' => [
                'Remove repeated video occurrences from an owned playlist, retaining first or last occurrence. Max 1000 entries, 20 writes per call.',
                ['playlist_id' => $playlist, 'keep' => ['type' => 'string', 'enum' => ['first', 'last']]] + $op,
                ['playlist_id', 'operation_key']
            ],
            'reorder_playlist' => [
                'Reorder all entries of an owned playlist using a complete permutation of occurrence IDs. Max 1000 entries, 20 writes per call.',
                [
                    'playlist_id' => $playlist,
                    'playlist_item_ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 1000,
                        'uniqueItems' => true,
                        'items' => $item
                    ]
                ] + $op,
                ['playlist_id', 'playlist_item_ids', 'operation_key']
            ],
            'transfer_tracks' => [
                'Copy or move up to 20 selected occurrences between different playlists. Move requires ownership of both; additions precede source removals.',
                [
                    'source_playlist_id' => $playlist,
                    'playlist_id' => $playlist,
                    'playlist_item_ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 20,
                        'uniqueItems' => true,
                        'items' => $item
                    ],
                    'move' => ['type' => 'boolean']
                ] + $op,
                ['source_playlist_id', 'playlist_id', 'playlist_item_ids', 'operation_key']
            ],
            'compare_playlists' => [
                'Compare complete playlist snapshots by video ID and occurrence counts. Max 1000 entries each; no writes.',
                ['playlist_id' => $playlist, 'other_playlist_id' => $playlist],
                ['playlist_id', 'other_playlist_id']
            ],
            'export_playlist' => [
                'Export one page of playlist entries as JSON, CSV or YouTube watch URLs. Includes next_page_token and complete=false when more pages remain.',
                [
                    'playlist_id' => $playlist,
                    'format' => ['type' => 'string', 'enum' => ['json', 'csv', 'urls']],
                    'page_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]
                ],
                ['playlist_id']
            ],
            'audit_playlist' => [
                'Inspect one page for omitted/private videos and returned region restrictions; unavailable IDs are not proof of deletion. Read-only, never removes entries.',
                [
                    'playlist_id' => $playlist,
                    'region' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                    'page_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]
                ],
                ['playlist_id']
            ],
        ];
    }
}
