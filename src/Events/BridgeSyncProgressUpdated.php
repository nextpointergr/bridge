<?php

namespace Nextpointer\Bridge\Events; // <--- Πρέπει να είναι ακριβώς έτσι

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BridgeSyncProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $source,
        public string $entity,
        public int $batchId,
        public int $processed,
        public int $total,
        public bool $isCompleted = false,
        public string $phase = 'fetching',
        public $message = ''
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('sync');
    }

    public function broadcastAs(): string
    {
        return 'sync.progress';
    }
}
