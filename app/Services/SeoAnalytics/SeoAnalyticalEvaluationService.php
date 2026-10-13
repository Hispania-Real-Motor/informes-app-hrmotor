<?php

namespace App\Services\SeoAnalytics;

use App\Models\AnalyticalMetricEvaluation;
use App\Models\AnalyticalMetricSnapshot;
use App\Models\AnalyticalRuleSet;
use App\Services\Analytics\AnalyticalEvaluationEngine;
use App\Services\Analytics\AnalyticalSnapshotFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAnalyticalEvaluationService
{
    public const DATASET = 'seo_analytical_evaluations';

    public const SOURCE = 'local_database';

    public function __construct(
        private readonly AnalyticalEvaluationEngine $engine,
        private readonly AnalyticalSnapshotFingerprint $fingerprints,
        private readonly SeoAnalyticalRuleSetResolver $ruleSets,
        private readonly SeoAnalyticalSnapshotScope $scope,
    ) {}

    /** @return array<string, int|string|null> */
    public function evaluate(int $days = 1, ?AnalyticalRuleSet $ruleSet = null): array
    {
        if ($days < 1 || $days > 90) {
            throw new RuntimeException('Los dias de evaluacion deben estar entre 1 y 90.');
        }

        $ruleSet ??= $this->ruleSets->active();
        $this->ruleSets->assertComplete($ruleSet);
        if ($days > 1 && $ruleSet->version_number > 1) {
            throw new RuntimeException('El backfill historico solo esta permitido mientras seo_rules_v1 es la unica version activa.');
        }

        $metricKeys = $ruleSet->rules->pluck('metric_key')->all();
        $snapshots = $this->snapshots($days, $metricKeys);
        $rules = $ruleSet->rules->keyBy('metric_key');
        $now = now();
        $rows = [];
        foreach ($snapshots as $snapshot) {
            $rule = $rules->get($snapshot->metric_key);
            if (! $rule) {
                throw new RuntimeException('Falta una regla SEO para '.$snapshot->metric_key.'.');
            }

            $evaluation = $this->engine->evaluate($snapshot->toArray(), $rule->toArray());
            $rows[] = [
                'analytical_metric_snapshot_id' => $snapshot->id,
                'analytical_rule_set_id' => $ruleSet->id,
                'analytical_metric_rule_id' => $rule->id,
                'module_key' => SeoAnalyticalMetricRegistry::MODULE,
                'metric_key' => $snapshot->metric_key,
                'data_date' => $snapshot->data_date->toDateString(),
                'evaluated_current_value' => $snapshot->current_value,
                'evaluated_baseline_value' => $snapshot->baseline_value,
                'evaluated_absolute_change' => $snapshot->absolute_change,
                'evaluated_relative_change' => $snapshot->relative_change,
                'evaluated_snapshot_is_evaluable' => $snapshot->is_evaluable,
                'evaluated_snapshot_reason' => $snapshot->evaluation_reason,
                'evaluated_snapshot_fingerprint' => $this->fingerprints->hash($snapshot->toArray()),
                'status' => $evaluation['status'],
                'direction' => $evaluation['direction'],
                'magnitude_band' => $evaluation['magnitude_band'],
                'reason_code' => $evaluation['reason_code'],
                'evaluated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows): void {
                AnalyticalMetricEvaluation::query()->upsert(
                    $rows,
                    ['analytical_metric_snapshot_id', 'analytical_rule_set_id'],
                    [
                        'analytical_metric_rule_id', 'module_key', 'metric_key', 'data_date',
                        'evaluated_current_value', 'evaluated_baseline_value', 'evaluated_absolute_change',
                        'evaluated_relative_change', 'evaluated_snapshot_is_evaluable',
                        'evaluated_snapshot_reason', 'evaluated_snapshot_fingerprint', 'status',
                        'direction', 'magnitude_band', 'reason_code', 'evaluated_at', 'updated_at',
                    ],
                );
            });
        }

        $counts = collect($rows)->countBy('status');

        return [
            'rule_version' => $ruleSet->version_key,
            'snapshots_evaluated' => count($rows),
            'ok_count' => (int) $counts->get('ok', 0),
            'observation_count' => (int) $counts->get('observation', 0),
            'deviation_count' => (int) $counts->get('deviation', 0),
            'critical_count' => (int) $counts->get('critical', 0),
            'not_evaluable_count' => (int) $counts->get('not-evaluable', 0),
            'max_data_date' => $snapshots->max(fn (AnalyticalMetricSnapshot $snapshot): string => $snapshot->data_date->toDateString()),
        ];
    }

    /** @return Collection<int, AnalyticalMetricSnapshot> */
    /** @param array<int, string> $metricKeys */
    private function snapshots(int $days, array $metricKeys): Collection
    {
        $latestDates = AnalyticalMetricSnapshot::query()
            ->selectRaw('metric_key, MAX(data_date) as max_data_date')
            ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->whereIn('metric_key', $metricKeys)
            ->where(fn (Builder $query) => $this->scope->apply($query))
            ->groupBy('metric_key')
            ->pluck('max_data_date', 'metric_key');
        if ($latestDates->isEmpty()) {
            return collect();
        }

        $starts = $latestDates->map(
            fn (string $date): string => CarbonImmutable::parse($date)->subDays($days - 1)->toDateString()
        );
        $snapshots = AnalyticalMetricSnapshot::query()
            ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->whereIn('metric_key', $metricKeys)
            ->where(fn (Builder $query) => $this->scope->apply($query))
            ->whereDate('data_date', '>=', $starts->min())
            ->whereDate('data_date', '<=', $latestDates->max())
            ->get();

        return $snapshots->filter(function (AnalyticalMetricSnapshot $snapshot) use ($latestDates, $starts): bool {
            $date = $snapshot->data_date->toDateString();

            return isset($latestDates[$snapshot->metric_key])
                && $date >= $starts[$snapshot->metric_key]
                && $date <= $latestDates[$snapshot->metric_key];
        })->values();
    }
}
