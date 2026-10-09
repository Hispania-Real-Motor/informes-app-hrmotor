<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProvidesRot4CampaignContext;
use Tests\TestCase;

class CampaignAttributionFieldMigrationTest extends TestCase
{
    use ProvidesRot4CampaignContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRot4CampaignContext();
    }

    public function test_interest_utm_dimensions_drive_matching_and_expose_the_real_source_field(): void
    {
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000001', 'utm_campaign' => 'New campaign',
            'utm_id' => 'new-id', 'utm_source' => 'google', 'utm_medium' => 'cpc',
            'utm_content' => 'new-content',
        ]);
        $this->createMetric('metric-new-campaign', 'campaign-new', 'New campaign', 'new-id');

        $stats = $this->build();

        $this->assertSame('salesforce_interests', $stats['interest_source_table']);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => null, 'interest_id' => 'a0I000000000000001',
            'campaign_acquired' => 'New campaign', 'acquired_id' => 'new-id',
            'matched_source_field' => 'salesforce_interests.utm_id',
        ]);
    }

    public function test_interest_without_utm_is_materialized_as_salesforce_origin_without_lead_fallback(): void
    {
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000002', 'source' => 'Web',
            'original_source' => 'Formulario corporativo', 'medium' => 'Organic', 'channel' => 'Digital',
        ]);

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => null, 'interest_id' => 'a0I000000000000002',
            'campaign_source_type' => 'salesforce_origin',
            'matched_source_field' => 'salesforce_interests.source', 'matched_source_value' => 'Web',
        ]);
    }

    public function test_deleted_interest_is_excluded_and_type_selects_the_correct_vehicle(): void
    {
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000003', 'type' => 'Tasación', 'utm_campaign' => 'Tasador',
            'sale_vehicle_salesforce_id' => 'a1V000000000000001',
            'appraisal_vehicle_salesforce_id' => 'a1V000000000000002',
        ]);
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000004', 'type' => 'Venta',
            'utm_campaign' => 'Venta activa', 'is_deleted' => true,
        ]);

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => 'a0I000000000000003', 'interest_type' => 'tasacion',
            'vehicle_interest' => 'a1V000000000000002',
        ]);
        $this->assertDatabaseMissing('campaign_attributions', ['interest_id' => 'a0I000000000000004']);
    }

    public function test_only_both_match_is_used_as_exact_opportunity_relationship(): void
    {
        $interest = $this->createInterest([
            'salesforce_id' => 'a0I000000000000005', 'utm_campaign' => 'Venta exacta',
            'inverse_opportunity_salesforce_id' => '006000000000000001',
        ]);
        $this->createOpportunity('006000000000000001');
        SalesforceOpportunityInterestDirect::query()->create([
            'direct_run_id' => $this->rot4DirectRunId,
            'opportunity_salesforce_id' => '006000000000000001',
            'interest_salesforce_id' => $interest->salesforce_id,
            'reference_status' => 'valid', 'opportunity_is_deleted' => false,
        ]);

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => $interest->salesforce_id, 'opportunity_id' => '006000000000000001',
            'opportunity_attribution_method' => 'both_match',
            'opportunity_relationship_status' => 'both_match',
        ]);
    }

    public function test_account_first_touch_is_separate_lower_confidence_and_never_uses_pii(): void
    {
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000006',
            'account_salesforce_id' => '001000000000000001', 'utm_campaign' => 'First touch',
        ]);
        $this->createOpportunity('006000000000000002', '001000000000000001');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => 'a0I000000000000006', 'opportunity_id' => '006000000000000002',
            'opportunity_attribution_method' => 'account_first_touch',
            'opportunity_attribution_confidence' => 'medium',
            'opportunity_relationship_status' => 'account_first_touch',
        ]);
        $sql = strtolower(implode(' ', $queries));
        $this->assertStringNotContainsString('email', $sql);
        $this->assertStringNotContainsString('phone', $sql);
    }

    public function test_meta_facebook_origin_is_not_inferred_as_instant_forms_without_explicit_campaign(): void
    {
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000007', 'source' => 'Facebook', 'medium' => 'Meta',
        ]);

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => 'a0I000000000000007', 'campaign_source_type' => 'salesforce_origin',
        ]);
        $this->assertDatabaseMissing('campaign_attributions', [
            'interest_id' => 'a0I000000000000007', 'campaign_name' => 'Formulario directo Meta',
        ]);
    }

    public function test_madrid_period_is_converted_to_utc_across_dst_boundary(): void
    {
        $this->createInterest([
            'salesforce_id' => 'a0I000000000000008',
            'salesforce_created_at' => '2026-03-28 23:30:00',
            'salesforce_last_modified_at' => '2026-03-28 23:30:00',
            'utm_campaign' => 'DST campaign',
        ]);

        $this->build('2026-03-29', '2026-03-30');

        $this->assertDatabaseHas('campaign_attributions', ['interest_id' => 'a0I000000000000008']);
    }

    public function test_source_change_during_build_rolls_back_the_period(): void
    {
        $this->createInterest(['salesforce_id' => 'a0I000000000000009', 'utm_campaign' => 'Unstable']);
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into "campaign_attributions"')) {
                ReportSyncRun::query()->create([
                    'dataset' => 'salesforce_interests', 'source' => 'salesforce',
                    'status' => 'running', 'started_at' => now(), 'timezone' => 'UTC',
                ]);
            }
        });

        try {
            $this->build();
            $this->fail('La mutación F2 debía invalidar la publicación.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cambió', $exception->getMessage());
        }

        $this->assertDatabaseMissing('campaign_attributions', ['interest_id' => 'a0I000000000000009']);
    }

    public function test_interest_queries_are_chunk_bounded_and_never_use_lead_or_pii_lookups(): void
    {
        foreach (range(1, 201) as $sequence) {
            $this->createInterest([
                'salesforce_id' => 'a0I'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT),
                'utm_campaign' => 'Bulk campaign',
            ]);
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->build();

        $interestSelects = collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "salesforce_interests"')
                || str_contains($sql, 'from `salesforce_interests`'),
        );
        $runtimeSql = implode(' ', $queries);

        $this->assertDatabaseCount('campaign_attributions', 201);
        $this->assertLessThanOrEqual(5, $interestSelects->count());
        $this->assertStringNotContainsString('salesforce_leads', $runtimeSql);
        $this->assertStringNotContainsString('campaign_salesforce_leads', $runtimeSql);
        $this->assertStringNotContainsString('email', $runtimeSql);
        $this->assertStringNotContainsString('phone', $runtimeSql);
        $this->assertStringNotContainsString('trim(', $runtimeSql);
    }

    public function test_account_first_touch_resolves_opportunities_in_bulk_without_per_opportunity_writes(): void
    {
        foreach (range(1, 25) as $sequence) {
            $suffix = str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
            $accountId = '001'.$suffix;
            $this->createInterest([
                'salesforce_id' => 'a0I'.$suffix,
                'account_salesforce_id' => $accountId,
                'utm_campaign' => 'Bulk first touch',
            ]);
            $this->createOpportunity('006'.$suffix, $accountId);
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->build();

        $interestSelects = collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "salesforce_interests"')
                || str_contains($sql, 'from `salesforce_interests`'),
        );
        $unresolvedWrites = collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'campaign_unresolved_attributions')
                && (str_starts_with($sql, 'delete') || str_starts_with($sql, 'insert') || str_starts_with($sql, 'update')),
        );

        $this->assertDatabaseCount('campaign_attributions', 25);
        $this->assertDatabaseCount('campaign_lead_attributions', 25);
        $this->assertLessThanOrEqual(4, $interestSelects->count());
        $this->assertCount(1, $unresolvedWrites);
    }

    private function createInterest(array $attributes): SalesforceInterest
    {
        return SalesforceInterest::query()->create(array_merge([
            'salesforce_id' => 'a0I000000000000000',
            'salesforce_created_at' => '2026-05-10 10:00:00',
            'salesforce_last_modified_at' => '2026-05-10 10:00:00',
            'status' => 'Nuevo', 'type' => 'Venta', 'is_deleted' => false,
            'synced_at' => '2026-05-10 10:05:00',
        ], $attributes));
    }

    private function createMetric(string $key, string $campaignId, string $name, ?string $adId = null): void
    {
        DB::table('campaign_platform_daily_metrics')->insert([
            'unique_key' => $key, 'platform' => 'google_ads', 'metric_date' => '2026-05-10',
            'account_id' => 'account', 'campaign_id' => $campaignId, 'campaign_name' => $name,
            'ad_id' => $adId, 'spend' => 10, 'impressions' => 100, 'clicks' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createOpportunity(string $salesforceId, ?string $accountId = null): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create([
            'salesforce_id' => $salesforceId, 'account_id' => $accountId,
            'name' => 'Opportunity fixture', 'created_date' => '2026-05-11 10:00:00',
            'stage_name' => 'Abierta', 'record_type_name' => 'Venta', 'is_deleted' => false,
        ]);
    }

    private function build(string $from = '2026-05-01', string $to = '2026-06-01'): array
    {
        return app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse($from, 'Europe/Madrid'),
            CarbonImmutable::parse($to, 'Europe/Madrid'),
        );
    }
}
