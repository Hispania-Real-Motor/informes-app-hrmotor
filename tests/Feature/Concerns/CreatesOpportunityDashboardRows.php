<?php

namespace Tests\Feature\Concerns;

use App\Models\SalesforceOpportunity;

trait CreatesOpportunityDashboardRows
{
    protected function opportunityRow(string $id, array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Reserva',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'portal_resolution_source' => 'opportunity',
            'reservation' => false,
            'cv_signed' => false,
        ], $overrides));
    }
}
