<?php

namespace Nextpointer\Bridge\Events; // <--- Πρέπει να είναι ακριβώς έτσι

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BridgeSyncProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $source,
        public string $entity,
        public int $batchId,
        public int $processed,
        public int $total,
        public bool $isCompleted = false
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("bridge-sync.{$this->batchId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sync.progress';
    }
}
