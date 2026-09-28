<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_run_id',
        'subject_type',
        'subject_salesforce_id',
        'lead_salesforce_id',
        'interest_salesforce_id',
        'migration_origin_lead_id',
        'immediate_master_lead_id',
        'resolved_master_lead_id',
        'relationship_status',
        'master_status',
        'canonical_person_status',
        'current_lead_alignment',
        'lead_is_deleted',
        'interest_is_deleted',
        'has_conflict',
    ];

    protected $casts = [
        'lead_is_deleted' => 'boolean',
        'interest_is_deleted' => 'boolean',
        'has_conflict' => 'boolean',
    ];
}
