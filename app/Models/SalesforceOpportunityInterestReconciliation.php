<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityInterestReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_run_id',
        'opportunity_salesforce_id',
        'direct_interest_salesforce_id',
        'inverse_interest_salesforce_ids',
        'inverse_reference_count',
        'relationship_status',
        'direct_reference_status',
        'direct_interest_presence_status',
        'direct_interest_is_deleted',
        'direct_opportunity_is_deleted',
        'inverse_opportunity_is_deleted',
        'inverse_opportunity_presence_status',
        'requires_review',
    ];

    protected $casts = [
        'reconciliation_run_id' => 'integer',
        'inverse_interest_salesforce_ids' => 'array',
        'inverse_reference_count' => 'integer',
        'direct_interest_is_deleted' => 'boolean',
        'direct_opportunity_is_deleted' => 'boolean',
        'inverse_opportunity_is_deleted' => 'boolean',
        'requires_review' => 'boolean',
    ];
}
