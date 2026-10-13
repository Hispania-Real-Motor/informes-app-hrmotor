<?php

namespace Tests\Feature;

use App\Models\AnalyticalMetricEvaluation;
use App\Models\AnalyticalMetricRule;
use App\Models\AnalyticalMetricSnapshot;
use App\Models\AnalyticalRuleSet;
use App\Models\OperationalAlert;
use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Services\Analytics\AnalyticalSnapshotFingerprint;
use App\Services\Analytics\SameWeekdayComparisonEngine;
use App\Services\SeoAnalytics\SeoAnalyticalComparisonDatasetService;
use App\Services\SeoAnalytics\SeoAnalyticalEvaluationDatasetService;
use App\Services\SeoAnalytics\SeoAnalyticalEvaluationService;
use App\Services\SeoAnalytics\SeoAnalyticalMetricRegistry;
use App\Services\SeoAnalytics\SeoAnalyticalRuleSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SeoAnalyticalEvaluationsTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_PROPERTY = 'sc-domain:evaluation.test';

    private const GA4_PROPERTY = '313695489';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_search_console.client_id' => 'test-search-client',
            'services.google_search_console.client_secret' => 'test-search-secret',
            'services.google_search_console.refresh_token' => 'test-search-refresh',
            'services.google_search_console.property' => self::SEARCH_PROPERTY,
            'services.google_analytics.client_id' => 'test-ga-client',
            'services.google_analytics.client_secret' => 'test-ga-secret',
            'services.google_analytics.refresh_token' => 'test-ga-refresh',
            'services.google_analytics.property_id' => self::GA4_PROPERTY,
        ]);
        Http::preventStrayRequests();
    }

    public function test_rot5_migration_versions_the_complete_rule_set_and_preserves_thresholds(): void
    {
        $this->assertTrue(Schema::hasTable('analytical_rule_sets'));
        $this->assertTrue(Schema::hasTable('analytical_metric_rules'));
        $this->assertTrue(Schema::hasTable('analytical_metric_evaluations'));
        $this->assertTrue(Schema::hasColumns('analytical_rule_sets', [
            'module_key', 'version_number', 'version_key', 'status', 'change_reason',
            'created_by_report_user_id', 'activated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('analytical_metric_rules', [
            'rule_set_id', 'metric_key', 'comparison_mode', 'favorable_direction', 'threshold_unit',
            'observation_threshold', 'deviation_threshold', 'critical_threshold',
            'minimum_baseline', 'minimum_absolute_change',
        ]));
        $this->assertTrue(Schema::hasColumns('analytical_metric_evaluations', [
            'evaluated_current_value', 'evaluated_baseline_value', 'evaluated_absolute_change',
            'evaluated_relative_change', 'evaluated_snapshot_is_evaluable',
            'evaluated_snapshot_reason', 'evaluated_snapshot_fingerprint',
        ]));

        $ruleSetIndexes = collect(DB::select("PRAGMA index_list('analytical_rule_sets')"))->pluck('name');
        $ruleIndexes = collect(DB::select("PRAGMA index_list('analytical_metric_rules')"))->pluck('name');
        $evaluationIndexes = collect(DB::select("PRAGMA index_list('analytical_metric_evaluations')"))->pluck('name');
        $this->assertContains('analytics_rules_module_version_uq', $ruleSetIndexes);
        $this->assertContains('analytics_rules_module_key_uq', $ruleSetIndexes);
        $this->assertContains('analytics_rules_active_idx', $ruleSetIndexes);
        $this->assertContains('analytics_metric_rules_identity_uq', $ruleIndexes);
        $this->assertContains('analytics_evaluation_snapshot_rules_uq', $evaluationIndexes);
        $this->assertContains('analytics_evaluation_metric_date_idx', $evaluationIndexes);
        $this->assertContains('analytics_evaluation_status_date_idx', $evaluationIndexes);
        $migrationSource = file_get_contents(database_path('migrations/2026_08_21_090000_create_analytical_evaluation_tables.php'));
        $this->assertStringContainsString("->decimal('observation_threshold', 20, 8)", $migrationSource);
        $this->assertStringContainsString("->decimal('minimum_baseline', 24, 8)", $migrationSource);
        $this->assertStringContainsString("->decimal('evaluated_current_value', 24, 8)", $migrationSource);
        $this->assertStringContainsString("->decimal('evaluated_baseline_value', 24, 8)", $migrationSource);
        $this->assertStringContainsString("->decimal('evaluated_absolute_change', 24, 8)", $migrationSource);
        $this->assertStringContainsString("->decimal('evaluated_relative_change', 20, 8)", $migrationSource);

        $this->assertSame(2, AnalyticalRuleSet::query()->count());
        $legacy = AnalyticalRuleSet::query()->where('version_number', 1)->with('rules')->sole();
        $ruleSet = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $this->assertSame('seo_rules_v2', $ruleSet->version_key);
        $this->assertSame('active', $ruleSet->status);
        $this->assertSame('superseded', $legacy->status);
        $this->assertNull($ruleSet->created_by_report_user_id);
        $this->assertCount(6, $ruleSet->rules);
        $this->assertNotNull($legacy->rules->firstWhere('metric_key', 'salesforce_organic_leads'));
        $this->assertNull($ruleSet->rules->firstWhere('metric_key', 'salesforce_organic_leads'));

        $rules = $ruleSet->rules->keyBy('metric_key');
        $this->assertRule($rules['search_console_clicks'], 'relative_percent', 'increase', 'percent', '10.00000000', '20.00000000', '35.00000000', '50.00000000', '10.00000000');
        $this->assertRule($rules['search_console_impressions'], 'relative_percent', 'increase', 'percent', '10.00000000', '20.00000000', '35.00000000', '1000.00000000', '100.00000000');
        $this->assertRule($rules['salesforce_organic_interests'], 'relative_percent', 'increase', 'percent', '10.00000000', '20.00000000', '35.00000000', '5.00000000', '2.00000000');
        $this->assertRule($rules['ga4_organic_key_events'], 'relative_percent', 'increase', 'percent', '10.00000000', '20.00000000', '35.00000000', '10.00000000', '3.00000000');
        $this->assertRule($rules['search_console_ctr'], 'absolute_percentage_points', 'increase', 'percentage_points', '0.50000000', '1.00000000', '2.00000000', null, null);
        $this->assertRule($rules['search_console_position'], 'absolute_value', 'decrease', 'positions', '0.50000000', '1.00000000', '2.00000000', null, null);
    }

    public function test_evaluation_persists_six_classifications_idempotently_with_property_isolation_and_no_business_alerts(): void
    {
        $this->seedCurrentSnapshots();
        $old = $this->snapshot('search_console_clicks', 'search_console', 'old-property', '2026-08-21', '1', '100', '-99', '-0.99');

        $first = app(SeoAnalyticalEvaluationService::class)->evaluate();
        $second = app(SeoAnalyticalEvaluationService::class)->evaluate();

        $this->assertSame(6, $first['snapshots_evaluated']);
        $this->assertSame(6, $second['snapshots_evaluated']);
        $this->assertSame(6, AnalyticalMetricEvaluation::query()->count());
        $this->assertFalse(AnalyticalMetricEvaluation::query()->where('analytical_metric_snapshot_id', $old->id)->exists());
        $this->assertSame(0, OperationalAlert::query()->count());
        $this->assertDatabaseHas('analytical_metric_evaluations', ['metric_key' => 'search_console_clicks', 'status' => 'observation', 'direction' => 'unfavorable']);
        $this->assertDatabaseHas('analytical_metric_evaluations', ['metric_key' => 'search_console_impressions', 'status' => 'deviation', 'direction' => 'unfavorable']);
        $this->assertDatabaseHas('analytical_metric_evaluations', ['metric_key' => 'search_console_ctr', 'status' => 'critical', 'direction' => 'unfavorable']);
        $this->assertDatabaseHas('analytical_metric_evaluations', ['metric_key' => 'search_console_position', 'status' => 'critical', 'direction' => 'unfavorable']);
        $this->assertDatabaseHas('analytical_metric_evaluations', ['metric_key' => 'salesforce_organic_interests', 'status' => 'observation', 'reason_code' => 'low_baseline_material_change']);
        $this->assertDatabaseHas('analytical_metric_evaluations', ['metric_key' => 'ga4_organic_key_events', 'status' => 'observation', 'direction' => 'favorable', 'magnitude_band' => 'critical']);
    }

    public function test_incomplete_active_rule_set_fails_without_partial_evaluations(): void
    {
        $this->seedCurrentSnapshots();
        AnalyticalMetricRule::query()->where('metric_key', 'search_console_ctr')->delete();

        $this->expectException(RuntimeException::class);
        try {
            app(SeoAnalyticalEvaluationService::class)->evaluate();
        } finally {
            $this->assertSame(0, AnalyticalMetricEvaluation::query()->count());
        }
    }

    public function test_admin_and_director_can_version_rules_with_audit_immediate_reevaluation_and_stale_edit_protection(): void
    {
        $this->seedCurrentSnapshots();
        app(SeoAnalyticalEvaluationService::class)->evaluate();
        $v1 = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $director = $this->user(ReportUser::ROLE_DIRECTOR, 'director-rules@example.test');
        $this->authenticate($director);
        $payload = $this->settingsPayload($v1, 'Ajuste aprobado por Dirección');

        $this->get(route('reports.seo-analytics.settings.index'))
            ->assertOk()
            ->assertSee('seo_rules_v2')
            ->assertSee('ROT-5');
        $this->put(route('reports.seo-analytics.settings.update'), $payload)->assertRedirect();

        $v2 = AnalyticalRuleSet::query()->where('version_number', 3)->with('rules')->sole();
        $this->assertSame('active', $v2->status);
        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame($director->id, $v2->created_by_report_user_id);
        $this->assertSame('Ajuste aprobado por Dirección', $v2->change_reason);
        $this->assertSame('10.00000000', $v1->rules->firstWhere('metric_key', 'search_console_clicks')->observation_threshold);
        $this->assertSame(12.0, (float) $v2->rules->firstWhere('metric_key', 'search_console_clicks')->observation_threshold);
        $this->assertSame(12, AnalyticalMetricEvaluation::query()->count());

        $stalePayload = $payload;
        $stalePayload['rules']['search_console_clicks']['observation_threshold'] = '99';
        $this->followingRedirects()
            ->put(route('reports.seo-analytics.settings.update'), $stalePayload)
            ->assertOk()
            ->assertSee('Los umbrales han cambiado desde que abriste esta pantalla.')
            ->assertSee('value="12.00000000"', false)
            ->assertDontSee('value="99"', false);
        $this->assertSame(3, AnalyticalRuleSet::query()->count());

        $payloadV2 = $this->settingsPayload($v2, 'Segundo ajuste auditado');
        $this->put(route('reports.seo-analytics.settings.update'), $payloadV2)->assertRedirect();
        $this->assertDatabaseHas('analytical_rule_sets', ['version_key' => 'seo_rules_v4', 'status' => 'active']);
        $this->assertSame(18, AnalyticalMetricEvaluation::query()->count());
    }

    public function test_validation_retry_preserves_stale_base_and_cannot_bypass_optimistic_locking(): void
    {
        $v1 = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $director = $this->user(ReportUser::ROLE_DIRECTOR, 'director-validation-lock@example.test');
        $this->authenticate($director);

        $payloadA = $this->settingsPayload($v1, 'Ajuste concurrente aprobado');
        $this->put(route('reports.seo-analytics.settings.update'), $payloadA)->assertRedirect();
        $v2 = AnalyticalRuleSet::query()->where('version_number', 3)->with('rules')->sole();
        $this->assertSame('12.00000000', $v2->rules->firstWhere('metric_key', 'search_console_clicks')->observation_threshold);

        $stalePayload = $this->settingsPayload($v1, '');
        $stalePayload['rules']['search_console_clicks']['observation_threshold'] = '99';

        $this->from(route('reports.seo-analytics.settings.index'))
            ->put(route('reports.seo-analytics.settings.update'), $stalePayload)
            ->assertRedirect(route('reports.seo-analytics.settings.index'))
            ->assertSessionHasErrors('change_reason')
            ->assertSessionHasInput('base_rule_set_id', $v1->id)
            ->assertSessionHasInput('base_version_number', 2);

        $this->get(route('reports.seo-analytics.settings.index'))
            ->assertOk()
            ->assertSee('name="base_rule_set_id" value="'.$v1->id.'"', false)
            ->assertSee('name="base_version_number" value="2"', false)
            ->assertSee('value="99"', false)
            ->assertDontSee('name="base_version_number" value="3"', false);

        $retryPayload = $stalePayload;
        $retryPayload['change_reason'] = 'Reintento deliberado tras validación';

        $this->followingRedirects()
            ->put(route('reports.seo-analytics.settings.update'), $retryPayload)
            ->assertOk()
            ->assertSee('Los umbrales han cambiado desde que abriste esta pantalla.')
            ->assertSee('name="base_rule_set_id" value="'.$v2->id.'"', false)
            ->assertSee('name="base_version_number" value="3"', false)
            ->assertSee('value="12.00000000"', false)
            ->assertDontSee('value="99"', false);

        $this->assertSame(3, AnalyticalRuleSet::query()->count());
        $this->assertFalse(AnalyticalRuleSet::query()->where('version_number', 4)->exists());
    }

    public function test_settings_reject_contract_tampering_and_unauthorized_roles_without_http(): void
    {
        $v1 = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $payload = $this->settingsPayload($v1, 'Intento manipulado');
        $payload['rules']['search_console_clicks']['comparison_mode'] = 'absolute_value';
        $this->put(route('reports.seo-analytics.settings.update'), $payload)
            ->assertSessionHasErrors('rules.search_console_clicks');
        $this->assertSame(2, AnalyticalRuleSet::query()->count());

        foreach ([ReportUser::ROLE_VIEWER, ReportUser::ROLE_MARKETING, ReportUser::ROLE_AREA_MANAGER] as $role) {
            $this->authenticate($this->user($role, $role.'-rules@example.test'));
            $this->get(route('reports.seo-analytics.settings.index'))->assertForbidden();
            $this->put(route('reports.seo-analytics.settings.update'), $payload)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_admin_can_open_settings_and_invalid_numeric_rules_are_rejected(): void
    {
        $v1 = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $this->authenticate(ReportUser::query()->where('role', ReportUser::ROLE_ADMIN)->firstOrFail());
        $this->get(route('reports.seo-analytics.settings.index'))->assertOk();

        foreach (['NaN', 'Infinity', '-1', '1,5', ''] as $invalid) {
            $payload = $this->settingsPayload($v1, 'Validacion de entrada numerica');
            $payload['rules']['search_console_clicks']['observation_threshold'] = $invalid;
            $this->put(route('reports.seo-analytics.settings.update'), $payload)
                ->assertSessionHasErrors();
        }

        $unordered = $this->settingsPayload($v1, 'Validacion del orden de umbrales');
        $unordered['rules']['search_console_clicks']['observation_threshold'] = '30';
        $unordered['rules']['search_console_clicks']['deviation_threshold'] = '20';
        $this->put(route('reports.seo-analytics.settings.update'), $unordered)
            ->assertSessionHasErrors('rules.search_console_clicks');

        $missingReason = $this->settingsPayload($v1, '');
        $this->put(route('reports.seo-analytics.settings.update'), $missingReason)
            ->assertSessionHasErrors('change_reason');

        $this->assertSame(2, AnalyticalRuleSet::query()->count());
        Http::assertNothingSent();
    }

    public function test_command_records_runs_validates_backfill_and_rejects_historical_reinterpretation_after_v2(): void
    {
        $this->seedCurrentSnapshots();
        $this->seedHistoricalSnapshots();
        $this->artisan('seo:evaluate-analytical-snapshots')->assertSuccessful();
        $this->artisan('seo:evaluate-analytical-snapshots', ['--days' => 0])->assertFailed();
        $this->artisan('seo:evaluate-analytical-snapshots', ['--days' => 91])->assertFailed();
        $this->artisan('seo:evaluate-analytical-snapshots', ['--days' => 30])->assertFailed();

        $completed = ReportSyncRun::query()->where('dataset', SeoAnalyticalEvaluationService::DATASET)->where('status', 'completed')->get();
        $this->assertCount(1, $completed);
        $this->assertSame('seo_rules_v2', data_get($completed->first()->stats, 'rule_version'));
        $this->assertSame(6, data_get($completed->first()->stats, 'snapshots_evaluated'));

        $v1 = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $admin = ReportUser::query()->where('role', ReportUser::ROLE_ADMIN)->firstOrFail();
        app(SeoAnalyticalRuleSetService::class)->createVersion($v1->id, 2, $this->ruleValues($v1), 'Nueva versión', $admin->id);
        $this->artisan('seo:evaluate-analytical-snapshots', ['--days' => 2])->assertFailed();
        $this->assertDatabaseHas('report_sync_runs', ['dataset' => SeoAnalyticalEvaluationService::DATASET, 'status' => 'failed']);
        $failed = ReportSyncRun::query()->where('dataset', SeoAnalyticalEvaluationService::DATASET)->where('status', 'failed')->latest('id')->firstOrFail();
        $this->assertNotEmpty($failed->error_message);
        $this->assertStringNotContainsString('select ', strtolower($failed->error_message));

        $scheduler = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString("Schedule::command('seo:evaluate-analytical-snapshots')", $scheduler);
        $this->assertStringContainsString("dailyAt('06:30')", $scheduler);
        $this->assertStringContainsString("'seo-evaluate-analytical-snapshots'", $scheduler);
        $this->assertStringContainsString('withoutOverlapping(120)', $scheduler);
    }

    public function test_dashboard_preserves_factual_comparison_and_shows_latest_evaluation_and_recent_signals_without_http(): void
    {
        $this->seedCurrentSnapshots();
        app(SeoAnalyticalEvaluationService::class)->evaluate();
        $v1 = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $admin = ReportUser::query()->where('role', ReportUser::ROLE_ADMIN)->firstOrFail();
        app(SeoAnalyticalRuleSetService::class)->createVersion(
            $v1->id,
            2,
            $this->ruleValues($v1),
            'Validacion de version visible unica',
            $admin->id,
        );

        foreach ([7, 28, 90] as $range) {
            $this->get(route('reports.seo-analytics.index', ['section' => 'summary', 'range' => $range]))
                ->assertOk()
                ->assertSee('Comparativa diaria')
                ->assertSee('Señales recientes')
                ->assertSee('Oportunidad / posible anomalía.')
                ->assertSee('data-report-status="critical"', false)
                ->assertSee('seo_rules_v3');
        }

        $signals = app(SeoAnalyticalEvaluationDatasetService::class)->recentSignals();
        $this->assertLessThanOrEqual(50, count($signals));
        $this->assertCount(6, $signals);
        $this->assertSame(['seo_rules_v3'], collect($signals)->pluck('rule_version')->unique()->values()->all());
        Http::assertNothingSent();
    }

    public function test_recent_signals_exclude_historical_lead_identity(): void
    {
        $this->seedCurrentSnapshots();
        app(SeoAnalyticalEvaluationService::class)->evaluate();
        $legacySnapshot = AnalyticalMetricSnapshot::query()->create([
            'module_key' => 'seo', 'metric_key' => 'salesforce_organic_leads',
            'metric_label' => 'Lead orgánico (Salesforce)', 'source_key' => 'salesforce',
            'source_identifier' => 'salesforce-organic-leads',
            'source_identifier_hash' => hash('sha256', 'salesforce-organic-leads'),
            'scope_key' => 'all', 'value_format' => 'integer', 'data_date' => '2026-08-20',
            'source_cutoff_at' => '2026-08-20 23:59:59', 'current_value' => '1',
            'reference_count' => 4, 'baseline_value' => '10', 'absolute_change' => '-9',
            'relative_change' => '-0.9', 'is_evaluable' => true,
            'engine_version' => SameWeekdayComparisonEngine::VERSION, 'computed_at' => now(),
        ]);
        $legacySet = AnalyticalRuleSet::query()->where('version_number', 1)->sole();
        $legacyRule = AnalyticalMetricRule::query()
            ->where('rule_set_id', $legacySet->id)
            ->where('metric_key', 'salesforce_organic_leads')
            ->sole();
        AnalyticalMetricEvaluation::query()->create([
            'analytical_metric_snapshot_id' => $legacySnapshot->id,
            'analytical_rule_set_id' => $legacySet->id,
            'analytical_metric_rule_id' => $legacyRule->id,
            'module_key' => 'seo', 'metric_key' => 'salesforce_organic_leads',
            'data_date' => '2026-08-20', 'evaluated_current_value' => '1',
            'evaluated_baseline_value' => '10', 'evaluated_absolute_change' => '-9',
            'evaluated_relative_change' => '-0.9', 'evaluated_snapshot_is_evaluable' => true,
            'evaluated_snapshot_fingerprint' => app(AnalyticalSnapshotFingerprint::class)
                ->hash($legacySnapshot->toArray()),
            'status' => 'critical', 'direction' => 'unfavorable', 'magnitude_band' => 'critical',
            'reason_code' => 'relative_threshold', 'evaluated_at' => now(),
        ]);

        $signals = app(SeoAnalyticalEvaluationDatasetService::class)->recentSignals();

        $this->assertNotContains('Lead orgánico (Salesforce)', collect($signals)->pluck('metric')->all());
        $this->assertContains('Interés orgánico (Salesforce)', collect($signals)->pluck('metric')->all());
    }

    public function test_evaluation_captures_facts_detects_rolling_revision_and_refreshes_idempotently(): void
    {
        $this->seedCurrentSnapshots();
        $snapshot = AnalyticalMetricSnapshot::query()->where('metric_key', 'search_console_clicks')->sole();
        $snapshot->update([
            'current_value' => '60',
            'baseline_value' => '100',
            'absolute_change' => '-40',
            'relative_change' => '-0.4',
        ]);

        app(SeoAnalyticalEvaluationService::class)->evaluate();
        $evaluation = AnalyticalMetricEvaluation::query()
            ->where('analytical_metric_snapshot_id', $snapshot->id)
            ->sole();
        $originalFingerprint = $evaluation->evaluated_snapshot_fingerprint;
        $this->assertSame('60.00000000', $evaluation->evaluated_current_value);
        $this->assertSame('100.00000000', $evaluation->evaluated_baseline_value);
        $this->assertSame('-40.00000000', $evaluation->evaluated_absolute_change);
        $this->assertSame('-0.40000000', $evaluation->evaluated_relative_change);
        $this->assertTrue($evaluation->evaluated_snapshot_is_evaluable);
        $this->assertNull($evaluation->evaluated_snapshot_reason);
        $this->assertSame('critical', $evaluation->status);

        $snapshot->update(['computed_at' => now()->addMinute(), 'd364_value' => '12345']);
        $unchanged = collect(app(SeoAnalyticalComparisonDatasetService::class)->build())->firstWhere('metric_key', 'search_console_clicks');
        $this->assertSame('critical', $unchanged['status']);

        $snapshot->update([
            'current_value' => '19',
            'baseline_value' => '20',
            'absolute_change' => '-1',
            'relative_change' => '-0.05',
        ]);
        $stale = collect(app(SeoAnalyticalComparisonDatasetService::class)->build())->firstWhere('metric_key', 'search_console_clicks');
        $this->assertSame('not-evaluable', $stale['status']);
        $this->assertSame('evaluation_stale', $stale['reason_code']);
        $this->assertSame('Evaluación pendiente de actualizar.', $stale['reading']);

        $historical = collect(app(SeoAnalyticalEvaluationDatasetService::class)->recentSignals())
            ->firstWhere('metric', 'Clicks orgánicos');
        $this->assertSame('60', $historical['current']);
        $this->assertSame('100,00', $historical['baseline']);
        $this->assertSame('-40,00%', $historical['variation']);
        $this->assertSame('critical', $historical['status']);

        app(SeoAnalyticalEvaluationService::class)->evaluate();
        $refreshed = $evaluation->fresh();
        $this->assertSame(6, AnalyticalMetricEvaluation::query()->count());
        $this->assertSame($evaluation->id, $refreshed->id);
        $this->assertNotSame($originalFingerprint, $refreshed->evaluated_snapshot_fingerprint);
        $this->assertSame('19.00000000', $refreshed->evaluated_current_value);
        $this->assertSame('20.00000000', $refreshed->evaluated_baseline_value);
        $this->assertSame('-0.05000000', $refreshed->evaluated_relative_change);
        $this->assertSame('ok', $refreshed->status);
    }

    public function test_migration_down_removes_only_evaluation_tables_and_can_restore_v1(): void
    {
        $migration = require database_path('migrations/2026_08_21_090000_create_analytical_evaluation_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('analytical_metric_evaluations'));
        $this->assertFalse(Schema::hasTable('analytical_metric_rules'));
        $this->assertFalse(Schema::hasTable('analytical_rule_sets'));
        $this->assertTrue(Schema::hasTable('analytical_metric_snapshots'));

        $migration->up();
        $this->assertSame(1, AnalyticalRuleSet::query()->count());
        $this->assertSame(6, AnalyticalMetricRule::query()->count());
    }

    private function seedCurrentSnapshots(): void
    {
        $this->snapshot('search_console_clicks', 'search_console', self::SEARCH_PROPERTY, '2026-08-20', '85', '100', '-15', '-0.15');
        $this->snapshot('search_console_impressions', 'search_console', self::SEARCH_PROPERTY, '2026-08-20', '750', '1000', '-250', '-0.25');
        $this->snapshot('search_console_ctr', 'search_console', self::SEARCH_PROPERTY, '2026-08-20', '0.005', '0.03', '-0.025', '-0.83333333');
        $this->snapshot('search_console_position', 'search_console', self::SEARCH_PROPERTY, '2026-08-20', '7.5', '5', '2.5', '0.5');
        $this->snapshot('salesforce_organic_interests', 'salesforce', SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER, '2026-08-20', '0', '4', '-4', '-1');
        $this->snapshot('ga4_organic_key_events', 'ga4', self::GA4_PROPERTY, '2026-08-20', '14', '10', '4', '0.4');
    }

    private function seedHistoricalSnapshots(): void
    {
        $this->snapshot('search_console_clicks', 'search_console', self::SEARCH_PROPERTY, '2026-08-19', '90', '100', '-10', '-0.1');
        $this->snapshot('search_console_impressions', 'search_console', self::SEARCH_PROPERTY, '2026-08-19', '900', '1000', '-100', '-0.1');
        $this->snapshot('search_console_ctr', 'search_console', self::SEARCH_PROPERTY, '2026-08-19', '0.025', '0.03', '-0.005', '-0.16666667');
        $this->snapshot('search_console_position', 'search_console', self::SEARCH_PROPERTY, '2026-08-19', '5.5', '5', '0.5', '0.1');
        $this->snapshot('salesforce_organic_interests', 'salesforce', SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER, '2026-08-19', '5', '5', '0', '0');
        $this->snapshot('ga4_organic_key_events', 'ga4', self::GA4_PROPERTY, '2026-08-19', '10', '10', '0', '0');
    }

    private function snapshot(
        string $metric,
        string $source,
        string $identifier,
        string $date,
        string $current,
        string $baseline,
        string $absolute,
        ?string $relative,
    ): AnalyticalMetricSnapshot {
        $definition = collect(app(SeoAnalyticalMetricRegistry::class)->metrics())->firstWhere('key', $metric);

        return AnalyticalMetricSnapshot::query()->create([
            'module_key' => 'seo', 'metric_key' => $metric, 'metric_label' => $definition['label'],
            'source_key' => $source, 'source_identifier' => $identifier,
            'source_identifier_hash' => hash('sha256', $identifier), 'scope_key' => $definition['scope'],
            'value_format' => $definition['format'], 'data_date' => $date, 'source_cutoff_at' => $date.' 00:00:00',
            'current_value' => $current, 'd7_value' => null, 'd14_value' => null, 'd21_value' => null, 'd28_value' => null,
            'reference_count' => 4, 'baseline_value' => $baseline, 'absolute_change' => $absolute,
            'relative_change' => $relative, 'd364_value' => '999', 'year_absolute_change' => null,
            'year_relative_change' => null, 'is_evaluable' => true, 'evaluation_reason' => null,
            'engine_version' => SameWeekdayComparisonEngine::VERSION, 'computed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(AnalyticalRuleSet $ruleSet, string $reason): array
    {
        $values = $this->ruleValues($ruleSet);
        $values['search_console_clicks']['observation_threshold'] = '12';

        return [
            'base_rule_set_id' => $ruleSet->id,
            'base_version_number' => $ruleSet->version_number,
            'change_reason' => $reason,
            'rules' => $values,
        ];
    }

    /** @return array<string, array<string, string>> */
    private function ruleValues(AnalyticalRuleSet $ruleSet): array
    {
        $ruleSet->loadMissing('rules');

        return $ruleSet->rules->mapWithKeys(function (AnalyticalMetricRule $rule): array {
            $values = [
                'observation_threshold' => $rule->observation_threshold,
                'deviation_threshold' => $rule->deviation_threshold,
                'critical_threshold' => $rule->critical_threshold,
            ];
            if ($rule->comparison_mode === 'relative_percent') {
                $values['minimum_baseline'] = $rule->minimum_baseline;
                $values['minimum_absolute_change'] = $rule->minimum_absolute_change;
            }

            return [$rule->metric_key => $values];
        })->all();
    }

    private function assertRule(
        AnalyticalMetricRule $rule,
        string $mode,
        string $direction,
        string $unit,
        string $observation,
        string $deviation,
        string $critical,
        ?string $minimumBaseline,
        ?string $minimumChange,
    ): void {
        $this->assertSame($mode, $rule->comparison_mode);
        $this->assertSame($direction, $rule->favorable_direction);
        $this->assertSame($unit, $rule->threshold_unit);
        $this->assertSame($observation, $rule->observation_threshold);
        $this->assertSame($deviation, $rule->deviation_threshold);
        $this->assertSame($critical, $rule->critical_threshold);
        $this->assertSame($minimumBaseline, $rule->minimum_baseline);
        $this->assertSame($minimumChange, $rule->minimum_absolute_change);
    }

    private function user(string $role, string $email): ReportUser
    {
        return ReportUser::query()->create([
            'name' => 'Test '.$role,
            'email' => $email,
            'password' => 'test-password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function authenticate(ReportUser $user): void
    {
        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ]);
    }
}
