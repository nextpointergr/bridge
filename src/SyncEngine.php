<?php

namespace Nextpointer\Bridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Exception;

class SyncEngine
{
    /**
     * ΦΑΣΗ 1: RUN (API -> TARGET)
     * Mapping και αποθήκευση στον Staging ή Live πίνακα.
     */
    public function run(string $source, string $entity, array $rows, int $batchId = 0): array
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);
        // Εδώ ορίζουμε το string του Class (π.χ. App\Models\Entity)
        $modelClass = $config['model'];
        // Εδώ φτιάχνουμε ένα instance για να παίρνουμε το table name κτλ
        $model = new $modelClass;


        $useStaging = $mapper->useStaging();
        $targetTable = $useStaging ? "staging_" . $model->getTable() : $model->getTable();
        $idField = $mapper->getIdentifierField();

        $shouldLog = $batchId > 0 && $mapper->shouldLog();

        $existingRecords = collect();
        if (!$useStaging && !empty($rows)) {
            // Μαζεύουμε τα IDs για να κάνουμε ΕΝΑ query στη βάση
            $idsInBatch = array_map(fn($row) => (string)($mapper->map($row)[$idField] ?? ''), $rows);

            $filteredIds = array_filter($idsInBatch);

            $filterByType = method_exists($mapper, 'shouldFilterByType') && $mapper->shouldFilterByType();

            if ($shouldLog) {
                $query = $modelClass::withTrashed()->whereIn($idField, $filteredIds);
                if ($filterByType) {
                    $query->where('type', $entity);
                }
                $existingRecords = $query->get()->keyBy(fn($item) => (string)$item->{$idField});
            } else {
                // ΔΙΟΡΘΩΣΗ: Προσθήκη type filter και στο απλό DB query
                $query = DB::table($targetTable)->whereIn($idField, $filteredIds);
                if ($filterByType) {
                    $query->where('type', $entity);
                }
                $hashes = $query->pluck('hash', $idField)->toArray();
                $existingRecords = collect($hashes)->map(fn($h) => (object)['hash' => $h]);
            }
        }




        $payload = [];
        $processedIds = [];
        $toLog = [];
        foreach ($rows as $row) {
            $mapped = $mapper->map($row);
            if (isset($mapped['payload']) && is_array($mapped['payload'])) {
                $mapped['payload'] = json_encode($mapped['payload'], JSON_UNESCAPED_UNICODE);
            }


            $mapper->beforeSync($mapped);

            $mapped['source'] = $source;
            $mapped['deleted_at'] = null;

            $hashFields = $mapper->getHashFields();
            $dataToHash = !empty($hashFields) ? array_intersect_key($mapped, array_flip($hashFields)) : $mapped;
            unset($dataToHash['hash'], $dataToHash['updated_at'], $dataToHash['created_at'], $dataToHash['deleted_at'], $dataToHash['source']);

            $newHash = md5(json_encode($dataToHash));
            $identifier = (string)$mapped[$idField];
            $existing = $existingRecords->get($identifier);

            // --- ΤΟ ΚΛΕΙΔΙ: ΕΛΕΓΧΟΣ HASH ---
            if (!$useStaging && $existing && $existing->hash === $newHash) {
                $processedIds[] = $identifier;
                continue; // Προσπέραση - Δεν υπάρχει αλλαγή
            }
            $mapped['hash'] = $newHash;
            $mapped['updated_at'] = now();
            $mapped['created_at'] = $mapped['created_at'] ?? now();
            $payload[] = $mapped;
            $processedIds[] = (string)$mapped[$idField];

            if (!$useStaging && $shouldLog) {
                $toLog[] = $mapped;
            }
        }

        if (!empty($payload)) {
            $dbChunkSize = $mapper->getDbChunkSize();
            foreach (array_chunk($payload, $dbChunkSize) as $chunk) {
                DB::table($targetTable)->upsert($chunk, [$idField], $mapper->getUpdateColumns());
            }
        }

        // --- LOGGING ΓΙΑ DIRECT SYNC ---
        if (!empty($toLog)) {
            $this->logActivities($batchId, $source, $entity, $toLog, $existingRecords, $idField);
        }




        return $processedIds;
    }

    /**
     * ΦΑΣΗ 2: FINALIZE (STAGING -> LIVE)
     */
    public function finalize(string $source, string $entity, int $batchId = 0): int
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);

        if (!$mapper->useStaging()) return 0;

        $modelClass = $config['model'];
        $liveTable = (new $modelClass)->getTable();
        $stagingTable = "staging_" . $liveTable;

        $uniqueKey = $mapper->getUniqueKey();
        $idField = $mapper->getIdentifierField();

        $liveColumns = $mapper->getLiveColumns();
        $allowedMap = array_flip($liveColumns);

        $totalRecords = DB::table($stagingTable)->count();

        $duplicateValues = DB::table($stagingTable)
            ->select($uniqueKey)
            ->whereNotNull($uniqueKey)
            ->where($uniqueKey, '!=', '')
            ->groupBy($uniqueKey)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($uniqueKey)
            ->toArray();

        $dbChunkSize = $mapper->getDbChunkSize();
        while (true) {
            $records = DB::table($stagingTable)->orderBy($idField)->limit($dbChunkSize)->get();
            if ($records->isEmpty()) break;

            $validPayload = [];
            $exceptions = [];
            $processedIds = [];

            foreach ($records as $record) {
                $recordArray = (array)$record;
                $processedIds[] = $recordArray[$idField];
                $cleanRecord = array_intersect_key($recordArray, $allowedMap);

                $currentEan = $cleanRecord[$uniqueKey] ?? null;
                $currentSourceId = $recordArray[$idField];
                $reason = null;

                if ($currentEan && in_array($currentEan, $duplicateValues)) {
                    $reason = "duplicate_{$uniqueKey}_detected_in_staging";
                } else {
                    $collision = DB::table($liveTable)
                        ->where($uniqueKey, $currentEan)
                        ->where(function($query) use ($idField, $currentSourceId) {
                            $query->whereNotNull($idField)
                                ->where($idField, '!=', (string)$currentSourceId);
                        })
                        ->first();

                    if ($collision) {
                        $reason = "ean_collision: EAN {$currentEan} is already owned by another record";
                    } else {
                        $reason = $mapper->validate($cleanRecord);
                    }
                }

                if ($reason) {
                    $exceptions[] = [
                        'source'     => $source,
                        'entity'     => $entity,
                        'identifier' => (string)$recordArray[$idField],
                        'reason'     => $reason,
                        'payload'    => json_encode($cleanRecord),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                } else {
                    $validPayload[] = $cleanRecord;
                }
            }

            $this->persist($modelClass, $mapper, $validPayload, $exceptions, $source, $entity, $batchId);
            DB::table($stagingTable)->whereIn($idField, $processedIds)->delete();
        }

        return $totalRecords;
    }

    /**
     * Persist με φιλτράρισμα Hash για αποφυγή Overwrite.
     */
   /** protected function persist($modelClass, $mapper, $payload, $exceptions, $source, $entity, $batchId): void
    {
        $tables = config('bridge.tables');
        $idField = $mapper->getIdentifierField();
        $uniqueKey = $mapper->getUniqueKey();
        $dbChunkSize = $mapper->getDbChunkSize();
        $tableName = (new $modelClass)->getTable();
        $liveColumns = $mapper->getLiveColumns();
        foreach (['source', 'updated_at', 'created_at', 'deleted_at', 'hash'] as $reqCol) {
            if (!in_array($reqCol, $liveColumns)) $liveColumns[] = $reqCol;
        }

        DB::transaction(function () use ($modelClass, $mapper, $payload, $exceptions, $source, $entity, $batchId, $tables, $idField,
            $uniqueKey, $dbChunkSize, $liveColumns, $tableName) {
            if (!empty($payload)) {
                $payloadEans = array_filter(array_column($payload, $uniqueKey));
                $validIds = array_column($payload, $idField);
                DB::table($tables['exceptions'])
                    ->where('source', $source)
                    ->where('entity', $entity)
                    ->whereIn('identifier', $validIds)
                    ->delete();
                foreach($payload as $p) {
                    if (!empty($p[$uniqueKey])) {
                        DB::table($tables['exceptions'])
                            ->where('source', $source)
                            ->where('entity', $entity)
                            ->where('reason', 'like', "%{$p[$uniqueKey]}%")
                            ->delete();
                    }
                }


                $existingRecords = $modelClass::withTrashed()->whereIn($uniqueKey, $payloadEans)->get()->keyBy($uniqueKey);

                if ($batchId > 0 && $mapper->shouldLog()) {
                    $this->logActivities($batchId, $source, $entity, $payload, $existingRecords, $uniqueKey);
                }

                // --- ΠΡΟΣΘΕΣΕ ΑΥΤΕΣ ΤΙΣ ΓΡΑΜΜΕΣ ΕΔΩ ---
                // Επαναφέρει αυτόματα όσα προϊόντα είναι Soft Deleted και περιλαμβάνονται στο τρέχον batch
                $modelClass::withTrashed()
                    ->whereIn($uniqueKey, $payloadEans)
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);
                // --------------------------------------


                $finalBatch = [];
                $changedItems = [];

                foreach ($payload as $row) {
                    $ean = $row[$uniqueKey] ?? null;
                    if (!$ean) continue;

                    $normalizedRow = [];
                    foreach ($liveColumns as $col) {
                        $normalizedRow[$col] = $row[$col] ?? null;
                    }
                    $normalizedRow['source'] = $source;

                    if ($existingRecords->has($ean)) {
                        $existingModel = $existingRecords->get($ean);
                        $ownedFields = method_exists($mapper, 'getOwnershipFields') ? $mapper->getOwnershipFields() : [];

                        // Smart Merge Logic
                        foreach ($normalizedRow as $field => $newValue) {
                            if (!in_array($field, $ownedFields) && !empty($existingModel->{$field})) {
                                $normalizedRow[$field] = $existingModel->{$field};
                            }
                        }

                        // Hash Check
                        $hashFields = $mapper->getHashFields();
                        $dataToHash = array_intersect_key($normalizedRow, array_flip($hashFields));
                        $finalHash = md5(json_encode($dataToHash));
                        $normalizedRow['hash'] = $finalHash;

                        $isHashDifferent = $existingModel->hash !== $finalHash;

                        $currentPayloadInDb = is_array($existingModel->payload)
                            ? json_encode($existingModel->payload, JSON_UNESCAPED_UNICODE)
                            : $existingModel->payload;

                        // 2. Προετοιμασία του νέου payload από το API (διασφάλιση String)
                        $newPayloadFromApi = is_array($normalizedRow['payload'])
                            ? json_encode($normalizedRow['payload'], JSON_UNESCAPED_UNICODE)
                            : $normalizedRow['payload'];

                        // 3. Σύγκριση πλέον ως Strings
                        $isPayloadDifferent = $currentPayloadInDb !== $newPayloadFromApi;

                        if ($isHashDifferent) {
                            $normalizedRow['updated_at'] = now();
                            $normalizedRow['payload'] = $newPayloadFromApi; // Διασφάλιση string για το upsert
                            $finalBatch[] = $normalizedRow;
                            $changedItems[] = ['payload' => $row, 'ean' => $ean];
                        } elseif ($isPayloadDifferent) {
                            // ΕΛΕΓΧΟΣ: Αν το νέο payload είναι άδειο (π.χ. από Prestashop)
                            // και η βάση έχει ήδη δεδομένα, ΑΓΝΟΗΣΕ ΤΟ.
                            $isEmptyNewPayload = empty(json_decode($newPayloadFromApi, true));
                            $hasExistingData = !empty(json_decode($currentPayloadInDb, true));

                            if ($isEmptyNewPayload && $hasExistingData) {
                                // Μην κάνεις τίποτα, κράτα την ποσότητα του ERP
                            } else {
                                // Ενημέρωσε κανονικά αν είναι πραγματική αλλαγή (π.χ. από ERP)
                                DB::table($tableName)
                                    ->where($uniqueKey, $ean)
                                    ->update(['payload' => $newPayloadFromApi]);

                                $changedItems[] = ['payload' => $row, 'ean' => $ean];
                            }
                        }
                    } else {
                        $normalizedRow['created_at'] = now();
                        $normalizedRow['updated_at'] = now();
                        $finalBatch[] = $normalizedRow;
                        $changedItems[] = ['payload' => $row, 'ean' => $ean];
                    }
                }

                if (!empty($finalBatch)) {
                    foreach (array_chunk($finalBatch, $dbChunkSize) as $chunk) {
                        $chunk = array_map(function($item) {
                            if (isset($item['payload']) && is_array($item['payload'])) {
                                $item['payload'] = json_encode($item['payload'], JSON_UNESCAPED_UNICODE);
                            }
                            return $item;
                        }, $chunk);
                        $modelClass::upsert($chunk, [$uniqueKey], $liveColumns);
                    }
                }



                foreach ($changedItems as $changed) {
                    $finalModel = $modelClass::where($uniqueKey, $changed['ean'])->first();
                    if ($finalModel) $mapper->afterSync($changed['payload'], $finalModel);
                }
            }

            if (!empty($exceptions)) {
                foreach ($exceptions as $ex) {
                    DB::table($tables['exceptions'])->updateOrInsert(
                        ['source' => $source, 'entity' => $entity, 'identifier' => (string)$ex['identifier']],
                        ['reason' => $ex['reason'], 'payload' => $ex['payload'], 'updated_at' => now(), 'created_at' => now()]
                    );

                    $modelClass::where($idField, $ex['identifier'])->delete();
                }
            }
        });
    } **/


    protected function persist($modelClass, $mapper, $payload, $exceptions, $source, $entity, $batchId): void
    {
        $tables = config('bridge.tables');
        $idField = $mapper->getIdentifierField(); // erp_id
        $uniqueKey = $mapper->getUniqueKey();      // ean
        $dbChunkSize = $mapper->getDbChunkSize();
        $tableName = (new $modelClass)->getTable();
        $liveColumns = $mapper->getLiveColumns();
        foreach (['source', 'updated_at', 'created_at', 'deleted_at', 'hash'] as $reqCol) {
            if (!in_array($reqCol, $liveColumns)) $liveColumns[] = $reqCol;
        }

        DB::transaction(function () use ($modelClass, $mapper, $payload, $exceptions, $source, $entity, $batchId, $tables, $idField,
            $uniqueKey, $dbChunkSize, $liveColumns, $tableName) {

            if (!empty($payload)) {
                $payloadEans = array_filter(array_column($payload, $uniqueKey));
                $payloadIdentifiers = array_filter(array_column($payload, $idField));

                // 1. Καθαρισμός παλιών Exceptions
                DB::table($tables['exceptions'])
                    ->where('source', $source)
                    ->where('entity', $entity)
                    ->whereIn('identifier', $payloadIdentifiers)
                    ->delete();

                foreach($payload as $p) {
                    if (!empty($p[$uniqueKey])) {
                        DB::table($tables['exceptions'])
                            ->where('source', $source)
                            ->where('entity', $entity)
                            ->where('reason', 'like', "%{$p[$uniqueKey]}%")
                            ->delete();
                    }
                }

                // 2. Φόρτωση υπαρχόντων records με δύο τρόπους για να πιάσουμε την αλλαγή EAN
                $existingByEan = $modelClass::withTrashed()->whereIn($uniqueKey, $payloadEans)->get()->keyBy($uniqueKey);
                $existingById  = $modelClass::withTrashed()->whereIn($idField, $payloadIdentifiers)->get()->keyBy($idField);

                if ($batchId > 0 && $mapper->shouldLog()) {
                    // Χρησιμοποιούμε το existingByEan για τα logs όπως πριν
                    $this->logActivities($batchId, $source, $entity, $payload, $existingByEan, $uniqueKey);
                }

                // Επαναφορά Soft Deleted
                $modelClass::withTrashed()
                    ->whereIn($idField, $payloadIdentifiers) // Χρήση idField για σιγουριά στην επαναφορά
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);

                $finalBatch = [];
                $changedItems = [];

                foreach ($payload as $row) {
                    $ean = $row[$uniqueKey] ?? null;
                    $erpId = $row[$idField] ?? null;
                    if (!$ean) continue;

                    $normalizedRow = [];
                    foreach ($liveColumns as $col) {
                        $normalizedRow[$col] = $row[$col] ?? null;
                    }
                    $normalizedRow['source'] = $source;

                    // --- IDENTITY FALLBACK LOGIC ---
                    $existingModel = null;
                    if ($existingByEan->has($ean)) {
                        $existingModel = $existingByEan->get($ean);
                    } elseif ($erpId && $existingById->has($erpId)) {
                        // ΕΔΩ ΕΙΝΑΙ ΤΟ ΚΛΕΙΔΙ: Το βρήκαμε από το ERP ID, άρα το EAN άλλαξε!
                        $existingModel = $existingById->get($erpId);
                    }

                    if ($existingModel) {
                        $ownedFields = method_exists($mapper, 'getOwnershipFields') ? $mapper->getOwnershipFields() : [];

                        // Smart Merge
                        foreach ($normalizedRow as $field => $newValue) {
                            if (!in_array($field, $ownedFields) && !empty($existingModel->{$field})) {
                                $normalizedRow[$field] = $existingModel->{$field};
                            }
                        }

                        // Hash Check
                        $hashFields = $mapper->getHashFields();
                        $dataToHash = array_intersect_key($normalizedRow, array_flip($hashFields));
                        $finalHash = md5(json_encode($dataToHash));
                        $normalizedRow['hash'] = $finalHash;

                        $isHashDifferent = $existingModel->hash !== $finalHash;
                        $currentPayloadInDb = null;
                        if (isset($existingModel->payload)) {

                            $currentPayloadInDb = is_array($existingModel->payload)
                                ? json_encode($existingModel->payload, JSON_UNESCAPED_UNICODE)
                                : $existingModel->payload;
                        }

                        $newPayloadFromApi = null;
                        if (isset($normalizedRow['payload'])) {

                            $newPayloadFromApi = is_array($normalizedRow['payload'])
                                ? json_encode($normalizedRow['payload'], JSON_UNESCAPED_UNICODE)
                                : $normalizedRow['payload'];
                        }
                        $isPayloadDifferent = false;
                        if (array_key_exists('payload', $normalizedRow)) {
                            $isPayloadDifferent = $currentPayloadInDb !== $newPayloadFromApi;
                        }


                        if ($isHashDifferent) {
                            $normalizedRow['updated_at'] = now();
                            $normalizedRow['payload'] = $newPayloadFromApi;
                            $existingModel->update($normalizedRow);
                            $changedItems[] = ['payload' => $row, 'ean' => $ean];
                        } elseif ($isPayloadDifferent) {
                            $isEmptyNewPayload = empty(json_decode($newPayloadFromApi, true));
                            $hasExistingData = !empty(json_decode($currentPayloadInDb, true));

                            if (!($isEmptyNewPayload && $hasExistingData)) {
                                DB::table($tableName)
                                    ->where($idField, $erpId) // Update με βάση το ERP ID
                                    ->update(['payload' => $newPayloadFromApi]);
                                $changedItems[] = ['payload' => $row, 'ean' => $ean];
                            }
                        }
                    } else {
                        // New Record
                        $normalizedRow['created_at'] = now();
                        $normalizedRow['updated_at'] = now();
                        $finalBatch[] = $normalizedRow;
                        $changedItems[] = ['payload' => $row, 'ean' => $ean];
                    }
                }

                if (!empty($finalBatch)) {
                    foreach (array_chunk($finalBatch, $dbChunkSize) as $chunk) {
                        $chunk = array_map(function($item) {
                            if (isset($item['payload']) && is_array($item['payload'])) {
                                $item['payload'] = json_encode($item['payload'], JSON_UNESCAPED_UNICODE);
                            }
                            return $item;
                        }, $chunk);
                        $modelClass::upsert($chunk, [$uniqueKey], $liveColumns);
                    }
                }

                // AfterSync Hooks
                foreach ($changedItems as $changed) {
                    $finalModel = $modelClass::where($uniqueKey, $changed['ean'])->first();
                    if ($finalModel) $mapper->afterSync($changed['payload'], $finalModel);
                }
            }

            // Exceptions logic
            if (!empty($exceptions)) {
                foreach ($exceptions as $ex) {
                    DB::table($tables['exceptions'])->updateOrInsert(
                        ['source' => $source, 'entity' => $entity, 'identifier' => (string)$ex['identifier']],
                        ['reason' => $ex['reason'], 'payload' => $ex['payload'], 'updated_at' => now(), 'created_at' => now()]
                    );
                    $modelClass::where($idField, $ex['identifier'])->delete();
                }
            }
        });
    }

    protected function logActivities($batchId, $source, $entity, $payload, $modelOrRecords, $key): void
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);
        $hashFields = $mapper->getHashFields();
        $fieldsToCompare = $hashFields;
        $firstRow = reset($payload);
        if ($firstRow && array_key_exists('payload', $firstRow)) {
            $fieldsToCompare[] = 'payload';
        }


        //$fieldsToCompare = array_unique(array_merge($hashFields, ['payload']));
        $fieldsToCompare = array_unique($fieldsToCompare);


        if (is_string($modelOrRecords)) {
            $query = $modelOrRecords::withTrashed()
                ->whereIn($key, array_column($payload, $key));
            if (method_exists($mapper, 'shouldFilterByType') && $mapper->shouldFilterByType()) {
                $query->where('type', $entity);
            }
            $existingRecords = $query->get()->keyBy($key);
        } else {
            $existingRecords = $modelOrRecords;
        }

        $activities = [];
        foreach ($payload as $newItem) {
            $id = (string)$newItem[$key];
            $oldItem = $existingRecords[$id] ?? null;

            if ($oldItem && isset($oldItem->type) && $oldItem->type !== ($newItem['type'] ?? $entity)) {
                $oldItem = null;
            }

            if (!$oldItem) {
                $action = 'created';
                $changes = null;
            } else {
                // Ανίχνευση Restore: Αν το παλιό αντικείμενο ήταν Soft Deleted
                $wasTrashed = method_exists($oldItem, 'trashed') && $oldItem->trashed();
                $action = $wasTrashed ? 'restored' : 'updated';

                $diff = [];
                foreach ($fieldsToCompare as $field) {
                    $oldVal = $oldItem->{$field} ?? null;
                    $newVal = $newItem[$field] ?? null;
// Ειδικά για το payload που είναι JSON string ή array
                    if ($field === 'payload') {
                        $oldJson = is_array($oldVal) ? json_encode($oldVal, JSON_UNESCAPED_UNICODE) : (string)$oldVal;
                        $newJson = is_array($newVal) ? json_encode($newVal, JSON_UNESCAPED_UNICODE) : (string)$newVal;
                        if (empty(json_decode($newJson, true))) {
                            continue;
                        }
                        if ($oldJson !== $newJson) {
                            $diff[$field] = ['before' => $oldJson, 'after' => $newJson];
                        }
                        continue;
                    }

                    // Σύγκριση Arrays (payload κλπ)
                    if (is_array($oldVal) || is_array($newVal)) {
                        if (json_encode($oldVal) !== json_encode($newVal)) {
                            $diff[$field] = ['before' => $oldVal, 'after' => $newVal];
                        }
                        continue;
                    }

                    // Σύγκριση Αριθμών
                    if (is_numeric($oldVal) && is_numeric($newVal)) {
                        if (abs((float)$oldVal - (float)$newVal) > 0.00001) {
                            $diff[$field] = ['before' => $oldVal, 'after' => $newVal];
                        }
                    }
                    // Σύγκριση Strings
                    elseif (trim((string)$oldVal) != trim((string)$newVal)) {
                        $diff[$field] = ['before' => $oldVal, 'after' => $newVal];
                    }
                }

                // ΚΡΙΣΙΜΟ: Αν δεν υπάρχει διαφορά στα πεδία ΚΑΙ δεν είναι Restore, τότε προσπέρασε
                if (empty($diff) && $action !== 'restored') {
                    continue;
                }

                // Αν είναι Restore και το diff είναι άδειο, βάλε ένα ενημερωτικό μήνυμα
                $changes = !empty($diff)
                    ? json_encode($diff, JSON_UNESCAPED_UNICODE)
                    : json_encode(['info' => 'Record restored without changes'], JSON_UNESCAPED_UNICODE);
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
            foreach (array_chunk($activities, 1000) as $chunk) {
                DB::table(config('bridge.tables.activities'))->insert($chunk);
            }
        }
    }

    public function cleanup(string $source, string $entity, array $allProcessedIds, int $batchId = 0): int
    {
        $config = config("bridge.sources.{$source}.entities.{$entity}");
        $mapper = app($config['mapper']);
        if (!$mapper->shouldCleanup() || empty($allProcessedIds)) return 0;

        $modelClass = $config['model'];
        $tableName = (new $modelClass)->getTable();
        $idField = $mapper->getIdentifierField();
        $uniqueKey = $mapper->getUniqueKey();      // ean
        $recordsToDelete = DB::table($tableName)
            ->where('source', $source)
            ->whereNull('deleted_at')
            ->whereNotIn($idField, $allProcessedIds)
            ->select($idField, $uniqueKey) // Παίρνουμε και τα δύο
            ->get();
        $count = $recordsToDelete->count();

        if ($count > 0) {
            // --- ΚΑΤΑΓΡΑΦΗ ΔΙΑΓΡΑΦΗΣ ΣΤΑ LOGS ---
            if ($batchId > 0 && $mapper->shouldLog()) {
                $deleteLogs = [];
                foreach ($recordsToDelete as $record) {
                    $deleteLogs[] = [
                        'batch_id'   => $batchId,
                        'source'     => $source,
                        'entity'     => $entity,
                        'identifier' => (string)($record->{$uniqueKey} ?? $record->{$idField}),
                        'action'     => 'deleted',
                        'changes'    => json_encode(['info' => 'Soft deleted during full sync cleanup'], JSON_UNESCAPED_UNICODE),
                        'created_at' => now()
                    ];
                }
                // Εισαγωγή των logs σε παρτίδες
                foreach (array_chunk($deleteLogs, 1000) as $chunk) {
                    DB::table(config('bridge.tables.activities'))->insert($chunk);
                }
            }
            $idsToDelete = $recordsToDelete->pluck($idField)->toArray();
            DB::table($tableName)
                ->where('source', $source)
                ->whereIn($idField, $idsToDelete)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        return $count;
    }
}
