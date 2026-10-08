<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class ExecutiveInsightsTest extends TestCase
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

        SalesforceUser::create([
            'salesforce_id' => '005-commercial',
            'name' => 'Comercial',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR TORREJON',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_alerta_potenciales_sin_trabajar_y_bajada_de_conversion(): void
    {
        $this->lead('00Q-current', 'Potencial', '2026-05-10 10:00:00');
        $this->lead('00Q-previous', 'Convertido', '2026-04-01 10:00:00');

        $insights = collect($this->getJson('/informes/leads/data/summary')->json('executive_insights'));

        $this->assertTrue($insights->contains(fn (array $item) => str_contains($item['problema_detectado'], 'potenciales sin trabajar')));
        $this->assertTrue($insights->contains(fn (array $item) => str_contains($item['problema_detectado'], 'conversión baja') || str_contains($item['problema_detectado'], 'conversion baja')));
    }

    public function test_alerta_si_sube_el_descarte(): void
    {
        $this->lead('00Q-current', 'Descartado', '2026-05-10 10:00:00');
        $this->lead('00Q-previous', 'Convertido', '2026-04-01 10:00:00');

        $insights = collect($this->getJson('/informes/leads/data/summary')->json('executive_insights'));

        $this->assertTrue($insights->contains(fn (array $item) => str_contains($item['problema_detectado'], 'descarte sube')));
    }

    public function test_mensaje_neutro_si_no_hay_alertas(): void
    {
        $this->lead('00Q-current', 'Convertido', '2026-05-10 10:00:00');
        $this->lead('00Q-previous', 'Convertido', '2026-04-01 10:00:00');

        $insights = collect($this->getJson('/informes/leads/data/summary')->json('executive_insights'));

        $this->assertTrue($insights->contains(fn (array $item) => $item['titulo'] === 'Sin alertas relevantes'));
    }

    private function lead(string $id, string $status, string $createdDate): void
    {
        SalesforceLead::create([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => $createdDate,
            'status' => $status,
            'owner_id' => '005-commercial',
            'owner_name' => 'Comercial',
            'portal_text' => 'Web',
            'delegacion_encargada_text' => 'HR MOTOR TORREJON',
        ]);
    }
}
