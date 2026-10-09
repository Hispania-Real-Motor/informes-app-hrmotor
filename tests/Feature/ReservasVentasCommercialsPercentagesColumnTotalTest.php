<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservasVentasCommercialsPercentagesColumnTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
    }

    public function test_zonas_delegaciones_y_comerciales_separan_conversion_y_participacion(): void
    {
        $this->commercialMetrics('juan', 'Comercial Juan', 'Alcobendas', 40, 12, 8);
        $this->commercialMetrics('ana', 'Comercial Ana', 'Bilbao', 304, 108, 72);

        $response = $this->getJson('/informes/reservas-ventas/data/commercials?'.$this->reservasVentasQuery());

        $zone = collect($response->json('zones'))->firstWhere('zone', 'Zona Sur y Centro');
        $delegation = collect($response->json('delegations'))->firstWhere('commercial_delegation', 'Alcobendas');
        $commercial = collect($response->json('commercials'))->firstWhere('comercial', 'Comercial Juan');

        foreach ([$zone, $delegation, $commercial] as $row) {
            $this->assertSame(40, $row['reservas_vivas']);
            $this->assertSame(12, $row['oportunidades_caidas']);
            $this->assertSame(8, $row['cv_firmados']);
            $this->assertSame(66.67, (float) $row['reservas_vivas_pct']);
            $this->assertSame(20.0, (float) $row['oportunidades_caidas_pct']);
            $this->assertSame(13.33, (float) $row['cv_firmados_pct']);
            $this->assertSame(11.63, (float) $row['reservas_vivas_participation_pct']);
            $this->assertSame(10.0, (float) $row['oportunidades_caidas_participation_pct']);
            $this->assertSame(10.0, (float) $row['cv_firmados_participation_pct']);
        }
    }

    private function commercialMetrics(
        string $ownerKey,
        string $ownerName,
        string $ownerDelegation,
        int $reservas,
        int $caidas,
        int $cvFirmados
    ): void {
        $ownerId = "005-$ownerKey";

        $this->opportunities("006-$ownerKey-reserva", $reservas, [
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'owner_delegation' => $ownerDelegation,
            'stage_name' => 'Reserva',
            'reservation' => true,
        ]);
        $this->opportunities("006-$ownerKey-caida", $caidas, [
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'owner_delegation' => $ownerDelegation,
            'stage_name' => 'Cerrada Perdida',
        ]);
        $this->opportunities("006-$ownerKey-cv", $cvFirmados, [
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'owner_delegation' => $ownerDelegation,
            'stage_name' => 'Contrato',
            'reservation' => true,
            'cv_signed' => true,
        ]);
    }

    private function opportunities(string $prefix, int $count, array $attributes = []): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $id = "$prefix-$i";
            $row = array_merge([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-05-10 10:00:00',
                'stage_name' => 'Abierta',
                'record_type_name' => 'Venta',
                'owner_id' => '005-owner',
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => 'Web',
                'portal_original' => 'Web',
                'portal_resolution_source' => 'opportunity',
                'reservation' => false,
                'cv_signed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $attributes);
            $row['portal_original'] = $attributes['portal_resolved'] ?? $row['portal_original'];
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk !== []) {
                SalesforceOpportunity::query()->insert($chunk);
            }
        }
    }

    private function reservasVentasQuery(): string
    {
        return http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]);
    }
}
