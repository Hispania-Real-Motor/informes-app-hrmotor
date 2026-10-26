<?php

namespace Tests\Integration;

use App\Services\Campaigns\CampaignCurrentStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Opt-in only: use a new, local MariaDB database containing no real data. */
class CampaignCurrentStatusMariaDbTest extends TestCase
{
    protected bool $authenticateReportsByDefault = false;

    protected function setUp(): void
    {
        if (getenv('CAMPAIGN_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Requires explicit isolated MariaDB test database.');
        }
        parent::setUp();
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mariadb'
            || $connection->getConfig('host') !== '127.0.0.1'
            || ! str_starts_with($connection->getDatabaseName(), 'hrmotor_phase_b_test_')) {
            throw new \RuntimeException('Only a dedicated local test database is allowed.');
        }
    }

    public function test_identity_index_strings_and_duplicate_rejection(): void
    {
        $indexes = DB::select('SHOW INDEX FROM campaign_current_statuses WHERE Key_name = ?', ['campaign_current_status_identity']);
        $this->assertSame(['platform', 'account_id', 'campaign_id'], array_column($indexes, 'Column_name'));
        $this->assertSame([0], array_values(array_unique(array_column($indexes, 'Non_unique'))));
        DB::beginTransaction();
        try {
            $row = $this->row('123456789012345678901234567890');
            DB::table('campaign_current_statuses')->insert($row);
            DB::table('campaign_current_statuses')->insert(array_replace($row, ['account_id' => '99000000003']));
            DB::table('campaign_current_statuses')->insert(array_replace($row, ['campaign_id' => '0000001']));
            $plan = DB::select('EXPLAIN SELECT campaign_id FROM campaign_current_statuses WHERE platform = ? AND account_id = ? AND campaign_id = ?', [$row['platform'], $row['account_id'], $row['campaign_id']]);
            $this->assertSame('campaign_current_status_identity', $plan[0]->key);
            $this->assertSame($row['campaign_id'], DB::table('campaign_current_statuses')->where('account_id', $row['account_id'])->where('campaign_id', $row['campaign_id'])->value('campaign_id'));
            $this->assertSame('0000001', DB::table('campaign_current_statuses')->where('campaign_id', '0000001')->value('campaign_id'));
            try {
                DB::table('campaign_current_statuses')->insert($row);
                $this->fail('The unique identity must reject a duplicate.');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->getCode());
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_utc_values_are_independent_of_session_timezone_and_freshness_is_exact(): void
    {
        DB::beginTransaction();
        try {
            $run = DB::table('report_sync_runs')->insertGetId(['dataset' => CampaignCurrentStatusService::DATASET, 'source' => 'meta:99000000001', 'status' => 'completed', 'started_at' => '2026-10-25 00:30:00']);
            $row = array_replace($this->row('99000000002'), ['report_sync_run_id' => $run]);
            DB::statement("SET time_zone = '+00:00'");
            DB::table('campaign_current_statuses')->insert($row);
            DB::statement("SET time_zone = '+02:00'");
            $this->assertSame('2026-10-25 00:30:00', DB::table('campaign_current_statuses')->where('campaign_id', $row['campaign_id'])->value('last_verified_at'));
            $this->assertSame('2026-10-25 00:30:00', DB::table('campaign_current_statuses')->where('campaign_id', $row['campaign_id'])->value('created_at'));
            $fields = DB::select('SHOW COLUMNS FROM campaign_current_statuses WHERE Field IN (?, ?)', ['last_observed_at', 'last_verified_at']);
            $this->assertSame(['datetime'], array_values(array_unique(array_column($fields, 'Type'))));
            $input = [['platform' => 'meta', 'account_id' => $row['account_id'], 'campaign_id' => $row['campaign_id']]];
            $service = app(CampaignCurrentStatusService::class);
            CarbonImmutable::setTestNow('2026-10-26 00:29:59 UTC');
            $this->assertSame('fresh', $service->decorate($input)[0]['current_status']['availability']);
            CarbonImmutable::setTestNow('2026-10-26 00:30:00 UTC');
            $this->assertSame('stale', $service->decorate($input)[0]['current_status']['availability']);
            CarbonImmutable::setTestNow('2026-10-24 23:30:00 UTC');
            $this->assertSame('unavailable', $service->decorate($input)[0]['current_status']['availability']);
        } finally {
            CarbonImmutable::setTestNow();
            DB::statement("SET time_zone = '+00:00'");
            DB::rollBack();
        }
    }

    public function test_concurrent_upserts_keep_one_complete_observation(): void
    {
        $connection = DB::connection();
        $row = $this->row('99000000004');
        ksort($row);
        $columns = ['raw_status', 'configured_status', 'last_valid_raw_status', 'last_valid_configured_status', 'last_observed_at', 'last_verified_at', 'updated_at'];
        // Use the same grammar as Query Builder upsert, not a substitute SQL dialect.
        $sql = $connection->getQueryGrammar()->compileUpsert($connection->table('campaign_current_statuses'), [$row], ['platform', 'account_id', 'campaign_id'], $columns);
        $barrier = tempnam(sys_get_temp_dir(), 'campaign-mariadb-');
        unlink($barrier);
        $code = <<<'PHP'
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $pdo = new PDO($input['dsn'], $input['user'], $input['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $deadline = microtime(true) + 30;
        while (!is_file($input['barrier'])) { if (microtime(true) > $deadline) { exit(2); } usleep(10000); }
        $pdo->beginTransaction();
        $pdo->prepare($input['sql'])->execute($input['bindings']);
        usleep(100000);
        $pdo->commit();
        PHP;
        $processes = [];
        try {
            foreach (['ACTIVE', 'PAUSED'] as $status) {
                $values = array_replace($row, ['raw_status' => $status, 'configured_status' => $status, 'last_valid_raw_status' => $status, 'last_valid_configured_status' => $status]);
                $process = new Process([PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=pdo_mysql', '-r', $code]);
                $process->setTimeout(60);
                $process->setInput(json_encode(['dsn' => 'mysql:host=127.0.0.1;port='.$connection->getConfig('port').';dbname='.$connection->getDatabaseName().';charset=utf8mb4', 'user' => $connection->getConfig('username'), 'password' => $connection->getConfig('password'), 'barrier' => $barrier, 'sql' => $sql, 'bindings' => array_values($values)], JSON_THROW_ON_ERROR));
                $process->start();
                $processes[] = $process;
            }
            touch($barrier);
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Concurrent test worker failed.');
            }
            $found = DB::table('campaign_current_statuses')->where('account_id', $row['account_id'])->where('campaign_id', $row['campaign_id'])->get();
            $this->assertCount(1, $found);
            $this->assertContains($found[0]->raw_status, ['ACTIVE', 'PAUSED']);
            $this->assertSame($found[0]->raw_status, $found[0]->configured_status);
            $this->assertSame($found[0]->raw_status, $found[0]->last_valid_raw_status);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
            DB::table('campaign_current_statuses')->where('account_id', $row['account_id'])->where('campaign_id', $row['campaign_id'])->delete();
        }
    }

    public function test_new_migration_is_reversible_without_changing_historical_schemas(): void
    {
        $tables = ['campaign_platform_daily_metrics', 'campaign_lead_attributions', 'campaign_attributions', 'campaign_investment_closures', 'campaign_investment_closure_snapshots', 'campaign_investment_closure_events'];
        $before = array_map(fn ($table) => DB::select('SHOW CREATE TABLE '.$table), $tables);
        $migration = require database_path('migrations/2026_10_23_120000_create_campaign_current_statuses_table.php');
        $this->assertSame(0, DB::table('campaign_current_statuses')->count());
        $migration->down();
        $this->assertFalse(Schema::hasTable('campaign_current_statuses'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('campaign_current_statuses'));
        $this->assertEquals($before, array_map(fn ($table) => DB::select('SHOW CREATE TABLE '.$table), $tables));
    }

    private function row(string $campaign): array
    {
        return ['platform' => 'meta', 'account_id' => '99000000001', 'campaign_id' => $campaign, 'sync_source' => 'meta:99000000001', 'raw_status' => 'ACTIVE', 'configured_status' => 'ACTIVE', 'last_valid_raw_status' => 'ACTIVE', 'last_valid_configured_status' => 'ACTIVE', 'last_observed_at' => '2026-10-25 00:30:00', 'last_verified_at' => '2026-10-25 00:30:00', 'created_at' => '2026-10-25 00:30:00', 'updated_at' => '2026-10-25 00:30:00'];
    }
}
