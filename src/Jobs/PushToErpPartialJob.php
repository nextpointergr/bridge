<?php

namespace Nextpointer\Bridge\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NextPointer\SoftOne\Services\ProductSyncService;

class PushToErpPartialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $modelId,
        protected array $changes
    ) {}

    public function handle(ProductSyncService $syncService): void
    {

        $product = Product::find($this->modelId);
        if (!$product) {
            Log::error("PushToErpPartialJob: Product with ID {$this->modelId} not found.");
            return;
        }

        $payload = array_merge($this->changes, [
            'id'     => $product->id,
            'erp_id' => $product->erp_id,
        ]);

        try {
            $newErpId = $syncService->sync($payload);
            if ($newErpId > 0 && $product->erp_id !== $newErpId) {
                $product->update(['erp_id' => $newErpId]);
                Log::info("Product {$product->id} linked to SoftOne ERP ID: {$newErpId}");
            }

        } catch (\Exception $e) {
            Log::error("Failed to sync product {$product->id} to SoftOne: " . $e->getMessage());
        }
    }
}
