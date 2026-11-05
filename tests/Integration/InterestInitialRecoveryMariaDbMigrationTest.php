<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Opt-in only: requires a new, local MariaDB 11 database containing no real data. */
class InterestInitialRecoveryMariaDbMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('INTEREST_RECOVERY_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Requires an explicit isolated MariaDB 11 test database.');
        }

        parent::setUp();

        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mariadb'
            || $connection->getConfig('host') !== '127.0.0.1'
            || ! str_starts_with($connection->getDatabaseName(), 'hrmotor_interest_recovery_test_')) {
            throw new \RuntimeException('Only a dedicated local MariaDB test database is allowed.');
        }

        $version = (string) DB::scalar('SELECT VERSION()');
        if (! str_starts_with($version, '11.')) {
            throw new \RuntimeException('The isolated migration test requires MariaDB 11.');
        }
    }

    public function test_migration_up_creates_short_indexes_and_named_foreign_keys(): void
    {
        $table = 'salesforce_interest_initial_recoveries';
        $indexes = collect(DB::select("SHOW INDEX FROM {$table}"))->groupBy('Key_name');
        $expected = [
            'sf_iir_recovery_identifier_uq' => ['recovery_identifier'],
            'sf_iir_candidate_generation_uq' => ['candidate_generation_id'],
            'sf_iir_interest_run_uq' => ['interest_run_id'],
            'sf_iir_status_idx' => ['status'],
            'sf_iir_phase_idx' => ['phase'],
            'sf_iir_status_heartbeat_idx' => ['status', 'heartbeat_at'],
            'sf_iir_source_status_idx' => ['source_generation_id', 'status'],
        ];

        $this->assertGreaterThan(64, strlen('salesforce_interest_initial_recoveries_recovery_identifier_unique'));
        foreach ($indexes as $name => $index) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
        foreach ($expected as $name => $columns) {
            $this->assertTrue($indexes->has($name));
            $this->assertSame($columns, $indexes[$name]->sortBy('Seq_in_index')->pluck('Column_name')->all());
        }
        foreach (array_keys(array_slice($expected, 0, 3, true)) as $name) {
            $this->assertSame([0], $indexes[$name]->pluck('Non_unique')->unique()->values()->all());
        }

        $foreignKeys = collect(DB::select(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table],
        ))->keyBy('CONSTRAINT_NAME');
        foreach (['sf_iir_source_fk' => 'source_generation_id', 'sf_iir_candidate_fk' => 'candidate_generation_id'] as $name => $column) {
            $this->assertTrue($foreignKeys->has($name));
            $this->assertSame($column, $foreignKeys[$name]->COLUMN_NAME);
            $this->assertSame('salesforce_interest_generations', $foreignKeys[$name]->REFERENCED_TABLE_NAME);
            $this->assertSame('id', $foreignKeys[$name]->REFERENCED_COLUMN_NAME);
        }
    }
}
