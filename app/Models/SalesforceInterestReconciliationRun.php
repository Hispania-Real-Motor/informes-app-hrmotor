<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestReconciliationRun extends Model
{
    protected $fillable = [
        'run_identifier',
        'reason',
        'status',
        'started_at',
        'completed_at',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];
}
