<?php

namespace App\Models;

use App\Services\Salesforce\SalesforceInterestFoundationResolver;
use Illuminate\Database\Eloquent\Model;

class SalesforceInterest extends Model
{
    protected $fillable = [
        'salesforce_id',
        'lead_salesforce_id',
        'account_salesforce_id',
        'migration_origin_lead_id',
        'salesforce_created_at',
        'salesforce_last_modified_at',
        'origin_created_at',
        'owner_salesforce_id',
        'owner_name',
        'status',
        'type',
        'source',
        'original_source',
        'medium',
        'channel',
        'origin_delegation',
        'utm_campaign',
        'utm_id',
        'utm_source',
        'utm_medium',
        'utm_content',
        'utm_term',
        'sale_vehicle_salesforce_id',
        'appraisal_vehicle_salesforce_id',
        'inverse_opportunity_salesforce_id',
        'raw_payload',
        'synced_at',
        'is_deleted',
        'salesforce_deleted_at',
        'deletion_detection_source',
    ];

    protected $casts = [
        'salesforce_created_at' => 'datetime',
        'salesforce_last_modified_at' => 'datetime',
        'origin_created_at' => 'datetime',
        'functional_created_at' => 'datetime',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
        'is_deleted' => 'boolean',
        'salesforce_deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (SalesforceInterest $interest): void {
            $resolver = new SalesforceInterestFoundationResolver;
            $materialized = $resolver->materialize($interest->getAttributes());

            $interest->forceFill([
                'canonical_person_type' => $materialized['canonical_person_type'],
                'canonical_person_salesforce_id' => $materialized['canonical_person_salesforce_id'],
                'functional_created_at' => $materialized['functional_created_at'],
            ]);
        });
    }
}
