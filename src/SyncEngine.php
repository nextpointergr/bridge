<?php

namespace Nextpointer\Bridge;

use Exception;
use Illuminate\Support\Facades\DB;
use Nextpointer\Bridge\Contracts\SyncMapper;

class SyncEngine
{
    /**
     * Η κύρια μέθοδος εκτέλεσης του συγχρονισμού για ένα batch δεδομένων.
     */
    public function run(string $source, string $entity, array $rows, int $batchId = 0): array
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");

        if (!$config) {
            throw new Exception("Bridge: Entity [{$entity}] for source [{$source}] is not configured.");
        }

        $mapper = app($config['mapper']);
        $model = $config['model'];

        $payload = [];
        $exceptions = [];
        $processedIds = [];

        foreach ($rows as $row) {
            $remoteId = (string)($row['id'] ?? '0');
            $processedIds[] = $remoteId;

            // 1. Mapping & Before Hook (Μετατροπή δεδομένων)
            $mapped = $mapper->map($row);
            $mapper->beforeSync($mapped);

            // 2. Custom Validation (Exceptions / Quarantine)
            if ($reason = $mapper->validate($mapped)) {
                $exceptions[] = [
                    'source'     => $source,
                    'entity'     => $entity,
                    'remote_id'  => $remoteId,
                    'reason'     => $reason,
                    'payload'    => json_encode($mapped),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                continue;
            }

            // 3. Smart Hashing (Έλεγχος αν άλλαξε το record)
            $hashFields = $mapper->getHashFields();
            $dataToHash = !empty($hashFields)
                ? array_intersect_key($mapped, array_flip($hashFields))
                : $mapped;

            // Αφαιρούμε timestamps από το hash για να μην κάνει update άσκοπα
            unset($dataToHash['hash'], $dataToHash['updated_at'], $dataToHash['created_at']);

            $mapped['hash'] = md5(json_encode($dataToHash));
            $mapped['updated_at'] = now();
            if (!isset($mapped['created_at'])) {
                $mapped['created_at'] = now();
            }

            $payload[] = $mapped;
        }

        // 4. Database Persistence (Upsert & Activities)
        $this->persist($model, $mapper, $payload, $exceptions, $source, $entity, $batchId, $rows);

        return $processedIds;
    }

    /**
     * Αποθήκευση στη βάση με Database Transaction.
     */
    protected function persist($model, $mapper, $payload, $exceptions, $source, $entity, $batchId, $rows): void
    {
        DB::transaction(function () use ($model, $mapper, $payload, $exceptions, $source, $entity, $batchId, $rows) {
            $tables = config('bridge.tables');

            // --- Αποθήκευση Έγκυρων Δεδομένων (Upsert) ---
            if (!empty($payload)) {
                $model::upsert($payload, [$mapper->getUniqueKey()], $mapper->getUpdateColumns());

                // Καταγραφή Activities (Logs) αν υπάρχει Batch ID
                if ($batchId > 0) {
                    $this->logActivities($batchId, $source, $entity, $payload, $mapper->getUniqueKey());
                }

                // Trigger AfterSync Hook
                $this->triggerAfterSync($model, $mapper, $payload, $rows);
            }

            // --- Διαχείριση Εξαιρέσεων (Exceptions / Quarantine) ---
            if (!empty($exceptions)) {
                DB::table($tables['exceptions'])->upsert(
                    $exceptions,
                    ['source', 'entity', 'remote_id'],
                    ['payload', 'reason', 'updated_at']
                );
            }

            // Καθαρισμός επιτυχημένων από τις εξαιρέσεις (αν υπήρχαν πριν)
            if (!empty($payload)) {
                $uniqueKeys = array_column($payload, $mapper->getUniqueKey());
                DB::table($tables['exceptions'])
                    ->where('source', $source)
                    ->where('entity', $entity)
                    ->whereIn('remote_id', $uniqueKeys)
                    ->delete();
            }
        });
    }

    /**
     * Καταγραφή των ενεργειών στον πίνακα Activities.
     */
    protected function logActivities(int $batchId, string $source, string $entity, array $payload, string $key): void
    {
        $activities = [];
        foreach ($payload as $item) {
            $activities[] = [
                'batch_id'   => $batchId,
                'source'     => $source,
                'entity'     => $entity,
                'identifier' => (string)$item[$key],
                'action'     => 'synced', // Μπορείς να το κάνεις πιο advanced με diff
                'changes'    => json_encode(['hash' => $item['hash']]),
                'created_at' => now(),
            ];
        }

        if (!empty($activities)) {
            DB::table(config('bridge.tables.activities'))->insert($activities);
        }
    }

    /**
     * Εκτέλεση του afterSync hook για κάθε μοντέλο που επηρεάστηκε.
     */
    protected function triggerAfterSync($model, $mapper, $payload, $rows): void
    {
        $key = $mapper->getUniqueKey();
        $syncedModels = $model::whereIn($key, array_column($payload, $key))
            ->get()
            ->keyBy($key);

        foreach ($rows as $originalRow) {
            $mappedData = $mapper->map($originalRow);
            $uVal = $mappedData[$key] ?? null;

            if ($uVal && isset($syncedModels[$uVal])) {
                $mapper->afterSync($originalRow, $syncedModels[$uVal]);
            }
        }
    }
}