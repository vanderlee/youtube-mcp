<?php

declare(strict_types=1);

namespace YouTubeMcp\Playlist;

use YouTubeMcp\Auth\Google;
use YouTubeMcp\Mcp\Arguments;
use YouTubeMcp\Mcp\ToolCatalog;
use YouTubeMcp\Operations\Receipts;
use YouTubeMcp\ServiceError;
use YouTubeMcp\Storage\Lock;
use YouTubeMcp\YouTube\Api;

/**
 * @phpstan-type WorkflowAction array{type: 'add', video_id: string}
 *     |array{type: 'remove', id: string, playlist_id?: string}
 *     |array{type: 'move', id: string, video_id: string, position: int}
 * @phpstan-type Snapshot array<string, list<array<string, mixed>>>
 * @phpstan-type WorkflowPlan array{before: Snapshot, actions: list<WorkflowAction>, skipped: int}
 */
final class Workflows
{
    private Scanner $scanner;

    public function __construct(
        private Google $google,
        private Api $api,
        private Receipts $receipts,
        private Lock $locks,
    ) {
        $this->scanner = new Scanner($api);
    }

    /**
     * @param array<string, mixed> $a
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function execute(string $user, array $scopes, string $tool, array $a): array
    {
        $write = ToolCatalog::isWrite($tool);
        $scope = ToolCatalog::scope($tool);
        if (!in_array($scope, $scopes, true)) {
            throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize ' . $scope . '.', [], 403);
        }
        [, $properties, $required] = WorkflowTools::specs()[$tool];
        Arguments::validate($a, $properties, $required);

        return $this->locks->run(
            'account:' . $user,
            function () use ($user, $tool, $a, $write) {
                $connection = $this->google->connection($user);
                if (!$write) {
                    return $this->read($user, $tool, $a);
                }

                return $this->receipts->run(
                    $user,
                    $connection['generation'],
                    $tool,
                    $a,
                    fn($step, $operation, $done) => $this->mutate($user, $connection, $tool, $a, $step, $operation, $done),
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{channel_id: string, generation: string} $connection
     * @param array<string, mixed> $done
     * @return array<string, mixed>
     */
    private function mutate(
        string $user,
        array $connection,
        string $tool,
        array $arguments,
        callable $step,
        string $operation,
        array $done,
    ): array {
        // Persist a frozen plan before dispatching any write; resume never replans from changed inputs.
        $plan = $step('plan', fn() => $this->plan($user, $connection['channel_id'], $tool, $arguments));
        $target = $arguments['playlist_id'] ?? null;
        if ($tool === 'copy_playlist') {
            $target = $this->createCopyTarget($user, $arguments, $step, $operation);
        }
        if (!is_string($target) || $target === '') {
            throw new ServiceError('INVALID_ARGUMENT', 'A destination playlist is required.');
        }

        $this->verifyPlan($user, $connection['channel_id'], $tool, $plan, $done, $target);

        $deadline = microtime(true) + 45;
        $writes = isset($done['create']) || $tool !== 'copy_playlist' ? 0 : 1;
        foreach ($plan['actions'] as $n => $action) {
            $label = 'action:' . $n;
            if (isset($done[$label])) {
                continue;
            }
            if ($writes >= 20 || microtime(true) > $deadline) {
                throw new ServiceError(
                    'OPERATION_TIME_LIMIT',
                    'At most 20 writes and 45 seconds per call. Inspect the receipt and explicitly resume remaining steps.',
                    [],
                    409,
                );
            }

            $playlist = $action['playlist_id'] ?? $target;
            $step($label, fn() => $this->dispatchAction($user, $playlist, $action, $operation));
            $writes++;
        }

        return [
            'playlist_id' => $target,
            'planned_changes' => count($plan['actions']),
            'skipped_entries' => $plan['skipped'],
            'complete' => true,
        ];
    }

