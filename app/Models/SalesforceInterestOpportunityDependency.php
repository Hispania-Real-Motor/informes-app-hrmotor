<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestOpportunityDependency extends Model
{
    protected $fillable = [
        'dependency_run_id',
        'salesforce_id',
        'presence_status',
        'is_deleted',
        'salesforce_last_modified_at',
        'system_modstamp_at',
    ];

    protected $casts = [
        'dependency_run_id' => 'integer',
        'is_deleted' => 'boolean',
        'salesforce_last_modified_at' => 'datetime',
        'system_modstamp_at' => 'datetime',
    ];
}
