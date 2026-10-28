<?php

namespace App\Services\Campaigns;

use App\Models\ReportSyncRun;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use App\Services\Salesforce\InterestGenerationReader;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Persistent source lineage for attribution and published campaign snapshots. */
class CampaignPublicationReadiness
{
    public const ATTRIBUTION_DATASET = 'campaign_attributions';

    public const PUBLICATION_DATASET = 'campaign_report_snapshots';

    private const SOURCE = 'campaigns';

    private const INPUTS = [
        'meta' => ['campaign_platform_daily_metrics', 'meta'],
        'google' => ['campaign_platform_daily_metrics', 'google_ads'],
        'interests' => [SalesforceInterestSyncService::DATASET, SalesforceInterestSyncService::SOURCE],
        'opportunities' => ['salesforce_opportunities', 'salesforce'],
    ];

    public function __construct(private readonly CampaignOpenInvestmentWindow $openWindow) {}

    /** @return array{ready:bool, reason:?string, retry_task:?string, ids:array<string,int|string>, cutoff:?CarbonImmutable} */
    public function sources(?CarbonInterface $start = null, ?CarbonInterface $end = null): array
    {
        $localStart = $start === null ? null : CarbonImmutable::parse($start)->setTimezone('Europe/Madrid');
        $localEnd = $end === null ? null : CarbonImmutable::parse($end)->setTimezone('Europe/Madrid');
        $openStart = $localStart !== null && $localEnd !== null ? $this->openWindow->start($localStart, $localEnd) : null;
        if ($start !== null && $end !== null && $openStart === null) {
            return $this->blocked('El período consultado no contiene inversión abierta verificable.');
        }
        $ids = [];
        $cutoffs = [];
        foreach (self::INPUTS as $key => [$dataset, $source]) {
            $run = $this->latest($dataset, $source);
            $task = match ($key) {
                'meta' => 'campaigns-sync-meta',
                'google' => 'campaigns-sync-google',
                'interests' => 'salesforce-sync-interest-reporting',
                'opportunities' => 'salesforce-sync-opportunities',
            };
            if ($run?->status !== 'completed' || $run->completed_at === null || $run->source_cutoff_at === null) {
                return $this->blocked('La fuente '.$key.' no tiene una última ejecución completa.', $task);
            }
            if ($openStart !== null && in_array($key, ['meta', 'google'], true)
                && ($run->period_start_at === null || $run->period_end_at === null
                    || $run->period_start_at->setTimezone('Europe/Madrid')->toDateString() > $openStart->toDateString()
                    || $run->period_end_at->setTimezone('Europe/Madrid')->toDateString() < $this->requiredSourceEndDate($localEnd))) {
                return $this->blocked('La última ejecución publicitaria de '.$key.' no cubre el período abierto.', $task);
            }
            if (in_array($key, ['meta', 'google'], true)) {
                $latestOperation = DB::table('operational_task_runs')->useWritePdo()
                    ->where('task_key', $task)->orderByDesc('id')->first();
                if ($latestOperation !== null && $latestOperation->status !== 'success'
                    && $latestOperation->queued_at !== null
                    && CarbonImmutable::parse($latestOperation->queued_at)->greaterThan($run->completed_at)) {
                    return $this->blocked('La última operación de '.$key.' no terminó correctamente.', $task);
                }
            }
            $ids[$key] = (int) $run->id;
            $cutoffs[] = CarbonImmutable::parse($run->source_cutoff_at);
        }

        $direct = SalesforceOpportunityInterestDirectRun::query()->useWritePdo()->orderByDesc('id')->first();
        if ($direct?->status !== 'completed' || $direct->completed_at === null || $direct->source_cutoff_at === null) {
            return $this->blocked('El snapshot directo Opportunity–Interest no está completo.', 'salesforce-sync-opportunity-interest-direct');
        }
        $opportunities = $this->latest(...self::INPUTS['opportunities']);
        if ($direct->completed_at->lessThan($opportunities->completed_at)) {
            return $this->blocked('El snapshot directo precede a la última sincronización de Opportunities.', 'salesforce-sync-opportunity-interest-direct');
        }
        $ids['direct'] = (int) $direct->id;
        $fingerprint = $direct->stats['reference_fingerprint'] ?? null;
        if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            return $this->blocked('El snapshot directo no tiene una huella verificable.', 'salesforce-sync-opportunity-interest-direct');
        }
        $ids['direct_fingerprint'] = $fingerprint;
        $cutoffs[] = CarbonImmutable::parse($direct->source_cutoff_at);

