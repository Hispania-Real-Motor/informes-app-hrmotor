<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InterestInitialRecoverySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_recovery_indexes_are_explicit_short_and_preserve_integrity(): void
    {
        $table = 'salesforce_interest_initial_recoveries';
        $indexes = collect(Schema::getIndexes($table))->keyBy('name');
        $expected = [
            'sf_iir_recovery_identifier_uq' => ['recovery_identifier'],
            'sf_iir_candidate_generation_uq' => ['candidate_generation_id'],
            'sf_iir_interest_run_uq' => ['interest_run_id'],
            'sf_iir_status_idx' => ['status'],
            'sf_iir_phase_idx' => ['phase'],
            'sf_iir_status_heartbeat_idx' => ['status', 'heartbeat_at'],
            'sf_iir_source_status_idx' => ['source_generation_id', 'status'],
        ];

        // MariaDB rejects the former generated unique name at 65 characters.
        $this->assertGreaterThan(64, strlen('salesforce_interest_initial_recoveries_recovery_identifier_unique'));

        foreach (['sf_iir_source_fk', 'sf_iir_candidate_fk'] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
        $this->assertFalse($indexes->has('salesforce_interest_initial_recoveries_recovery_identifier_unique'));

        foreach ($expected as $name => $columns) {
            $this->assertLessThanOrEqual(64, strlen($name));
            $this->assertTrue($indexes->has($name));
            $this->assertSame($columns, $indexes[$name]['columns']);
        }

        foreach (array_keys(array_slice($expected, 0, 3, true)) as $name) {
            $this->assertTrue($indexes[$name]['unique']);
        }

        $foreignKeys = collect(Schema::getForeignKeys($table));
        foreach (['source_generation_id', 'candidate_generation_id'] as $column) {
            $foreignKey = $foreignKeys->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);
            $this->assertNotNull($foreignKey);
            $this->assertSame('salesforce_interest_generations', $foreignKey['foreign_table']);
            $this->assertSame(['id'], $foreignKey['foreign_columns']);
        }
    }
}
