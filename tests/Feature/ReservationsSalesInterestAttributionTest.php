<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Services\Reports\ReservationsSales\OpportunityInterestAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationsSalesInterestAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_bidirectional_truth_table_in_bulk(): void
    {
        $context = $this->sourceContext();
        $both = $this->opportunity('006000000000000AAA');
        $directOnly = $this->opportunity('006000000000001AAA');
        $inverseOnly = $this->opportunity('006000000000002AAA');
        $contradiction = $this->opportunity('006000000000003AAA');
        $shared = $this->opportunity('006000000000004AAA');
        $invalid = $this->opportunity('006000000000005AAA');
        $none = $this->opportunity('006000000000006AAA');

        $this->direct($context['direct_run_id'], $both->salesforce_id, 'a01000000000000AAA');
        $this->direct($context['direct_run_id'], $directOnly->salesforce_id, 'a01000000000001AAA');
        $this->direct($context['direct_run_id'], $contradiction->salesforce_id, 'a01000000000003AAA');
        $this->direct($context['direct_run_id'], $shared->salesforce_id, 'a01000000000004AAA');
        $this->direct($context['direct_run_id'], $invalid->salesforce_id, null, 'invalid');

        $this->interest('a01000000000000AAA', $both->salesforce_id, 'Coches.net');
        $this->interest('a01000000000001AAA', null, 'Wallapop');
        $this->interest('a01000000000002AAA', $inverseOnly->salesforce_id, 'Web');
        $this->interest('a01000000000003AAA', null, 'Coches.net');
        $this->interest('a01000000000013AAA', $contradiction->salesforce_id, 'Wallapop');
        $this->interest('a01000000000004AAA', $shared->salesforce_id, 'Coches.net');
        $this->interest('a01000000000014AAA', $shared->salesforce_id, 'Wallapop');

        $resolved = app(OpportunityInterestAttributionService::class)->resolve(collect([
            $both, $directOnly, $inverseOnly, $contradiction, $shared, $invalid, $none,
        ]), $context);

        $this->assertSame('both_match', $resolved[$both->salesforce_id]['relationship_status']);
        $this->assertSame('direct_only', $resolved[$directOnly->salesforce_id]['relationship_status']);
        $this->assertSame('inverse_only', $resolved[$inverseOnly->salesforce_id]['relationship_status']);
        $this->assertSame('contradiction', $resolved[$contradiction->salesforce_id]['relationship_status']);
        $this->assertSame('inverse_shared', $resolved[$shared->salesforce_id]['relationship_status']);
        $this->assertSame('unresolved', $resolved[$invalid->salesforce_id]['relationship_status']);
        $this->assertSame('no_reference', $resolved[$none->salesforce_id]['relationship_status']);
        $this->assertSame('Coches.net', $resolved[$both->salesforce_id]['interest_source']);
        $this->assertNull($resolved[$directOnly->salesforce_id]['interest_source']);
    }

    public function test_portal_precedence_uses_interest_only_for_both_match(): void
    {
        $service = app(OpportunityInterestAttributionService::class);
        $opportunity = $this->opportunity('006000000000020AAA', [
            'portal_original' => '3CX',
            'opportunity_source_raw' => 'Web',
        ]);

        $interest = $service->effectivePortal($opportunity, [
            'relationship_status' => 'both_match',
            'interest_source' => 'Wallapop',
        ]);
        $unilateral = $service->effectivePortal($opportunity, [
            'relationship_status' => 'direct_only',
            'interest_source' => 'Coches.net',
        ]);
        $opportunity->portal_original = 'Meta';
        $conclusive = $service->effectivePortal($opportunity, [
            'relationship_status' => 'both_match',
            'interest_source' => 'Wallapop',
        ]);

        $this->assertSame(['portal' => 'Wallapop', 'source' => 'interest'], $interest);
        $this->assertSame(['portal' => 'Web', 'source' => 'opportunity_source'], $unilateral);
        $this->assertSame(['portal' => 'Meta', 'source' => 'opportunity'], $conclusive);
    }

    public function test_stale_direct_snapshot_degrades_without_interest_attribution(): void
    {
        $this->sourceContext('2026-10-09 08:00:00');
        $context = app(OpportunityInterestAttributionService::class)->capture(
            CarbonImmutable::parse('2026-10-09 09:00:00 UTC'),
        );

        $this->assertFalse($context['available']);
        $this->assertSame('direct_snapshot_stale', $context['reason']);
    }

    public function test_source_change_during_build_fails_safe(): void
    {
        $context = $this->sourceContext();
        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_interests',
            'source' => 'salesforce',
            'status' => 'running',
            'started_at' => '2026-10-09 10:30:00',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fuentes locales de atribución cambiaron');

        app(OpportunityInterestAttributionService::class)->validate($context);
    }

    public function test_inverse_identity_is_trimmed_and_deleted_interest_lifecycle_is_auditable(): void
    {
        $context = $this->sourceContext();
        $opportunity = $this->opportunity('006000000000030AAA');
        $this->direct($context['direct_run_id'], $opportunity->salesforce_id, 'a01000000000030AAA');
        $interest = $this->interest('a01000000000030AAA', ' 006000000000030AAA ', 'Wallapop');
        $interest->forceFill(['is_deleted' => true])->save();

        $resolution = app(OpportunityInterestAttributionService::class)
            ->resolve(collect([$opportunity]), $context)
            ->get($opportunity->salesforce_id);

        $this->assertSame('both_match', $resolution['relationship_status']);
        $this->assertSame(['a01000000000030AAA'], $resolution['inverse_interest_ids']);
        $this->assertSame('a01000000000030AAA', $resolution['interest_id']);
        $this->assertTrue($resolution['interest_is_deleted']);
    }

    /** @return array<string,mixed> */
    private function sourceContext(string $directCutoff = '2026-10-09 10:00:00'): array
    {
        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_interests',
            'source' => 'salesforce',
            'status' => 'completed',
            'source_cutoff_at' => '2026-10-09 09:00:00',
            'started_at' => '2026-10-09 09:00:00',
            'completed_at' => '2026-10-09 09:01:00',
        ]);
        SalesforceOpportunityInterestDirectRun::query()->create([
            'run_identifier' => fake()->uuid(),
            'reason' => 'Fixture contractual ROT-3',
            'status' => 'completed',
            'source_cutoff_at' => $directCutoff,
            'started_at' => $directCutoff,
            'completed_at' => $directCutoff,
            'stats' => [],
        ]);

        return app(OpportunityInterestAttributionService::class)->capture();
    }

    private function opportunity(string $id, array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $id,
            'created_date' => '2026-10-09 08:00:00',
            'record_type_name' => 'Venta',
            'portal_original' => '3CX',
        ], $overrides));
    }

    private function interest(string $id, ?string $opportunityId, string $source): SalesforceInterest
    {
        return SalesforceInterest::query()->create([
            'salesforce_id' => $id,
            'salesforce_created_at' => '2026-10-09 07:00:00',
            'salesforce_last_modified_at' => '2026-10-09 07:00:00',
            'type' => 'Venta',
            'source' => $source,
            'inverse_opportunity_salesforce_id' => $opportunityId,
            'is_deleted' => false,
        ]);
    }

    private function direct(int $runId, string $opportunityId, ?string $interestId, string $status = 'valid'): void
    {
        SalesforceOpportunityInterestDirect::query()->create([
            'direct_run_id' => $runId,
            'opportunity_salesforce_id' => $opportunityId,
            'interest_salesforce_id' => $interestId,
            'reference_status' => $status,
            'opportunity_is_deleted' => false,
        ]);
    }
}
