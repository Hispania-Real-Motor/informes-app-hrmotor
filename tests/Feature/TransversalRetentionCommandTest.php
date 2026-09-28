<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransversalRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_pruning_aplica_fronteras_y_es_idempotente_sin_borrar_alertas_abiertas(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 12:00:00');
        $now = now();

        DB::table('salesforce_users')->insert([
            [
                'salesforce_id' => 'RAW-OLD',
                'raw_payload' => json_encode(['synthetic' => 'old']),
                'created_at' => $now->copy()->subMonthsNoOverflow(3),
                'updated_at' => $now->copy()->subMonthsNoOverflow(2)->subDay(),
            ],
            [
                'salesforce_id' => 'RAW-RECENT',
                'raw_payload' => json_encode(['synthetic' => 'recent']),
                'created_at' => $now->copy()->subMonth(),
                'updated_at' => $now->copy()->subMonthsNoOverflow(2)->addDay(),
            ],
        ]);

        $oldRunId = $this->insertSyncRun('completed-old', 'completed', $now->copy()->subMonthNoOverflow()->subDay());
        $this->insertSyncRun('completed-recent', 'completed', $now->copy()->subMonthNoOverflow()->addDay());
        $this->insertSyncRun('failed-13-days', 'failed', $now->copy()->subDays(13));
        $this->insertSyncRun('failed-15-days', 'failed', $now->copy()->subDays(15));

        DB::table('salesforce_interest_sync_errors')->insert([
            'report_sync_run_id' => $oldRunId,
            'salesforce_id' => 'a01000000000000901',
            'phase' => 'active_persist',
            'error_code' => '23000',
            'error_message' => 'Synthetic safe persistence failure.',
            'occurred_at' => $now->copy()->subMonthNoOverflow()->subDay(),
            'created_at' => $now->copy()->subMonthNoOverflow()->subDay(),
            'updated_at' => $now->copy()->subMonthNoOverflow()->subDay(),
        ]);
        DB::table('salesforce_interests')->insert([
            'salesforce_id' => 'a01000000000000902',
            'salesforce_created_at' => $now->copy()->subMonthsNoOverflow(3),
            'salesforce_last_modified_at' => $now->copy()->subMonthsNoOverflow(3),
            'functional_created_at' => $now->copy()->subMonthsNoOverflow(3),
            'raw_payload' => json_encode(['synthetic' => 'interest-old']),
            'created_at' => $now->copy()->subMonthsNoOverflow(3),
            'updated_at' => $now->copy()->subMonthsNoOverflow(2)->subDay(),
        ]);

        OperationalAlert::query()->create($this->alertPayload('open-old', 'open', null));
        OperationalAlert::query()->create($this->alertPayload(
            'resolved-old',
            'resolved',
            $now->copy()->subMonthNoOverflow()->subDay(),
        ));
        OperationalAlert::query()->create($this->alertPayload(
            'resolved-recent',
            'resolved',
            $now->copy()->subMonthNoOverflow()->addDay(),
        ));

        $this->artisan('reports:prune-transversal-data', ['--chunk' => 50])->assertSuccessful();
        $this->artisan('reports:prune-transversal-data', ['--chunk' => 50])->assertSuccessful();

        $this->assertNull(DB::table('salesforce_users')->where('salesforce_id', 'RAW-OLD')->value('raw_payload'));
        $this->assertNotNull(DB::table('salesforce_users')->where('salesforce_id', 'RAW-RECENT')->value('raw_payload'));
        $this->assertDatabaseMissing('report_sync_runs', ['dataset' => 'completed-old']);
        $this->assertDatabaseMissing('salesforce_interest_sync_errors', ['report_sync_run_id' => $oldRunId]);
        $this->assertDatabaseHas('salesforce_interests', ['salesforce_id' => 'a01000000000000902']);
        $this->assertNull(DB::table('salesforce_interests')
            ->where('salesforce_id', 'a01000000000000902')
            ->value('raw_payload'));
        $this->assertDatabaseHas('report_sync_runs', ['dataset' => 'completed-recent']);
        $this->assertDatabaseHas('report_sync_runs', ['dataset' => 'failed-13-days']);
        $this->assertDatabaseMissing('report_sync_runs', ['dataset' => 'failed-15-days']);
        $this->assertDatabaseHas('operational_alerts', ['technical_identifier' => 'open-old', 'state' => 'open']);
        $this->assertDatabaseMissing('operational_alerts', ['technical_identifier' => 'resolved-old']);
        $this->assertDatabaseHas('operational_alerts', ['technical_identifier' => 'resolved-recent']);
    }

    public function test_dry_run_no_modifica_payloads_ni_ejecuciones(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 12:00:00');
        $old = now()->subMonthsNoOverflow(3);
        DB::table('salesforce_users')->insert([
            'salesforce_id' => 'RAW-DRY',
            'raw_payload' => json_encode(['synthetic' => true]),
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $this->insertSyncRun('completed-dry', 'completed', $old);

        $this->artisan('reports:prune-transversal-data', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(DB::table('salesforce_users')->where('salesforce_id', 'RAW-DRY')->value('raw_payload'));
        $this->assertDatabaseHas('report_sync_runs', ['dataset' => 'completed-dry']);
    }

    private function insertSyncRun(string $dataset, string $status, mixed $completedAt): int
    {
        return DB::table('report_sync_runs')->insertGetId([
            'dataset' => $dataset,
            'source' => 'synthetic',
            'status' => $status,
            'started_at' => $completedAt,
            'completed_at' => $completedAt,
            'timezone' => 'Europe/Madrid',
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);
    }

    private function alertPayload(string $identifier, string $state, mixed $resolvedAt): array
    {
        return [
            'fingerprint' => hash('sha256', $identifier),
            'type' => 'synthetic',
            'severity' => 'high',
            'source' => 'test',
            'state' => $state,
            'message' => 'Synthetic operational alert.',
            'technical_identifier' => $identifier,
            'first_detected_at' => now()->subMonthsNoOverflow(3),
            'last_detected_at' => now()->subMonthsNoOverflow(3),
            'occurrences' => 1,
            'resolution' => $resolvedAt ? 'Resolved.' : null,
            'resolved_at' => $resolvedAt,
        ];
    }
}
