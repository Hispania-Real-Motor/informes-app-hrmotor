<?php

namespace App\Services\Analytics\Executive;

use RuntimeException;

final class ExecutiveMetricRulesEngine
{
    public const RULE_VERSION = 'executive_metric_rules_v1';

    public const STATUS_CORRECT = 'correcto';

    public const STATUS_ATTENTION = 'atencion';

    public const STATUS_DEVIATION = 'desviacion';

    public const STATUS_CRITICAL = 'critico';

    public const STATUS_NOT_EVALUABLE = 'no_evaluable';

    public const DIRECTION_FAVORABLE = 'favorable';

    public const DIRECTION_STABLE = 'estable';

    public const DIRECTION_UNFAVORABLE = 'desfavorable';

    public const DIRECTION_NOT_EVALUABLE = 'no_evaluable';

    public const HEALTH_UPDATED = 'actualizado';

    public const HEALTH_PARTIAL = 'parcial';

    public const HEALTH_STALE = 'desactualizado';

    public const HEALTH_INCIDENT = 'incidencia';

    public const REASON_NORMAL_EVALUATION = 'exe_v1_normal_evaluation';

    public const REASON_MISSING_WEEKLY_REFERENCE = 'exe_v1_missing_weekly_reference';

    public const REASON_INSUFFICIENT_BASELINE = 'exe_v1_insufficient_baseline';

    public const REASON_INCOMPLETE_DAY = 'exe_v1_incomplete_day';

    public const REASON_DATA_INCIDENT = 'exe_v1_data_incident';

    public const REASON_ZERO_CURRENT = 'exe_v1_zero_current';

    public const REASON_NEGATIVE_VALUE = 'exe_v1_negative_value';

    public const REASON_MISSING_CURRENT = 'exe_v1_missing_current';

    private const REQUIRED_REFERENCES = ['d7', 'd14', 'd21', 'd28'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $metric = $this->metricKey($input);
        $health = $this->health($input['data_health'] ?? self::HEALTH_UPDATED);
        $current = $this->optionalNonNegativeNumber($input['current'] ?? null);
        $references = $this->references($input['references'] ?? []);
        $config = $this->config($input['config'] ?? []);
        $reasonCodes = [];

        if ($current === null) {
            $reasonCodes[] = self::REASON_MISSING_CURRENT;
        }

        foreach (self::REQUIRED_REFERENCES as $key) {
            if (! array_key_exists($key, $references) || $references[$key] === null) {
                $reasonCodes[] = self::REASON_MISSING_WEEKLY_REFERENCE.':'.$key;
            }
        }

        $hasNegative = $this->hasNegative($input['current'] ?? null)
            || $this->referencesContainNegative($input['references'] ?? []);
        if ($hasNegative) {
            $reasonCodes[] = self::REASON_NEGATIVE_VALUE;
        }

        if (($input['day_complete'] ?? true) !== true) {
            $reasonCodes[] = self::REASON_INCOMPLETE_DAY;
        }

        if ($health === self::HEALTH_INCIDENT || ($input['data_incident'] ?? false) === true) {
            $reasonCodes[] = self::REASON_DATA_INCIDENT;
        }

        $completeReferences = $this->completeWeeklyReferences($references);
        $baseline = $completeReferences === null
            ? null
            : array_sum($completeReferences) / count($completeReferences);

        if ($baseline !== null && $baseline < $config['minimum_baseline']) {
            $reasonCodes[] = self::REASON_INSUFFICIENT_BASELINE;
        }

        if ($reasonCodes !== []) {
            return $this->notEvaluableResult($metric, $health, $current, $references, $baseline, $reasonCodes);
        }

        $difference = abs($current - $baseline);
        $variationPercent = (($current - $baseline) / $baseline) * 100;
        $magnitudePercent = abs($variationPercent);
        $direction = match (true) {
            $current > $baseline => self::DIRECTION_FAVORABLE,
            $current < $baseline => self::DIRECTION_UNFAVORABLE,
            default => self::DIRECTION_STABLE,
        };

        if ($current == 0.0) {
            return $this->result(
                metric: $metric,
                evaluable: true,
                status: self::STATUS_CRITICAL,
                direction: self::DIRECTION_UNFAVORABLE,
                health: $health,
                current: $current,
                references: $references,
                baseline: $baseline,
                variationPercent: $variationPercent,
                magnitudePercent: $magnitudePercent,
                absoluteDifference: $difference,
                reasonCodes: [self::REASON_ZERO_CURRENT],
            );
        }

        $status = $this->status($magnitudePercent, $difference, $config);

        return $this->result(
            metric: $metric,
            evaluable: true,
            status: $status,
            direction: $direction,
            health: $health,
            current: $current,
            references: $references,
            baseline: $baseline,
            variationPercent: $variationPercent,
            magnitudePercent: $magnitudePercent,
            absoluteDifference: $difference,
            reasonCodes: [self::REASON_NORMAL_EVALUATION],
        );
    }

