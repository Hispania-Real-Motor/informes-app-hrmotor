<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugReservasVentasTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_muestra_fuente_de_origen_y_ejemplos_sin_clasificar(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-1',
            'name' => 'Oportunidad',
            'portal_original' => '3CX',
            'portal_resolved' => 'Sin clasificar',
            'portal_original' => '3CX',
            'portal_resolution_source' => 'unclassified',
            'opportunity_source_raw' => 'COCHES.NET',
            'opportunity_source_normalized' => 'Coches.net',
            'portal_resolution_debug' => ['reason' => 'test'],
        ]);

        $this->artisan('reports:debug-reservas-ventas', ['--unclassified-portals' => true])
            ->expectsOutputToContain('Fuente origen Opportunity')
            ->expectsOutputToContain('Fuente origen normalizada')
            ->expectsOutputToContain('Total Sin clasificar: 1')
            ->expectsOutputToContain('006-1')
            ->assertExitCode(0);
    }
}
