<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadsDelegationsByLeadDelegationAggregationTest extends TestCase
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

    public function test_delegaciones_de_reparto_agrupa_solo_por_delegacion_del_lead(): void
    {
        $this->commercial('005-norte', 'Comercial Norte', 'HR MOTOR BILBAO');
        $this->commercial('005-sur', 'Comercial Sur', 'HR MOTOR TORREJON');

        $this->lead('00Q-sin-norte-1', 'Convertido', ['owner_id' => '005-norte', 'owner_name' => 'Comercial Norte']);
        $this->lead('00Q-sin-norte-2', 'Potencial', ['owner_id' => '005-norte', 'owner_name' => 'Comercial Norte']);
        $this->lead('00Q-sin-sur-1', 'Descartado', ['owner_id' => '005-sur', 'owner_name' => 'Comercial Sur']);
        $this->lead('00Q-sin-sur-2', 'Potencial', ['owner_id' => '005-sur', 'owner_name' => 'Comercial Sur']);

        $this->lead('00Q-madrid-norte', 'Convertido', [
            'owner_id' => '005-norte',
            'owner_name' => 'Comercial Norte',
            'delegacion_encargada_text' => 'Madrid',
        ]);
        $this->lead('00Q-madrid-sur', 'Potencial', [
            'owner_id' => '005-sur',
            'owner_name' => 'Comercial Sur',
            'delegacion_encargada_text' => 'HR MOTOR MADRID',
        ]);

        $rows = collect($this->getJson('/informes/leads/data/delegations')->json('items'));

        $this->assertSame(1, $rows->where('delegacion', 'Sin clasificar')->count());
        $this->assertSame(1, $rows->where('delegacion', 'Madrid General')->count());

        $unclassified = $rows->firstWhere('delegacion', 'Sin clasificar');
        $this->assertArrayNotHasKey('zone', $unclassified);
        $this->assertSame(4, $unclassified['leads_totales']);
        $this->assertSame(1, $unclassified['convertidos']);
        $this->assertSame(1, $unclassified['descartados']);
        $this->assertSame(2, $unclassified['potenciales']);
        $this->assertSame(2, $unclassified['potenciales_sin_trabajar']);
        $this->assertSame(2, $unclassified['gestionados']);
        $this->assertSame(25.0, (float) $unclassified['conversion_pct']);
        $this->assertSame(25.0, (float) $unclassified['descarte_pct']);
        $this->assertSame(50.0, (float) $unclassified['gestionados_pct']);

        $madrid = $rows->firstWhere('delegacion', 'Madrid General');
        $this->assertSame(2, $madrid['leads_totales']);
        $this->assertSame(1, $madrid['convertidos']);
        $this->assertSame(1, $madrid['potenciales']);
        $this->assertSame(50.0, (float) $madrid['conversion_pct']);
    }

    public function test_filtro_zona_se_mantiene_y_despues_agrupa_por_delegacion_del_lead(): void
    {
        $this->commercial('005-norte', 'Comercial Norte', 'HR MOTOR BILBAO');
        $this->commercial('005-sur', 'Comercial Sur', 'HR MOTOR TORREJON');

        $this->lead('00Q-sin-norte-1', 'Convertido', ['owner_id' => '005-norte', 'owner_name' => 'Comercial Norte']);
        $this->lead('00Q-sin-norte-2', 'Potencial', ['owner_id' => '005-norte', 'owner_name' => 'Comercial Norte']);
        $this->lead('00Q-sin-sur', 'Descartado', ['owner_id' => '005-sur', 'owner_name' => 'Comercial Sur']);
        $this->lead('00Q-madrid-norte', 'Potencial', [
            'owner_id' => '005-norte',
            'owner_name' => 'Comercial Norte',
            'delegacion_encargada_text' => 'Madrid',
        ]);

        $rows = collect($this->getJson('/informes/leads/data/delegations?zone=Zona%20Norte')->json('items'));

        $this->assertSame(2, $rows->firstWhere('delegacion', 'Sin clasificar')['leads_totales']);
        $this->assertSame(1, $rows->firstWhere('delegacion', 'Madrid General')['leads_totales']);
        $this->assertSame(1, $rows->where('delegacion', 'Sin clasificar')->count());
    }

    private function commercial(string $id, string $name, string $delegation): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => 'Compra/Venta',
            'user_delegation' => $delegation,
            'is_active' => true,
        ]);
    }

    private function lead(string $id, string $status, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-10 10:00:00',
            'status' => $status,
            'owner_id' => '005-norte',
            'owner_name' => 'Comercial Norte',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
