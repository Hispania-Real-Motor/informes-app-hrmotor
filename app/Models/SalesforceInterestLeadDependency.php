<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestLeadDependency extends Model
{
    protected $fillable = [
        'dependency_run_id',
        'salesforce_id',
        'presence_status',
        'is_origin_reference',
        'is_master_dependency',
        'is_deleted',
        'salesforce_master_record_id',
        'salesforce_last_modified_at',
        'system_modstamp_at',
    ];

    protected $casts = [
        'is_origin_reference' => 'boolean',
        'is_master_dependency' => 'boolean',
        'is_deleted' => 'boolean',
        'salesforce_last_modified_at' => 'datetime',
        'system_modstamp_at' => 'datetime',
    ];
}
