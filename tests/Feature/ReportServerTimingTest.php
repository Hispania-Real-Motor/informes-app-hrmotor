<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Services\Reports\Calls\CallDashboardDatasetService;
use App\Support\ReportServerTiming;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\Feature\Concerns\CreatesLeadDashboardRows;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class ReportServerTimingTest extends TestCase
{
    use CreatesCallDashboardRows;
    use CreatesLeadDashboardRows;
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
        config()->set('openai.enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_report_timing_is_disabled_by_default_and_does_not_change_the_leads_payload(): void
    {
        $this->leadRow('timing-lead');

        config()->set('reports.server_timing', false);
        $withoutTiming = $this->getJson('/informes/leads/data/summary')->assertOk();

        config()->set('reports.server_timing', true);
        Cache::flush();
        $withTiming = $this->getJson('/informes/leads/data/summary')->assertOk();

        $withoutTiming->assertHeaderMissing('Server-Timing');
        $this->assertTiming($withTiming->headers->get('Server-Timing'), [
            'leads-cache-miss',
            'leads-current-total',
            'leads-previous-total',
            'leads-groups',
            'leads-finalize',
            'leads-insights',
            'leads-filters',
            'leads-total',
        ]);
        $this->assertSame($withoutTiming->json(), $withTiming->json());
    }

    public function test_admin_sees_only_cache_hit_timing_after_leads_payload_is_cached(): void
    {
        config()->set('reports.server_timing', true);
        $this->leadRow('timing-lead');

        $this->getJson('/informes/leads/data/summary')->assertOk();
        $warm = $this->getJson('/informes/leads/data/summary')->assertOk();
        $header = (string) $warm->headers->get('Server-Timing');

        $this->assertTiming($header, ['leads-cache-hit', 'leads-total']);
        $this->assertStringNotContainsString('leads-current-total', $header);
        $this->assertStringNotContainsString('leads-previous-total', $header);
    }

    public function test_non_admin_never_receives_report_timing_when_the_flag_is_enabled(): void
    {
        config()->set('reports.server_timing', true);
        $this->leadRow('timing-lead');
        $viewer = ReportUser::query()->create([
            'name' => 'Timing viewer',
            'email' => 'timing-viewer@example.test',
            'password' => 'synthetic-password',
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $viewer->id,
            'report_user_role' => $viewer->role,
            'report_user_email' => $viewer->email,
        ])->getJson('/informes/leads/data/summary')
            ->assertOk()
            ->assertHeaderMissing('Server-Timing');

        $this->callRow('timing-call');
        foreach ([
            '/informes/llamadas/data/summary',
            '/informes/llamadas/data/agents',
            '/informes/llamadas/data/delegations',
        ] as $url) {
            $this->withSession([
                'informes_authenticated' => true,
                'report_user_id' => $viewer->id,
                'report_user_role' => $viewer->role,
                'report_user_email' => $viewer->email,
            ])->getJson($url)
                ->assertOk()
                ->assertHeaderMissing('Server-Timing');
        }
    }

    public function test_calls_endpoints_expose_internal_timings_only_on_cache_miss(): void
    {
        config()->set('reports.server_timing', true);
        $this->callRow('timing-call');

        foreach ([
            '/informes/llamadas/data/summary' => [
                'calls-summary-miss', 'calls-current', 'calls-previous', 'calls-agents-teams', 'calls-portals',
                'calls-agents-shared-miss', 'calls-daily', 'calls-ranking', 'calls-reconciliation', 'calls-filters',
                'calls-filters-miss', 'calls-metadata', 'calls-summary-total',
            ],
            '/informes/llamadas/data/agents' => ['calls-agents-miss', 'calls-agents-query', 'calls-agents-shared-hit', 'calls-agents-finalize', 'calls-agents-total'],
            '/informes/llamadas/data/delegations' => ['calls-delegations-miss', 'calls-delegations-query', 'calls-delegations-finalize', 'calls-delegations-total'],
        ] as $url => $expected) {
            $cold = $this->getJson($url)->assertOk();
            $warm = $this->getJson($url)->assertOk();

            $this->assertTiming($cold->headers->get('Server-Timing'), $expected);
            $this->assertTiming($warm->headers->get('Server-Timing'), [str_replace('-miss', '-hit', $expected[0]), end($expected)]);
            $this->assertStringNotContainsString($expected[1], (string) $warm->headers->get('Server-Timing'));
            $this->assertSame($cold->json(), $warm->json());
        }
    }

    public function test_timing_measurement_is_isolated_and_records_a_failed_block_before_rethrowing(): void
    {
        $first = new ReportServerTiming;
        $second = new ReportServerTiming;

        try {
            $first->measure('leads-failed', function (): never {
                throw new \RuntimeException('synthetic timing failure');
            });
        } catch (\RuntimeException) {
            // The request exception remains unchanged; the timing object only measures it.
        }

        $this->assertStringContainsString('leads-failed;dur=', $first->headerValue());
        $this->assertSame('', $second->headerValue());
    }

    public function test_pre_warmed_filters_and_shared_agents_are_isolated_by_scope_and_data_version(): void
    {
        config()->set('reports.server_timing', true);
        $this->callRow('timing-call', ['operational_user_id' => 'commercial-1']);
        $dataset = app(CallDashboardDatasetService::class);
        $dataset->prewarmFilterOptions();

        $admin = $this->getJson('/informes/llamadas/data/summary')->assertOk();
        $this->assertStringContainsString('calls-filters-hit;dur=', (string) $admin->headers->get('Server-Timing'));
        $this->assertStringContainsString('calls-agents-shared-miss;dur=', (string) $admin->headers->get('Server-Timing'));

        $method = new \ReflectionMethod($dataset, 'sharedAgentAndTeamMetricRows');
        $period = ['start' => CarbonImmutable::now()->subDays(30), 'end' => CarbonImmutable::now()];
        $filters = [
            'period' => 'last_30_days', 'current_start' => '', 'current_end' => '', 'comparison_start' => '', 'comparison_end' => '',
            'team' => '', 'direction' => '', 'status' => '', 'origin' => '', 'delegation' => '', 'zone' => '', 'portal' => '', 'user' => '',
            'access_commercial' => 'commercial-1', 'access_delegation' => null, 'access_zone' => null,
        ];
        $scopedTiming = new ReportServerTiming;
        $method->invoke($dataset, $filters, $period, $scopedTiming);
        $this->assertStringContainsString('calls-agents-shared-miss;dur=', $scopedTiming->headerValue());

        Cache::forever('salesforce_calls_dashboard_cache_version', 2);
        $versionTiming = new ReportServerTiming;
        $method->invoke($dataset, $filters, $period, $versionTiming);
        $this->assertStringContainsString('calls-agents-shared-miss;dur=', $versionTiming->headerValue());
    }

    /** @param list<string> $expectedMetrics */
    private function assertTiming(?string $header, array $expectedMetrics): void
    {
        $this->assertIsString($header);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]+;dur=\\d+\\.\\d{3}(?:, [A-Za-z0-9-]+;dur=\\d+\\.\\d{3})*$/', $header);

        foreach ($expectedMetrics as $metric) {
            $this->assertStringContainsString($metric.';dur=', $header);
        }
    }
}
