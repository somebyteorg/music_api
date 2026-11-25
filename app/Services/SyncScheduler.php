<?php

namespace App\Services;

use App\Jobs;
use App\Models;
use Illuminate\Support\Facades\DB;

class SyncScheduler
{
    public function mark(string $type, string $id): void
    {
        if (!in_array($type, ['songs', 'albums', 'artists', 'libraries', 'full'], true)) {
            return;
        }
        $key = $type === 'full' ? 'full' : $type.':'.$id;
        Models\SyncState::select(['id'])->firstOrCreate(['id' => $key]);
        Models\SyncState::whereKey($key)->increment('revision');
    }

    public function changed(string $type, string $id, ?array $before, array $after): void
    {
        $this->mark($type, $id);
        if ($type === 'files') {
            foreach (array_unique(array_filter([$before['song_id'] ?? null, $after['song_id'] ?? null])) as $songId) {
                $this->mark('songs', $songId);
            }
        }
        if ($graph = $before['_merge'] ?? null) {
            $this->merged($type, $graph);
        }
    }

    public function merged(string $type, array $graph): void
    {
        $this->mark($type, $graph['target_id']);
        foreach (array_unique([...array_column($graph['source_tracks'] ?? [], 'song_id'), ...array_keys($graph['song_versions'] ?? []), ...array_column($graph['songs'] ?? [], 'id')]) as $songId) {
            $this->mark('songs', $songId);
        }
        foreach (array_column($graph['albums'] ?? [], 'id') as $albumId) {
            $this->mark('albums', $albumId);
        }
    }

    public function touch(bool $full = false, bool $dispatch = true): void
    {
        if ($full) {
            $this->mark('full', '');
        }
        Models\SyncState::select(['id'])->firstOrCreate(['id' => 'library']);
        Models\SyncState::whereKey('library')->increment('revision', 1, ['error' => null]);
        if ($dispatch) {
            DB::afterCommit(fn() => Jobs\SyncLibrary::dispatch()->delay(now()->addSeconds(3)));
        }
    }
}
