<?php
namespace Nextpointer\Bridge\Contracts;

interface SyncProvider {
    public function setEntity(string $entity): self;
    public function fetchData(int $offset, int $limit, ?string $since = null): array;
}