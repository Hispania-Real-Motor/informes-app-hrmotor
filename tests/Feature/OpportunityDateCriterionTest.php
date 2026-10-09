<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDateCriterionTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtra_por_creacion_reserva_y_firma_cv(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-created',
            'created_date' => '2026-05-08 10:00:00',
            'reservation_date' => '2026-04-01',
            'cv_signed_date' => '2026-04-02',
            'stage_name' => 'Reserva',
            'owner_id' => '005-1',
            'owner_name' => 'Uno',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'reservation' => true,
            'cv_signed' => false,
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-reserved',
            'created_date' => '2026-04-01 10:00:00',
            'reservation_date' => '2026-05-08',
            'cv_signed_date' => '2026-04-02',
            'stage_name' => 'Reserva',
            'owner_id' => '005-2',
            'owner_name' => 'Dos',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'reservation' => true,
            'cv_signed' => false,
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-signed',
            'created_date' => '2026-04-01 10:00:00',
            'reservation_date' => '2026-04-02',
            'cv_signed_date' => '2026-05-08',
            'stage_name' => 'Contrato',
            'owner_id' => '005-3',
            'owner_name' => 'Tres',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'reservation' => true,
            'cv_signed' => true,
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-deleted',
            'created_date' => '2026-05-08 11:00:00',
            'reservation_date' => '2026-05-08',
            'cv_signed_date' => '2026-05-08',
            'stage_name' => 'Reserva',
            'owner_id' => '005-deleted',
            'owner_name' => 'Eliminada',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'reservation' => true,
            'cv_signed' => false,
            'is_deleted' => true,
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)->create([
            'salesforce_id' => '006-missing',
            'created_date' => '2026-05-08 12:00:00',
            'reservation_date' => '2026-05-08',
            'cv_signed_date' => '2026-05-08',
            'stage_name' => 'Reserva',
            'owner_id' => '005-missing',
            'owner_name' => 'No localizada',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'reservation' => true,
            'cv_signed' => false,
            'is_deleted' => true,
            'deletion_detection_source' => SalesforceOpportunity::PRESENCE_SOURCE_MISSING,
        ]);

        $base = [
            'period' => 'custom',
            'current_start' => '2026-05-08',
            'current_end' => '2026-05-09',
            'comparison_start' => '2026-05-01',
            'comparison_end' => '2026-05-02',
        ];

        $this->assertTotal(2, array_merge($base, ['date_criterion' => 'created_date']));
        $this->assertTotal(2, array_merge($base, ['date_criterion' => 'reservation_date']));
        $this->assertTotal(2, array_merge($base, ['date_criterion' => 'cv_signed_date']));
        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($base, ['date_criterion' => 'created_date'])))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_vivas', 2);
    }

    private function assertTotal(int $expected, array $query): void
    {
        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', $expected);
    }
}
