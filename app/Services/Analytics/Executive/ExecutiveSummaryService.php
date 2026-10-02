<?php

namespace App\Services\Analytics\Executive;

use Carbon\CarbonImmutable;

final class ExecutiveSummaryService
{
    private const METRIC_LABELS = [
        'leads' => 'Leads',
        'reservas' => 'Reservas',
        'ventas' => 'Ventas',
    ];

    private const STATUS_PRESENTATION = [
        ExecutiveMetricRulesEngine::STATUS_CORRECT => ['label' => 'Correcto', 'state' => 'ok', 'severity' => 1],
        ExecutiveMetricRulesEngine::STATUS_ATTENTION => ['label' => 'Atención', 'state' => 'observation', 'severity' => 2],
        ExecutiveMetricRulesEngine::STATUS_DEVIATION => ['label' => 'Desviación', 'state' => 'deviation', 'severity' => 3],
        ExecutiveMetricRulesEngine::STATUS_CRITICAL => ['label' => 'Crítico', 'state' => 'critical', 'severity' => 4],
        ExecutiveMetricRulesEngine::STATUS_NOT_EVALUABLE => ['label' => 'No evaluable', 'state' => 'not-evaluable', 'severity' => 0],
    ];

    private const DIRECTION_PRESENTATION = [
        ExecutiveMetricRulesEngine::DIRECTION_FAVORABLE => ['label' => 'Favorable', 'rank' => 1],
        ExecutiveMetricRulesEngine::DIRECTION_STABLE => ['label' => 'Estable', 'rank' => 2],
        ExecutiveMetricRulesEngine::DIRECTION_UNFAVORABLE => ['label' => 'Desfavorable', 'rank' => 0],
        ExecutiveMetricRulesEngine::DIRECTION_NOT_EVALUABLE => ['label' => 'No evaluable', 'rank' => 3],
    ];

    private const HEALTH_PRESENTATION = [
        ExecutiveMetricRulesEngine::HEALTH_UPDATED => ['label' => 'Actualizado', 'state' => 'ok'],
        ExecutiveMetricRulesEngine::HEALTH_PARTIAL => ['label' => 'Parcial', 'state' => 'observation'],
        ExecutiveMetricRulesEngine::HEALTH_STALE => ['label' => 'Desactualizado', 'state' => 'deviation'],
        ExecutiveMetricRulesEngine::HEALTH_INCIDENT => ['label' => 'Incidencia', 'state' => 'critical'],
    ];

