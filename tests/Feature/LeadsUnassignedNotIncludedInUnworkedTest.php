<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesLeadDashboardRows;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadsUnassignedNotIncludedInUnworkedTest extends TestCase
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

    public function test_technical_potential_without_activity_is_unassigned_but_not_unworked(): void
    {
        $this->leadRow('00Q-tech', [
            'owner_id' => '0052X00000AP4U5QAL',
            'owner_name' => 'Admin adesso',
        ]);
        $this->leadRow('00Q-real', [
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
        ]);

        $this->getJson('/informes/leads/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.leads_unassigned', 1)
            ->assertJsonPath('kpis.potenciales_sin_trabajar', 1);
    }
}
