<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityInterestReconciliationRun extends Model
{
    protected $fillable = [
        'run_identifier',
        'reason',
        'status',
        'direct_run_id',
        'direct_cutoff_at',
        'inverse_run_id',
        'inverse_interest_sync_run_id',
        'inverse_interest_cutoff_at',
        'started_at',
        'completed_at',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'direct_run_id' => 'integer',
        'direct_cutoff_at' => 'datetime',
        'inverse_run_id' => 'integer',
        'inverse_interest_sync_run_id' => 'integer',
        'inverse_interest_cutoff_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];
}
