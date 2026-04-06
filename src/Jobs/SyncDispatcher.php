<?php

namespace Nextpointer\Bridge\Jobs;

use Illuminate\Bus\Batchable; // <--- ΠΡΟΣΘΕΣΕ ΑΥΤΟ
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
    // Πρόσθεσε το Batchable trait εδώ
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(
        public string $source,
        public string $entity,
        public bool $fullSync = false
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $lockKey = "bridge_lock_{$this->source}_{$this->entity}";
        $lock = Cache::lock($lockKey, 3600);

        if (!$lock->get()) {
            Log::info("Bridge: Sync already running for {$this->source}:{$this->entity}");
            return;
        }

        // Αυτό είναι το ID του δικού μας πίνακα sync_batches
        $internalBatchId = DB::table(config('bridge.tables.batches'))->insertGetId([
            'source'     => $this->source,
            'entity'     => $this->entity,
            'status'     => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Προσοχή: Περνάμε το $internalBatchId στη θέση της παραμέτρου $syncBatchId
        $job = new UniversalSyncJob(
            source: $this->source,
            entity: $this->entity,
            syncBatchId: $internalBatchId, // Χρήση του νέου ονόματος
            fullSync: $this->fullSync
        );

        if ($this->batch()) {
            $this->batch()->add($job);
        } else {
            dispatch($job);
        }
    }
}