        return ['ready' => true, 'reason' => null, 'retry_task' => null, 'ids' => $ids, 'cutoff' => collect($cutoffs)->sort()->first()];
    }

    public function needsAttribution(array $ids): bool
    {
        $build = $this->latest(self::ATTRIBUTION_DATASET, self::SOURCE);
        if ($build?->status !== 'completed' || ! is_array($build->stats['source_ids'] ?? null)) {
            return true;
        }
        $previous = $build->stats['source_ids'];
        foreach (['meta', 'google'] as $key) {
            if ((int) ($previous[$key] ?? 0) !== $ids[$key]) {
                return true;
            }
        }

        if ($ids['direct_fingerprint'] === '' || ($previous['direct_fingerprint'] ?? null) !== $ids['direct_fingerprint']) {
            return true;
        }
        if ($this->opportunitiesChangedSince((int) ($previous['opportunities'] ?? 0), $ids['opportunities'])) {
            return true;
        }

        return $this->interestsChangedSince((int) ($previous['interests'] ?? 0), $ids['interests']);
    }

    public function attributionReady(array $ids): ?ReportSyncRun
    {
        $build = $this->latest(self::ATTRIBUTION_DATASET, self::SOURCE);
        if ($build?->status !== 'completed' || $this->needsAttribution($ids)) {
            return null;
        }

        return $build;
    }

    /** @return array{ready:bool, cutoff:?CarbonImmutable, reason:?string} */
    public function published(?CarbonInterface $start = null, ?CarbonInterface $end = null, ?array $filters = null): array
    {
        $sources = $this->sources($start, $end);
        if (! $sources['ready']) {
            return ['ready' => false, 'cutoff' => null, 'reason' => $sources['reason']];
        }
        $build = $this->attributionReady($sources['ids']);
        $publication = $this->latest(self::PUBLICATION_DATASET, self::SOURCE);
        $publishedIds = $publication?->stats['source_ids'] ?? [];
        $samePublishedSources = is_array($publishedIds)
            && (int) ($publishedIds['meta'] ?? 0) === $sources['ids']['meta']
            && (int) ($publishedIds['google'] ?? 0) === $sources['ids']['google']
            && (int) ($publishedIds['opportunities'] ?? 0) === $sources['ids']['opportunities']
            && ($publishedIds['direct_fingerprint'] ?? null) === $sources['ids']['direct_fingerprint'];
        $snapshot = $publication?->status === 'completed'
            ? DB::table('campaign_report_snapshots')->useWritePdo()->where('id', $publication->stats['snapshot_id'] ?? 0)->first(['period_start', 'period_end', 'filters_hash'])
            : null;
        $snapshotCovers = $start === null || $end === null || ($snapshot !== null
            && CarbonImmutable::parse($snapshot->period_start)->toDateString() <= CarbonImmutable::parse($start)->setTimezone('Europe/Madrid')->toDateString()
            && CarbonImmutable::parse($snapshot->period_end)->toDateString() >= CarbonImmutable::parse($end)->setTimezone('Europe/Madrid')->subDay()->toDateString());
        if ($build === null || $publication?->status !== 'completed'
            || (int) ($publication->stats['attribution_run_id'] ?? 0) !== (int) $build->id
            || ! $samePublishedSources
            || $snapshot === null || ! $snapshotCovers
            || ($filters !== null && ! hash_equals((string) $snapshot->filters_hash, self::filtersHash($filters)))
            || ($start !== null && $end !== null && ! $this->attributionCovers($build, $start, $end))) {
            return ['ready' => false, 'cutoff' => null, 'reason' => 'La publicación no incorpora todas las dependencias verificadas.'];
        }

        return ['ready' => true, 'cutoff' => $sources['cutoff'], 'reason' => null];
    }

    public function latestAttribution(): ?ReportSyncRun
    {
        return $this->latest(self::ATTRIBUTION_DATASET, self::SOURCE);
    }

    public static function filtersHash(array $filters): string
    {
        unset($filters['start_date'], $filters['end_date']);

        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }

    public function attributionCovers(ReportSyncRun $build, CarbonInterface $start, CarbonInterface $end): bool
    {
        $localStart = CarbonImmutable::parse($start)->setTimezone('Europe/Madrid');
        $localEnd = CarbonImmutable::parse($end)->setTimezone('Europe/Madrid');
        $openStart = $this->openWindow->start($localStart, $localEnd);

        return $openStart !== null
            && ($build->stats['range_start'] ?? '') <= $openStart->toDateString()
            && ($build->stats['range_end_exclusive'] ?? '') >= $this->requiredSourceEndDate($localEnd);
    }

    private function requiredSourceEndDate(CarbonInterface $end): string
    {
        // The running day is explicitly partial; later dates require complete-day coverage.
        return min($end->toDateString(), CarbonImmutable::now('Europe/Madrid')->toDateString());
    }

    private function latest(string $dataset, string $source): ?ReportSyncRun
    {
        if ($dataset === SalesforceInterestSyncService::DATASET) {
            try {
                return app(InterestGenerationReader::class)->f2();
            } catch (InterestDatasetUnavailable) {
                return null; // Keep the existing blocked-readiness contract without adopting legacy data.
            }
        }

        return ReportSyncRun::query()->useWritePdo()->where('dataset', $dataset)->where('source', $source)->orderByDesc('id')->first();
    }

    /** @return array{ready:false, reason:string, retry_task:?string, ids:array{}, cutoff:null} */
    private function blocked(string $reason, ?string $task = null): array
    {
        return ['ready' => false, 'reason' => $reason, 'retry_task' => $task, 'ids' => [], 'cutoff' => null];
    }

    private function interestsChangedSince(int $previousId, int $currentId): bool
    {
        if ($previousId <= 0 || $currentId < $previousId) {
            return true;
        }
        if ($previousId === $currentId) {
            return false;
        }

        $changed = false;
        ReportSyncRun::query()->useWritePdo()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->whereIn('id', DB::table('salesforce_interest_generations')->select('interest_run_id')->where('status', 'published'))
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->where('id', '>', $previousId)
            ->where('id', '<=', $currentId)
            ->orderBy('id')
            ->chunkById(200, function ($runs) use (&$changed): bool {
                foreach ($runs as $run) {
                    if ($run->status !== 'completed') {
                        $changed = true;

                        return false;
                    }
                    $stats = $run->stats ?? [];
                    if (! isset($stats['inserted'], $stats['updated'], $stats['reactivated'])) {
                        $changed = true;

                        return false;
                    }
                    if ((int) $stats['inserted'] + (int) $stats['updated'] + (int) $stats['reactivated'] > 0) {
                        $changed = true;

                        return false;
                    }
                }

                return true;
            });

        return $changed;
    }

    private function opportunitiesChangedSince(int $previousId, int $currentId): bool
    {
        if ($previousId <= 0 || $currentId < $previousId) {
            return true;
        }
        if ($previousId === $currentId) {
            return false;
        }

        $changed = false;
        ReportSyncRun::query()->useWritePdo()
            ->where('dataset', 'salesforce_opportunities')->where('source', 'salesforce')
            ->where('id', '>', $previousId)->where('id', '<=', $currentId)
            ->chunkById(200, function ($runs) use (&$changed): bool {
                foreach ($runs as $run) {
                    $stats = $run->stats ?? [];
                    if ($run->status !== 'completed' || ! isset($stats['material_changed'], $stats['deleted_changed'])
                        || (int) $stats['material_changed'] + (int) $stats['deleted_changed'] > 0) {
                        $changed = true;

                        return false;
                    }
                }

                return true;
            });

        return $changed;
    }
}
