<?php

namespace App\Jobs;

use App\Models;
use App\Services;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncLibrary implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    private const BATCH_SIZE = 500;
    private const RUN_BUDGET_SECONDS = 480;

    public int $tries = 5;

    public int $timeout = 600;
    public int $uniqueFor = 660;

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(Services\NavidromeSync $sync): bool
    {
        return Cache::lock('music-library-sync', 660)->get(fn() => $this->synchronize($sync));
    }

    private function synchronize(Services\NavidromeSync $sync): bool
    {
        $state = Models\SyncState::select(Models\SyncState::FIELDS)->find('library');
        if (!$state || $state->revision <= $state->synced_revision) {
            return true;
        }

        try {
            $deadline = hrtime(true) + self::RUN_BUDGET_SECONDS * 1_000_000_000;
            $pending = Models\SyncState::select(['id', 'revision'])->whereColumn('revision', '>', 'synced_revision');
            $full = (clone $pending)->whereKey('full')->get();
            $didSync = !$state->synced_at || $full->isNotEmpty();
            if ($didSync) {
                $sync->run();
                $this->acknowledge($full);
                // Record the initial full sync so the next run can resume the remaining batches.
                Models\SyncState::whereKey('library')->whereNull('synced_at')->update(['synced_at' => now()]);
            }

            $finished = $pending->whereNotIn('id', ['library', 'full'])->chunkById(
                self::BATCH_SIZE,
                function (Collection $changes) use ($sync, $deadline, &$didSync) {
                    if (hrtime(true) >= $deadline) {
                        return false;
                    }

                    $sync->run($changes->pluck('id')->all());
                    $this->acknowledge($changes);
                    $didSync = true;

                    return true;
                }
            );
            if (!$finished) {
                return false;
            }
            if (!$didSync) {
                $sync->run([]);
            }

            Models\SyncState::whereKey('library')->where('synced_revision', '<', $state->revision)
                ->update(['synced_revision' => $state->revision, 'synced_at' => now(), 'error' => null]);

            return true;
        } catch (\Throwable $e) {
            $state->update(['error' => '同步暂未完成，请重试。']);
            throw $e;
        }
    }

    private function acknowledge(Collection $changes): void
    {
        Models\SyncState::upsert($changes->map(fn($change) => [
            'id' => $change->id,
            'revision' => $change->revision,
            'synced_revision' => $change->revision,
        ])->all(), ['id'], ['synced_revision' => DB::raw('GREATEST(sync_states.synced_revision, EXCLUDED.synced_revision)')]);
    }
}
