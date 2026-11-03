<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionDeliveryReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_key',
        'period_start',
        'period_end',
        'opportunity_salesforce_id',
        'product_salesforce_id',
        'plate',
        'opportunity_cv_signed_date',
        'product_delivery_date',
        'product_state',
        'discrepancy_type',
        'reconciled_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'opportunity_cv_signed_date' => 'date',
        'product_delivery_date' => 'date',
        'reconciled_at' => 'datetime',
    ];
}
