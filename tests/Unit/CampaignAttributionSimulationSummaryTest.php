<?php

namespace Tests\Unit;

use App\Services\Campaigns\CampaignAttributionBuilderService;
use ReflectionMethod;
use Tests\TestCase;

class CampaignAttributionSimulationSummaryTest extends TestCase
{
    public function test_dry_run_reconciliation_distinguishes_unattributed_removed_and_existing_transitions(): void
    {
        $currentRows = [
            'same' => $this->row('same', 'campaign-a', method: 'campaign_id'),
            'became-unattributed' => $this->row('became-unattributed', 'campaign-a'),
            'still-unattributed' => $this->row('still-unattributed'),
            'campaign-changed' => $this->row('campaign-changed', 'campaign-a'),
            'method-changed' => $this->row('method-changed', 'campaign-a', method: 'campaign_name'),
            'new-ambiguous' => $this->row('new-ambiguous', 'campaign-a'),
            'ambiguity-resolved' => $this->row('ambiguity-resolved', 'campaign-a', ambiguous: true),
            'removed' => $this->row('removed', 'campaign-a'),
        ];
        $simulatedRows = [
            $this->row('same', 'campaign-a', method: 'campaign_id'),
            $this->row('became-unattributed'),
            $this->row('still-unattributed'),
            $this->row('new-attribution', 'campaign-b'),
            $this->row('campaign-changed', 'campaign-c'),
            $this->row('method-changed', 'campaign-a', method: 'campaign_id'),
            $this->row('new-ambiguous', 'campaign-a', ambiguous: true),
            $this->row('ambiguity-resolved', 'campaign-a'),
        ];
        $leads = collect($simulatedRows)->map(fn (array $row): object => (object) [
            'salesforce_id' => $row['interest_id'],
            'record_type_name' => 'Venta',
        ]);

        $method = new ReflectionMethod(CampaignAttributionBuilderService::class, 'simulationSummary');
        $summary = $method->invoke(
            app(CampaignAttributionBuilderService::class),
            $leads,
            $currentRows,
            $simulatedRows,
        );
        $changes = $summary['changes'];

        $this->assertSame(1, $changes['became_unattributed']['count']);
        $this->assertSame(['became-unattributed'], $changes['became_unattributed']['sample_ids']);
        $this->assertNotContains('still-unattributed', $changes['became_unattributed']['sample_ids']);
        $this->assertSame(1, $changes['removed_attribution']['count']);
        $this->assertSame(['removed'], $changes['removed_attribution']['sample_ids']);
        $this->assertSame(['new-attribution'], $changes['new_attribution']['sample_ids']);
        $this->assertContains('same', $changes['same_campaign_same_method']['sample_ids']);
        $this->assertContains('campaign-changed', $changes['campaign_identity_changed']['sample_ids']);
        $this->assertSame(['method-changed'], $changes['attribution_method_changed']['sample_ids']);
        $this->assertSame(['new-ambiguous'], $changes['new_ambiguous']['sample_ids']);
        $this->assertSame(['ambiguity-resolved'], $changes['ambiguity_resolved']['sample_ids']);
    }

    /** @return array<string, mixed> */
    private function row(
        string $leadId,
        ?string $campaignId = null,
        string $method = 'campaign_name',
        bool $ambiguous = false,
    ): array {
        return [
            'interest_id' => $leadId,
            'platform' => 'meta',
            'campaign_id' => $campaignId,
            'campaign_name' => null,
            'attribution_method' => $method,
            'is_ambiguous' => $ambiguous,
            'campaign_source_type' => 'platform',
        ];
    }
}
