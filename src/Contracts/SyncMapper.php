<?php
namespace Nextpointer\Bridge\Contracts;
use Illuminate\Database\Eloquent\Model;

interface SyncMapper {
    public function getModel(): string;
    public function getUniqueKey(): string;
    public function getUpdateColumns(): array;
    public function map(array $row): array;
    public function validate(array $mapped): ?string;
    public function beforeSync(array &$mapped): void;
    public function afterSync(array $originalRow, Model $modelInstance): void;
    public function getHashFields(): array;
}