<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreManagerCommissionTargetEvent extends Model
{
    protected $fillable = [
        'target_id',
        'month',
        'store_key',
        'operation_id',
        'event_type',
        'source_month',
        'report_user_id',
        'before_delivery_target',
        'before_purchase_target',
        'after_delivery_target',
        'after_purchase_target',
        'metadata',
    ];

    protected $casts = [
        'before_delivery_target' => 'integer',
        'before_purchase_target' => 'integer',
        'after_delivery_target' => 'integer',
        'after_purchase_target' => 'integer',
        'metadata' => 'array',
    ];
}
