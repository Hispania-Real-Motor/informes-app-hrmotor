<?php

namespace Tests\Concerns;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Illuminate\Support\Str;

trait ProvidesRot4CampaignContext
{
    protected int $rot4InterestRunId;

    protected int $rot4DirectRunId;

    protected function setUpRot4CampaignContext(): void
    {
        $interestRun = ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'source_cutoff_at' => '2026-12-31 23:59:59',
            'started_at' => '2026-12-31 23:59:58',
            'completed_at' => '2026-12-31 23:59:59',
            'timezone' => 'UTC',
        ]);
        $this->rot4InterestRunId = (int) $interestRun->id;

        $directRun = SalesforceOpportunityInterestDirectRun::query()->create([
            'run_identifier' => (string) Str::uuid(),
            'reason' => 'Contexto de pruebas ROT-4',
            'status' => 'completed',
            'source_cutoff_at' => '2026-12-31 23:59:59',
            'started_at' => '2026-12-31 23:59:58',
            'completed_at' => '2026-12-31 23:59:59',
        ]);
        $this->rot4DirectRunId = (int) $directRun->id;

        // Adapter exclusively for pre-ROT-4 campaign fixtures. Production code
        // never reads Lead; new contractual tests create Interests directly.
        SalesforceLead::saved(function (SalesforceLead $lead): void {
            $this->mirrorLegacyCampaignFixtureAsInterest($lead);
        });
    }

    protected function mirrorLegacyCampaignFixtureAsInterest(SalesforceLead $lead): SalesforceInterest
    {
        $interest = SalesforceInterest::query()->updateOrCreate(
            ['salesforce_id' => (string) $lead->salesforce_id],
            [
                'lead_salesforce_id' => $lead->salesforce_id,
                'account_salesforce_id' => $lead->converted_account_id,
                'salesforce_created_at' => $lead->created_date,
                'salesforce_last_modified_at' => $lead->salesforce_last_modified_at ?? $lead->created_date,
                'owner_salesforce_id' => $lead->owner_id,
                'owner_name' => $lead->owner_name,
                'status' => $lead->status,
                'type' => $lead->record_type_name,
                'source' => $lead->source_origin_new ?: $lead->fuente_origen,
                'original_source' => $lead->fuente_origen,
                'medium' => $lead->medium_origin_new ?: $lead->medio_origen,
                'channel' => $lead->channel_new,
                'origin_delegation' => $lead->delegation_origin_new
                    ?: $lead->delegacion_encargada_bueno
                    ?: $lead->delegacion_encargada_text,
                'utm_campaign' => $lead->utm_campaign_new ?: $lead->campaign_acquired,
                'utm_id' => $lead->utm_id_new ?: $lead->acquired_id,
                'utm_source' => $lead->utm_source_new ?: $lead->acquired_source_legacy,
                'utm_medium' => $lead->utm_medium_new ?: $lead->acquired_medium_legacy,
                'utm_content' => $lead->utm_content_new ?: $lead->content_acquired,
                'sale_vehicle_salesforce_id' => $lead->vehicle_interest,
                'appraisal_vehicle_salesforce_id' => $lead->vehicle_interest,
                'inverse_opportunity_salesforce_id' => $lead->converted_opportunity_id,
                'synced_at' => $lead->synced_at,
                'is_deleted' => (bool) $lead->is_deleted,
            ],
        );

        if (filled($lead->converted_opportunity_id)) {
            SalesforceOpportunityInterestDirect::query()->updateOrCreate(
                [
                    'direct_run_id' => $this->rot4DirectRunId,
                    'opportunity_salesforce_id' => (string) $lead->converted_opportunity_id,
                ],
                [
                    'interest_salesforce_id' => (string) $lead->salesforce_id,
                    'reference_status' => 'valid',
                    'opportunity_is_deleted' => false,
                ],
            );
        }

        return $interest;
    }
}
