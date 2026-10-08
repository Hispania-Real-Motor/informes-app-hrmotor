<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class SummaryEndpointAiInsightsTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.enabled', false);
        config()->set('openai.api_key', null);
        Cache::clear();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_summary_devuelve_insights_ejecutivos_y_origen(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q1',
            'name' => 'Lead',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'owner_id' => '005-owner',
            'portal_text' => 'Web',
        ]);

        $response = $this->getJson('/informes/leads/data/summary');

        $response->assertOk();
        $response->assertJsonStructure([
            'executive_insights' => [['titulo', 'problema_detectado', 'evidencia', 'recomendacion', 'prioridad']],
            'executive_insights_source',
        ]);
        $this->assertSame('fallback', $response->json('executive_insights_source'));
    }
}
