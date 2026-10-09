<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservasVentasPortalsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_agrupa_por_portal_normalizado_y_no_muestra_basura(): void
    {
        foreach ([
            ['006-1', 'COCHES.NET'],
            ['006-2', 'Coches Net Málaga'],
            ['006-3', 'Facebook'],
            ['006-4', '3CX'],
            ['006-5', '-'],
        ] as [$id, $portal]) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => $id,
                'created_date' => '2026-05-10 10:00:00',
                'stage_name' => 'Reserva',
                'owner_id' => '005-'.$id,
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => $portal,
                'portal_original' => $portal,
                'reservation' => true,
                'cv_signed' => false,
            ]);
        }

        $response = $this->getJson('/informes/reservas-ventas/data/portals?'.http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]));

        $response->assertOk();
        $portals = collect($response->json('items'))->pluck('portal')->all();

        $this->assertContains('Coches.net', $portals);
        $this->assertContains('Meta', $portals);
        $this->assertContains('Sin clasificar', $portals);
        $this->assertNotContains('COCHES.NET', $portals);
        $this->assertNotContains('Coches Net Málaga', $portals);
        $this->assertNotContains('Facebook', $portals);
        $this->assertNotContains('3CX', $portals);
        $this->assertNotContains('-', $portals);
    }
}
