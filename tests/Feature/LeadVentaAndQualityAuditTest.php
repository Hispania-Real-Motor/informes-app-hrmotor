<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadVentaAndQualityAuditTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    public function test_incidencias_de_calidad_auditan_exactamente_el_kpi_y_no_fusionan_nombres(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        try {
            $this->commercial('005-a', 'Nombre compartido', 'HR MOTOR TORREJON');
            $this->commercial('005-b', 'Nombre compartido', null);
            $this->lead('00Q-no-commercial', '005-missing');
            $this->lead('00Q-no-delegation', '005-b', ['delegacion_encargada_bueno' => 'HR MOTOR TORREJON']);
            $this->lead('00Q-unclassified', '005-a');

            $summary = $this->getJson('/informes/leads/data/summary')->assertOk()->json('kpis');

            foreach ([
                'without_eligible_commercial' => ['key' => 'without_eligible_commercial', 'ids' => ['00Q-no-commercial']],
                'without_commercial_delegation' => ['key' => 'without_commercial_delegation', 'ids' => ['00Q-no-delegation']],
                'unclassified' => ['key' => 'unclassified', 'ids' => ['00Q-no-commercial', '00Q-unclassified']],
            ] as $metric => $expected) {
                $audit = $this->getJson('/informes/leads/data/kpi-audit?metric='.$metric)
                    ->assertOk()
                    ->json();

                $this->assertSame($summary[$expected['key']], $audit['total']);
                $this->assertCount($summary[$expected['key']], $audit['items']);
                $this->assertEqualsCanonicalizing(
                    $expected['ids'],
                    collect($audit['items'])->pluck('interest_id')->all(),
                );
            }

            $commercials = $this->getJson('/informes/leads/data/commercials')->assertOk()->json('commercials');
            $sharedNameRows = collect($commercials)->where('comercial', 'Nombre compartido')->values();

            $this->assertCount(2, $sharedNameRows);
            $this->assertEqualsCanonicalizing(
                ['005-a', '005-b'],
                $sharedNameRows->pluck('commercial_id')->all(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function commercial(string $id, string $name, ?string $delegation): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => 'Compra/Venta',
            'user_delegation' => $delegation,
            'is_active' => true,
        ]);
    }

    private function lead(string $id, string $ownerId, array $overrides = []): void
    {
        SalesforceLead::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-08-01 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Lead',
            'owner_id' => $ownerId,
            'portal_text' => 'Web',
        ], $overrides));
    }
}
