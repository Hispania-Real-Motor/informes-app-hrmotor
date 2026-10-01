<?php

namespace App\Services\Analytics\Executive;

use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use Carbon\CarbonImmutable;

final class ExecutiveDailyDatasetService
{
    public const TIMEZONE = 'Europe/Madrid';

    private const REFERENCE_OFFSETS = [
        'd7' => 7,
        'd14' => 14,
        'd21' => 21,
        'd28' => 28,
        'd364' => 364,
    ];

    public function __construct(
        private readonly SalesforceLeadDashboardDatasetService $leads,
        private readonly ReservationsSalesDashboardDatasetService $reservationsSales,
    ) {}

    /** @return array<string, mixed> */
    public function build(?CarbonImmutable $now = null): array
    {
        $execution = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $cutoffDate = $execution->subDay()->toDateString();
        $cutoffStart = CarbonImmutable::parse($cutoffDate, self::TIMEZONE)->startOfDay();
        $cutoffEndExclusive = $cutoffStart->addDay();
        $mtdStart = $cutoffStart->startOfMonth();

        $metrics = [
            'leads' => $this->metric('leads', $cutoffStart, $cutoffEndExclusive, $mtdStart),
            'reservas' => $this->metric('reservas', $cutoffStart, $cutoffEndExclusive, $mtdStart),
            'ventas' => $this->metric('ventas', $cutoffStart, $cutoffEndExclusive, $mtdStart),
        ];

        return [
            'cutoff' => [
                'date' => $cutoffDate,
                'timezone' => self::TIMEZONE,
                'day_complete' => collect($metrics)->every(fn (array $metric): bool => $metric['day_complete'] === true),
                'start_inclusive' => $cutoffStart->toIso8601String(),
                'end_exclusive' => $cutoffEndExclusive->toIso8601String(),
            ],
            'mtd_period' => [
                'start_inclusive' => $mtdStart->toIso8601String(),
                'end_exclusive' => $cutoffEndExclusive->toIso8601String(),
                'timezone' => self::TIMEZONE,
            ],
            'metrics' => $metrics,
        ];
    }