    /**
     * @param array<string, mixed> $done
     * @param WorkflowPlan $plan
     */
    private function verifyPlan(
        string $user,
        string $channel,
        string $tool,
        array $plan,
        array $done,
        string $target,
    ): void {
        $expected = $plan['before'];
        if ($tool === 'copy_playlist') {
            $expected[$target] = [];
        }

        foreach ($plan['actions'] as $n => $action) {
            if (isset($done['action:' . $n])) {
                $this->apply($expected, $action, $done['action:' . $n], $target);
            }
        }

        $verificationDeadline = microtime(true) + 45;
        foreach ($expected as $playlist => $entries) {
            $this->scanner->owned($user, $playlist, $channel);
            $current = $this->scanner->entries($user, $playlist, $verificationDeadline);
            $identity = static fn(array $entry): array => [$entry['id'], $entry['video_id'] ?? null];
            if (array_map($identity, $current) !== array_map($identity, $entries)) {
                throw new ServiceError(
                    'PLAYLIST_CHANGED',
                    'Playlist order or membership differs from the saved plan. Reconcile this operation before starting a new one.',
                    [],
                    409,
                );
            }
        }
    }

    /**
     * @param WorkflowAction $action
     * @return array<string, mixed>
     */
    private function dispatchAction(string $user, string $playlist, array $action, string $operation): array
    {
        if ($action['type'] === 'remove') {
            return $this->api->call($user, 'playlistItems.delete', ['id' => $action['id']], operation: $operation);
        }

        $snippet = [
            'playlistId' => $playlist,
            'resourceId' => ['kind' => 'youtube#video', 'videoId' => $action['video_id']],
        ];
        if ($action['type'] === 'move') {
            $snippet['position'] = $action['position'];
        }

        $body = ['snippet' => $snippet] + ($action['type'] === 'move' ? ['id' => $action['id']] : []);
        $result = $this->api->call(
            $user,
            $action['type'] === 'move' ? 'playlistItems.update' : 'playlistItems.insert',
            ['part' => 'snippet'],
            $body,
            operation: $operation,
        );
        if (!is_string($result['id'] ?? null) || $result['id'] === '') {
            throw new ServiceError(
                'UPSTREAM_UNCERTAIN',
                'Playlist change returned no occurrence ID. Reconcile before retrying.',
                [],
                502,
            );
        }

        return ['id' => $result['id']];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return WorkflowPlan
     */
    private function plan(string $user, string $channel, string $tool, array $arguments): array
    {
        $deadline = microtime(true) + 45;
        $before = [];
        $target = $arguments['playlist_id'] ?? null;
        if ($target !== null) {
            $this->scanner->owned($user, $target, $channel);
            $before[$target] = $this->scanner->entries($user, $target, $deadline);
        }

        $actions = [];
        $skipped = 0;
        if (in_array($tool, ['copy_playlist', 'merge_playlists', 'transfer_tracks'], true)) {
            [$before, $actions, $skipped] = $this->sourceActions($user, $channel, $tool, $arguments, $before, $deadline);
        } elseif ($tool === 'remove_playlist_duplicates') {
            $actions = $this->duplicateActions($before[$target], $arguments['keep'] ?? 'first');
        } else {
            $actions = $this->reorderActions($before[$target], $tool, $arguments);
        }

        $targetCount = $target === null ? 0 : count($before[$target] ?? []);
        foreach ($actions as $action) {
            if ($action['type'] === 'add') {
                $targetCount++;
            }
            if ($action['type'] === 'remove' && !isset($action['playlist_id'])) {
                $targetCount--;
            }
        }
        if ($targetCount > 1000) {
            throw new ServiceError(
                'PLAYLIST_SCAN_LIMIT',
                'The target would exceed the 1000-entry workflow limit. No new write was dispatched.',
                [],
                409,
            );
        }

        return ['before' => $before, 'actions' => $actions, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param Snapshot $before
     * @return array{Snapshot, list<WorkflowAction>, int}
     */
    private function sourceActions(
        string $user,
        string $channel,
        string $tool,
        array $arguments,
        array $before,
        float $deadline,
    ): array {
        $target = $arguments['playlist_id'] ?? null;
        $actions = [];
        $skipped = 0;
        $sources = $arguments['source_playlist_ids'] ?? [$arguments['source_playlist_id']];
        if (in_array($target, $sources, true)) {
            throw new ServiceError('INVALID_ARGUMENT', 'Source and destination playlists must differ.');
        }

        $targetEntries = $target === null ? [] : ($before[$target] ?? []);
        $seen = array_fill_keys(array_filter(array_column($targetEntries, 'video_id')), true);
        $sourceCount = 0;
        foreach ($sources as $source) {
            if ($tool === 'transfer_tracks' && ($arguments['move'] ?? false)) {
                $this->scanner->owned($user, $source, $channel);
            } else {
                $this->scanner->metadata($user, $source);
            }

            $entries = $this->scanner->entries($user, $source, $deadline);
            $sourceCount += count($entries);
            if ($sourceCount > 1000) {
                throw new ServiceError('PLAYLIST_SCAN_LIMIT', 'Combined sources exceed 1000 entries.', [], 409);
            }
            if ($tool === 'transfer_tracks') {
                $byId = array_column($entries, null, 'id');
                foreach ($arguments['playlist_item_ids'] as $id) {
                    if (!isset($byId[$id])) {
                        throw new ServiceError('ITEM_NOT_IN_PLAYLIST', 'A selected occurrence is not in the source.', [], 409);
                    }
                }
                if ($arguments['move'] ?? false) {
                    $before[$source] = $entries;
                }

                $entries = array_map(fn($id) => $byId[$id], array_values($arguments['playlist_item_ids']));
            }

            [$sourceActions, $sourceSkipped, $seen] = $this->sourceAdditions($entries, $tool, $arguments, $seen);
            array_push($actions, ...$sourceActions);
            $skipped += $sourceSkipped;
            if ($tool === 'transfer_tracks' && ($arguments['move'] ?? false)) {
                foreach ($entries as $entry) {
                    $actions[] = ['type' => 'remove', 'playlist_id' => $source, 'id' => $entry['id']];
                }
            }
        }

        return [$before, $actions, $skipped];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<WorkflowAction>
     */
    private function duplicateActions(array $entries, string $keep): array
    {
        $entries = $keep === 'last' ? array_reverse($entries) : $entries;
        $actions = [];
        $seen = [];
        foreach ($entries as $entry) {
            $video = $entry['video_id'];
            if ($video === null) {
                continue;
            }
            if (isset($seen[$video])) {
                $actions[] = ['type' => 'remove', 'id' => $entry['id']];
            } else {
                $seen[$video] = true;
            }
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $entries
     * @return list<WorkflowAction>
     */
    private function reorderActions(array $entries, string $tool, array $arguments): array
    {
        $actions = [];
        $desired = $this->orderedEntries($entries, $tool, $arguments);

        foreach ($desired as $position => $entry) {
            $index = array_search($entry['id'], array_column($entries, 'id'), true);
            if ($index === false) {
                throw new ServiceError('RECONCILIATION_REQUIRED', 'Playlist order contains an unknown occurrence.', [], 409);
            }
            if ($index === $position) {
                continue;
            }
            if (!is_string($entry['video_id']) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $entry['video_id'])) {
                throw new ServiceError('INVALID_VIDEO', 'Cannot reorder an occurrence without a valid video ID.');
            }

            $actions[] = ['type' => 'move', 'id' => $entry['id'], 'video_id' => $entry['video_id'], 'position' => $position];
            array_splice($entries, $index, 1);
            array_splice($entries, $position, 0, [$entry]);
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function orderedEntries(array $entries, string $tool, array $arguments): array
    {
        if ($tool === 'reorder_playlist') {
            $ids = array_column($entries, 'id');
            if (
                count($ids) !== count($arguments['playlist_item_ids'])
                || array_diff($ids, $arguments['playlist_item_ids'])
            ) {
                throw new ServiceError('INVALID_ARGUMENT', 'Supply each current occurrence ID exactly once.');
            }

            $byId = array_column($entries, null, 'id');
            $desired = array_map(fn($id) => $byId[$id], array_values($arguments['playlist_item_ids']));
        } else {
            $desired = $entries;
            $order = $arguments['order'];
            if (
                str_starts_with($order, 'added_')
                && array_filter($entries, fn($entry) => $entry['added_at'] === null)
            ) {
                throw new ServiceError('YOUTUBE_RESPONSE_INVALID', 'Playlist-added timestamps were not returned.', [], 502);
            }
            if (str_starts_with($order, 'added_')) {
                foreach ($desired as &$entry) {
                    try {
                        $entry['added_sort_key'] = (float) (new \DateTimeImmutable($entry['added_at']))->format('U.u');
                    } catch (\Exception) {
                        throw new ServiceError('YOUTUBE_RESPONSE_INVALID', 'An invalid playlist-added timestamp was returned.', [], 502);
                    }
                }
                unset($entry);
            }
            usort(
                $desired,
                static function ($left, $right) use ($order) {
                    $comparison = str_starts_with($order, 'title_') ? strnatcasecmp($left['title'], $right['title']) : $left['added_sort_key'] <=> $right['added_sort_key'];
                    if (in_array($order, ['title_desc', 'added_newest'], true)) {
                        $comparison = -$comparison;
                    }

                    return $comparison ?: $left['position'] <=> $right['position'];
                },
            );
        }

        return $desired;
    }

    /**
     * @param WorkflowAction $action
     * @param Snapshot $expected
     * @param array<string, mixed> $result
     */
    private function apply(array &$expected, array $action, array $result, string $target): void
    {
        $playlist = $action['playlist_id'] ?? $target;
        if ($action['type'] === 'add') {
            $expected[$playlist][] = ['id' => $result['id'], 'video_id' => $action['video_id']];

            return;
        }

        $index = array_search($action['id'], array_column($expected[$playlist], 'id'), true);
        if ($index === false) {
            throw new ServiceError('RECONCILIATION_REQUIRED', 'Saved operation plan is inconsistent.', [], 409);
        }

        $entry = $expected[$playlist][$index];
        array_splice($expected[$playlist], $index, 1);
        if ($action['type'] === 'move') {
            array_splice($expected[$playlist], $action['position'], 0, [$entry]);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function read(string $user, string $tool, array $arguments): array
    {
        if ($tool === 'compare_playlists') {
            return $this->comparePlaylists($user, $arguments);
        }

        $response = $this->api->call(
            $user,
            'playlistItems.list',
            [
                'part' => 'snippet,contentDetails',
                'playlistId' => $arguments['playlist_id'],
                'maxResults' => $arguments['page_size'] ?? 50,
            ]
                + (isset($arguments['page_token']) ? ['pageToken' => $arguments['page_token']] : []),
        );
        $entries = array_map(
            fn($entry) => [
                'playlist_item_id' => $entry['id'],
                'video_id' => $entry['contentDetails']['videoId'] ?? $entry['snippet']['resourceId']['videoId'] ?? null,
                'title' => $entry['snippet']['title'] ?? '',
                'position' => $entry['snippet']['position'] ?? null,
            ],
            array_values($response['items'] ?? []),
        );
        $result = [
            'next_page_token' => $response['nextPageToken'] ?? null,
            'complete' => !isset($response['nextPageToken']),
        ];
        if ($tool === 'audit_playlist') {
            return $result + $this->auditEntries($user, $entries, $arguments);
        }

        $format = $arguments['format'] ?? 'json';
        if ($format === 'json') {
            return $result + ['format' => $format, 'entries' => $entries];
        }
        if ($format === 'urls') {
            return $result + [
                'format' => $format,
                'urls' => array_map(
                    fn($entry) => $entry['video_id'] ? 'https://www.youtube.com/watch?v=' . $entry['video_id'] : null,
                    $entries,
                ),
            ];
        }

        $csv = $this->exportCsv($entries);

        return $result + ['format' => 'csv', 'csv' => $csv];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function exportCsv(array $entries): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new ServiceError('STORAGE_UNAVAILABLE', 'Cannot open playlist export stream.', [], 503);
        }
        fputcsv($stream, ['playlist_item_id', 'video_id', 'title', 'position'], ',', '"', '');
        foreach ($entries as $entry) {
            // Prevent spreadsheet formula evaluation when the exported title is opened in Excel.
            foreach ($entry as &$cell) {
                if (is_string($cell) && preg_match('/^[\x00-\x20]*[=+@-]/', $cell)) {
                    $cell = "'" . $cell;
                }
            }
            unset($cell);
            fputcsv($stream, array_values($entry), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new ServiceError('STORAGE_UNAVAILABLE', 'Cannot read playlist export stream.', [], 503);
        }

        return $csv;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function createCopyTarget(string $user, array $arguments, callable $step, string $operation): string
    {
        $created = $step(
            'create',
            fn() => $this->api->call(
                $user,
                'playlists.insert',
                ['part' => 'snippet,status'],
                [
                    'snippet' => ['title' => $arguments['title']],
                    'status' => ['privacyStatus' => $arguments['privacy'] ?? 'private'],
                ],
                operation: $operation,
            ),
        );
        $target = $created['id'] ?? null;
        if (!is_string($target) || $target === '') {
            throw new ServiceError('UPSTREAM_UNCERTAIN', 'Creation returned no playlist ID. Reconcile before retrying.', [], 502);
        }

        return $target;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function comparePlaylists(string $user, array $arguments): array
    {
        $deadline = microtime(true) + 45;
        $left = $this->scanner->entries($user, $arguments['playlist_id'], $deadline);
        $right = $this->scanner->entries($user, $arguments['other_playlist_id'], $deadline);
        $leftCounts = array_count_values(array_filter(array_column($left, 'video_id'), 'is_string'));
        $rightCounts = array_count_values(array_filter(array_column($right, 'video_id'), 'is_string'));

        return [
            'only_in_left' => array_keys(array_diff_key($leftCounts, $rightCounts)),
            'only_in_right' => array_keys(array_diff_key($rightCounts, $leftCounts)),
            'left_occurrence_counts' => $leftCounts,
            'right_occurrence_counts' => $rightCounts,
            'complete' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function auditEntries(string $user, array $entries, array $arguments): array
    {
        $ids = array_values(array_unique(array_filter(array_column($entries, 'video_id'), 'is_string')));
        $videos = $ids ? $this->api->call($user, 'videos.list', ['part' => 'snippet,status,contentDetails', 'id' => implode(',', $ids)]) : ['items' => []];
        $byId = array_column($videos['items'] ?? [], null, 'id');
        foreach ($entries as &$entry) {
            $video = $byId[$entry['video_id']] ?? null;
            $entry['returned'] = $video !== null;
            $entry['privacy'] = $video['status']['privacyStatus'] ?? null;
            $restrictions = $video['contentDetails']['regionRestriction'] ?? [];
            $entry['region_restrictions'] = $restrictions;
            if (isset($arguments['region'])) {
                $entry['region_restricted'] = $video === null ? null : (
                    in_array($arguments['region'], $restrictions['blocked'] ?? [], true)
                    || (isset($restrictions['allowed']) && !in_array($arguments['region'], $restrictions['allowed'], true))
                );
            }
        }
        unset($entry);

        return [
            'entries' => $entries,
            'note' => 'Omitted metadata does not distinguish deletion from lack of access. No entries were removed.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $arguments
     * @param array<string, bool> $seen
     * @return array{list<WorkflowAction>, int, array<string, bool>}
     */
    private function sourceAdditions(array $entries, string $tool, array $arguments, array $seen): array
    {
        $actions = [];
        $skipped = 0;
        foreach ($entries as $entry) {
            $video = $entry['video_id'];
            if (!is_string($video) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $video)) {
                if ($tool === 'transfer_tracks') {
                    throw new ServiceError('INVALID_VIDEO', 'Cannot transfer an occurrence without a valid video ID.');
                }

                $skipped++;
                continue;
            }
            if (
                $tool !== 'transfer_tracks'
                && ($arguments['duplicate_policy'] ?? 'skip') === 'skip'
                && isset($seen[$video])
            ) {
                $skipped++;
                continue;
            }

            $seen[$video] = true;
            $actions[] = ['type' => 'add', 'video_id' => $video];
        }

        return [$actions, $skipped, $seen];
    }
}
