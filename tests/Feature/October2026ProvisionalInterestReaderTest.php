<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class October2026ProvisionalInterestReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_only_the_fixed_validated_october_journals_without_exposing_row_identifiers(): void
    {
        $this->seedValidJournals();
        $this->assertNotSame($this->materializationSourceManifest(), $this->activitySourceManifest());
        app(October2026ProvisionalInterestReader::class)->validate();
        $overview = app(October2026ProvisionalInterestReader::class)->overview();

        $this->assertSame(2, $overview['coverage']['f2_inventory']);
        $this->assertSame(2, $overview['coverage']['materialized']);
        $this->assertSame(1, $overview['coverage']['eligible_active_matching']);
        $this->assertSame(1, $overview['coverage']['content_changed']);
        $this->assertSame(1, $overview['coverage']['canonical_identity_unavailable']);
        $this->assertSame(1, $overview['classification']['total_included']);
        $this->assertSame(1, $overview['classification']['classified']);
        $this->assertSame(0, $overview['classification']['unclassified']);
        $this->assertSame([['delegation' => 'Madrid General', 'interests' => 1]], $overview['by_delegation']);
        $this->assertSame(1, collect($overview['classification']['by_group'])->sum('interests'));
        $this->assertSame(1, collect($overview['classification']['by_zone'])->sum('interests'));
        $this->assertSame(1, $overview['functional_kpis']['total']);
        $this->assertSame('available_with_coverage_limits', $overview['availability']['leads_interest_overview']);
        $this->assertSame('available_with_coverage_limits', $overview['availability']['provisional_interest_kpis']);
        $this->assertSame('unavailable_missing_historical_salesforce_user_snapshot', $overview['availability']['commercials_eligible']);
        $this->assertSame('unavailable_missing_relationship_status_and_historical_f5_certification', $overview['availability']['activity_kpis']);
        $this->assertSame('unavailable', $overview['availability']['campaigns_calls_and_seo']);
        $this->assertArrayNotHasKey('salesforce_id', $overview);
    }

    public function test_it_uses_the_official_delegation_normalizer_without_inferencing_a_commercial_delegation(): void
    {
        $normalizer = app(LeadDelegationNormalizer::class);
        $expected = [
            'HR MOTOR TORREJON' => ['Torrejón', 'Grupo Madrid', 'Zona Sur y Centro'],
            'Sant Boi de Llobregat' => ['Sant Boi', 'Grupo Barcelona', 'Zona Cataluña'],
            'Valencia Paterna' => ['Paterna', 'Grupo Valencia', 'Zona Mediterraneo'],
            'Sevilla-Alcalá de Guadaira-HR Motor' => ['Alcalá de Guadaira', 'Grupo Sevilla', 'Zona Sur y Centro'],
            'Malaga Centro' => ['Málaga Centro', 'Grupo Málaga', 'Zona Sur y Centro'],
            'Zona Navarra' => ['Navarra General', 'Grupo Navarra', 'Zona Norte'],
            'Castellon general' => ['Castellón General', 'Grupo Castellón', 'Zona Mediterraneo'],
            'HR MOTOR BILBAO' => ['Bilbao', 'Grupo País Vasco', 'Zona Norte'],
        ];
        foreach ($expected as $raw => [$delegation, $group, $zone]) {
            $result = $normalizer->normalize($raw);
            $this->assertSame($delegation, $result['delegation']);
            $this->assertSame($group, $result['group']);
            $this->assertSame($zone, $result['zone']);
            $this->assertTrue($result['is_classified']);
        }

        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 1)
            ->where('salesforce_id', $this->id(1))
            ->update(['origin_delegation' => 'not an official delegation']);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(0, $overview['classification']['classified']);
        $this->assertSame(1, $overview['classification']['raw_unmapped']);
        $this->assertSame(0, $overview['classification']['raw_literal_general']);
        $this->assertSame('Sin clasificar', $overview['by_delegation'][0]['delegation']);
        $stored = (string) DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->value('overview');
        $this->assertStringNotContainsString('not an official delegation', $stored);
    }

    public function test_it_keeps_the_literal_general_within_the_unrecognized_origin_audit(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 1)
            ->where('salesforce_id', $this->id(1))
            ->update(['origin_delegation' => 'General']);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(1, $overview['classification']['total_included']);
        $this->assertSame(0, $overview['classification']['classified']);
        $this->assertSame(1, $overview['classification']['raw_unmapped']);
        $this->assertSame(1, $overview['classification']['raw_literal_general']);
        $this->assertSame(1, $overview['classification']['data_quality_review']['literal_general']);
        $this->assertSame('Sin clasificar', $overview['by_delegation'][0]['delegation']);
    }

    public function test_it_distinguishes_an_empty_origin_from_an_unmapped_value_without_reclassifying_it(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 1)
            ->where('salesforce_id', $this->id(1))
            ->update(['origin_delegation' => '   ']);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(1, $overview['classification']['unclassified']);
        $this->assertSame(1, $overview['classification']['raw_empty']);
        $this->assertSame(0, $overview['classification']['raw_unmapped']);
        $this->assertSame(1, $overview['classification']['data_quality_review']['required']);
    }

    public function test_it_calculates_only_the_functional_kpis_supported_by_valid_f2m_rows(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->update([
            'status' => 'Potencial', 'type' => 'Venta', 'source' => 'Web', 'medium' => 'Orgánico', 'channel' => 'Formulario',
        ]);
        $this->appendEligibleRows([
            ['Convertido', 'Venta con cambio', 'Web', 'Pago', 'Llamada'],
            ['Descartado', 'Tasación', 'Partner', null, null],
            ['Estado desconocido', 'Tipo no reconocido', null, 'Orgánico', 'Otro'],
        ]);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(4, $overview['functional_kpis']['total']);
        $this->assertSame([
            ['status' => 'Convertido', 'interests' => 1],
            ['status' => 'Descartado', 'interests' => 1],
            ['status' => 'Estado desconocido', 'interests' => 1],
            ['status' => 'Potencial', 'interests' => 1],
        ], $overview['functional_kpis']['statuses']);
        $this->assertSame([
            ['type' => 'Sin clasificar', 'interests' => 1],
            ['type' => 'Tasación', 'interests' => 1],
            ['type' => 'Venta', 'interests' => 1],
            ['type' => 'Venta con cambio', 'interests' => 1],
        ], $overview['functional_kpis']['types']);
        $this->assertSame(4, collect($overview['functional_kpis']['sources'])->sum('interests'));
        $this->assertSame(4, collect($overview['functional_kpis']['media'])->sum('interests'));
        $this->assertSame(4, collect($overview['functional_kpis']['channels'])->sum('interests'));
        $this->assertSame('unavailable_missing_historical_salesforce_user_snapshot', $overview['availability']['commercial_delegations']);
        $this->assertSame('unavailable_missing_relationship_status_and_historical_f5_certification', $overview['availability']['activity_kpis']);
    }

    public function test_it_rejects_pending_validation_and_invalidates_a_same_count_content_change(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_a_validated_http_read_does_not_scan_the_three_row_journals(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $reader->overview();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertStringNotContainsString('salesforce_interest_monthly_recovery_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_materialized_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_activity_rows', $queries);
        $this->assertStringContainsString('salesforce_interest_october_provisional_validations', $queries);
    }

    public function test_a_content_change_without_a_row_count_change_invalidates_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        DB::transaction(function (): void {
            DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]);
            app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(1);
        });

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_a_rolled_back_authorised_content_change_preserves_the_current_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $revision = (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');

        try {
            DB::transaction(function (): void {
                DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]);
                app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(1);
                throw new \RuntimeException('synthetic rollback');
            });
        } catch (\RuntimeException) {
            // Intentional: both the content mutation and its invalidation roll back.
        }

        $this->assertSame($revision, (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->assertSame(str_repeat('a', 64), DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->value('content_hash'));
        $this->assertSame(2, $reader->overview()['coverage']['materialized']);
    }

    public function test_operational_metadata_does_not_invalidate_content_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $revision = (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');

        DB::table('salesforce_interest_monthly_recoveries')->where('id', 1)->update(['heartbeat_at' => now(), 'updated_at' => now()]);

        $this->assertSame($revision, (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->assertSame(2, $reader->overview()['coverage']['f2_inventory']);
    }

    public function test_an_explicit_operational_revocation_fails_closed_without_scanning_the_journals(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        app(October2026ProvisionalEvidenceInvalidator::class)->revoke();

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_a_content_mutation_concurrent_with_validation_cannot_publish_stale_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $previousRevision = (int) DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->value('integrity_revision');

        $interrupted = new October2026ProvisionalInterestReader(
            beforeValidationPublication: fn () => app(October2026ProvisionalEvidenceInvalidator::class)->revoke(),
        );

        try {
            $interrupted->validate();
            $this->fail('Concurrent invalidation must prevent publication.');
        } catch (ProvisionalInterestSourceUnavailable) {
            $this->assertSame($previousRevision, (int) DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->value('integrity_revision'));
        }

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_http_rechecks_the_revision_before_returning_a_cached_overview(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $reader = new October2026ProvisionalInterestReader(
            beforeOverviewFinalCheck: fn () => app(October2026ProvisionalEvidenceInvalidator::class)->revoke(),
        );

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_it_rejects_a_f5_source_snapshot_that_does_not_match_its_own_contract(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update([
            'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => str_repeat('f', 64)]),
        ]));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_it_fails_closed_when_a_materialization_source_snapshot_no_longer_matches_f2(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_it_fails_closed_when_the_fixed_journal_is_not_the_complete_madrid_october_period(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_recoveries')->where('id', 1)->update(['functional_end_at' => '2026-11-01 00:00:00']));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_the_admin_only_screen_is_disabled_by_default_and_fails_closed_when_evidence_is_incomplete(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')->assertNotFound();
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->asReportUser($viewer)->get('/informes/leads/provisional/october-2026')->assertForbidden();
        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Octubre 2026 · datos reconstruidos')
            ->assertDontSee($this->id(1));

        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update(['phase' => 'verify_task']));
        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')->assertStatus(503);
    }

    public function test_the_original_interests_dashboard_renders_the_validated_october_projection_without_loading_the_official_dataset(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->asReportUser($admin)->get('/informes/leads')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Octubre 2026')
            ->assertSee('id="leadReportContent"', false)
            ->assertSee('Octubre 2026 · datos reconstruidos')
            ->assertDontSee('provisional y no certificado históricamente')
            ->assertDontSee('Explorar agregados provisionales');
        $this->asReportUser($viewer)->get('/informes/leads')->assertDontSee('value="october_2026"', false);
        $this->asReportUser($viewer)->get('/informes/leads?period=october_2026')->assertForbidden();
        $this->asReportUser($admin)->get('/informes/leads?period=current_month')
            ->assertOk()
            ->assertSee('window.initialLeadPeriod = "current_month";', false)
            ->assertDontSee('id="octoberProvisionalNotice"', false)
            ->assertDontSee('id="octoberGroup"', false);
        $this->asReportUser($admin)->get('/informes/leads?period=october_2026')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('id="leadReportContent"', false)
            ->assertSee('Grupo de procedencia')
            ->assertSee('Algunas métricas comerciales y de actividad todavía no tienen datos suficientes.')
            ->assertSee('window.initialLeadPeriod = "october_2026";', false)
            ->assertSee('<div class="is-hidden" id="leadUnavailableState">', false);

        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update(['phase' => 'verify_task']));
        $this->asReportUser($admin)->get('/informes/leads?period=october_2026')->assertStatus(503);
    }

    public function test_october_dashboard_filters_use_the_versioned_projection_and_reconcile_combined_dimensions(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->seedValidJournals();
        $this->appendEligibleRows([
            ['Convertido', 'Venta', 'Web', 'Pago', 'Formulario', 'Madrid'],
            ['Descartado', 'Tasación', 'Partner', 'Orgánico', 'Llamada', 'Valencia'],
            ['Potencial', 'Venta', 'Web', 'Orgánico', 'Formulario', 'Madrid'],
        ]);
        app(October2026ProvisionalInterestReader::class)->validate();
        $admin = $this->user(ReportUser::ROLE_ADMIN);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026&lead_delegation=Madrid%20General&portal=Web&status=Convertido');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('provisional', true)
            ->assertJsonPath('kpis.leads_totales', 1)
            ->assertJsonPath('kpis.convertidos', 1)
            ->assertJsonPath('kpis.descartados', 0)
            ->assertJsonPath('kpis.llamadas', 0)
            ->assertJsonPath('kpis.formularios', 1)
            ->assertJsonPath('kpis.formularios_pct', 100)
            ->assertJsonPath('kpis.potenciales_sin_trabajar', null);
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026&portal=Partner&channel=Llamada')
            ->assertOk()
            ->assertJsonPath('kpis.leads_totales', 1)
            ->assertJsonPath('kpis.llamadas', 1)
            ->assertJsonPath('kpis.formularios', 0);
        $this->asReportUser($admin)->getJson('/informes/leads/data/delegations?period=october_2026')
            ->assertOk()
            ->assertJsonPath('items.0.llamadas', 0);
        $this->assertStringContainsString('salesforce_interest_october_provisional_projection_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_materialized_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_recovery_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_activity_rows', $queries);
    }

    public function test_october_dashboard_rejects_a_projection_invalidated_between_query_and_response(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $snapshot = app(October2026ProvisionalInterestReader::class)->dashboardProjection();
        app(October2026ProvisionalEvidenceInvalidator::class)->revoke();

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->assertDashboardProjectionCurrent(
            $snapshot['integrity_revision'],
            $snapshot['projection_key'],
        );
    }

    public function test_observed_owners_are_grouped_by_stable_identity_and_unknown_assignments_keep_the_total(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->seedValidJournals();
        $ownerA = '005000000000000001';
        $ownerB = '005000000000000002';
        $this->appendEligibleRows([
            ['Potencial', 'Venta', 'Web', 'Pago', 'Formulario', 'Madrid', $ownerA, 'Nombre repetido'],
            ['Convertido', 'Venta', 'Web', 'Pago', 'Llamada', 'Madrid', $ownerB, 'Nombre repetido'],
            ['Potencial', 'Venta', 'Web', 'Pago', 'Formulario', 'Madrid', '0052X00000AP4U5QAL', 'API User'],
        ]);
        DB::table('commercial_delegation_snapshots')->insert([
            ['salesforce_user_id' => $ownerA, 'delegation' => 'Madrid', 'zone' => 'Zona Sur y Centro', 'observed_from' => '2026-10-10 00:00:00', 'observed_until' => null, 'source' => 'salesforce_user_observation', 'created_at' => now(), 'updated_at' => now()],
            ['salesforce_user_id' => $ownerB, 'delegation' => 'Barcelona', 'zone' => 'Zona Cataluña', 'observed_from' => '2026-10-10 00:00:00', 'observed_until' => null, 'source' => 'salesforce_user_observation', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(October2026ProvisionalInterestReader::class)->validate();
        $admin = $this->user(ReportUser::ROLE_ADMIN);

        $summary = $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026');
        $summary->assertOk()->assertJsonPath('kpis.leads_totales', 4)->assertJsonPath('kpis.leads_unassigned', 1);
        $commercials = $this->asReportUser($admin)->getJson('/informes/leads/data/commercials?period=october_2026');
        $commercials->assertOk();
        $items = $commercials->json('commercials');
        $this->assertSame(4, collect($items)->sum('leads_totales'));
        $this->assertCount(4, $items);
        $this->assertCount(2, collect($items)->filter(fn (array $item): bool => str_contains($item['comercial'], 'Nombre repetido')));
        $this->assertSame(4, collect($commercials->json('delegations'))->sum('leads_totales'));
        $this->assertSame(4, collect($commercials->json('zones'))->sum('leads_totales'));
        $this->assertDatabaseCount('salesforce_interest_october_provisional_owners', 4);
        $this->assertDatabaseMissing('salesforce_interest_october_provisional_owners', ['name_ciphertext' => 'Nombre repetido']);

        $ownerKey = hash_hmac('sha256', $ownerA, (string) config('app.key'));
        $this->assertContains($ownerKey, collect($summary->json('filters.commercials'))->pluck('id')->all());
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026&commercial='.$ownerKey.'&channel=Formulario')
            ->assertOk()->assertJsonPath('kpis.leads_totales', 1)->assertJsonPath('kpis.formularios', 1);
    }

    public function test_commercial_snapshot_change_invalidates_owner_projection_and_rollback_restores_it(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->seedValidJournals();
        $ownerId = '005000000000000001';
        $this->appendEligibleRows([['Potencial', 'Venta', 'Web', 'Pago', 'Formulario', 'Madrid', $ownerId, 'Comercial observado']]);
        DB::table('commercial_delegation_snapshots')->insert([
            'salesforce_user_id' => $ownerId, 'delegation' => 'Madrid', 'zone' => 'Zona Sur y Centro',
            'observed_from' => '2026-10-10 00:00:00', 'observed_until' => null, 'open_marker' => 1,
            'source' => 'salesforce_user_observation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => $ownerId, 'name' => 'Comercial observado', 'is_active' => true,
            'profile_name' => 'Compra/Venta', 'user_delegation' => 'Barcelona',
        ]);
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026')->assertOk();
        $revision = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');

        try {
            DB::transaction(function (): void {
                app(CommercialDelegationSnapshotService::class)->captureCurrentUsers(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // The source update and evidence invalidation must roll back together.
        }
        $this->assertEquals($revision, DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026')->assertOk();

        app(CommercialDelegationSnapshotService::class)->captureCurrentUsers(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
        $this->assertEquals($revision + 1, DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026')->assertStatus(503);

        $reader->validate();
        SalesforceUser::query()->where('salesforce_id', $ownerId)->update(['user_delegation' => 'Madrid']);
        $stableRevision = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');
        app(CommercialDelegationSnapshotService::class)->captureCurrentUsers(CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'));
        $this->assertEquals($stableRevision, DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026')->assertOk();
    }

    public function test_overlapping_commercial_observations_do_not_invent_an_october_delegation(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->seedValidJournals();
        $ownerId = '005000000000000001';
        $this->appendEligibleRows([['Potencial', 'Venta', 'Web', 'Pago', 'Formulario', 'Madrid', $ownerId, 'Comercial observado']]);
        DB::table('commercial_delegation_snapshots')->insert([
            ['salesforce_user_id' => $ownerId, 'delegation' => 'Madrid', 'zone' => 'Zona Sur y Centro', 'observed_from' => '2026-10-01 00:00:00', 'observed_until' => '2026-11-10 00:00:00', 'open_marker' => null, 'source' => 'salesforce_user_observation', 'created_at' => now(), 'updated_at' => now()],
            ['salesforce_user_id' => $ownerId, 'delegation' => 'Barcelona', 'zone' => 'Zona Cataluña', 'observed_from' => '2026-10-20 00:00:00', 'observed_until' => null, 'open_marker' => 1, 'source' => 'salesforce_user_observation', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(October2026ProvisionalInterestReader::class)->validate();

        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $commercials = $this->asReportUser($admin)->getJson('/informes/leads/data/commercials?period=october_2026');
        $commercials->assertOk();
        $ownerKey = hash_hmac('sha256', $ownerId, (string) config('app.key'));
        $ownerRow = collect($commercials->json('commercials'))->firstWhere('group_key', $ownerKey);
        $this->assertSame('Asignación no acreditada', $ownerRow['commercial_delegation']);
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026')
            ->assertOk()->assertJsonPath('kpis.without_eligible_commercial', null);
    }

    public function test_representative_october_volume_is_validated_outside_http_and_http_reads_only_the_snapshot(): void
    {
        $this->seedRepresentativeJournals();
        $reader = app(October2026ProvisionalInterestReader::class);

        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 34_239);
        $this->assertDatabaseCount('salesforce_interest_monthly_materialized_rows', 34_229);
        $this->assertDatabaseCount('salesforce_interest_monthly_activity_rows', 47_572);
        $reader->validate();
        $metrics = $reader->validationMetrics();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startedAt = hrtime(true);
        $overview = $reader->overview();
        $httpDurationNs = hrtime(true) - $startedAt;
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertSame(34_239, $overview['coverage']['f2_inventory']);
        $this->assertSame(34_229, $overview['coverage']['materialized']);
        $this->assertSame(10, $overview['coverage']['missing_from_materialization']);
        $this->assertSame(34_229, $overview['functional_kpis']['total']);
        $this->assertArrayHasKey('total_ns', $metrics);
        $this->assertGreaterThan(0, $metrics['total_ns']);
        $this->assertArrayHasKey('functional_kpis_ns', $metrics);
        $this->assertGreaterThan(0, $metrics['functional_kpis_ns']);
        $this->assertArrayHasKey('dashboard_projection_ns', $metrics);
        $this->assertGreaterThan(0, $metrics['dashboard_projection_ns']);
        foreach (['statuses', 'types', 'sources', 'media', 'channels'] as $dimension) {
            $this->assertSame(34_229, collect($overview['functional_kpis'][$dimension])->sum('interests'));
        }
        $this->assertGreaterThan(0, $httpDurationNs);
        $this->assertStringNotContainsString('salesforce_interest_monthly_recovery_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_materialized_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_activity_rows', $queries);
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        $this->assertDatabaseCount('salesforce_interest_october_provisional_projection_rows', 34_229);

        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        DB::flushQueryLog();
        $dashboardStartedAt = hrtime(true);
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary?period=october_2026')
            ->assertOk()->assertJsonPath('kpis.leads_totales', 34_229);
        $dashboardDurationNs = hrtime(true) - $dashboardStartedAt;
        $dashboardQueries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        foreach (['salesforce_interest_monthly_recovery_rows', 'salesforce_interest_monthly_materialized_rows', 'salesforce_interest_monthly_activity_rows'] as $journal) {
            $this->assertStringNotContainsString($journal, $dashboardQueries);
        }
        $this->assertLessThanOrEqual(15, count(DB::getQueryLog()));
        fwrite(STDERR, sprintf(
            "October representative volume: validation_ms=%.2f overview_ms=%.2f http_ms=%.2f http_queries=%d peak_memory_mb=%.1f\n",
            $metrics['total_ns'] / 1_000_000,
            $httpDurationNs / 1_000_000,
            $dashboardDurationNs / 1_000_000,
            count(DB::getQueryLog()),
            memory_get_peak_usage(true) / 1_048_576,
        ));
    }

    public function test_mariadb_can_reapply_the_additive_evidence_migration_without_row_triggers(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB-specific migration assertions run in the MariaDB CI job.');
        }

        $migration = require database_path('migrations/2026_11_11_120000_add_october_provisional_validation_evidence.php');
        $migration->up();
        $projectionMigration = require database_path('migrations/2026_11_12_120000_add_october_provisional_interest_projection.php');
        $projectionMigration->up();
        $ownerMigration = require database_path('migrations/2026_11_13_120000_add_october_provisional_owner_projection.php');
        $ownerMigration->up();
        $ownerMigration->up();
        $tables = [
            'salesforce_interest_monthly_recoveries',
            'salesforce_interest_monthly_recovery_rows',
            'salesforce_interest_monthly_materializations',
            'salesforce_interest_monthly_materialized_rows',
            'salesforce_interest_monthly_activity_recoveries',
            'salesforce_interest_monthly_activity_rows',
        ];
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $triggers = DB::select("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table IN ({$placeholders})", $tables);

        $this->assertDatabaseCount('salesforce_interest_monthly_integrity_revisions', 1);
        $this->assertTrue(Schema::hasColumn('salesforce_interest_october_provisional_validations', 'projection_key'));
        $this->assertTrue(Schema::hasTable('salesforce_interest_october_provisional_projection_rows'));
        $this->assertTrue(Schema::hasColumn('salesforce_interest_october_provisional_projection_rows', 'owner_key'));
        $this->assertTrue(Schema::hasTable('salesforce_interest_october_provisional_owners'));
        $this->assertSame([], $triggers);
    }

    private function seedValidJournals(): void
    {
        $now = now();
        DB::table('salesforce_interest_monthly_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'functional_start_at' => '2026-09-30 22:00:00', 'functional_end_at' => '2026-10-31 23:00:00',
            'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => json_encode(['persisted' => 2]), 'evidence' => json_encode(['verification' => ['verified' => false]]), 'reason_hash' => hash('sha256', 'synthetic october source'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sourceRowIds = [];
        foreach ([[1, str_repeat('a', 64), false], [2, str_repeat('b', 64), true]] as [$number, $hash, $deleted]) {
            $sourceRowIds[$number] = DB::table('salesforce_interest_monthly_recovery_rows')->insertGetId([
                'monthly_recovery_id' => 1, 'salesforce_id' => $this->id($number), 'content_hash' => $hash, 'is_deleted' => $deleted,
                'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $materializationSourceManifest = $this->materializationSourceManifest();
        $activitySourceManifest = $this->activitySourceManifest();
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => 1, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => $materializationSourceManifest]), 'reason_hash' => hash('sha256', 'synthetic materialization'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[1, str_repeat('a', 64), true, false, 'Madrid', $this->id(11)], [2, str_repeat('c', 64), false, true, null, null]] as [$number, $hash, $matching, $deleted, $delegation, $person]) {
            DB::table('salesforce_interest_monthly_materialized_rows')->insert([
                'monthly_materialization_id' => 1, 'monthly_recovery_row_id' => $sourceRowIds[$number], 'salesforce_id' => $this->id($number), 'source_content_hash' => $number === 1 ? str_repeat('a', 64) : str_repeat('b', 64), 'content_hash' => $hash,
                'content_matches_inventory' => $matching, 'is_deleted' => $deleted, 'salesforce_created_at' => '2026-10-02 08:00:00', 'salesforce_last_modified_at' => '2026-10-02 08:00:00', 'functional_created_at' => '2026-10-02 08:00:00',
                'origin_delegation' => $delegation, 'canonical_person_salesforce_id' => $person, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $materializationManifest = $this->materializationManifest();
        DB::table('salesforce_interest_monthly_materializations')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => 2, 'sha256' => $materializationManifest], 'verification' => ['verified' => false]])]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => $activitySourceManifest]), 'evidence' => json_encode(['manifest' => ['rows' => 0, 'sha256' => hash('sha256', '')], 'verification' => ['verified' => false]]),
            'reason_hash' => hash('sha256', 'synthetic f5'), 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedRepresentativeJournals(): void
    {
        $now = now();
        $f2Rows = 34_239;
        $f2mRows = 34_229;
        $f5Rows = 47_572;
        DB::table('salesforce_interest_monthly_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'functional_start_at' => '2026-09-30 22:00:00', 'functional_end_at' => '2026-10-31 23:00:00',
            'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 69,
            'counters' => json_encode(['persisted' => $f2Rows]), 'evidence' => json_encode(['verification' => ['verified' => false]]), 'reason_hash' => hash('sha256', 'representative october source'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2Source = hash_init('sha256');
        $f5Source = hash_init('sha256');
        foreach (array_chunk(range(1, $f2Rows), 500) as $numbers) {
            $batch = [];
            foreach ($numbers as $number) {
                $id = $this->id($number);
                $hash = hash('sha256', 'source-'.$number);
                $batch[] = ['monthly_recovery_id' => 1, 'salesforce_id' => $id, 'content_hash' => $hash, 'is_deleted' => false, 'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now];
                hash_update($f2Source, $id.'|'.$hash."|0\n");
                hash_update($f5Source, $id.":0\n");
            }
            DB::table('salesforce_interest_monthly_recovery_rows')->insert($batch);
        }
        $sourceRowIds = DB::table('salesforce_interest_monthly_recovery_rows')
            ->where('monthly_recovery_id', 1)
            ->pluck('id', 'salesforce_id')
            ->all();
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => 1, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 69,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => $f2Rows, 'source_sha256' => hash_final($f2Source)]), 'reason_hash' => hash('sha256', 'representative materialization'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2mManifest = hash_init('sha256');
        foreach (array_chunk(range(1, $f2mRows), 500) as $numbers) {
            $batch = [];
            foreach ($numbers as $number) {
                $id = $this->id($number);
                $sourceHash = hash('sha256', 'source-'.$number);
                $contentHash = hash('sha256', 'materialized-'.$number);
                $batch[] = ['monthly_materialization_id' => 1, 'monthly_recovery_row_id' => $sourceRowIds[$id], 'salesforce_id' => $id, 'source_content_hash' => $sourceHash, 'content_hash' => $contentHash, 'content_matches_inventory' => true, 'is_deleted' => false, 'salesforce_created_at' => '2026-10-02 08:00:00', 'salesforce_last_modified_at' => '2026-10-02 08:00:00', 'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now];
                hash_update($f2mManifest, $id.'|'.$contentHash."\n");
            }
            DB::table('salesforce_interest_monthly_materialized_rows')->insert($batch);
        }
        DB::table('salesforce_interest_monthly_materializations')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => $f2mRows, 'sha256' => hash_final($f2mManifest)], 'verification' => ['verified' => false]])]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 96,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => $f2Rows, 'source_sha256' => hash_final($f5Source)]), 'reason_hash' => hash('sha256', 'representative f5'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f5Manifest = hash_init('sha256');
        foreach (array_chunk(range(1, $f5Rows), 500) as $numbers) {
            $batch = [];
            foreach ($numbers as $number) {
                $activityId = '00T'.str_pad((string) $number, 15, '0', STR_PAD_LEFT);
                $contentHash = hash('sha256', 'activity-'.$number);
                $batch[] = ['monthly_activity_recovery_id' => 1, 'activity_kind' => 'Task', 'activity_salesforce_id' => $activityId, 'interest_salesforce_id' => $this->id((($number - 1) % $f2Rows) + 1), 'activity_is_deleted' => false, 'interest_is_deleted' => false, 'content_hash' => $contentHash, 'created_at' => $now, 'updated_at' => $now];
                hash_update($f5Manifest, $contentHash."\n");
            }
            DB::table('salesforce_interest_monthly_activity_rows')->insert($batch);
        }
        DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => $f5Rows, 'sha256' => hash_final($f5Manifest)], 'verification' => ['verified' => false]])]);
    }

    /** @param list<array{0:string,1:string,2:?string,3:?string,4:?string}> $rows */
    private function appendEligibleRows(array $rows): void
    {
        $now = now();
        foreach ($rows as $offset => $values) {
            [$status, $type, $source, $medium, $channel] = $values;
            $delegation = $values[5] ?? 'Madrid';
            $number = $offset + 3;
            $hash = hash('sha256', 'synthetic-'.$number);
            $sourceRowId = DB::table('salesforce_interest_monthly_recovery_rows')->insertGetId([
                'monthly_recovery_id' => 1, 'salesforce_id' => $this->id($number), 'content_hash' => $hash, 'is_deleted' => false,
                'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('salesforce_interest_monthly_materialized_rows')->insert([
                'monthly_materialization_id' => 1, 'monthly_recovery_row_id' => $sourceRowId, 'salesforce_id' => $this->id($number),
                'source_content_hash' => $hash, 'content_hash' => $hash, 'content_matches_inventory' => true, 'is_deleted' => false,
                'salesforce_created_at' => '2026-10-02 08:00:00', 'salesforce_last_modified_at' => '2026-10-02 08:00:00',
                'functional_created_at' => '2026-10-02 08:00:00', 'status' => $status, 'type' => $type, 'source' => $source,
                'medium' => $medium, 'channel' => $channel, 'origin_delegation' => $delegation,
                'owner_salesforce_id' => $values[6] ?? null, 'owner_name' => $values[7] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $materializationSource = hash_init('sha256');
        $activitySource = hash_init('sha256');
        foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 1)->orderBy('salesforce_id')->cursor() as $row) {
            hash_update($materializationSource, $row->salesforce_id.'|'.$row->content_hash.'|'.(int) $row->is_deleted."\n");
            hash_update($activitySource, $row->salesforce_id.':'.(int) $row->is_deleted."\n");
        }
        $sourceRows = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 1)->count();
        $materializationManifest = hash_init('sha256');
        $materializedRows = 0;
        foreach (DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->orderBy('salesforce_id')->cursor() as $row) {
            hash_update($materializationManifest, $row->salesforce_id.'|'.$row->content_hash."\n");
            $materializedRows++;
        }
        $activityManifest = hash_init('sha256');
        $activityRows = 0;
        foreach (DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', 1)->orderBy('activity_kind')->orderBy('activity_salesforce_id')->cursor() as $row) {
            hash_update($activityManifest, $row->content_hash."\n");
            $activityRows++;
        }
        DB::table('salesforce_interest_monthly_materializations')->where('id', 1)->update([
            'source_evidence' => json_encode(['source_rows' => $sourceRows, 'source_sha256' => hash_final($materializationSource)]),
            'evidence' => json_encode(['manifest' => ['rows' => $materializedRows, 'sha256' => hash_final($materializationManifest)], 'verification' => ['verified' => false]]),
        ]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update([
            'source_evidence' => json_encode(['source_rows' => $sourceRows, 'source_sha256' => hash_final($activitySource)]),
            'evidence' => json_encode(['manifest' => ['rows' => $activityRows, 'sha256' => hash_final($activityManifest)], 'verification' => ['verified' => false]]),
        ]);
    }

    private function materializationSourceManifest(): string
    {
        return hash('sha256', $this->id(1).'|'.str_repeat('a', 64)."|0\n".$this->id(2).'|'.str_repeat('b', 64)."|1\n");
    }

    private function authorisedChange(\Closure $change): void
    {
        DB::transaction(function () use ($change): void {
            $change();
            app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(1);
        });
    }

    private function activitySourceManifest(): string
    {
        return hash('sha256', $this->id(1).":0\n".$this->id(2).":1\n");
    }

    private function materializationManifest(): string
    {
        return hash('sha256', $this->id(1).'|'.str_repeat('a', 64)."\n".$this->id(2).'|'.str_repeat('c', 64)."\n");
    }

    private function id(int $number): string
    {
        return 'a01'.str_pad((string) $number, 15, '0', STR_PAD_LEFT);
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::create(['name' => 'Synthetic', 'email' => uniqid('', true).'@example.test', 'password' => 'synthetic-password', 'role' => $role, 'is_active' => true]);
    }

    private function asReportUser(ReportUser $user): static
    {
        return $this->withSession(['informes_authenticated' => true, 'report_user_id' => $user->id, 'report_user_role' => $user->role, 'report_user_email' => $user->email]);
    }
}
