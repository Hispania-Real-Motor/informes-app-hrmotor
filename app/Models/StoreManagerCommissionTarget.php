<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreManagerCommissionTarget extends Model
{
    protected $fillable = [
        'month', 'zone', 'store_key', 'store_name', 'delivery_target',
        'purchase_target', 'source_sha256', 'source_label', 'source_type',
        'source_month', 'imported_by', 'modified_by',
    ];

    protected $casts = [
        'delivery_target' => 'integer',
        'purchase_target' => 'integer',
    ];
}
