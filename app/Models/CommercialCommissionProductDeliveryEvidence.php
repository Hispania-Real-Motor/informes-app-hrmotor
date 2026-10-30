<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionProductDeliveryEvidence extends Model
{
    protected $fillable = [
        'salesforce_product_id',
        'state',
        'delivery_date',
        'plate',
        'salesforce_last_modified_at',
        'raw_payload',
        'synced_at',
        'sync_token',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'salesforce_last_modified_at' => 'datetime',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
