<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceInterest;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use App\Services\Campaigns\CampaignLeadSyncService;
use App\Services\Campaigns\GoogleCampaignSyncService;
use App\Services\Campaigns\MetaCampaignSyncService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunityHistorySyncService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Concerns\ProvidesRot4CampaignContext;
use Tests\TestCase;

class CampaignCommandsTest extends TestCase
{
    use ProvidesRot4CampaignContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRot4CampaignContext();
    }

    public function test_rot4_scheduler_no_longer_runs_campaign_lead_sync_and_builds_after_direct_snapshot(): void
    {
        $scheduler = file_get_contents(base_path('routes/console.php'));
        $command = "Schedule::command('salesforce:sync-campaign-leads --days=120')";
        $this->assertSame(0, substr_count($scheduler, $command));

        $builder = "Schedule::command('campaigns:build-attribution --days=120')";
        $direct = "Schedule::command('salesforce:sync-opportunity-interest-direct";
        $directPosition = strpos($scheduler, $direct);
        $this->assertNotFalse($directPosition);
        $builderPosition = strpos($scheduler, $builder);
        $this->assertNotFalse($builderPosition);
        $builderIdentifierPosition = strpos($scheduler, "'campaigns-build-attribution'", $builderPosition);
        $this->assertNotFalse($builderIdentifierPosition);

        $builderConfiguration = substr($scheduler, $builderPosition, $builderIdentifierPosition - $builderPosition);
        $this->assertStringContainsString("dailyAt('08:00')", $builderConfiguration);
        $directIdentifierPosition = strpos($scheduler, "'salesforce-sync-opportunity-interest-direct'", $directPosition);
        $this->assertNotFalse($directIdentifierPosition);
        $directConfiguration = substr($scheduler, $directPosition, $directIdentifierPosition - $directPosition);
        $this->assertStringContainsString("dailyAt('07:35')", $directConfiguration);
    }

    public function test_campaign_lead_sync_mapper_guarda_campos_de_adquisicion(): void
    {
        $service = new CampaignLeadSyncService($this->createMock(SalesforceClient::class));

        $row = $service->mapRecord([
            'Id' => '00Q-campaign',
            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            'Name' => 'Lead Campana',
            'Status' => 'Nuevo',
            'OwnerId' => '005-owner',
            'Owner' => ['Name' => 'Gestor Campana'],
            'Phone' => '+34 600 000 001',
            'MobilePhone' => '600000002',
            'Email' => 'lead@example.com',
            'IsConverted' => true,
            'ConvertedDate' => '2026-05-11T10:00:00.000+0000',
            'ConvertedAccountId' => '001-account',
            'ConvertedContactId' => '003-contact',
            'ConvertedOpportunityId' => '006-opportunity',
            'LEA_SEL_Fuente_Origen__c' => 'Meta',
            'LEA_SEL_Medio_Origen__c' => 'Formulario',
            'Campa_a_Adquirida__c' => 'Campana Meta',
            'Id_Adquirido__c' => 'ad-123',
            'Contenido_Adquirido__c' => 'creative-123',
            'LEA_BUS_Vehiculo_de_interes__c' => 'Vehiculo A',
            'Delegacion_Encargada_Text__c' => 'Alcobendas',
            'Delegacion_Encargada__c' => 'deleg-1',
            'Delegacion_Encargada_Bueno__c' => 'Alcobendas',
        ], now());

        $this->assertSame('00Q-campaign', $row['salesforce_id']);
        $this->assertSame('Gestor Campana', $row['owner_name']);
        $this->assertSame('Meta', $row['fuente_origen']);
        $this->assertSame('Formulario', $row['medio_origen']);
        $this->assertSame('Campana Meta', $row['campaign_acquired']);
        $this->assertSame('ad-123', $row['acquired_id']);
        $this->assertSame('creative-123', $row['content_acquired']);
        $this->assertSame('Alcobendas', $row['delegacion_encargada_text']);
    }

    public function test_campaign_lead_sync_fresh_no_borra_salesforce_leads_generales(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-general',
            'name' => 'Lead general',
            'created_date' => now()->subDay(),
            'status' => 'Potencial',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
        ]);
        CampaignSalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-old',
            'name' => 'Lead campana antiguo',
            'created_date' => now()->subDay(),
            'campaign_acquired' => 'Campana anterior',
        ]);

        $client = $this->createMock(SalesforceClient::class);
        $client->method('query')->willReturn([]);

        $result = (new CampaignLeadSyncService($client))->sync(now()->subDays(3), now(), true);

        $this->assertSame('campaign_salesforce_leads', $result['table']);
        $this->assertSame(1, $result['deleted']);
        $this->assertDatabaseHas('salesforce_leads', ['salesforce_id' => '00Q-general']);
        $this->assertDatabaseMissing('campaign_salesforce_leads', ['salesforce_id' => '00Q-campaign-old']);
    }

    public function test_build_attribution_usa_salesforce_interests_y_persiste_source_campaign_name(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-sale',
            'name' => 'Lead venta',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana Salesforce',
        ]);

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame('salesforce_interests', $result['interest_source_table']);
        $this->assertSame(1, $result['saved_attributions']);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-campaign-sale',
            'platform' => 'salesforce',
            'campaign_name' => 'Campana Salesforce',
            'source_campaign_name' => 'Campana Salesforce',
            'campaign_type' => 'venta',
            'opportunity_id' => null,
        ]);
    }

    public function test_builder_excluye_leads_eliminados_y_guarda_traza_exacta_del_match(): void
    {
        foreach ([false, true] as $deleted) {
            SalesforceLead::query()->create([
                'salesforce_id' => $deleted ? '00Q-deleted-campaign' : '00Q-active-campaign',
                'name' => $deleted ? 'Eliminado' : 'Activo',
                'created_date' => '2026-05-10 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'record_type_normalized' => 'venta',
                'campaign_acquired' => 'VENTAS_1',
                'is_deleted' => $deleted,
            ]);
        }

        DB::table('campaign_platform_daily_metrics')->insert([
            'unique_key' => hash('sha256', 'metric-trace'),
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => '123',
            'campaign_id' => '456',
            'campaign_name' => 'VENTAS 1',
            'spend' => 10,
            'impressions' => 1,
            'clicks' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseMissing('campaign_lead_attributions', ['interest_id' => '00Q-deleted-campaign']);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-active-campaign',
            'attribution_method' => 'campaign_name_exact_match',
            'matched_source_field' => 'salesforce_interests.utm_campaign',
            'matched_source_value' => 'VENTAS_1',
            'matched_platform_field' => 'campaign_name',
            'matched_platform_value' => 'VENTAS 1',
            'match_candidate_count' => 1,
        ]);
    }

    public function test_original_advertising_ids_precede_meta_inference_and_conflicts_are_ambiguous(): void
    {
        foreach ([
            ['id' => '00Q-meta-google', 'acquired' => '21455768559', 'content' => null],
            ['id' => '00Q-content-google', 'acquired' => 'no-match', 'content' => 'ad-google'],
            ['id' => '00Q-meta-fallback', 'acquired' => 'no-match', 'content' => null],
            ['id' => '00Q-conflict', 'acquired' => 'ad-a', 'content' => 'ad-b'],
        ] as $lead) {
            SalesforceLead::query()->create([
                'salesforce_id' => $lead['id'], 'created_date' => '2026-05-10 10:00:00', 'status' => 'Potencial',
                'record_type_name' => 'Venta', 'campaign_acquired' => 'Campana distinta', 'acquired_id' => $lead['acquired'],
                'content_acquired' => $lead['content'], 'portal_text' => 'Meta', 'fuente_origen' => 'Facebook',
            ]);
        }
        foreach ([['21455768559', null, 'Google campaña'], ['google-a', 'ad-google', 'Google ad'], ['google-b', 'ad-a', 'A'], ['google-c', 'ad-b', 'B']] as $index => $metric) {
            DB::table('campaign_platform_daily_metrics')->insert([
                'unique_key' => hash('sha256', 'meta-precedence-'.$index), 'platform' => 'google_ads', 'metric_date' => '2026-05-10',
                'account_id' => '1', 'campaign_id' => $metric[0], 'campaign_name' => $metric[2], 'ad_id' => $metric[1],
                'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(CampaignAttributionBuilderService::class)->build(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-06-01'));
        $this->assertDatabaseHas('campaign_attributions', ['interest_id' => '00Q-meta-google', 'platform' => 'google_ads', 'campaign_id' => '21455768559', 'attribution_method' => 'campaign_id_match']);
        $this->assertDatabaseHas('campaign_attributions', ['interest_id' => '00Q-content-google', 'platform' => 'google_ads', 'campaign_id' => 'google-a', 'attribution_method' => 'ad_id_match', 'matched_source_field' => 'salesforce_interests.utm_content']);
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-meta-fallback',
            'campaign_id' => 'no-match',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
        ]);
        $this->assertDatabaseHas('campaign_attributions', ['interest_id' => '00Q-conflict', 'campaign_source_type' => 'ambiguous_attribution']);
    }

    public function test_meta_direct_form_requires_an_explicit_interest_campaign_name(): void
    {
        foreach ([
            ['id' => '00Q-meta-null', 'campaign' => null, 'acquired' => null, 'content' => null, 'portal' => 'Meta', 'source' => 'Facebook'],
            ['id' => '00Q-meta-empty', 'campaign' => '', 'acquired' => null, 'content' => null, 'portal' => 'Meta', 'source' => 'Facebook'],
            ['id' => '00Q-meta-google-id', 'campaign' => null, 'acquired' => 'google-campaign-id', 'content' => null, 'portal' => 'Meta', 'source' => 'Facebook'],
            ['id' => '00Q-not-meta-empty', 'campaign' => null, 'acquired' => null, 'content' => null, 'portal' => 'Web', 'source' => 'Google'],
            ['id' => '00Q-meta-explicit', 'campaign' => 'Formulario Directo Meta', 'acquired' => null, 'content' => null, 'portal' => 'Meta', 'source' => 'Facebook'],
        ] as $lead) {
            SalesforceLead::query()->create([
                'salesforce_id' => $lead['id'], 'created_date' => '2026-05-10 10:00:00', 'status' => 'Potencial',
                'record_type_name' => 'Venta', 'campaign_acquired' => $lead['campaign'], 'acquired_id' => $lead['acquired'],
                'content_acquired' => $lead['content'], 'portal_text' => $lead['portal'], 'fuente_origen' => $lead['source'],
            ]);
        }

        DB::table('campaign_platform_daily_metrics')->insert([
            'unique_key' => hash('sha256', 'meta-candidate-google-id'), 'platform' => 'google_ads', 'metric_date' => '2026-05-10',
            'account_id' => '1', 'campaign_id' => 'google-campaign-id', 'campaign_name' => 'Google Campaign',
            'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_platform_daily_metrics')->insert([
            'unique_key' => hash('sha256', 'meta-candidate-fallback'), 'platform' => 'meta', 'metric_date' => '2026-05-10',
            'account_id' => '1', 'campaign_id' => 'meta-instantforms-source', 'campaign_name' => 'Prospeccion InstantForms',
            'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        );

        foreach (['00Q-meta-null', '00Q-meta-empty'] as $leadId) {
            $this->assertDatabaseHas('campaign_attributions', [
                'interest_id' => $leadId,
                'platform' => 'salesforce',
                'campaign_id' => null,
            ]);
        }
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-meta-explicit',
            'platform' => 'meta',
            'campaign_id' => 'meta_instantforms_direct_form',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-meta-google-id',
            'platform' => 'google_ads',
            'campaign_id' => 'google-campaign-id',
            'attribution_method' => 'campaign_id_match',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-not-meta-empty',
            'campaign_source_type' => 'salesforce_origin',
        ]);
        $this->assertSame(5, $result['candidate_interests']);
        $this->assertSame(0, $result['excluded_campaigns']);
    }

    public function test_rebuild_del_mismo_periodo_no_pierde_oportunidades_ya_atribuidas(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-rebuild-opportunity',
            'name' => 'Lead rebuild oportunidad',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Tasacion',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'TASADOR LANDING SEARCH 1',
            'converted_opportunity_id' => '006-rebuild-opportunity',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-rebuild-opportunity',
            'name' => 'Oportunidad rebuild',
            'created_date' => '2026-05-12 10:00:00',
            'record_type_name' => 'Tasacion',
            'stage_name' => 'Generar contrato',
            'account_id' => '001-rebuild-opportunity',
            'reservation' => false,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-20',
            'opo_for_importe_total' => -9000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-rebuild-opportunity',
            'opportunity_id' => '006-rebuild-opportunity',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-rebuild-opportunity',
            'opportunity_id' => '006-rebuild-opportunity',
            'has_purchase' => true,
        ]);
    }

    public function test_sync_commands_no_fallan_sin_credenciales(): void
    {
        config()->set('services.meta_ads.access_token', null);
        config()->set('services.meta_ads.ad_account_ids', []);
        config()->set('services.google_ads.developer_token', null);
        config()->set('services.google_ads.customer_ids', []);

        $this->artisan('campaigns:sync-meta', ['--days' => 1])
            ->expectsOutputToContain('Meta Ads configurado: no')
            ->assertExitCode(0);

        $this->artisan('campaigns:sync-google', ['--days' => 1])
            ->expectsOutputToContain('Google Ads configurado: no')
            ->assertExitCode(0);
    }

    public function test_platform_sync_commands_accept_explicit_from_and_to_range(): void
    {
        $this->mock(MetaCampaignSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(function ($start, $end): bool {
                    return $start->equalTo(CarbonImmutable::parse('2026-01-01')->startOfDay())
                        && $end->equalTo(CarbonImmutable::parse('2026-02-01')->startOfDay());
                })
                ->andReturn([
                    'configured' => true,
                    'processed' => 0,
                    'saved' => 0,
                    'warnings' => [],
                ]);
        });

        $this->mock(GoogleCampaignSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(function ($start, $end): bool {
                    return $start->equalTo(CarbonImmutable::parse('2026-01-01')->startOfDay())
                        && $end->equalTo(CarbonImmutable::parse('2026-02-01')->startOfDay());
                })
                ->andReturn([
                    'configured' => true,
                    'processed' => 0,
                    'saved' => 0,
                    'warnings' => [],
                ]);
        });

        $this->artisan('campaigns:sync-meta', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
        ])->assertExitCode(0);

        $this->artisan('campaigns:sync-google', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
        ])->assertExitCode(0);
    }

    public function test_salesforce_sync_commands_accept_explicit_from_and_to_range(): void
    {
        $this->mock(CampaignLeadSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(function ($start, $end, $fresh): bool {
                    return $fresh === false
                        && $start->equalTo(CarbonImmutable::parse('2026-01-01')->startOfDay())
                        && $end->equalTo(CarbonImmutable::parse('2026-02-01')->startOfDay());
                })
                ->andReturn([
                    'table' => 'campaign_salesforce_leads',
                    'deleted' => 0,
                    'queried' => 0,
                    'saved' => 0,
                    'with_campaign_acquired' => 0,
                    'with_acquired_id' => 0,
                    'with_content_acquired' => 0,
                    'with_fuente_origen' => 0,
                    'with_medio_origen' => 0,
                    'without_acquisition' => 0,
                    'warnings' => [],
                ]);
        });

        $this->mock(SalesforceMonthlyUsersSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->andReturn([
                    'queried' => 0,
                    'saved' => 0,
                ]);
        });

        $this->mock(SalesforceOpportunitySyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(function ($start, $end, $modified): bool {
                    return $start->equalTo(CarbonImmutable::parse('2026-01-01')->startOfDay())
                        && $end->equalTo(CarbonImmutable::parse('2026-02-01')->startOfDay())
                        && $modified === false;
                })
                ->andReturn([
                    'queried' => 0,
                    'saved' => 0,
                    'deletions' => [
                        'queried' => 0,
                        'matched_local' => 0,
                        'changed' => 0,
                        'unchanged' => 0,
                    ],
                    'stats' => [
                        'opportunity' => 0,
                        'lead' => 0,
                        'opportunity_source' => 0,
                        'fallback_exposicion' => 0,
                        'fallback_web' => 0,
                        'unclassified' => 0,
                        'reservas_vivas' => 0,
                        'caidas' => 0,
                        'cv_firmados' => 0,
                    ],
                ]);
        });

        $this->mock(SalesforceOpportunityHistorySyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(function ($start, $end): bool {
                    return $start->equalTo(CarbonImmutable::parse('2026-01-01')->startOfDay())
                        && $end->equalTo(CarbonImmutable::parse('2026-02-01')->startOfDay());
                })
                ->andReturn([
                    'queried' => 0,
                    'saved' => 0,
                    'verified_cancellations' => 0,
                    'covered_intervals' => 0,
                    'unverifiable' => 0,
                    'unresolved_dependencies' => 0,
                ]);
        });

        $this->artisan('salesforce:sync-campaign-leads', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
        ])
            ->expectsOutputToContain('Periodo fin exclusivo: 2026-02-01T00:00:00Z')
            ->assertExitCode(0);

        $this->artisan('salesforce:sync-opportunities', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
        ])
            ->expectsOutputToContain('Periodo fin exclusivo: 2026-02-01T00:00:00Z')
            ->expectsOutputToContain('OpportunityHistory consultados: 0')
            ->assertExitCode(0);
    }

    public function test_build_attribution_command_excluye_leads_sin_atribucion_clara(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-clear',
            'name' => 'Lead claro',
            'created_date' => now()->subDay(),
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana real',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
        ]);

        foreach ([
            ['00Q-none', 'none'],
            ['00Q-literal', 'salesforce_interests.utm_campaign'],
            ['00Q-tasador', 'tasador'],
            ['00Q-ren2click', 'ren2click'],
            ['00Q-hrrenting', 'hrrenting'],
        ] as [$id, $campaign]) {
            SalesforceLead::query()->create([
                'salesforce_id' => $id,
                'name' => 'Lead '.$campaign,
                'created_date' => now()->subDay(),
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'campaign_acquired' => $campaign,
            ]);
        }

        $this->artisan('campaigns:build-attribution', ['--days' => 3])
            ->assertExitCode(0);

        $this->assertSame(6, CampaignAttribution::query()->count());
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-clear',
            'campaign_name' => 'Campana real',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-clear',
            'campaign_name' => 'Campana real',
            'source_campaign_name' => 'Campana real',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', ['interest_id' => '00Q-tasador', 'match_status' => 'excluded_campaign_tasador']);
        $this->assertDatabaseHas('campaign_lead_attributions', ['interest_id' => '00Q-ren2click', 'match_status' => 'excluded_campaign_ren2click']);
        $this->assertDatabaseHas('campaign_lead_attributions', ['interest_id' => '00Q-hrrenting', 'match_status' => 'excluded_campaign_hrrenting']);
    }

    public function test_campaign_report_commands_accept_legacy_window_option(): void
    {
        $this->artisan('campaigns:build-attribution', [
            '--days' => 3,
            '--window' => 30,
        ])->assertExitCode(0);

        $this->artisan('reports:refresh-campaigns', [
            '--days' => 3,
            '--window' => 30,
        ])->assertExitCode(0);
    }

    public function test_attribution_dry_run_does_not_write_and_matches_real_builder(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-dry-run', 'name' => 'Lead de simulacion',
            'created_date' => '2026-05-10 10:00:00', 'status' => 'Potencial',
            'record_type_name' => 'Lead', 'owner_id' => '005-real',
            'owner_name' => 'Comercial Real', 'campaign_acquired' => 'Campaign simulada',
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-dry-run-null', 'name' => 'Lead sin tipo',
            'created_date' => '2026-05-11 10:00:00', 'status' => 'Potencial',
            'record_type_name' => null, 'owner_id' => '005-real',
            'owner_name' => 'Comercial Real', 'campaign_acquired' => 'Campaign sin tipo',
        ]);

        $before = DB::table('campaign_attributions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $builder = app(CampaignAttributionBuilderService::class);
        $simulated = $builder->build(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-06-01'), true);

        $this->assertTrue($simulated['dry_run']);
        $this->assertSame(2, $simulated['simulation']['campaign_interests_examined']);
        $this->assertSame($before, DB::table('campaign_attributions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('campaign_lead_attributions', 0);

        $builder->build(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-06-01'));

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-dry-run',
            'campaign_name' => 'Campaign simulada',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
        ]);
        $this->assertSame(2, $simulated['simulation']['sets']['attributed']['count']);
        $this->assertSame(1, $simulated['simulation']['interest_types']['venta']);
        $this->assertSame(1, $simulated['simulation']['interest_types']['null']);
    }

    public function test_build_attribution_respeta_oportunidades_ya_atribuidas_fuera_de_rango(): void
    {
        CampaignAttribution::query()->create([
            'interest_id' => '00Q-old',
            'opportunity_id' => '006-shared',
            'platform' => 'salesforce',
            'campaign_name' => 'Campana antigua',
            'interest_functional_created_at' => '2026-03-10 10:00:00',
            'has_opportunity' => true,
            'attribution_method' => 'salesforce_only',
            'attribution_confidence' => 'low',
            'match_status' => 'salesforce_only',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-new',
            'name' => 'Lead nuevo',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana nueva',
            'converted_opportunity_id' => '006-shared',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-shared',
            'name' => 'Opportunity compartida',
            'created_date' => '2026-05-12 10:00:00',
            'stage_name' => 'Abierta',
            'record_type_name' => 'Venta',
        ]);

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame(1, $result['saved_attributions']);
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-old',
            'opportunity_id' => '006-shared',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-new',
            'opportunity_id' => null,
            'campaign_name' => 'Campana nueva',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-new',
            'opportunity_id' => null,
            'campaign_name' => 'Campana nueva',
            'source_campaign_name' => 'Campana nueva',
        ]);
    }

    public function test_tasacion_valida_no_convertida_entra_y_preserva_source_campaign_name(): void
    {
        foreach ([
            'TASADOR_LANDING_SEARCH_1',
            'Expiey_Leads_Geo_Tasación',
            'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
        ] as $index => $campaign) {
            SalesforceLead::query()->create([
                'salesforce_id' => '00Q-tasacion-'.$index,
                'name' => 'Lead '.$campaign,
                'created_date' => '2026-05-10 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Tasacion',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'campaign_acquired' => $campaign,
            ]);
        }

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-tasacion-0',
            'campaign_type' => 'tasacion',
            'source_campaign_name' => 'TASADOR_LANDING_SEARCH_1',
            'opportunity_id' => null,
            'has_purchase' => false,
            'has_sale' => false,
            'has_reservation' => false,
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-tasacion-1',
            'campaign_type' => 'tasacion',
            'source_campaign_name' => 'Expiey_Leads_Geo_Tasación',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-tasacion-2',
            'campaign_type' => 'tasacion',
            'source_campaign_name' => 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
        ]);
    }

    public function test_rebuild_de_periodo_elimina_atribuciones_obsoletas_y_mantiene_solo_leads_validos_actuales(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-rebuild',
            'name' => 'Lead Rebuild',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana vigente',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-rebuild',
            'source_campaign_name' => 'Campana vigente',
        ]);

        SalesforceInterest::query()->where('salesforce_id', '00Q-rebuild')->update([
            'utm_campaign' => 'tasador',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-rebuild',
            'match_status' => 'excluded_campaign_tasador',
        ]);
    }

    public function test_debug_attribution_command_resume_validos_y_excluidos(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-debug-venta',
            'name' => 'Lead Debug Venta',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana Debug Venta',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-debug-excluded',
            'name' => 'Lead Debug Excluded',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Tasacion',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'tasador',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->artisan('campaigns:debug-attribution', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
        ])
            ->expectsOutputToContain('[Todas] validos=1 | atribuciones=2')
            ->expectsOutputToContain('excluded_campaign_tasador')
            ->expectsOutputToContain('Campana Debug Venta')
            ->assertExitCode(0);
    }

    public function test_refresh_campaigns_store_crea_snapshot(): void
    {
        $this->artisan('reports:refresh-campaigns', ['--days' => 1, '--store' => true])
            ->expectsOutputToContain('Snapshot id:')
            ->assertExitCode(0);

        $this->assertDatabaseCount('campaign_report_snapshots', 1);
    }
}
