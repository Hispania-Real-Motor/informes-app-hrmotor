<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE = 'seo';

    private const LEGACY_METRIC = 'salesforce_organic_leads';

    private const INTEREST_METRIC = 'salesforce_organic_interests';

    private const CHANGE_REASON = 'ROT-5: rotate Salesforce SEO reporting from Lead to Interest while preserving analytical thresholds.';

    private const LEGACY_KEYS = [
        'ga4_organic_key_events',
        'salesforce_organic_leads',
        'search_console_clicks',
        'search_console_ctr',
        'search_console_impressions',
        'search_console_position',
    ];

    private const INTEREST_KEYS = [
        'ga4_organic_key_events',
        'salesforce_organic_interests',
        'search_console_clicks',
        'search_console_ctr',
        'search_console_impressions',
        'search_console_position',
    ];

    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        DB::transaction(function (): void {
            $activeSets = DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            if ($activeSets->count() !== 1) {
                throw new RuntimeException('ROT-5 requires exactly one active SEO rule set.');
            }

            $active = $activeSets->first();
            $existing = DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->where('change_reason', self::CHANGE_REASON)
                ->first();
            if ($existing !== null) {
                $this->reactivateExisting($active, $existing);

                return;
            }

            $maximumVersion = (int) DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->max('version_number');
            if ((int) $active->version_number !== $maximumVersion) {
                throw new RuntimeException('ROT-5 cannot version a non-latest active SEO rule set.');
            }

            $rules = DB::table('analytical_metric_rules')
                ->where('rule_set_id', $active->id)
                ->orderBy('metric_key')
                ->get();
            $this->assertKeys($rules->pluck('metric_key')->all(), self::LEGACY_KEYS, 'legacy');

            $now = now();
            $newVersion = (int) $active->version_number + 1;
            $newRuleSetId = DB::table('analytical_rule_sets')->insertGetId([
                'module_key' => self::MODULE,
                'version_number' => $newVersion,
                'version_key' => 'seo_rules_v'.$newVersion,
                'status' => 'active',
                'change_reason' => self::CHANGE_REASON,
                'created_by_report_user_id' => null,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('analytical_metric_rules')->insert($rules->map(function (object $rule) use ($newRuleSetId, $now): array {
                return [
                    'rule_set_id' => $newRuleSetId,
                    'metric_key' => $rule->metric_key === self::LEGACY_METRIC
                        ? self::INTEREST_METRIC
                        : $rule->metric_key,
                    'comparison_mode' => $rule->comparison_mode,
                    'favorable_direction' => $rule->favorable_direction,
                    'threshold_unit' => $rule->threshold_unit,
                    'observation_threshold' => $rule->observation_threshold,
                    'deviation_threshold' => $rule->deviation_threshold,
                    'critical_threshold' => $rule->critical_threshold,
                    'minimum_baseline' => $rule->minimum_baseline,
                    'minimum_absolute_change' => $rule->minimum_absolute_change,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all());

            DB::table('analytical_rule_sets')->where('id', $active->id)->update([
                'status' => 'superseded',
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        DB::transaction(function (): void {
            $rotated = DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->where('change_reason', self::CHANGE_REASON)
                ->lockForUpdate()
                ->first();
            if ($rotated === null) {
                return;
            }
            if ($rotated->status !== 'active') {
                throw new RuntimeException('ROT-5 rollback refused because its SEO rule set is no longer active.');
            }

            $laterVersions = DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->where('version_number', '>', $rotated->version_number)
                ->exists();
            if ($laterVersions) {
                throw new RuntimeException('ROT-5 rollback refused because later SEO rule-set versions exist.');
            }

            $predecessor = DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->where('version_number', (int) $rotated->version_number - 1)
                ->first();
            if ($predecessor === null || $predecessor->status !== 'superseded') {
                throw new RuntimeException('ROT-5 rollback cannot identify its superseded predecessor safely.');
            }

            $this->assertKeys(
                DB::table('analytical_metric_rules')->where('rule_set_id', $rotated->id)->pluck('metric_key')->all(),
                self::INTEREST_KEYS,
                'Interest',
            );
            $this->assertKeys(
                DB::table('analytical_metric_rules')->where('rule_set_id', $predecessor->id)->pluck('metric_key')->all(),
                self::LEGACY_KEYS,
                'predecessor',
            );

            $now = now();
            DB::table('analytical_rule_sets')->where('id', $rotated->id)->update([
                'status' => 'superseded',
                'updated_at' => $now,
            ]);
            DB::table('analytical_rule_sets')->where('id', $predecessor->id)->update([
                'status' => 'active',
                'activated_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function reactivateExisting(object $active, object $existing): void
    {
        if ($existing->status !== 'superseded'
            || (int) $existing->version_number !== (int) $active->version_number + 1
            || DB::table('analytical_rule_sets')
                ->where('module_key', self::MODULE)
                ->where('version_number', '>', $existing->version_number)
                ->exists()) {
            throw new RuntimeException('ROT-5 cannot safely reactivate its historical SEO rule set.');
        }

        $this->assertKeys(
            DB::table('analytical_metric_rules')->where('rule_set_id', $active->id)->pluck('metric_key')->all(),
            self::LEGACY_KEYS,
            'legacy',
        );
        $this->assertKeys(
            DB::table('analytical_metric_rules')->where('rule_set_id', $existing->id)->pluck('metric_key')->all(),
            self::INTEREST_KEYS,
            'Interest',
        );

        $now = now();
        DB::table('analytical_rule_sets')->where('id', $active->id)->update(['status' => 'superseded', 'updated_at' => $now]);
        DB::table('analytical_rule_sets')->where('id', $existing->id)->update([
            'status' => 'active',
            'activated_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<int, string> $actual @param array<int, string> $expected */
    private function assertKeys(array $actual, array $expected, string $context): void
    {
        sort($actual);
        $sortedExpected = $expected;
        sort($sortedExpected);
        if ($actual !== $sortedExpected) {
            throw new RuntimeException("ROT-5 requires exactly the six {$context} SEO metric rules.");
        }
    }
};
