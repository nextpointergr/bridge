<?php

namespace Nextpointer\Bridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nextpointer\Bridge\SyncEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Nextpointer\Bridge\Events\BridgeSyncProgressUpdated;

class UniversalSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $source,
        public string $entity,
        public int $batchId,
        public bool $fullSync = false,
        public int $offset = 0,
        public int $limit = 500
    ) {}

    public function handle(SyncEngine $engine): void
    {
        $config = config("bridge.sources.{$this->source}");
        $provider = app($config['provider'])->setEntity($this->entity);
        $tables = config('bridge.tables');
        $since = null;

        // Incremental logic
        if (!$this->fullSync) {
            $lastBatch = DB::table($tables['batches'])
                ->where('source', $this->source)
                ->where('entity', $this->entity)
                ->where('status', 'completed')
                ->latest('finished_at')
                ->first();
            $since = $lastBatch?->finished_at;
        }

        while (true) {
            $response = $provider->fetchData($this->offset, $this->limit, $since);
            $rows = $response['data'] ?? [];
            $total = $response['total'] ?? 0;

            if ($this->offset === 0) {
                DB::table($tables['batches'])->where('id', $this->batchId)->update(['total' => $total]);
            }

            if (empty($rows)) break;

            // Εκτέλεση Engine (Mapping & Upsert)
            $engine->run($this->source, $this->entity, $rows, $this->batchId);

            $this->offset += count($rows);

            DB::table($tables['batches'])->where('id', $this->batchId)->increment('processed', count($rows));

            // Broadcast Progress
            broadcast(new BridgeSyncProgressUpdated(
                $this->source, $this->entity, $this->batchId, $this->offset, $total, false
            ));

            if (count($rows) < $this->limit) break;
        }

        // Ολοκλήρωση
        DB::table($tables['batches'])->where('id', $this->batchId)->update([
            'status' => 'completed',
            'finished_at' => now()
        ]);

        broadcast(new BridgeSyncProgressUpdated(
            $this->source, $this->entity, $this->batchId, $this->offset, $this->offset, true
        ));

        Cache::lock("bridge_lock_{$this->source}_{$this->entity}")->forceRelease();
    }
}
