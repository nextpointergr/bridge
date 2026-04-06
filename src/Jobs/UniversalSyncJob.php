<?php

namespace Nextpointer\Bridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nextpointer\Bridge\SyncEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Cache;
use Nextpointer\Bridge\Events\BridgeSyncProgressUpdated;

class UniversalSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(
        public string $source,
        public string $entity,
        public int $syncBatchId,
        public bool $fullSync = false,
        public int $offset = 0,
        public int $limit = 500
    ) {}

    public function handle(SyncEngine $engine): void
    {
        $this->notify("Ο εργάτης ξεκίνησε την προετοιμασία...", 0, 0);

        $config = config("bridge.sources.{$this->source}");
        $entityConfig = $config['entities'][$this->entity];
        $mapper = app($entityConfig['mapper']); // Φέρνουμε τον Mapper
        $limit = $mapper->getBatchLimit();

        $provider = app($config['provider'])->setEntity($this->entity);
        $tables = config('bridge.tables');
        $since = null;

        // Έλεγχος αν η οντότητα απαιτεί Staging
        $useStaging = $mapper->useStaging();

        // 1. Προσδιορισμός Incremental Sync
        if ($mapper->syncByDate() && !$this->fullSync) {
            $lastBatch = DB::table($tables['batches'])
                ->where('source', $this->source)
                ->where('entity', $this->entity)
                ->where('status', 'completed')
                ->latest('finished_at')
                ->first();
            $since = $lastBatch?->finished_at;
        }

        // 2. Fetch & Process Loop
        $firstRun = true;
        $total = 0;

        while (true) {
            $response = $provider->fetchData($this->offset, $limit, $since);
            $rows = $response['data'] ?? [];

            if ($firstRun) {
                $total = (int) ($response['total'] ?? 0);
                DB::table($tables['batches'])->where('id', $this->syncBatchId)->update(['total' => $total]);

                if ($total === 0 || empty($rows)) {
                    $this->completeBatch($tables['batches'], 0, "Το σύστημα είναι ήδη ενημερωμένο.");
                    return;
                }

                $msg = $useStaging ? "Προετοιμασία στο Stage..." : "Απευθείας συγχρονισμός στη βάση...";
                $this->notify($msg, 0, $total, 'fetching');
                $firstRun = false;
            }

            if (empty($rows)) break;

            // Mapping & Upsert (Η run() πλέον ξέρει αν θα πάει Staging ή Live μέσω του Mapper)
            $engine->run($this->source, $this->entity, $rows, $this->syncBatchId);

            $this->offset += count($rows);
            $displayProcessed = ($total > 0 && $this->offset > $total) ? $total : $this->offset;

            DB::table($tables['batches'])->where('id', $this->syncBatchId)->update([
                'processed' => $displayProcessed
            ]);

            $this->notify("Επεξεργασία: $displayProcessed / $total", $displayProcessed, $total, 'fetching');

            if (count($rows) < $limit || $this->offset >= $total) break;
        }


        if ($useStaging) {
            DB::table($tables['batches'])->where('id', $this->syncBatchId)->update([
                'status' => 'finalizing',
                'processed' => 0
            ]);

            try {
                $this->notify("Έλεγχος και οριστικοποίηση δεδομένων...", 0, $total, 'finalizing');
                $engine->finalize($this->source, $this->entity, $this->syncBatchId);

                $this->completeBatch($tables['batches'], $total, "Ο συγχρονισμός ολοκληρώθηκε επιτυχώς!");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Bridge Finalize Failed [{$this->entity}]: " . $e->getMessage());
                DB::table($tables['batches'])->where('id', $this->syncBatchId)->update(['status' => 'failed']);
                Cache::lock("bridge_lock_{$this->source}_{$this->entity}")->forceRelease();
                throw $e;
            }
        } else {

            $this->completeBatch($tables['batches'], $total, "Ο συγχρονισμός (Direct) ολοκληρώθηκε!");
        }
    }

    private function completeBatch(string $tableName, int $total, string $message): void
    {
        DB::table($tableName)->where('id', $this->syncBatchId)->update([
            'status' => 'completed',
            'processed' => $total,
            'finished_at' => now()
        ]);

        broadcast(new BridgeSyncProgressUpdated(
            $this->source, $this->entity, $this->syncBatchId, $total, $total, true, 'completed', $message
        ));

        Cache::lock("bridge_lock_{$this->source}_{$this->entity}")->forceRelease();
    }

    private function notify($message, $processed = 0, $total = 0, $phase = 'processing') {
        broadcast(new BridgeSyncProgressUpdated(
            $this->source, $this->entity, $this->syncBatchId, $processed, $total, false, $phase, $message
        ));
    }
}
