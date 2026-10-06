<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityInterestDirect extends Model
{
    protected $fillable = [
        'direct_run_id',
        'opportunity_salesforce_id',
        'interest_salesforce_id',
        'reference_status',
        'opportunity_is_deleted',
        'salesforce_last_modified_at',
        'system_modstamp_at',
    ];

    protected $casts = [
        'direct_run_id' => 'integer',
        'opportunity_is_deleted' => 'boolean',
        'salesforce_last_modified_at' => 'datetime',
        'system_modstamp_at' => 'datetime',
    ];
}
