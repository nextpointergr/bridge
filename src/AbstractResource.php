<?php

namespace Nextpointer\Bridge;

use Nextpointer\Bridge\Contracts\BridgeResource;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractResource implements BridgeResource
{
    /**
     * Default Ρυθμίσεις.
     * Οποιοδήποτε resource κάνει extends αυτό το class θα έχει αυτές τις τιμές
     * εκτός αν τις κάνει override.
     */
    public function useStaging(): bool { return false; }
    public function syncByDate(): bool { return true; }
    public function getBatchLimit(): int { return 500; }
    public function shouldLog(): bool { return true; }
    public function shouldCleanup(): bool { return false; }
    public function forceFullSync(): bool { return false; }
    public function only(): ?array { return null; }



    /**
     * Default Hooks (Κενά).
     */
    public function beforeSync(array &$mapped): void {}
    public function afterSync(array $originalRow, Model $modelInstance): void {}
    public function validate(array $mapped): ?string { return null; }

    /**
     * Αυτά ΠΡΕΠΕΙ να ορίζονται σε κάθε Resource (Product, Brand κλπ).
     */
    abstract public function getModel(): string;
    abstract public function getUniqueKey(): string;
    abstract public function getIdentifierField(): string;
    abstract public function getUpdateColumns(): array;
    abstract public function map(array $row): array;
    abstract public function getHashFields(): array;
    public function getDbChunkSize(): int
    {
        return 500; // Default τιμή
    }

    public function getLiveColumns(): array
    {
        return array_unique(array_merge($this->getUpdateColumns(), [
            $this->getIdentifierField(),
            'source',
            'hash',
            'created_at',
            'updated_at',
            'deleted_at'
        ]));
    }
}
