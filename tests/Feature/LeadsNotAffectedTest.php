<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadsNotAffectedTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_endpoints_principales_de_leads_siguen_respondiendo(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-lead-ok',
            'name' => 'Lead OK',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'owner_id' => '005-owner',
            'owner_name' => 'Owner',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'delegacion_encargada_text' => 'Madrid',
        ]);

        $this->get('/informes/leads')->assertOk();
        $this->getJson('/informes/leads/data/summary')->assertOk()->assertJsonPath('kpis.leads_totales', 1);
        $this->getJson('/informes/leads/data/commercials')->assertOk();
        $this->getJson('/informes/leads/data/delegations')->assertOk();
        $this->getJson('/informes/leads/data/portals')->assertOk();
    }
}
