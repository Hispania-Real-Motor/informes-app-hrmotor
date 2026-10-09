<?php

namespace Tests\Feature;

use App\Models\AnalyticalRuleSet;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SeoRot5MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_interest_projection_schema_is_explicit_unique_and_keeps_the_legacy_table(): void
    {
        $this->assertTrue(Schema::hasColumns('seo_salesforce_organic_interest_daily_metrics', [
            'data_date', 'interest_count', 'source_timezone', 'source_interest_sync_run_id',
            'source_interest_cutoff_at', 'extracted_at',
        ]));
        $this->assertTrue(Schema::hasTable('seo_salesforce_organic_daily_metrics'));
        $indexes = collect(DB::select("PRAGMA index_list('seo_salesforce_organic_interest_daily_metrics')"))->pluck('name');
        $this->assertContains('seo_sf_organic_interest_date_uq', $indexes);

        $row = [
            'data_date' => '2026-10-08', 'interest_count' => 3, 'source_timezone' => 'Europe/Madrid',
            'source_interest_sync_run_id' => 99, 'source_interest_cutoff_at' => '2026-10-09 10:00:00',
            'extracted_at' => now(),
        ];
        SeoSalesforceOrganicInterestDailyMetric::query()->create($row);
        $metric = SeoSalesforceOrganicInterestDailyMetric::query()->sole();
        $this->assertSame(3, $metric->interest_count);
        $this->assertSame(99, $metric->source_interest_sync_run_id);

        $this->expectException(QueryException::class);
        SeoSalesforceOrganicInterestDailyMetric::query()->create($row);
    }

    public function test_interest_projection_schema_rolls_back_without_touching_the_legacy_table(): void
    {
        $migration = require database_path('migrations/2026_10_09_100000_create_seo_salesforce_organic_interest_daily_metrics_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('seo_salesforce_organic_interest_daily_metrics'));
        $this->assertTrue(Schema::hasTable('seo_salesforce_organic_daily_metrics'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('seo_salesforce_organic_interest_daily_metrics'));
    }

    public function test_rule_rotation_creates_vn_plus_one_and_preserves_every_threshold(): void
    {
        $legacy = AnalyticalRuleSet::query()->where('version_number', 1)->with('rules')->sole();
        $rotated = AnalyticalRuleSet::query()->where('status', 'active')->with('rules')->sole();
        $legacyRule = $legacy->rules->firstWhere('metric_key', 'salesforce_organic_leads');
        $interestRule = $rotated->rules->firstWhere('metric_key', 'salesforce_organic_interests');

        $this->assertSame($legacy->version_number + 1, $rotated->version_number);
        $this->assertSame('superseded', $legacy->status);
        $this->assertNull($rotated->created_by_report_user_id);
        $this->assertNotNull($interestRule);
        foreach ([
            'comparison_mode', 'favorable_direction', 'threshold_unit', 'observation_threshold',
            'deviation_threshold', 'critical_threshold', 'minimum_baseline', 'minimum_absolute_change',
        ] as $field) {
            $this->assertSame((string) $legacyRule->{$field}, (string) $interestRule->{$field});
        }
    }

    public function test_rule_rotation_down_restores_only_its_predecessor_and_can_be_reapplied_without_losing_history(): void
    {
        $migration = require database_path('migrations/2026_10_09_110000_rotate_seo_salesforce_rule_to_interests.php');
        $migration->down();

        $this->assertDatabaseHas('analytical_rule_sets', ['version_number' => 1, 'status' => 'active']);
        $this->assertDatabaseHas('analytical_rule_sets', ['version_number' => 2, 'status' => 'superseded']);
        $this->assertDatabaseHas('analytical_metric_rules', ['metric_key' => 'salesforce_organic_leads']);
        $this->assertDatabaseHas('analytical_metric_rules', ['metric_key' => 'salesforce_organic_interests']);

        $migration->up();
        $this->assertDatabaseCount('analytical_rule_sets', 2);
        $this->assertDatabaseHas('analytical_rule_sets', ['version_number' => 2, 'status' => 'active']);
    }

    public function test_rule_rotation_down_refuses_to_destroy_a_later_version(): void
    {
        AnalyticalRuleSet::query()->where('status', 'active')->update(['status' => 'superseded']);
        AnalyticalRuleSet::query()->create([
            'module_key' => 'seo', 'version_number' => 3, 'version_key' => 'seo_rules_v3',
            'status' => 'active', 'change_reason' => 'Later approved configuration',
            'created_by_report_user_id' => null, 'activated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_09_110000_rotate_seo_salesforce_rule_to_interests.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_rule_rotation_uses_the_actual_active_version_instead_of_assuming_v1(): void
    {
        $migration = require database_path('migrations/2026_10_09_110000_rotate_seo_salesforce_rule_to_interests.php');
        $migration->down();

        $rotated = AnalyticalRuleSet::query()->where('version_number', 2)->sole();
        DB::table('analytical_metric_rules')->where('rule_set_id', $rotated->id)->delete();
        $rotated->delete();

        $v1 = AnalyticalRuleSet::query()->where('version_number', 1)->with('rules')->sole();
        $v1->update(['status' => 'superseded']);
        $v4 = AnalyticalRuleSet::query()->create([
            'module_key' => 'seo', 'version_number' => 4, 'version_key' => 'seo_rules_v4',
            'status' => 'active', 'change_reason' => 'Approved pre-ROT-5 rule configuration',
            'created_by_report_user_id' => null, 'activated_at' => now(),
        ]);
        foreach ($v1->rules as $rule) {
            DB::table('analytical_metric_rules')->insert([
                'rule_set_id' => $v4->id,
                'metric_key' => $rule->metric_key,
                'comparison_mode' => $rule->comparison_mode,
                'favorable_direction' => $rule->favorable_direction,
                'threshold_unit' => $rule->threshold_unit,
                'observation_threshold' => $rule->observation_threshold,
                'deviation_threshold' => $rule->deviation_threshold,
                'critical_threshold' => $rule->critical_threshold,
                'minimum_baseline' => $rule->minimum_baseline,
                'minimum_absolute_change' => $rule->minimum_absolute_change,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        $this->assertDatabaseHas('analytical_rule_sets', ['version_number' => 4, 'status' => 'superseded']);
        $this->assertDatabaseHas('analytical_rule_sets', ['version_number' => 5, 'status' => 'active']);
        $this->assertDatabaseHas('analytical_metric_rules', [
            'rule_set_id' => AnalyticalRuleSet::query()->where('version_number', 5)->value('id'),
            'metric_key' => 'salesforce_organic_interests',
        ]);
    }
}
