<?php

namespace Nextpointer\Bridge\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushToShopPartialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $productId,
        protected array $changes
    ) {}

    public function handle(): void
    {
        $product = Product::find($this->productId);
        if (!$product || !$product->prestashop_id) return;

        $payload = [
            'id' => $product->prestashop_id
        ];

        // Mapping Βασικών Πεδίων
        foreach ($this->changes as $field => $value) {
            match ($field) {
                'ean'             => $payload['ean'] = $value,
                'sku'             => $payload['reference'] = $value,
                'name'            => $payload['name'] = $value,
                'wholesale_price' => $payload['price'] = (float)$value, // Εδώ στέλνουμε την τιμή λιανικής αν θες
                'active'          => $payload['active'] = (int)$value,
                'category_id'     =>  $payload['id_category_default'],
                'image_url'       => $payload['image_url'] = $value,
                'quantity'        => $payload['quantity'] = (int)$value,
                default           => null
            };
        }


        // --- ΠΡΟΣΘΗΚΗ EXTRA ΠΕΔΙΩΝ (Αν υπάρχουν στο Model) ---
        // Αν το Laravel Model σου έχει αυτά τα πεδία, τα προσθέτουμε στο payload
        if (isset($product->description))       $payload['description'] = $product->description;
        if (isset($product->description_short)) $payload['description_short'] = $product->description_short;
        if (isset($product->mpn))               $payload['mpn'] = $product->mpn;
        if (isset($product->brand_name))        $payload['brand'] = $product->brand_name;

        // Μετατροπή των Features από JSON (αν τα αποθηκεύεις έτσι στο Laravel)
        if (!empty($product->features)) {
            $payload['features'] = is_array($product->features)
                ? $product->features
                : json_decode($product->features, true);
        }

        // Gallery Images
        if (!empty($product->gallery_images)) {
            $payload['images'] = is_array($product->gallery_images)
                ? $product->gallery_images
                : json_decode($product->gallery_images, true);
        }

        // Αποστολή μέσω της Bridge
        RemotePushJob::dispatch(
            $product,
            $payload,
            'toShop',
            $product->prestashop_id
        );
    }
}
