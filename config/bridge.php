<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    | Εδώ ορίζονται τα ονόματα των πινάκων που χρησιμοποιεί το πακέτο.
    */
    'tables' => [
        'batches'    => 'sync_batches',
        'exceptions' => 'sync_exceptions',
        'activities' => 'sync_activities',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Sources
    |--------------------------------------------------------------------------
    | Εδώ δηλώνεις τις πηγές δεδομένων (π.χ. ERP, E-shop) και τα entities τους.
    */
    'sources' => [

        // Demo Source: Χρησιμοποιείται για testing ή ως template
        'demo' => [
            'provider' => \App\Sync\Providers\DemoProvider::class,
            'entities' => [
                'items' => [
                    'model'  => \App\Models\Product::class, // Το Laravel model
                    'resource' => \App\Sync\Resources\DemoResource::class, // Το Resource που έκανες publish
                ],
            ],
        ],

        'prestashop' => [
            'provider' => \App\Sync\Providers\PrestaProvider::class,
            'entities' => [
                'products' => [
                    'model'  => \App\Models\Product::class,
                    'resource' => \App\Sync\Resources\ProductMapper::class,
                ],
            ],
        ],

        // Εδώ μπορείς να προσθέσεις pylon, woo, κτλ.
    ],
];
