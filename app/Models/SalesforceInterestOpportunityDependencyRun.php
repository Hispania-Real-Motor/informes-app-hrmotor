<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestOpportunityDependencyRun extends Model
{
    protected $fillable = [
        'run_identifier',
        'reason',
        'status',
        'source_interest_sync_run_id',
        'source_interest_cutoff_at',
        'started_at',
        'completed_at',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'source_interest_sync_run_id' => 'integer',
        'source_interest_cutoff_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];
}
