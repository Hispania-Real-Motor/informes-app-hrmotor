<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Services\Analytics\Executive\ExecutiveDailyDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class ExecutiveSummaryPageTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 08:00:00', ExecutiveDailyDatasetService::TIMEZONE));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_and_director_can_render_global_executive_summary(): void
    {
        $this->coverSources();

        foreach ([ReportUser::ROLE_ADMIN, ReportUser::ROLE_DIRECTOR] as $role) {
            $user = $this->createUser($role);

            $this->withSession($this->sessionFor($user))
                ->get('/informes')
                ->assertOk()
                ->assertSee('Resumen Ejecutivo')
                ->assertSee('Último día cerrado: 30/09/2026')
                ->assertSee('Leads')
                ->assertSee('Reservas')
                ->assertSee('Ventas')
                ->assertSee('No hay alertas de negocio activas para el último día evaluable.')
                ->assertDontSee('Sin datos analíticos en este lote');
        }
    }

    public function test_operational_roles_cannot_view_summary(): void
    {
        $this->coverSources();

        foreach ([
            ReportUser::ROLE_VIEWER => '/informes/leads',
            ReportUser::ROLE_AREA_MANAGER => '/informes/leads',
            ReportUser::ROLE_DELEGATION_MANAGER => '/informes/leads',
            ReportUser::ROLE_COMMERCIAL => '/informes/leads',
            ReportUser::ROLE_COMMISSION_AUDITOR => '/informes/comisiones-comerciales',
            ReportUser::ROLE_STOCK_ONLY => '/informes/stock',
            ReportUser::ROLE_MARKETING => '/informes/leads',
            ReportUser::ROLE_FINANCIAL => '/informes/comisiones-comerciales',
        ] as $role => $destination) {
            $user = $this->createUser($role);

            $this->withSession($this->sessionFor($user))
                ->get('/informes')
                ->assertRedirect($destination);
        }
    }

    public function test_summary_html_does_not_expose_pii_salesforce_ids_or_sync_run_ids(): void
    {
        $this->coverSources();
        $this->lead('00Q-sensitive-summary', '2026-09-30 10:00:00', [
            'name' => 'Sensitive Lead Summary',
            'email' => 'summary-sensitive@example.test',
            'phone' => '+34999111222',
        ]);
        $this->opportunityRow('006-sensitive-summary', [
            'name' => 'Sensitive Opportunity Summary',
            'created_date' => '2026-08-01 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-09-30',
            'account_name' => 'Sensitive Account Summary',
            'account_phone' => '+34888111222',
            'account_person_email' => 'account-summary@example.test',
        ]);

        $admin = $this->createUser(ReportUser::ROLE_ADMIN);
        $html = $this->withSession($this->sessionFor($admin))
            ->get('/informes')
            ->assertOk()
            ->getContent();

        foreach ([
            '00Q-sensitive-summary',
            '006-sensitive-summary',
            'summary-sensitive@example.test',
            '+34999111222',
            'Sensitive Account Summary',
            'account-summary@example.test',
            'sync_run_id',
            'coverage_base_run_id',
            'freshness_run_id',
            'exe_v1_',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    private function coverSources(): void
    {
        $this->coverLeadSource('2025-09-01', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-01', '2026-10-01 00:00:00', '2026-10-01 00:00:00', mode: 'all_history');
    }

    private function coverLeadSource(string $start, string $cutoff): void
    {
        ReportSyncRun::query()->create([
            'dataset' => 'leads_dashboard',
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => CarbonImmutable::parse($start, 'Europe/Madrid')->startOfDay(),
            'period_end_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid'),
            'source_cutoff_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid'),
            'started_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid')->subMinutes(5),
            'completed_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid'),
            'timezone' => 'Europe/Madrid',
            'stats' => [],
        ]);
    }

    private function coverOpportunitySource(
        string $start,
        string $end,
        ?string $cutoff = null,
        string $status = 'completed',
        string $mode = 'period',
    ): void {
        $endAt = CarbonImmutable::parse($end, 'Europe/Madrid');

        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_opportunities',
            'source' => 'salesforce',
            'status' => $status,
            'period_start_at' => CarbonImmutable::parse($start, 'Europe/Madrid')->startOfDay(),
            'period_end_at' => $endAt,
            'source_cutoff_at' => $cutoff !== null ? CarbonImmutable::parse($cutoff, 'Europe/Madrid') : null,
            'started_at' => $endAt->subMinutes(5),
            'completed_at' => $status === 'completed' ? $endAt : null,
            'timezone' => 'Europe/Madrid',
            'stats' => ['mode' => $mode],
        ]);
    }

    private function lead(string $id, string $createdDate, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => $createdDate,
            'synced_at' => '2026-10-01 00:00:00',
            'salesforce_last_modified_at' => '2026-10-01 00:00:00',
            'is_deleted' => false,
            'status' => 'Nuevo',
            'owner_id' => '005-commercial-summary',
            'owner_name' => 'Comercial Summary',
            'portal_text' => 'Web',
            'delegacion_encargada_text' => 'HR MOTOR TORREJON',
        ], $overrides));
    }

    private function createUser(string $role): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'-'.str()->random(8).'@example.test',
            'password' => Hash::make('secret12'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function sessionFor(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
            'report_user_name' => $user->name,
        ];
    }
}
