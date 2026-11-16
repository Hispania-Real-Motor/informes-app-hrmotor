<?php

return [
    'delegation_mutex' => [
        'store' => env('STOCK_DELEGATION_LOCK_STORE'),
        'key' => env('STOCK_DELEGATION_LOCK_KEY', 'stock:delegation-writes'),
        // Longer than the two-hour daily-sync lock; expiry recovers abandoned locks.
        'ttl_seconds' => (int) env('STOCK_DELEGATION_LOCK_TTL_SECONDS', 14400),
        'wait_seconds' => (int) env('STOCK_DELEGATION_LOCK_WAIT_SECONDS', 5),
    ],
    'recommendation_weights' => [
        'model_sale' => 9.0,
        'brand_sale' => 2.5,
        'segment_sale' => 1.6,
        'fuel_sale' => 1.2,
        'price_band_sale' => 1.8,
        'fast_rotation' => 12.0,
        'free_capacity' => 1.2,
        'same_model_stock' => 15.0,
        'old_same_model_stock' => 10.0,
        'similar_stock' => 1.0,
        'no_history' => 30.0,
    ],
    'review_days' => 60,
    'priority_days' => 90,
    'recommendation_page_size' => 150,
    'directed_transfer_max_units' => 150,
    'vehicle_detail_limit' => 250,
    'excluded_destination_keys' => [
        'dos hermanas',
        'hr motor dos hermanas',
    ],
    'delegation_identity_repairs' => [
        [
            'canonical_name' => 'Rivas',
            'normalized_key' => 'rivas',
            'salesforce_id' => 'a007R000010UF74QAG',
            'source_keys' => ['hr motor rivas vaciamadrid'],
            'commercial_group' => 'Grupo Madrid',
            'zone' => 'Zona Sur y Centro',
        ],
        [
            'canonical_name' => 'Villarreal/Almassora',
            'normalized_key' => 'villarreal almassora',
            'salesforce_id' => 'a00Qx00000ZB5rRIAT',
            'source_keys' => ['hr motor villarreal'],
            'commercial_group' => 'Grupo Castellón',
            'zone' => 'Zona Mediterraneo',
        ],
    ],
    'expected_signed_stages' => [
        'Contrato',
        'Cerrada ganada',
    ],
    'excluded_catalog_terms' => [
        'prueba',
        'test',
        'formacion',
        'fuera de stock',
    ],
    'duplicate_model_priority' => 3,
    'clearly_better_score_delta' => 40,
    'ranking_limit' => 10,
];
