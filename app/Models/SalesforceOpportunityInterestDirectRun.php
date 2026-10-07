<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityInterestDirectRun extends Model
{
    protected $fillable = [
        'run_identifier',
        'reason',
        'status',
        'source_cutoff_at',
        'started_at',
        'completed_at',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'source_cutoff_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];
}
