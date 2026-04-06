<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
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
    */
    'sources' => [
        'prestashop' => [
            'provider' => \App\Sync\Providers\PrestaProvider::class,
            'entities' => [
                'products' => [
                    'model'  => \App\Models\Product::class,
                    'mapper' => \App\Sync\Mappers\ProductMapper::class,
                ],
            ],
        ],
        // Πρόσθεσε εδώ pylon, woo, κτλ.
    ],
];