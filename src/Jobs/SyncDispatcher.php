<?php

namespace Nextpointer\Bridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncDispatcher implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $source,
        public string $entity,
        public bool $fullSync = false
    ) {}

    public function handle(): void
    {
        $lockKey = "bridge_lock_{$this->source}_{$this->entity}";
        $lock = Cache::lock($lockKey, 3600);

        if (!$lock->get()) {
            Log::info("Bridge: Sync already running for {$this->source}:{$this->entity}");
            return;
        }

        $batchId = DB::table(config('bridge.tables.batches'))->insertGetId([
            'source'     => $this->source,
            'entity'     => $this->entity,
            'status'     => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UniversalSyncJob::dispatch($this->source, $this->entity, $batchId, $this->fullSync);
    }
}
