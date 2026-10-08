<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class ExposureFilterTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_legacy_exposition_filter_is_not_exposed_or_applied_to_interests(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-exp',
            'name' => 'Exposicion',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'portal_text' => 'Exposición',
        ]);
        SalesforceLead::create([
            'salesforce_id' => '00Q-web',
            'name' => 'Web',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'portal_text' => 'Web',
        ]);

        $included = $this->getJson('/informes/leads/data/summary?exposition_mode=with');
        $excluded = $this->getJson('/informes/leads/data/summary?exposition_mode=without');

        $this->assertSame(2, $included->json('kpis.leads_totales'));
        $this->assertSame(2, $excluded->json('kpis.leads_totales'));
        $this->assertStringNotContainsString('expositionMode', file_get_contents(resource_path('views/reports/leads/index.blade.php')));
        $this->assertStringNotContainsString('only', file_get_contents(resource_path('js/reports/leads-dashboard.js')));
    }
}
