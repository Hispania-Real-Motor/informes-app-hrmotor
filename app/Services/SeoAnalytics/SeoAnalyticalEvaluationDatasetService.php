<?php

namespace App\Services\SeoAnalytics;

use App\Models\AnalyticalMetricEvaluation;
use App\Models\AnalyticalMetricSnapshot;
use App\Services\Analytics\AnalyticalSnapshotFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SeoAnalyticalEvaluationDatasetService
{
    public function __construct(
        private readonly SeoAnalyticalSnapshotScope $scope,
        private readonly AnalyticalSnapshotFingerprint $fingerprints,
        private readonly SeoAnalyticalMetricRegistry $registry,
    ) {}

    /** @param Collection<int, AnalyticalMetricSnapshot> $snapshots
     * @return Collection<int, AnalyticalMetricEvaluation>
     */
    public function latestForSnapshots(Collection $snapshots): Collection
    {
        if ($snapshots->isEmpty()) {
            return collect();
        }

        return AnalyticalMetricEvaluation::query()
            ->whereIn('analytical_metric_snapshot_id', $snapshots->pluck('id'))
            ->with('ruleSet:id,version_number,version_key')
            ->get()
            ->sortByDesc(fn (AnalyticalMetricEvaluation $evaluation): int => $evaluation->ruleSet->version_number)
            ->unique('analytical_metric_snapshot_id')
            ->keyBy('analytical_metric_snapshot_id');
    }

    /** @return array<int, array<string, mixed>> */
    public function recentSignals(): array
    {
        $metricKeys = collect($this->registry->metrics())->pluck('key')->all();
        $latestDate = AnalyticalMetricSnapshot::query()
            ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->whereIn('metric_key', $metricKeys)
            ->where(fn (Builder $query) => $this->scope->apply($query))
            ->max('data_date');
        if (! $latestDate) {
            return [];
        }

        $start = CarbonImmutable::parse((string) $latestDate)->subDays(29)->toDateString();
        $evaluations = AnalyticalMetricEvaluation::query()
            ->from('analytical_metric_evaluations as evaluations')
            ->select('evaluations.*')
            ->join('analytical_metric_snapshots as snapshots', 'snapshots.id', '=', 'evaluations.analytical_metric_snapshot_id')
            ->join('analytical_rule_sets as rule_sets', 'rule_sets.id', '=', 'evaluations.analytical_rule_set_id')
            ->where('evaluations.module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->whereIn('evaluations.metric_key', $metricKeys)
            ->where('evaluations.status', '!=', 'ok')
            ->whereDate('evaluations.data_date', '>=', $start)
            ->where(fn (Builder $query) => $this->scope->apply($query, 'snapshots.'))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('analytical_metric_evaluations as newer_evaluations')
                    ->join('analytical_rule_sets as newer_rule_sets', 'newer_rule_sets.id', '=', 'newer_evaluations.analytical_rule_set_id')
                    ->whereColumn('newer_evaluations.analytical_metric_snapshot_id', 'evaluations.analytical_metric_snapshot_id')
                    ->whereColumn('newer_rule_sets.version_number', '>', 'rule_sets.version_number');
            })
            ->with(['snapshot', 'ruleSet:id,version_number,version_key'])
            ->orderByDesc('evaluations.data_date')
            ->orderByRaw("CASE evaluations.status WHEN 'critical' THEN 1 WHEN 'deviation' THEN 2 WHEN 'observation' THEN 3 ELSE 4 END")
            ->limit(50)
            ->get();

        return $evaluations->map(fn (AnalyticalMetricEvaluation $evaluation): array => [
            'data_date' => $evaluation->data_date->toDateString(),
            'metric' => $evaluation->snapshot->metric_label,
            'status' => $evaluation->status,
            'direction' => $evaluation->direction,
            'direction_label' => $this->directionLabel($evaluation->direction),
            'current' => $this->formatSource($evaluation->evaluated_current_value, $evaluation->snapshot->value_format),
            'baseline' => $this->formatDerived($evaluation->evaluated_baseline_value, $evaluation->snapshot->value_format),
            'variation' => $this->formatPercent($evaluation->evaluated_relative_change),
            'rule_version' => $evaluation->ruleSet->version_key,
            'reading' => $this->reading($evaluation),
        ])->all();
    }

    public function matchesSnapshot(
        AnalyticalMetricEvaluation $evaluation,
        AnalyticalMetricSnapshot $snapshot,
    ): bool {
        $storedFingerprint = $evaluation->evaluated_snapshot_fingerprint;
        if (! is_string($storedFingerprint) || strlen($storedFingerprint) !== 64) {
            return false;
        }

        return hash_equals(
            $storedFingerprint,
            $this->fingerprints->hash($snapshot->toArray()),
        );
    }

    public function reading(?AnalyticalMetricEvaluation $evaluation): string
    {
        if (! $evaluation) {
            return 'Pendiente de evaluación.';
        }
        if ($evaluation->direction === 'favorable' && $evaluation->status === 'observation') {
            return 'Oportunidad / posible anomalía.';
        }

        return match ($evaluation->status) {
            'ok' => 'Dentro del rango habitual.',
            'observation' => 'Cambio desfavorable a vigilar.',
            'deviation' => 'Desviación relevante frente a la referencia semanal.',
            'critical' => 'Deterioro muy superior al rango habitual.',
            default => match ($evaluation->reason_code) {
                'missing_current' => 'Dato actual no disponible.',
                'insufficient_history' => 'Sin histórico suficiente.',
                default => 'No existe información suficiente para evaluar.',
            },
        };
    }

    public function directionLabel(string $direction): string
    {
        return match ($direction) {
            'stable' => 'Estable',
            'favorable' => 'Favorable',
            'unfavorable' => 'Desfavorable',
            default => 'No evaluable',
        };
    }

    private function formatSource(?string $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'integer' => number_format((float) $value, 0, ',', '.'),
            'percent' => number_format((float) $value * 100, 2, ',', '.').'%',
            default => number_format((float) $value, 2, ',', '.'),
        };
    }

    private function formatDerived(?string $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'percent' => number_format((float) $value * 100, 2, ',', '.').'%',
            default => number_format((float) $value, 2, ',', '.'),
        };
    }

    private function formatPercent(?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        $numeric = (float) $value * 100;

        return ($numeric > 0 ? '+' : '').number_format($numeric, 2, ',', '.').'%';
    }
}
