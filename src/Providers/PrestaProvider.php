<?php

namespace App\Sync\Providers; // Το namespace που θέλεις να έχει στο app του χρήστη

use Nextpointer\Bridge\Contracts\SyncProvider;
use Nextpointer\Prestashop\Client\PrestashopClient;
use Illuminate\Support\Facades\Log;

class PrestaProvider implements SyncProvider
{
    protected string $entity;
    protected ?array $only = null;
    protected PrestashopClient $client;

    public function __construct(PrestashopClient $client)
    {
        $this->client = $client;
    }

    public function setEntity(string $entity): self
    {
        $this->entity = $entity;
        return $this;
    }

    public function setOnly(?array $only): self
    {
        $this->only = $only;
        return $this;
    }

    public function fetchData(int $offset, int $limit, ?string $since = null): array
    {
        $resource = $this->client->{$this->entity}();

        if (!empty($this->only)) {
            $resource->only(implode(',', $this->only));
        }

        if ($since) {
            $resource->since($since);
        }

        $response = $resource->offset($offset)
            ->limit($limit)
            ->get();

        Log::info("Sync Debug: Entity {$this->entity}", [
            'count' => count($response['data'] ?? []),
            'total' => $response['meta']['total'] ?? 0,
        ]);

        return [
            'data'  => $response['data'] ?? [],
            'total' => $response['meta']['total'] ?? 0,
        ];
    }
}