    /** @return array<string, mixed> */
    public static function metricConfig(float|int $minimumBaseline, float|int $attention, float|int $deviation, float|int $critical): array
    {
        return [
            'minimum_baseline' => $minimumBaseline,
            'absolute_gates' => [
                self::STATUS_ATTENTION => $attention,
                self::STATUS_DEVIATION => $deviation,
                self::STATUS_CRITICAL => $critical,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaultMetricConfigs(): array
    {
        return [
            'leads' => self::metricConfig(100, 30, 50, 100),
            'reservas' => self::metricConfig(5, 3, 5, 8),
            'ventas' => self::metricConfig(5, 3, 5, 8),
        ];
    }

    /** @param array<string, mixed> $input */
    private function metricKey(array $input): string
    {
        $metric = $input['metric_key'] ?? null;
        if (! is_string($metric) || trim($metric) === '') {
            throw new RuntimeException('La metrica ejecutiva requiere un identificador estable.');
        }

        return $metric;
    }

    private function health(mixed $health): string
    {
        if (! in_array($health, [self::HEALTH_UPDATED, self::HEALTH_PARTIAL, self::HEALTH_STALE, self::HEALTH_INCIDENT], true)) {
            throw new RuntimeException('La salud del dato ejecutivo no es valida.');
        }

        return $health;
    }

    /** @param array<string, mixed> $references
     * @return array<string, float|null>
     */
    private function references(array $references): array
    {
        $normalized = [];
        foreach ([...self::REQUIRED_REFERENCES, 'd364'] as $key) {
            if (array_key_exists($key, $references)) {
                $normalized[$key] = $this->optionalNonNegativeNumber($references[$key]);
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $config
     * @return array{minimum_baseline: float, absolute_gates: array<string, float>}
     */
    private function config(array $config): array
    {
        $gates = $config['absolute_gates'] ?? null;
        if (! is_array($gates)) {
            throw new RuntimeException('La configuracion ejecutiva requiere puertas absolutas.');
        }

        $minimumBaseline = $this->requiredNonNegativeNumber($config['minimum_baseline'] ?? null);
        $attention = $this->requiredNonNegativeNumber($gates[self::STATUS_ATTENTION] ?? null);
        $deviation = $this->requiredNonNegativeNumber($gates[self::STATUS_DEVIATION] ?? null);
        $critical = $this->requiredNonNegativeNumber($gates[self::STATUS_CRITICAL] ?? null);

        if ($minimumBaseline <= 0.0) {
            throw new RuntimeException('El baseline minimo ejecutivo debe ser mayor que cero.');
        }

        if ($attention > $deviation || $deviation > $critical) {
            throw new RuntimeException('Las puertas absolutas ejecutivas deben cumplir atencion <= desviacion <= critico.');
        }

        return [
            'minimum_baseline' => $minimumBaseline,
            'absolute_gates' => [
                self::STATUS_ATTENTION => $attention,
                self::STATUS_DEVIATION => $deviation,
                self::STATUS_CRITICAL => $critical,
            ],
        ];
    }

    private function status(float $magnitudePercent, float $absoluteDifference, array $config): string
    {
        $band = match (true) {
            $magnitudePercent > 40.0 => self::STATUS_CRITICAL,
            $magnitudePercent >= 25.0 => self::STATUS_DEVIATION,
            $magnitudePercent >= 15.0 => self::STATUS_ATTENTION,
            default => self::STATUS_CORRECT,
        };

        foreach ([self::STATUS_CRITICAL, self::STATUS_DEVIATION, self::STATUS_ATTENTION] as $candidate) {
            if ($this->severity($candidate) <= $this->severity($band)
                && $absoluteDifference >= $config['absolute_gates'][$candidate]) {
                return $candidate;
            }
        }

        return self::STATUS_CORRECT;
    }

    private function severity(string $status): int
    {
        return match ($status) {
            self::STATUS_ATTENTION => 1,
            self::STATUS_DEVIATION => 2,
            self::STATUS_CRITICAL => 3,
            default => 0,
        };
    }

    /** @param array<string, float|null> $references
     * @return array<int, float>|null
     */
    private function completeWeeklyReferences(array $references): ?array
    {
        $values = [];
        foreach (self::REQUIRED_REFERENCES as $key) {
            if (! array_key_exists($key, $references) || $references[$key] === null) {
                return null;
            }

            $values[] = $references[$key];
        }

        return $values;
    }

    private function optionalNonNegativeNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return $this->requiredNumber($value);
    }

    private function requiredNonNegativeNumber(mixed $value): float
    {
        $number = $this->requiredNumber($value);
        if ($number < 0.0) {
            throw new RuntimeException('La configuracion ejecutiva no admite valores negativos.');
        }

        return $number;
    }

    private function requiredNumber(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new RuntimeException('El valor ejecutivo no es numerico.');
        }

        if (! is_numeric($value)) {
            throw new RuntimeException('El valor ejecutivo no es numerico.');
        }

        return (float) $value;
    }

    private function hasNegative(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value)
            && (float) $value < 0.0;
    }

    private function referencesContainNegative(mixed $references): bool
    {
        if (! is_array($references)) {
            return false;
        }

        foreach ([...self::REQUIRED_REFERENCES, 'd364'] as $key) {
            if (array_key_exists($key, $references) && $this->hasNegative($references[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, float|null>  $references
     * @param  array<int, string>  $reasonCodes
     * @return array<string, mixed>
     */
    private function notEvaluableResult(
        string $metric,
        string $health,
        ?float $current,
        array $references,
        ?float $baseline,
        array $reasonCodes,
    ): array {
        return $this->result(
            metric: $metric,
            evaluable: false,
            status: self::STATUS_NOT_EVALUABLE,
            direction: self::DIRECTION_NOT_EVALUABLE,
            health: $health,
            current: $current,
            references: $references,
            baseline: $baseline,
            variationPercent: null,
            magnitudePercent: null,
            absoluteDifference: null,
            reasonCodes: array_values(array_unique($reasonCodes)),
        );
    }

    /**
     * @param  array<string, float|null>  $references
     * @param  array<int, string>  $reasonCodes
     * @return array<string, mixed>
     */
    private function result(
        string $metric,
        bool $evaluable,
        string $status,
        string $direction,
        string $health,
        ?float $current,
        array $references,
        ?float $baseline,
        ?float $variationPercent,
        ?float $magnitudePercent,
        ?float $absoluteDifference,
        array $reasonCodes,
    ): array {
        return [
            'rule_version' => self::RULE_VERSION,
            'metric_key' => $metric,
            'evaluable' => $evaluable,
            'business_alert' => $evaluable && $status !== self::STATUS_CORRECT && $health !== self::HEALTH_INCIDENT,
            'status' => $status,
            'direction' => $direction,
            'data_health' => $health,
            'current' => $current,
            'baseline' => $baseline,
            'references' => [
                'd7' => $references['d7'] ?? null,
                'd14' => $references['d14'] ?? null,
                'd21' => $references['d21'] ?? null,
                'd28' => $references['d28'] ?? null,
            ],
            'd364_reference' => $references['d364'] ?? null,
            'variation_percent' => $variationPercent,
            'magnitude_variation_percent' => $magnitudePercent,
            'absolute_difference' => $absoluteDifference,
            'reason_codes' => $reasonCodes,
            'confirmed_cause' => null,
            'possible_cause_to_review' => null,
            'recommended_action_key' => null,
        ];
    }
}