    public function __construct(
        private readonly ExecutiveDailyDatasetService $dailyDataset,
        private readonly ExecutiveMetricRulesEngine $rulesEngine,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return $this->compose($this->dailyDataset->build());
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public function compose(array $dataset): array
    {
        $metrics = [];
        foreach (array_keys(self::METRIC_LABELS) as $metricKey) {
            $metricDataset = $dataset['metrics'][$metricKey] ?? [];
            $evaluation = $this->rulesEngine->evaluate($metricDataset['engine_input'] ?? [
                'metric_key' => $metricKey,
                'current' => null,
                'references' => [],
                'config' => ExecutiveMetricRulesEngine::defaultMetricConfigs()[$metricKey],
                'data_health' => ExecutiveMetricRulesEngine::HEALTH_PARTIAL,
                'day_complete' => false,
                'data_incident' => false,
            ]);

            $metrics[$metricKey] = $this->metricPayload($metricKey, $metricDataset, $evaluation);
        }

        return [
            'cutoff' => $this->cutoffPayload($dataset['cutoff'] ?? []),
            'mtd_period' => $this->periodPayload($dataset['mtd_period'] ?? []),
            'metrics' => $metrics,
            'alerts' => $this->alerts($metrics),
            'data_health_summary' => $this->dataHealthSummary($metrics),
            'meta' => [
                'rule_version' => ExecutiveMetricRulesEngine::RULE_VERSION,
                'metric_count' => count($metrics),
                'generated_at' => CarbonImmutable::now(ExecutiveDailyDatasetService::TIMEZONE)->toIso8601String(),
                'source' => 'executive_daily_dataset_v1',
            ],
        ];
    }

    /** @param array<string, mixed> $dataset
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function metricPayload(string $metricKey, array $dataset, array $evaluation): array
    {
        $status = $this->statusPresentation((string) $evaluation['status']);
        $direction = $this->directionPresentation((string) $evaluation['direction']);
        $health = $this->healthPresentation((string) $evaluation['data_health']);

        return [
            'metric_key' => $metricKey,
            'label' => self::METRIC_LABELS[$metricKey] ?? ucfirst($metricKey),
            'current' => $evaluation['current'],
            'baseline' => $evaluation['baseline'],
            'mtd' => $dataset['mtd'] ?? null,
            'references' => $evaluation['references'],
            'd364_reference' => $evaluation['d364_reference'],
            'variation_percent' => $evaluation['variation_percent'],
            'magnitude_variation_percent' => $evaluation['magnitude_variation_percent'],
            'absolute_difference' => $evaluation['absolute_difference'],
            'evaluable' => $evaluation['evaluable'],
            'business_alert' => $evaluation['business_alert'],
            'data_incident' => (bool) ($dataset['data_incident'] ?? false),
            'day_complete' => (bool) ($dataset['day_complete'] ?? false),
            'status' => $status,
            'direction' => $direction,
            'data_health' => $health,
            'reason_summaries' => $this->reasonSummaries($evaluation['reason_codes'] ?? []),
            'confirmed_cause' => $evaluation['confirmed_cause'] ?? null,
            'possible_cause_to_review' => $evaluation['possible_cause_to_review'] ?? null,
            'recommended_action_key' => $evaluation['recommended_action_key'] ?? null,
            'display' => [
                'current' => $this->number($evaluation['current']),
                'baseline' => $this->decimal($evaluation['baseline']),
                'mtd' => $this->number($dataset['mtd'] ?? null),
                'd364_reference' => $this->number($evaluation['d364_reference']),
                'variation_percent' => $this->percent($evaluation['variation_percent']),
                'absolute_difference' => $this->decimal($evaluation['absolute_difference']),
            ],
            'coverage' => [
                'current' => $this->coveragePayload($dataset['coverage']['current'] ?? []),
                'references' => collect($dataset['coverage']['references'] ?? [])->map(fn (array $sample): array => $this->coveragePayload($sample))->all(),
                'mtd' => $this->coveragePayload($dataset['coverage']['mtd'] ?? []),
            ],
        ];
    }

    /** @param array<string, mixed> $metrics
     * @return array<int, array<string, mixed>>
     */
    private function alerts(array $metrics): array
    {
        return collect($metrics)
            ->filter(fn (array $metric): bool => $metric['business_alert'] === true)
            ->sort(function (array $a, array $b): int {
                return [$b['status']['severity'], $a['direction']['rank'], (float) $b['absolute_difference'], $a['metric_key']]
                    <=> [$a['status']['severity'], $b['direction']['rank'], (float) $a['absolute_difference'], $b['metric_key']];
            })
            ->take(5)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function dataHealthSummary(array $metrics): array
    {
        $healthCounts = collect($metrics)->countBy(fn (array $metric): string => $metric['data_health']['key'])->all();
        $incidentCount = (int) ($healthCounts[ExecutiveMetricRulesEngine::HEALTH_INCIDENT] ?? 0);
        $partialCount = (int) ($healthCounts[ExecutiveMetricRulesEngine::HEALTH_PARTIAL] ?? 0);
        $staleCount = (int) ($healthCounts[ExecutiveMetricRulesEngine::HEALTH_STALE] ?? 0);
        $allUpdated = $incidentCount === 0 && $partialCount === 0 && $staleCount === 0;

        return [
            'all_evaluable' => collect($metrics)->every(fn (array $metric): bool => $metric['evaluable'] === true),
            'all_data_updated' => $allUpdated,
            'has_incidents' => $incidentCount > 0,
            'has_partial' => $partialCount > 0,
            'has_stale' => $staleCount > 0,
            'headline' => match (true) {
                $incidentCount > 0 => 'Con incidencias de datos',
                $partialCount > 0 => 'Parcialmente evaluable',
                $staleCount > 0 => 'Con datos desactualizados',
                default => 'Completamente evaluable',
            },
            'counts' => [
                ExecutiveMetricRulesEngine::HEALTH_UPDATED => (int) ($healthCounts[ExecutiveMetricRulesEngine::HEALTH_UPDATED] ?? 0),
                ExecutiveMetricRulesEngine::HEALTH_PARTIAL => $partialCount,
                ExecutiveMetricRulesEngine::HEALTH_STALE => $staleCount,
                ExecutiveMetricRulesEngine::HEALTH_INCIDENT => $incidentCount,
            ],
        ];
    }

    /** @param array<string, mixed> $cutoff
     * @return array<string, mixed>
     */
    private function cutoffPayload(array $cutoff): array
    {
        $date = (string) ($cutoff['date'] ?? '');

        return $cutoff + [
            'display_date' => $this->date($date),
            'display_label' => filled($date) ? 'Último día cerrado: '.$this->date($date) : 'Último día cerrado no disponible',
        ];
    }

    /** @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    private function periodPayload(array $period): array
    {
        $start = $this->dateFromIso((string) ($period['start_inclusive'] ?? ''));
        $end = $this->dateFromIso((string) ($period['end_exclusive'] ?? ''), subtractDay: true);

        return $period + [
            'display_range' => $start !== null && $end !== null ? $start.' - '.$end : 'Periodo MTD no disponible',
        ];
    }

    /** @return array{key: string, label: string, state: string, severity: int} */
    private function statusPresentation(string $status): array
    {
        $presentation = self::STATUS_PRESENTATION[$status] ?? self::STATUS_PRESENTATION[ExecutiveMetricRulesEngine::STATUS_NOT_EVALUABLE];

        return ['key' => $status] + $presentation;
    }

    /** @return array{key: string, label: string, rank: int} */
    private function directionPresentation(string $direction): array
    {
        $presentation = self::DIRECTION_PRESENTATION[$direction] ?? self::DIRECTION_PRESENTATION[ExecutiveMetricRulesEngine::DIRECTION_NOT_EVALUABLE];

        return ['key' => $direction] + $presentation;
    }

    /** @return array{key: string, label: string, state: string} */
    private function healthPresentation(string $health): array
    {
        $presentation = self::HEALTH_PRESENTATION[$health] ?? self::HEALTH_PRESENTATION[ExecutiveMetricRulesEngine::HEALTH_PARTIAL];

        return ['key' => $health] + $presentation;
    }

    /** @param array<int, string> $reasonCodes
     * @return array<int, string>
     */
    private function reasonSummaries(array $reasonCodes): array
    {
        return collect($reasonCodes)
            ->map(function (string $code): string {
                $base = str_contains($code, ':') ? str($code)->before(':')->toString() : $code;

                return match ($base) {
                    ExecutiveMetricRulesEngine::REASON_MISSING_CURRENT => 'Falta el dato del dia evaluado.',
                    ExecutiveMetricRulesEngine::REASON_MISSING_WEEKLY_REFERENCE => 'Falta una referencia semanal obligatoria.',
                    ExecutiveMetricRulesEngine::REASON_INSUFFICIENT_BASELINE => 'El baseline no alcanza el mínimo evaluable.',
                    ExecutiveMetricRulesEngine::REASON_INCOMPLETE_DAY => 'La cobertura del día no es completa.',
                    ExecutiveMetricRulesEngine::REASON_DATA_INCIDENT => 'Existe una incidencia de calidad o sincronización.',
                    ExecutiveMetricRulesEngine::REASON_ZERO_CURRENT => 'El valor actual es cero con baseline evaluable.',
                    ExecutiveMetricRulesEngine::REASON_NEGATIVE_VALUE => 'Hay valores negativos no válidos para métricas de conteo.',
                    default => 'Evaluación ejecutiva normal.',
                };
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $coverage
     * @return array<string, mixed>
     */
    private function coveragePayload(array $coverage): array
    {
        return [
            'date' => $coverage['date'] ?? null,
            'complete' => (bool) ($coverage['complete'] ?? false),
            'data_incident' => (bool) ($coverage['data_incident'] ?? false),
            'raw_value' => $coverage['raw_value'] ?? null,
        ];
    }

    private function number(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value, 0, ',', '.');
    }

    private function decimal(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value, 1, ',', '.');
    }

    private function percent(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value, 1, ',', '.').' %';
    }

    private function date(string $date): string
    {
        if (blank($date)) {
            return '-';
        }

        return CarbonImmutable::parse($date, ExecutiveDailyDatasetService::TIMEZONE)->format('d/m/Y');
    }

    private function dateFromIso(string $date, bool $subtractDay = false): ?string
    {
        if (blank($date)) {
            return null;
        }

        $parsed = CarbonImmutable::parse($date, ExecutiveDailyDatasetService::TIMEZONE);
        if ($subtractDay) {
            $parsed = $parsed->subDay();
        }

        return $parsed->format('d/m/Y');
    }
}
