<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestActivity extends Model
{
    protected $fillable = [
        'activity_run_id',
        'activity_kind',
        'activity_salesforce_id',
        'interest_salesforce_id',
        'who_salesforce_id',
        'relationship_status',
        'activity_is_deleted',
        'interest_is_deleted',
        'activity_date',
        'start_datetime',
        'salesforce_created_at',
        'salesforce_last_modified_at',
        'system_modstamp_at',
    ];

    protected $casts = [
        'activity_run_id' => 'integer',
        'activity_is_deleted' => 'boolean',
        'interest_is_deleted' => 'boolean',
        'activity_date' => 'date',
        'start_datetime' => 'datetime',
        'salesforce_created_at' => 'datetime',
        'salesforce_last_modified_at' => 'datetime',
        'system_modstamp_at' => 'datetime',
    ];
}
