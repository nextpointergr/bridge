<?php

namespace Nextpointer\Bridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncEngine
{
    /**
     * ΦΑΣΗ 1: RUN (API -> TARGET)
     * Mapping των δεδομένων και αποθήκευση είτε στον Staging είτε απευθείας στο Live.
     */
    public function run(string $source, string $entity, array $rows, int $batchId = 0): array
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);
        $model = new $config['model'];

        // ΔΥΝΑΜΙΚΟΣ ΠΡΟΟΡΙΣΜΟΣ: Staging ή Live table;
        $useStaging = $mapper->useStaging();
        $targetTable = $useStaging ? "staging_" . $model->getTable() : $model->getTable();

        $idField = $mapper->getIdentifierField();
        $payload = [];
        $processedIds = [];

        foreach ($rows as $row) {
            $mapped = $mapper->map($row);
            $mapper->beforeSync($mapped);

            $mapped['source'] = $source;
            $mapped['updated_at'] = now();
            $mapped['created_at'] = $mapped['created_at'] ?? now();
            $mapped['deleted_at'] = null;
            $hashFields = $mapper->getHashFields();
            $dataToHash = !empty($hashFields) ? array_intersect_key($mapped, array_flip($hashFields)) : $mapped;
            unset($dataToHash['hash'], $dataToHash['updated_at'], $dataToHash['created_at'], $dataToHash['deleted_at'], $dataToHash['source']);
            $mapped['hash'] = md5(json_encode($dataToHash));

            $payload[] = $mapped;
            $processedIds[] = (string)$mapped[$idField];
        }

        if (!empty($payload)) {
            foreach (array_chunk($payload, 500) as $chunk) {
                // Γράφουμε στον πίνακα που αποφασίστηκε (Target Table)
                DB::table($targetTable)->upsert($chunk, [$idField], $mapper->getUpdateColumns());

                $msg = $useStaging ? "Προετοιμασία στο Stage: " : "Συγχρονισμός: ";
                broadcast(new \Nextpointer\Bridge\Events\BridgeSyncProgressUpdated(
                    $source, $entity, $batchId, 0, 0, false, 'fetching',
                    $msg . count($chunk) . " εγγραφές..."
                ));
            }

            // Αν είναι Direct Sync (όχι staging), καταγράφουμε και τα Activities εδώ
            if (!$useStaging && $batchId > 0) {
                $this->logActivities($batchId, $source, $entity, $payload, $config['model'], $idField);
            }
        }

        return $processedIds;
    }

    /**
     * ΦΑΣΗ 2: FINALIZE (STAGING -> LIVE)
     * Εκτελείται ΜΟΝΟ αν η οντότητα χρησιμοποιεί Staging.
     */
    public function finalize(string $source, string $entity, int $batchId = 0): int
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);

        // Αν κάποιος την καλέσει κατά λάθος για Direct οντότητα, σταματάμε.
        if (!$mapper->useStaging()) return 0;

        $modelClass = $config['model'];
        $liveTable = (new $modelClass)->getTable();
        $stagingTable = "staging_" . $liveTable;

        $uniqueKey = $mapper->getUniqueKey();
        $idField = $mapper->getIdentifierField();

        $totalRecords = DB::table($stagingTable)->count();
        $processedCount = 0;

        // Προ-εντοπισμός διπλοτύπων
        $duplicateValues = DB::table($stagingTable)
            ->select($uniqueKey)
            ->whereNotNull($uniqueKey)
            ->where($uniqueKey, '!=', '')
            ->groupBy($uniqueKey)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($uniqueKey)
            ->toArray();

        broadcast(new \Nextpointer\Bridge\Events\BridgeSyncProgressUpdated(
            $source, $entity, $batchId, 0, $totalRecords, false, 'finalizing',
            "Έναρξη ελέγχου εγκυρότητας και διπλοτύπων..."
        ));

        while (true) {
            $records = DB::table($stagingTable)->orderBy($idField)->limit(500)->get();
            if ($records->isEmpty()) break;

            $validPayload = [];
            $exceptions = [];
            $processedIds = [];

            foreach ($records as $record) {
                $recordArray = (array)$record;
                $processedIds[] = $recordArray[$idField];
                $currentVal = $recordArray[$uniqueKey] ?? null;
                $reason = null;

                if ($currentVal && in_array($currentVal, $duplicateValues)) {
                    $reason = "duplicate_{$uniqueKey}_detected";
                } else {
                    $reason = $mapper->validate($recordArray);
                }

                if ($reason) {
                    $exceptions[] = [
                        'source'     => $source,
                        'entity'     => $entity,
                        'identifier' => (string)$recordArray[$idField],
                        'reason'     => $reason,
                        'payload'    => json_encode($recordArray),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                } else {
                    $validPayload[] = $recordArray;
                }
            }

            $this->persist($modelClass, $mapper, $validPayload, $exceptions, $source, $entity, $batchId);
            DB::table($stagingTable)->whereIn($idField, $processedIds)->delete();

            $processedCount += $records->count();
            broadcast(new \Nextpointer\Bridge\Events\BridgeSyncProgressUpdated(
                $source, $entity, $batchId, $processedCount, $totalRecords, false, 'finalizing',
                "Μεταφορά στο Production: " . number_format($processedCount) . " / " . number_format($totalRecords)
            ));
        }

        return $totalRecords;
    }

    /**
     * Persist με Transaction
     */
    protected function persist($modelClass, $mapper, $payload, $exceptions, $source, $entity, $batchId): void
    {
        $tables = config('bridge.tables');
        $idField = $mapper->getIdentifierField();
        $tableName = (new $modelClass)->getTable();

        DB::transaction(function () use ($modelClass, $mapper, $payload, $exceptions, $source, $entity, $batchId, $tables, $idField, $tableName) {
            if (!empty($payload)) {
                $validIds = array_column($payload, $idField);
                DB::table($tables['exceptions'])->where('source', $source)->where('entity', $entity)->whereIn('identifier', $validIds)->delete();

                foreach (array_chunk($payload, 500) as $chunk) {
                    $modelClass::upsert($chunk, [$idField], $mapper->getUpdateColumns());
                }

                if ($batchId > 0) {
                    $this->logActivities($batchId, $source, $entity, $payload, $modelClass, $idField);
                }
            }

            if (!empty($exceptions)) {
                foreach ($exceptions as $ex) {
                    DB::table($tables['exceptions'])->updateOrInsert(
                        ['source' => $source, 'entity' => $entity, 'identifier' => $ex['identifier']],
                        ['reason' => $ex['reason'], 'payload' => $ex['payload'], 'updated_at' => now(), 'created_at' => now()]
                    );
                    if (method_exists($modelClass, 'bootSoftDeletes')) {
                        DB::table($tableName)->where($idField, $ex['identifier'])->update(['deleted_at' => now()]);
                    }
                }
            }
        });
    }

    /**
     * Log Activities
     */
    protected function logActivities($batchId, $source, $entity, $payload, $modelClass, $key): void
    {
        $existingRecords = $modelClass::withTrashed()
            ->whereIn($key, array_column($payload, $key))
            ->get()
            ->keyBy($key);

        $activities = [];
        foreach ($payload as $newItem) {
            $id = (string)$newItem[$key];
            $oldItem = $existingRecords[$id] ?? null;
            $action = $oldItem ? ($oldItem->trashed() ? 'restored' : 'updated') : 'created';

            if ($action === 'updated' && ($oldItem->hash ?? null) === $newItem['hash']) continue;

            $activities[] = [
                'batch_id' => $batchId,
                'source' => $source,
                'entity' => $entity,
                'identifier' => $id,
                'action' => $action,
                'changes' => null,
                'created_at' => now()
            ];
        }

        if (!empty($activities)) {
            foreach (array_chunk($activities, 500) as $chunk) {
                DB::table(config('bridge.tables.activities'))->insert($chunk);
            }
        }
    }
}
