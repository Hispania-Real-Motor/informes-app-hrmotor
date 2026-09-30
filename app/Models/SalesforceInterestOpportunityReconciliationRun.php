<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestOpportunityReconciliationRun extends Model
{
    protected $fillable = [
        'run_identifier',
        'reason',
        'status',
        'opportunity_dependency_run_id',
        'source_interest_sync_run_id',
        'source_interest_cutoff_at',
        'source_opportunity_sync_run_id',
        'source_opportunity_sync_status',
        'source_opportunity_period_end_at',
        'source_opportunity_completed_at',
        'source_opportunity_presence_run_id',
        'started_at',
        'completed_at',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'opportunity_dependency_run_id' => 'integer',
        'source_interest_sync_run_id' => 'integer',
        'source_interest_cutoff_at' => 'datetime',
        'source_opportunity_sync_run_id' => 'integer',
        'source_opportunity_period_end_at' => 'datetime',
        'source_opportunity_completed_at' => 'datetime',
        'source_opportunity_presence_run_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];
}
