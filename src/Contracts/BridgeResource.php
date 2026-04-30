<?php
namespace Nextpointer\Bridge\Contracts;
use Illuminate\Database\Eloquent\Model;

interface BridgeResource {
    public function getModel(): string;
    public function getUniqueKey(): string;
    public function getUpdateColumns(): array;
    public function map(array $row): array;
    public function validate(array $mapped): ?string;
    public function beforeSync(array &$mapped): void;
    public function afterSync(array $originalRow, Model $modelInstance): void;
    public function getHashFields(): array;
    public function useStaging(): bool;
    public function syncByDate(): bool;
    public function getBatchLimit(): int;
    public function shouldLog(): bool;
    public function shouldCleanup(): bool;
    public function getIdentifierField();
    public function getDbChunkSize(): int; // <--- Πρόσθεσε αυτό για τη βάση
    public function forceFullSync(): bool;
    public function only(): ?array; // <--- Πρόσθεσε αυτό
    public function getLiveColumns(): array;
}
