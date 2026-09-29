<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestSyncError extends Model
{
    protected $fillable = [
        'report_sync_run_id',
        'salesforce_id',
        'phase',
        'error_code',
        'error_message',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