    /** @return array<string, mixed> */
    private function metric(
        string $metric,
        CarbonImmutable $cutoffStart,
        CarbonImmutable $cutoffEndExclusive,
        CarbonImmutable $mtdStart,
    ): array {
        $current = $this->sample($metric, $cutoffStart, $cutoffEndExclusive);
        $references = [];
        foreach (self::REFERENCE_OFFSETS as $key => $offset) {
            $referenceStart = $cutoffStart->subDays($offset);
            $references[$key] = $this->sample($metric, $referenceStart, $referenceStart->addDay());
        }
        $mtd = $this->sample($metric, $mtdStart, $cutoffEndExclusive);
        $weeklyReferences = [
            'd7' => $references['d7']['value'],
            'd14' => $references['d14']['value'],
            'd21' => $references['d21']['value'],
            'd28' => $references['d28']['value'],
            'd364' => $references['d364']['value'],
        ];
        $requiredSamples = [
            $current,
            $references['d7'],
            $references['d14'],
            $references['d21'],
            $references['d28'],
        ];
        $dataIncident = collect($requiredSamples)->contains(fn (array $sample): bool => $sample['data_incident']);
        $dayComplete = ! $dataIncident
            && collect($requiredSamples)->every(fn (array $sample): bool => $sample['complete']);
        $health = $this->health($requiredSamples, $dataIncident);

        return [
            'metric_key' => $metric,
            'current' => $current['value'],
            'references' => $weeklyReferences,
            'mtd' => $mtd['value'],
            'data_health' => $health,
            'day_complete' => $dayComplete,
            'data_incident' => $dataIncident,
            'coverage' => [
                'current' => $this->coveragePayload($current),
                'references' => collect($references)->map(fn (array $sample): array => $this->coveragePayload($sample))->all(),
                'mtd' => $this->coveragePayload($mtd),
            ],
            'source_cutoff' => $current['source_cutoff'],
            'engine_input' => [
                'metric_key' => $metric,
                'current' => $current['value'],
                'references' => $weeklyReferences,
                'config' => ExecutiveMetricRulesEngine::defaultMetricConfigs()[$metric],
                'data_health' => $health,
                'day_complete' => $dayComplete,
                'data_incident' => $dataIncident,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sample(string $metric, CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $raw = $metric === 'leads'
            ? $this->leadSample($start, $endExclusive)
            : $this->reservationSalesSample($metric, $start, $endExclusive);
        $sourceCutoff = $this->sourceCutoff($raw['source_cutoff']);
        $complete = $sourceCutoff !== null && $sourceCutoff->greaterThanOrEqualTo($endExclusive);
        $incident = (bool) ($raw['data_incident'] ?? false)
            || $this->syncRunIncident($raw['source_cutoff']);

        return [
            'date' => $start->toDateString(),
            'start_inclusive' => $start->toIso8601String(),
            'end_exclusive' => $endExclusive->toIso8601String(),
            'value' => $complete && ! $incident ? (int) $raw['value'] : null,
            'raw_value' => (int) $raw['value'],
            'complete' => $complete,
            'data_incident' => $incident,
            'source_cutoff' => $raw['source_cutoff'],
            'coverage' => $raw['coverage'],
        ];
    }

    /** @return array<string, mixed> */
    private function leadSample(CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $endInclusive = $endExclusive->subSecond();
        $sample = $this->leads->executiveLeadTotal($start, $endInclusive);

        return [
            'value' => $sample['value'],
            'source_cutoff' => $sample['source_cutoff'],
            'coverage' => $sample['coverage'],
            'data_incident' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function reservationSalesSample(string $metric, CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $sample = $this->reservationsSales->executiveProduction($start, $endExclusive);
        $qualityIncidents = collect($sample['data_quality']['incidents'] ?? [])
            ->where('breakdown_status', 'data_quality_incident')
            ->values();

        return [
            'value' => $sample[$metric],
            'source_cutoff' => $sample['source_cutoff'],
            'coverage' => [
                'duplicate_event_groups' => $sample['data_quality']['duplicate_event_groups'] ?? 0,
                'incident_count' => $qualityIncidents->count(),
            ],
            'data_incident' => $qualityIncidents->isNotEmpty(),
        ];
    }

    private function sourceCutoff(array $sourceCutoff): ?CarbonImmutable
    {
        $value = $sourceCutoff['dataset_cutoff_at'] ?? null;
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, self::TIMEZONE)->setTimezone(self::TIMEZONE);
    }

    /** @param  array<int, array<string, mixed>>  $requiredSamples */
    private function health(array $requiredSamples, bool $dataIncident): string
    {
        if ($dataIncident) {
            return ExecutiveMetricRulesEngine::HEALTH_INCIDENT;
        }

        if (collect($requiredSamples)->contains(fn (array $sample): bool => $this->sourceCutoff($sample['source_cutoff']) === null)) {
            return ExecutiveMetricRulesEngine::HEALTH_PARTIAL;
        }

        if (collect($requiredSamples)->contains(fn (array $sample): bool => ! $sample['complete'])) {
            return ExecutiveMetricRulesEngine::HEALTH_STALE;
        }

        return ExecutiveMetricRulesEngine::HEALTH_UPDATED;
    }

    private function syncRunIncident(array $sourceCutoff): bool
    {
        $status = $sourceCutoff['sync_run_status'] ?? null;

        return filled($status) && $status !== 'completed';
    }

    /** @return array<string, mixed> */
    private function coveragePayload(array $sample): array
    {
        return [
            'date' => $sample['date'],
            'start_inclusive' => $sample['start_inclusive'],
            'end_exclusive' => $sample['end_exclusive'],
            'complete' => $sample['complete'],
            'data_incident' => $sample['data_incident'],
            'raw_value' => $sample['raw_value'],
            'details' => $sample['coverage'],
            'source_cutoff' => $sample['source_cutoff'],
        ];
    }
}
