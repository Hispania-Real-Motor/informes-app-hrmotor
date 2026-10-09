<?php

namespace App\Services\SeoAnalytics;

use App\Models\ReportSyncRun;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SalesforceOrganicInterestProjectionService
{
    public const DATASET = 'seo_salesforce_organic_interests';

    public const SOURCE = 'local_database';

    private const CHUNK_SIZE = 1000;

    public function __construct(private readonly ReportSyncRunService $syncRuns) {}

    /** @return array{run: ReportSyncRun, period_start: CarbonImmutable, period_end: CarbonImmutable, cutoff: CarbonImmutable, stats: array<string, mixed>} */
    public function sync(int $days, ?CarbonImmutable $now = null): array
    {
        if ($days < 1 || $days > (int) config('seo_analytics.max_history_sync_days', 480)) {
            throw new RuntimeException('SEO Interest projection days must be between 1 and 480.');
        }

        $timezone = (string) config('seo_analytics.timezone', 'Europe/Madrid');
        $sourceRun = $this->latestStableInterestRun();
        $localNow = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $localClosedDay = $localNow->startOfDay()->subDay();
        $f2ClosedDay = CarbonImmutable::parse($sourceRun->source_cutoff_at)
            ->setTimezone($timezone)
            ->startOfDay()
            ->subDay();
        $periodEnd = $localClosedDay->lessThanOrEqualTo($f2ClosedDay) ? $localClosedDay : $f2ClosedDay;
        $periodStart = $periodEnd->subDays($days - 1);
        $startUtc = $periodStart->startOfDay()->utc();
        $endExclusiveUtc = $periodEnd->addDay()->startOfDay()->utc();
        $projectionCutoff = $endExclusiveUtc->subSecond();
        $run = $this->syncRuns->start(
            self::DATASET,
            self::SOURCE,
            $startUtc,
            $projectionCutoff,
            $timezone,
        );
        $stats = [
            'source_interest_dataset' => SalesforceInterestSyncService::DATASET,
            'source_interest_sync_run_id' => $sourceRun->id,
            'source_interest_cutoff_at' => CarbonImmutable::parse($sourceRun->source_cutoff_at)->utc()->toIso8601String(),
            'requested_days' => $days,
            'queried_interests' => 0,
            'organic_interests' => 0,
            'days_persisted' => 0,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'errors' => 0,
        ];

        try {
            $counts = $this->aggregate($startUtc, $endExclusiveUtc, $timezone, $stats);
            $this->afterInterestsRead($run, $stats);
            $this->assertSourceRunUnchanged($sourceRun);
            $rows = $this->rows($periodStart, $periodEnd, $counts, $timezone, $sourceRun);
            $stats['days_persisted'] = count($rows);

            DB::transaction(function () use ($rows, $run, $sourceRun, $projectionCutoff, &$stats): void {
                SeoSalesforceOrganicInterestDailyMetric::query()->upsert(
                    $rows,
                    ['data_date'],
                    [
                        'interest_count', 'source_timezone', 'source_interest_sync_run_id',
                        'source_interest_cutoff_at', 'extracted_at', 'updated_at',
                    ],
                );
                $this->afterProjectionPersisted($run, $stats);
                $this->assertSourceRunUnchanged($sourceRun);
                $this->beforeProjectionCompleted($run, $stats);
                $this->assertSourceRunUnchanged($sourceRun);
                $this->syncRuns->complete($run, $projectionCutoff, $stats);
                $this->assertSourceRunUnchanged($sourceRun);
            });

            return [
                'run' => $run->fresh(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'cutoff' => $projectionCutoff,
                'stats' => $stats,
            ];
        } catch (Throwable) {
            $stats['errors'] = 1;
            $run->update(['stats' => $stats]);
            $this->syncRuns->fail($run, 'SEO Interest projection failed safely.');

            throw new RuntimeException('SEO Interest projection failed safely.');
        }
    }

    private function latestStableInterestRun(): ReportSyncRun
    {
        $run = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();

        if ($run === null || $run->status !== 'completed' || $run->source_cutoff_at === null) {
            throw new RuntimeException('The latest Interest sync run is not a stable completed source.');
        }

        return $run;
    }

    /** @param array<string, mixed> $stats @return array<string, int> */
    private function aggregate(
        CarbonImmutable $startUtc,
        CarbonImmutable $endExclusiveUtc,
        string $timezone,
        array &$stats,
    ): array {
        $counts = [];

        DB::table('salesforce_interests')
            ->select(['id', 'functional_created_at'])
            ->where('is_deleted', false)
            ->where('medium', 'Orgánico')
            ->where('functional_created_at', '>=', $startUtc)
            ->where('functional_created_at', '<', $endExclusiveUtc)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($interests) use (&$counts, &$stats, $timezone): void {
                foreach ($interests as $interest) {
                    $date = CarbonImmutable::parse((string) $interest->functional_created_at, 'UTC')
                        ->setTimezone($timezone)
                        ->toDateString();
                    $counts[$date] = ($counts[$date] ?? 0) + 1;
                    $stats['queried_interests']++;
                    $stats['organic_interests']++;
                }
            }, 'id');

        return $counts;
    }

    /** @param array<string, int> $counts @return list<array<string, mixed>> */
    private function rows(
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $counts,
        string $timezone,
        ReportSyncRun $sourceRun,
    ): array {
        $now = now('UTC');
        $rows = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $rows[] = [
                'data_date' => $date->toDateString(),
                'interest_count' => $counts[$date->toDateString()] ?? 0,
                'source_timezone' => $timezone,
                'source_interest_sync_run_id' => $sourceRun->id,
                'source_interest_cutoff_at' => $sourceRun->source_cutoff_at,
                'extracted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function assertSourceRunUnchanged(ReportSyncRun $sourceRun): void
    {
        $latest = $this->latestStableInterestRun();
        if ($latest->id !== $sourceRun->id
            || ! $latest->source_cutoff_at?->equalTo($sourceRun->source_cutoff_at)) {
            throw new RuntimeException('The Interest source changed while the SEO projection was being built.');
        }
    }

    /** @param array<string, mixed> $stats */
    protected function afterInterestsRead(ReportSyncRun $run, array $stats): void {}

    /** @param array<string, mixed> $stats */
    protected function afterProjectionPersisted(ReportSyncRun $run, array $stats): void {}

    /** @param array<string, mixed> $stats */
    protected function beforeProjectionCompleted(ReportSyncRun $run, array $stats): void {}
}
