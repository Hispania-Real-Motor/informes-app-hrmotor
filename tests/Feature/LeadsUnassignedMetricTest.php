<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesLeadDashboardRows;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadsUnassignedMetricTest extends TestCase
{
    use CreatesLeadDashboardRows;
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_technical_owner_ids_and_names_count_as_unassigned_leads(): void
    {
        foreach ([
            ['00Q-id-1', '0052X00000AP4U5QAL', 'Otro'],
            ['00Q-id-2', '0057R00000AKkz0QAD', 'Otro'],
            ['00Q-id-3', '0057R00000CQGZaQAP', 'Otro'],
            ['00Q-name-1', '005-other-1', 'API User'],
            ['00Q-name-2', '005-other-2', 'Carlos Torres'],
        ] as [$id, $ownerId, $ownerName]) {
            $this->leadRow($id, [
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
            ]);
        }

        $this->leadRow('00Q-real', [
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
        ]);

        $this->getJson('/informes/leads/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.leads_unassigned', 5)
            ->assertJsonPath('kpis.potenciales', 6);
    }
}
