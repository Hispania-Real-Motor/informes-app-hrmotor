<?php

namespace Tests\Feature;

use App\Mail\SeoExecutiveDailyReportMail;
use App\Models\AnalyticalMetricEvaluation;
use App\Models\AnalyticalMetricRule;
use App\Models\AnalyticalMetricSnapshot;
use App\Models\AnalyticalRuleSet;
use App\Models\ReportSyncRun;
use App\Models\SeoExecutiveDailyReport;
use App\Models\SeoExecutiveEmailDelivery;
use App\Models\SeoExecutiveEmailSetting;
use App\Services\Analytics\AnalyticalSnapshotFingerprint;
use App\Services\Analytics\SameWeekdayComparisonEngine;
use App\Services\SeoAnalytics\Ga4OrganicConversionSyncService;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use App\Services\SeoAnalytics\SeoAnalyticalMetricRegistry;
use App\Services\SeoAnalytics\SeoExecutiveDailyEmailService;
use App\Services\SeoAnalytics\SeoExecutiveDailyReportDatasetService;
use App\Services\SeoAnalytics\SeoExecutiveDailyReportService;
use App\Services\SeoAnalytics\SeoExecutiveMailSender;
use App\Services\SeoAnalytics\SeoTechnicalHealthSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SeoExecutiveDailyEmailTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-21';

    private const SEARCH_PROPERTY = 'sc-domain:executive.test';

    private const GA4_PROPERTY = '313695489';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-21 08:00:00 Europe/Madrid');
        config([
            'app.url' => 'https://reports.example.test',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'reports@example.test',
            'services.google_search_console.client_id' => 'test-search-client',
            'services.google_search_console.client_secret' => 'test-search-secret',
            'services.google_search_console.refresh_token' => 'test-search-refresh',
            'services.google_search_console.property' => self::SEARCH_PROPERTY,
            'services.google_analytics.client_id' => 'test-ga-client',
            'services.google_analytics.client_secret' => 'test-ga-secret',
            'services.google_analytics.refresh_token' => 'test-ga-refresh',
            'services.google_analytics.property_id' => self::GA4_PROPERTY,
            'salesforce.auth_mode' => 'client_credentials',
            'salesforce.token_url' => 'https://example.invalid/oauth2/token',
            'salesforce.client_id' => 'test-salesforce-client',
            'salesforce.client_secret' => 'test-salesforce-secret',
            'salesforce.refresh_token' => null,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dataset_always_contains_registry_order_and_six_neutral_metrics_without_snapshots(): void
    {
        $payload = app(SeoExecutiveDailyReportDatasetService::class)->build(CarbonImmutable::parse(self::DATE));

        $this->assertCount(6, $payload['metrics']);
        $this->assertSame(
            collect(app(SeoAnalyticalMetricRegistry::class)->metrics())->pluck('key')->all(),
            collect($payload['metrics'])->pluck('metric_key')->all(),
        );
        $this->assertSame(['not-evaluable'], collect($payload['metrics'])->pluck('status')->unique()->values()->all());
        $this->assertSame(['—'], collect($payload['metrics'])->pluck('current')->unique()->values()->all());
        $this->assertSame(['Sin datos disponibles para evaluar.'], collect($payload['metrics'])->pluck('reading')->unique()->values()->all());
        $this->assertSame(6, $payload['counts']['not_evaluable']);
        $this->assertStringContainsString('6 métricas no evaluables', $payload['executive_summary']);
        Http::assertNothingSent();
    }

    public function test_dataset_reuses_evaluations_source_freshness_and_factual_partial_health(): void
    {
        $this->seedEvaluatedMetrics();
        $this->seedSourceRuns();
        config(['seo_analytics.technical_health.site_url' => 'https://example.test']);
        $this->completedRun(SeoTechnicalHealthSyncService::DATASET, 'technical-http', '2026-08-20', [
            'site_host' => 'example.test',
            'check_date' => '2026-08-20',
            'checked_urls' => 12,
            'http_4xx' => 2,
            'http_5xx' => 1,
            'network_errors' => 3,
            'noindex_urls' => 4,
            'canonical_mismatch_urls' => 5,
            'redirected_urls' => 6,
            'sitemap_scan_complete' => false,
            'outside_sitemap_urls' => 9,
        ]);
        $this->failedRun(SeoTechnicalHealthSyncService::DATASET, ['site_host' => 'example.test']);

        $payload = app(SeoExecutiveDailyReportDatasetService::class)->build(CarbonImmutable::parse(self::DATE));
        $metrics = collect($payload['metrics'])->keyBy('metric_key');
        $this->assertSame('ok', $metrics['search_console_clicks']['status']);
        $this->assertSame('observation', $metrics['search_console_impressions']['status']);
        $this->assertSame('deviation', $metrics['search_console_ctr']['status']);
        $this->assertSame('critical', $metrics['search_console_position']['status']);
        $this->assertSame('not-evaluable', $metrics['salesforce_organic_interests']['status']);
        $this->assertSame('observation', $metrics['ga4_organic_key_events']['status']);
        $this->assertSame('favorable', $metrics['ga4_organic_key_events']['direction']);
        $this->assertSame('Oportunidad / posible anomalía.', $metrics['ga4_organic_key_events']['reading']);
        $this->assertSame('Error último sync', collect($payload['sources'])->firstWhere('key', 'search-console')['badge']);
        $this->assertStringContainsString('2026-08-18', collect($payload['sources'])->firstWhere('key', 'search-console')['detail']);
        $this->assertSame('2026-08-19', $metrics['salesforce_organic_interests']['data_date']);
        $this->assertSame('2026-08-17', $metrics['ga4_organic_key_events']['data_date']);
        $this->assertSame('Error ultimo sync', $payload['health']['source']['badge']);
        $this->assertSame('Comprobación de sitemap parcial', $payload['health']['sitemap_label']);
        $this->assertNull($payload['health']['outside_sitemap_urls']);
        $this->assertArrayNotHasKey('status', $payload['health']);

        $clicks = AnalyticalMetricSnapshot::query()->where('metric_key', 'search_console_clicks')->sole();
        $clicks->update(['current_value' => '50', 'absolute_change' => '-50', 'relative_change' => '-0.5']);
        $stale = collect(app(SeoExecutiveDailyReportDatasetService::class)->build(CarbonImmutable::parse(self::DATE))['metrics'])
            ->firstWhere('metric_key', 'search_console_clicks');
        $this->assertSame('not-evaluable', $stale['status']);
        $this->assertSame('evaluation_stale', $stale['reason_code']);
        $this->assertSame('Evaluación pendiente de actualizar.', $stale['reading']);
        Http::assertNothingSent();
    }

    public function test_mailable_has_stable_subject_html_text_dashboard_link_and_escaped_content(): void
    {
        $payload = app(SeoExecutiveDailyReportDatasetService::class)->build(CarbonImmutable::parse(self::DATE));
        $payload['metrics'][0]['label'] = '<script>alert(1)</script>';
        $mail = new SeoExecutiveDailyReportMail($payload);

        $this->assertSame('SEO y Analytics · Resumen diario · 21/08/2026', $mail->envelope()->subject);
        $html = $mail->render();
        $text = view('mail.seo-executive-daily-report-text', ['payload' => $payload])->render();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('tracking', mb_strtolower($html));
        $dashboardUrl = route('reports.seo-analytics.index', ['section' => 'summary']);
        $this->assertStringContainsString($dashboardUrl, $html);
        $this->assertStringContainsString('SEIS MÉTRICAS ACTUALES', $text);
        $this->assertStringContainsString('Dashboard: '.$dashboardUrl, $text);
    }

    public function test_individual_delivery_is_idempotent_and_new_recipient_uses_frozen_daily_report(): void
    {
        Mail::fake();
        $this->settings(['one@example.test', 'two@example.test']);
        $date = CarbonImmutable::parse(self::DATE);

        $first = app(SeoExecutiveDailyEmailService::class)->send($date);
        $this->assertSame(2, $first['sent_count']);
        $this->assertSame(0, $first['already_sent_count']);
        Mail::assertSent(SeoExecutiveDailyReportMail::class, 2);
        foreach (['one@example.test', 'two@example.test'] as $recipient) {
            Mail::assertSent(SeoExecutiveDailyReportMail::class, fn (SeoExecutiveDailyReportMail $mail): bool => $mail->hasTo($recipient) && count($mail->to) === 1);
        }

        $report = SeoExecutiveDailyReport::query()->sole();
        $originalHash = $report->payload_hash;
        $originalPayload = $report->payload;
        $this->assertSame($originalHash, app(SeoExecutiveDailyReportService::class)->payloadHash($originalPayload));
        $second = app(SeoExecutiveDailyEmailService::class)->send($date);
        $this->assertSame(0, $second['sent_count']);
        $this->assertSame(2, $second['already_sent_count']);
        Mail::assertSent(SeoExecutiveDailyReportMail::class, 2);

        $this->settings(['one@example.test', 'two@example.test', 'new@example.test']);
        $third = app(SeoExecutiveDailyEmailService::class)->send($date);
        $this->assertSame(1, $third['sent_count']);
        $this->assertSame(2, $third['already_sent_count']);
        Mail::assertSent(SeoExecutiveDailyReportMail::class, 3);
        $this->assertSame($originalHash, $report->fresh()->payload_hash);
        $this->assertSame($originalPayload, $report->fresh()->payload);
        $this->assertSame(1, SeoExecutiveDailyReport::query()->count());
        $this->assertSame(3, SeoExecutiveEmailDelivery::query()->where('status', 'sent')->count());
    }

    public function test_partial_failure_is_sanitized_and_retry_only_sends_failed_recipient(): void
    {
        $this->settings(['a@example.test', 'b@example.test', 'c@example.test']);
        $firstSender = Mockery::mock(SeoExecutiveMailSender::class);
        $firstSender->shouldReceive('send')->times(3)->andReturnUsing(function (string $recipient): void {
            if ($recipient === 'c@example.test') {
                throw new RuntimeException('SMTP password=secret-value failed');
            }
        });
        $this->app->instance(SeoExecutiveMailSender::class, $firstSender);

        $first = app(SeoExecutiveDailyEmailService::class)->send(CarbonImmutable::parse(self::DATE));
        $this->assertSame(2, $first['sent_count']);
        $this->assertSame(1, $first['failed_count']);
        $failed = SeoExecutiveEmailDelivery::query()->where('recipient_email', 'c@example.test')->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $failed->attempt_count);
        $this->assertStringContainsString('password=[redacted]', $failed->error_message);
        $this->assertStringNotContainsString('secret-value', $failed->error_message);

        $retrySender = Mockery::mock(SeoExecutiveMailSender::class);
        $retrySender->shouldReceive('send')->once()->with('c@example.test', Mockery::type('array'));
        $this->app->instance(SeoExecutiveMailSender::class, $retrySender);
        $retry = app(SeoExecutiveDailyEmailService::class)->send(CarbonImmutable::parse(self::DATE));
        $this->assertSame(1, $retry['sent_count']);
        $this->assertSame(2, $retry['already_sent_count']);
        $this->assertSame(0, $retry['failed_count']);
        $this->assertSame('sent', $failed->fresh()->status);
        $this->assertSame(2, $failed->fresh()->attempt_count);
    }

    public function test_smtp_success_with_sent_persistence_failure_stays_non_retryable_and_fails_command(): void
    {
        $this->settings(['uncertain@example.test']);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_executive_sent_confirmation
            BEFORE UPDATE OF status ON seo_executive_email_deliveries
            WHEN NEW.status = 'sent'
            BEGIN
                SELECT RAISE(ABORT, 'forced sent persistence failure');
            END
            SQL);

        $sender = Mockery::mock(SeoExecutiveMailSender::class);
        $sender->shouldReceive('send')->once()->with('uncertain@example.test', Mockery::type('array'));
        $this->app->instance(SeoExecutiveMailSender::class, $sender);

        $this->artisan('seo:send-executive-daily-email')->assertFailed();

        $delivery = SeoExecutiveEmailDelivery::query()->sole();
        $this->assertSame(SeoExecutiveEmailDelivery::STATUS_SENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertStringContainsString('no se pudo confirmar localmente', $delivery->error_message);
        $run = ReportSyncRun::query()
            ->where('dataset', SeoExecutiveDailyEmailService::DATASET)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, data_get($run->stats, 'sent_count'));
        $this->assertSame(1, data_get($run->stats, 'in_progress_count'));
        $this->assertSame(1, data_get($run->stats, 'confirmation_pending_count'));

        $this->artisan('seo:send-executive-daily-email')->assertFailed();
        $this->assertSame(SeoExecutiveEmailDelivery::STATUS_SENDING, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempt_count);
    }

    public function test_zero_row_sent_confirmation_fails_closed_for_an_unexpected_concurrent_state(): void
    {
        $this->settings(['concurrent@example.test']);
        $sender = Mockery::mock(SeoExecutiveMailSender::class);
        $sender->shouldReceive('send')->once()->andReturnUsing(function (): void {
            SeoExecutiveEmailDelivery::query()
                ->where('status', SeoExecutiveEmailDelivery::STATUS_SENDING)
                ->update(['status' => SeoExecutiveEmailDelivery::STATUS_FAILED]);
        });
        $this->app->instance(SeoExecutiveMailSender::class, $sender);

        $first = app(SeoExecutiveDailyEmailService::class)->send(CarbonImmutable::parse(self::DATE));

        $delivery = SeoExecutiveEmailDelivery::query()->sole();
        $this->assertSame(0, $first['sent_count']);
        $this->assertSame(0, $first['failed_count']);
        $this->assertSame(1, $first['in_progress_count']);
        $this->assertSame(1, $first['confirmation_pending_count']);
        $this->assertSame(SeoExecutiveEmailDelivery::STATUS_SENDING, $delivery->status);

        $second = app(SeoExecutiveDailyEmailService::class)->send(CarbonImmutable::parse(self::DATE));
        $this->assertSame(1, $second['in_progress_count']);
        $this->assertSame(0, $second['sent_count']);
        $this->assertSame(1, $delivery->fresh()->attempt_count);
    }

    public function test_zero_row_sent_confirmation_accepts_a_concurrently_confirmed_sent_state(): void
    {
        $this->settings(['confirmed@example.test']);
        $sender = Mockery::mock(SeoExecutiveMailSender::class);
        $sender->shouldReceive('send')->once()->andReturnUsing(function (): void {
            SeoExecutiveEmailDelivery::query()
                ->where('status', SeoExecutiveEmailDelivery::STATUS_SENDING)
                ->update([
                    'status' => SeoExecutiveEmailDelivery::STATUS_SENT,
                    'sent_at' => now(),
                ]);
        });
        $this->app->instance(SeoExecutiveMailSender::class, $sender);

        $first = app(SeoExecutiveDailyEmailService::class)->send(CarbonImmutable::parse(self::DATE));
        $second = app(SeoExecutiveDailyEmailService::class)->send(CarbonImmutable::parse(self::DATE));

        $this->assertSame(1, $first['sent_count']);
        $this->assertSame(0, $first['confirmation_pending_count']);
        $this->assertSame(SeoExecutiveEmailDelivery::STATUS_SENT, SeoExecutiveEmailDelivery::query()->sole()->status);
        $this->assertSame(1, $second['already_sent_count']);
        $this->assertSame(0, $second['sent_count']);
    }

    public function test_sending_delivery_is_not_reclaimed_or_sent_again(): void
    {
        Mail::fake();
        $this->settings(['busy@example.test']);
        $date = CarbonImmutable::parse(self::DATE);
        $report = app(SeoExecutiveDailyReportService::class)->forDate($date);
        SeoExecutiveEmailDelivery::query()->create([
            'seo_executive_daily_report_id' => $report->id,
            'report_date' => self::DATE,
            'recipient_email' => 'busy@example.test',
            'recipient_hash' => hash('sha256', 'busy@example.test'),
            'status' => 'sending',
            'attempt_count' => 1,
            'last_attempt_at' => now(),
        ]);

        $stats = app(SeoExecutiveDailyEmailService::class)->send($date);
        $this->assertSame(1, $stats['in_progress_count']);
        $this->assertSame(0, $stats['sent_count']);
        Mail::assertNothingSent();
    }

    public function test_command_fails_without_recipients_and_records_safe_runs_then_succeeds_without_snapshots(): void
    {
        Mail::fake();
        $this->artisan('seo:send-executive-daily-email')->assertFailed();
        $failed = ReportSyncRun::query()->where('dataset', SeoExecutiveDailyEmailService::DATASET)->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('No hay destinatarios configurados', $failed->error_message);
        Mail::assertNothingSent();

        $this->settings(['direction@example.test']);
        $this->artisan('seo:send-executive-daily-email')->assertSuccessful();
        $completed = ReportSyncRun::query()->where('dataset', SeoExecutiveDailyEmailService::DATASET)->where('status', 'completed')->sole();
        $this->assertSame(6, data_get($completed->stats, 'metric_count'));
        $this->assertSame(6, data_get($completed->stats, 'not_evaluable_count'));
        $encoded = json_encode($completed->stats, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('direction@example.test', $encoded);
        $this->assertStringNotContainsString('password', mb_strtolower($encoded));
        Mail::assertSent(SeoExecutiveDailyReportMail::class, 1);

        $this->artisan('seo:send-executive-daily-email')->assertSuccessful();
        Mail::assertSent(SeoExecutiveDailyReportMail::class, 1);
    }

    public function test_command_rejects_non_delivery_mailer_and_marks_partial_delivery_failed_with_safe_stats(): void
    {
        Mail::fake();
        $this->settings(['direction@example.test']);
        config(['mail.default' => 'log']);
        $this->artisan('seo:send-executive-daily-email')->assertFailed();
        $this->assertSame(0, SeoExecutiveDailyReport::query()->count());
        Mail::assertNothingSent();

        config(['mail.default' => 'smtp']);
        $sender = Mockery::mock(SeoExecutiveMailSender::class);
        $sender->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP client_secret=do-not-store failed'));
        $this->app->instance(SeoExecutiveMailSender::class, $sender);
        $this->artisan('seo:send-executive-daily-email')->assertFailed();

        $delivery = SeoExecutiveEmailDelivery::query()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('client_secret=[redacted]', $delivery->error_message);
        $this->assertStringNotContainsString('do-not-store', $delivery->error_message);
        $failedRun = ReportSyncRun::query()
            ->where('dataset', SeoExecutiveDailyEmailService::DATASET)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('failed', $failedRun->status);
        $this->assertSame(1, data_get($failedRun->stats, 'failed_count'));
        $this->assertStringNotContainsString('direction@example.test', json_encode($failedRun->stats, JSON_THROW_ON_ERROR));
    }

    public function test_scheduler_keeps_existing_sequence_and_adds_monitored_0800_delivery(): void
    {
        $scheduler = file_get_contents(base_path('routes/console.php'));
        foreach (['05:15', '05:30', '05:45', '06:00', '06:15', '06:30'] as $time) {
            $this->assertStringContainsString("dailyAt('{$time}')", $scheduler);
        }
        $this->assertStringContainsString("Schedule::command('seo:send-executive-daily-email')", $scheduler);
        $this->assertStringContainsString("dailyAt('08:00')", $scheduler);
        $this->assertStringContainsString("timezone('Europe/Madrid')", $scheduler);
        $this->assertStringContainsString('withoutOverlapping(30)', $scheduler);
        $this->assertStringContainsString("'seo-send-executive-daily-email'", $scheduler);
    }

    /** @param array<int, string> $recipients */
    private function settings(array $recipients): void
    {
        SeoExecutiveEmailSetting::query()->updateOrCreate(
            ['module_key' => 'seo'],
            ['recipients' => $recipients, 'updated_by_report_user_id' => null],
        );
    }

    private function seedEvaluatedMetrics(): void
    {
        $statuses = [
            'search_console_clicks' => ['ok', 'stable', 'none', 'within_expected_range', true],
            'search_console_impressions' => ['observation', 'unfavorable', 'observation', 'relative_threshold', true],
            'search_console_ctr' => ['deviation', 'unfavorable', 'deviation', 'absolute_threshold', true],
            'search_console_position' => ['critical', 'unfavorable', 'critical', 'absolute_threshold', true],
            'salesforce_organic_interests' => ['not-evaluable', 'not_evaluable', 'not-evaluable', 'missing_current', false],
            'ga4_organic_key_events' => ['observation', 'favorable', 'critical', 'relative_threshold', true],
        ];
        $dates = ['salesforce_organic_interests' => '2026-08-19', 'ga4_organic_key_events' => '2026-08-17'];
        $ruleSet = AnalyticalRuleSet::query()->where('status', 'active')->sole();

        foreach (app(SeoAnalyticalMetricRegistry::class)->metrics() as $definition) {
            $sourceIdentifier = match ($definition['source']) {
                'search_console' => self::SEARCH_PROPERTY,
                'ga4' => self::GA4_PROPERTY,
                default => SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER,
            };
            $evaluable = $statuses[$definition['key']][4];
            $snapshot = AnalyticalMetricSnapshot::query()->create([
                'module_key' => 'seo',
                'metric_key' => $definition['key'],
                'metric_label' => $definition['label'],
                'source_key' => $definition['source'],
                'source_identifier' => $sourceIdentifier,
                'source_identifier_hash' => hash('sha256', $sourceIdentifier),
                'scope_key' => $definition['scope'],
                'value_format' => $definition['format'],
                'data_date' => $dates[$definition['key']] ?? '2026-08-18',
                'source_cutoff_at' => ($dates[$definition['key']] ?? '2026-08-18').' 00:00:00',
                'current_value' => $evaluable ? '90' : null,
                'reference_count' => $evaluable ? 4 : 0,
                'baseline_value' => $evaluable ? '100' : null,
                'absolute_change' => $evaluable ? '-10' : null,
                'relative_change' => $evaluable ? '-0.1' : null,
                'is_evaluable' => $evaluable,
                'evaluation_reason' => $evaluable ? null : 'missing_current',
                'engine_version' => SameWeekdayComparisonEngine::VERSION,
                'computed_at' => now(),
            ]);
            $rule = AnalyticalMetricRule::query()->where('rule_set_id', $ruleSet->id)->where('metric_key', $definition['key'])->sole();
            AnalyticalMetricEvaluation::query()->create([
                'analytical_metric_snapshot_id' => $snapshot->id,
                'analytical_rule_set_id' => $ruleSet->id,
                'analytical_metric_rule_id' => $rule->id,
                'module_key' => 'seo',
                'metric_key' => $definition['key'],
                'data_date' => $snapshot->data_date,
                'evaluated_current_value' => $snapshot->current_value,
                'evaluated_baseline_value' => $snapshot->baseline_value,
                'evaluated_absolute_change' => $snapshot->absolute_change,
                'evaluated_relative_change' => $snapshot->relative_change,
                'evaluated_snapshot_is_evaluable' => $snapshot->is_evaluable,
                'evaluated_snapshot_reason' => $snapshot->evaluation_reason,
                'evaluated_snapshot_fingerprint' => app(AnalyticalSnapshotFingerprint::class)->hash($snapshot->toArray()),
                'status' => $statuses[$definition['key']][0],
                'direction' => $statuses[$definition['key']][1],
                'magnitude_band' => $statuses[$definition['key']][2],
                'reason_code' => $statuses[$definition['key']][3],
                'evaluated_at' => now(),
            ]);
        }
    }

    private function seedSourceRuns(): void
    {
        $this->completedRun(SearchConsoleSyncService::DATASET, 'google-search-console', '2026-08-18', ['property' => self::SEARCH_PROPERTY]);
        $this->failedRun(SearchConsoleSyncService::DATASET, ['property' => self::SEARCH_PROPERTY]);
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, 'local_database', '2026-08-19');
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, 'google-analytics', '2026-08-17', ['property_id' => self::GA4_PROPERTY]);
    }

    /** @param array<string, mixed> $stats */
    private function completedRun(string $dataset, string $source, string $cutoff, array $stats = []): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => $source,
            'status' => 'completed',
            'period_start_at' => $cutoff.' 00:00:00',
            'period_end_at' => $cutoff.' 23:59:59',
            'source_cutoff_at' => $cutoff.' 23:59:59',
            'started_at' => $cutoff.' 06:00:00',
            'completed_at' => $cutoff.' 06:01:00',
            'timezone' => 'Europe/Madrid',
            'stats' => $stats,
        ]);
    }

    /** @param array<string, mixed> $stats */
    private function failedRun(string $dataset, array $stats): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => 'test-source',
            'status' => 'failed',
            'period_start_at' => '2026-08-20 00:00:00',
            'period_end_at' => '2026-08-20 23:59:59',
            'started_at' => '2026-08-20 07:00:00',
            'completed_at' => '2026-08-20 07:01:00',
            'timezone' => 'Europe/Madrid',
            'stats' => $stats,
            'error_message' => 'Fallo técnico seguro',
        ]);
    }
}
