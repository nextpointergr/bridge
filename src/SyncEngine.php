<?php

namespace Nextpointer\Bridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Schema; // <--- ΑΥΤΟ ΛΕΙΠΕΙ
use Illuminate\Database\Schema\Blueprint; // <--- ΚΑΙ ΑΥΤΟ ΓΙΑ ΤΟ CLOSURE
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

//        $this->ensureUniqueIndex($targetTable, $idField);

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
            if (!$useStaging && $batchId > 0 && $mapper->shouldLog()) {
                // Στέλνουμε το payload για καταγραφή ενώ η βάση έχει ακόμα τα παλιά δεδομένα
                $this->logActivities($batchId, $source, $entity, $payload, $config['model'], $idField);
            }

            foreach (array_chunk($payload, 500) as $chunk) {
                DB::table($targetTable)->upsert($chunk, [$idField], $mapper->getUpdateColumns());

                $msg = $useStaging ? "Προετοιμασία στο Stage: " : "Συγχρονισμός: ";
                broadcast(new \Nextpointer\Bridge\Events\BridgeSyncProgressUpdated(
                    $source, $entity, $batchId, 0, 0, false, 'fetching',
                    $msg . count($chunk) . " εγγραφές..."
                ));
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

                if ($batchId > 0 && $mapper->shouldLog()) {
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
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);

        // Έλεγχος αν ο mapper επιτρέπει το logging
        if (!$mapper->shouldLog()) {
            return;
        }

        // ΟΡΙΣΜΟΣ ΤΗΣ ΜΕΤΑΒΛΗΤΗΣ (Αυτό έλειπε)
        $hashFields = $mapper->getHashFields();

        $existingRecords = $modelClass::withTrashed()
            ->whereIn($key, array_column($payload, $key))
            ->get()
            ->keyBy($key);

        $activities = [];
        foreach ($payload as $newItem) {
            $id = (string)$newItem[$key];
            $oldItem = $existingRecords[$id] ?? null;

            $action = $oldItem ? ($oldItem->trashed() ? 'restored' : 'updated') : 'created';
            if ($action === 'updated' && ($oldItem->hash ?? null) === $newItem['hash']) {
                continue;
            }

            $changes = null;

            // Υπολογισμός Changes (Before/After) μόνο αν υπάρχει παλιό αντικείμενο
            if ($oldItem && $action !== 'created') {
                $diff = [];
                foreach ($hashFields as $field) {
                    $oldVal = $oldItem->{$field} ?? null;
                    $newVal = $newItem[$field] ?? null;

                    // Χαλαρή σύγκριση για αποφυγή θεμάτων string vs int
                    if ($oldVal != $newVal) {
                        $diff[$field] = [
                            'before' => $oldVal,
                            'after'  => $newVal
                        ];
                    }
                }
                $changes = !empty($diff) ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null;
            }

            $activities[] = [
                'batch_id'   => $batchId,
                'source'     => $source,
                'entity'     => $entity,
                'identifier' => $id,
                'action'     => $action,
                'changes'    => $changes,
                'created_at' => now()
            ];
        }

        if (!empty($activities)) {
            foreach (array_chunk($activities, 500) as $chunk) {
                DB::table(config('bridge.tables.activities'))->insert($chunk);
            }
        }
    }



    /**
     * Καθαρισμός ορφανών εγγραφών (Μόνο για Full Sync).
     */
    public function cleanup(string $source, string $entity, array $allProcessedIds, int $batchId = 0): int
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);


        if (!$mapper->shouldCleanup() || empty($allProcessedIds)) {
            return 0;
        }

        $modelClass = $config['model'];
        $model = new $modelClass;
        $tableName = $model->getTable();
        $idField = $mapper->getIdentifierField(); // π.χ. prestashop_id



        $idsToDelete = DB::table($tableName)
            ->where('source', $source)
            ->whereNull('deleted_at') // Μόνο όσα δεν είναι ήδη διεγραμμένα
            ->whereNotIn($idField, $allProcessedIds)
            ->pluck($idField)
            ->toArray();

        $count = count($idsToDelete);

        if ($count > 0) {
            // 2. Καταγραφή στα Activities (πριν τη διαγραφή)
            if ($mapper->shouldLog() && $batchId > 0) {
                $logData = [];
                foreach ($idsToDelete as $id) {
                    $logData[] = [
                        'batch_id'   => $batchId,
                        'source'     => $source,
                        'entity'     => $entity,
                        'identifier' => (string)$id,
                        'action'     => 'deleted',
                        'changes'    => json_encode(['info' => 'Removed during full sync cleanup'], JSON_UNESCAPED_UNICODE),
                        'created_at' => now()
                    ];
                }

                foreach (array_chunk($logData, 500) as $chunk) {
                    DB::table(config('bridge.tables.activities'))->insert($chunk);
                }
            }

            // 3. Εκτέλεση Soft Delete στη Live βάση
            DB::table($tableName)
                ->where('source', $source)
                ->whereIn($idField, $idsToDelete)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now()
                ]);

            broadcast(new \Nextpointer\Bridge\Events\BridgeSyncProgressUpdated(
                $source, $entity, $batchId, 100, 100, false, 'finalizing',
                "Καθαρίστηκαν $count παλιά προϊόντα (δεν βρέθηκαν στο API)."
            ));
        }

        return $count;
    }

    protected function ensureUniqueIndex($table, $column)
    {

        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            // Έλεγχος αν το index είναι UNIQUE και αν περιλαμβάνει τη στήλη μας
            if ($index['unique'] && in_array($column, $index['columns'])) {
                return; // Το index υπάρχει ήδη, σταμάτα εδώ
            }
        }

        // Αν δεν βρεθεί, το δημιουργούμε δυναμικά
        Schema::table($table, function (Blueprint $table) use ($column) {
            $table->unique($column);
        });
    }
}
