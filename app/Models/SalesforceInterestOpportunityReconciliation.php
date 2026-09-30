<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceInterestOpportunityReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_run_id',
        'interest_salesforce_id',
        'opportunity_salesforce_id',
        'relationship_status',
        'opportunity_presence_status',
        'opportunity_evidence_source',
        'interest_is_deleted',
        'opportunity_is_deleted',
        'opportunity_salesforce_deleted_at',
        'opportunity_deletion_detection_source',
        'inverse_reference_count',
        'requires_review',
    ];

    protected $casts = [
        'reconciliation_run_id' => 'integer',
        'interest_is_deleted' => 'boolean',
        'opportunity_is_deleted' => 'boolean',
        'opportunity_salesforce_deleted_at' => 'datetime',
        'inverse_reference_count' => 'integer',
        'requires_review' => 'boolean',
    ];
}
