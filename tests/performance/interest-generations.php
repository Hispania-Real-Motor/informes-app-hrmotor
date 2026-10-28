<?php

use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\InterestGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Standalone synthetic workload. Never accepts an arbitrary database or a production environment.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'interests_generation_benchmark'
    || DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('Only the disposable MariaDB benchmark database is allowed.');
}
$store = app(InterestGenerationStore::class);
$mode = $argv[1] ?? 'write';
if ($mode === 'read') {
    $samples = [];
    $end = microtime(true) + (int) ($argv[2] ?? 180);
    $g = $store->published() ?? throw new RuntimeException('Start reader after base_ready.');
    while (microtime(true) < $end) {
        $start = microtime(true);
        $store->rows($g->id)->where('is_deleted', false)
            ->whereBetween('functional_created_at', ['2026-10-01', '2026-10-31'])
            ->select('status')->selectRaw('count(*) as total')->groupBy('status')->get();
        $samples[] = (microtime(true) - $start) * 1000;
        usleep(100000);
    }
    sort($samples);
    echo json_encode(['reader_generation' => $g->id, 'samples' => count($samples),
        'p50_ms' => $samples[(int) floor(count($samples) * .5)],
        'p95_ms' => $samples[(int) floor(count($samples) * .95)], 'max_ms' => max($samples)], JSON_PRETTY_PRINT)."\n";
    exit;
}
if ($mode !== 'delta' && DB::table('salesforce_interest_generations')->exists()) {
    throw new RuntimeException('Benchmark requires empty generation storage; existing data is never removed.');
}
$count = 270000;
$payloadBytes = 2048;
$cutoff = CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC');
$start = microtime(true);
$row = static fn (int $i, bool $changed): array => [
    'salesforce_id' => sprintf('a01%015d', $i), 'migration_origin_lead_id' => sprintf('00Q%015d', $i),
    'lead_salesforce_id' => sprintf('00Q%015d', $i), 'account_salesforce_id' => sprintf('001%015d', $i),
    'canonical_person_type' => 'Account', 'canonical_person_salesforce_id' => sprintf('001%015d', $i),
    'salesforce_created_at' => '2026-10-01 00:00:00', 'salesforce_last_modified_at' => '2026-10-28 09:00:00',
    'functional_created_at' => '2026-10-01 00:00:00', 'is_deleted' => false,
    'status' => $changed ? 'changed' : 'new', 'source' => 'synthetic', 'type' => 'Venta',
    'raw_payload' => json_encode(['synthetic' => str_repeat('x', $payloadBytes)]),
];
$write = static function (int $id, bool $changed) use ($store, $count, $row): void {
    for ($offset = 0; $offset < $count; $offset += 200) {
        $rows = [];
        for ($i = $offset + 1; $i <= min($count, $offset + 200); $i++) {
            $rows[] = $row($i, $changed);
        }
        $store->persist($id, $rows);
    }
};
if ($mode !== 'delta') {
    $base = $store->begin($cutoff, true);
    $run = app(ReportSyncRunService::class)->start('salesforce_interests', 'salesforce', $cutoff, $cutoff, 'UTC');
    $store->bindRun($base, $run);
    $write($base, false);
    $store->finishF2($base);
    app(ReportSyncRunService::class)->complete($run, $cutoff);
    $f5 = SalesforceInterestActivityRun::create(['run_identifier' => (string) Str::uuid(),
        'reason' => 'Synthetic benchmark only, no Salesforce calls', 'status' => 'completed',
        'source_interest_sync_run_id' => $run->id, 'source_interest_cutoff_at' => $cutoff,
        'source_cutoff_at' => $cutoff, 'started_at' => $cutoff, 'completed_at' => $cutoff]);
    $store->finishF5($base, $f5);
    $manifest = $store->manifest($base);
    $g = $store->generation($base);
    $store->certify($base, ['source' => 'isolated_salesforce_reconstruction',
        'manifest_sha256' => $manifest['sha256'], 'manifest' => $manifest,
        'f5' => ['run_id' => $g->activity_run_id, 'cutoff_at' => $g->cutoff_at],
        'external' => ['source' => 'synthetic_salesforce_export', 'identifiers_sha256' => $manifest['identifiers_sha256'],
            'rows' => $manifest['rows'], 'deleted' => $manifest['deleted'],
            'relationships_sha256' => hash('sha256', 'synthetic-relations'), 'coverage_sha256' => hash('sha256', 'synthetic-coverage')],
        'checks' => array_fill_keys(['identifiers', 'counts', 'deletions', 'relationships', 'coverage', 'f5'], true)]);
    $store->publish($base);
    $baseSeconds = microtime(true) - $start;
    echo json_encode(['base_ready' => $base, 'base_seconds' => $baseSeconds])."\n";
} else {
    $base = $store->published()->id;
    $baseSeconds = null;
}
$start = microtime(true);
$next = $store->begin($cutoff->addHour());
$copySeconds = microtime(true) - $start;
$start = microtime(true);
$write($next, true);
$deltaSeconds = microtime(true) - $start;
$tables = DB::select("SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('salesforce_interest_versions', 'salesforce_interest_members')");
$locks = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Innodb_os_log_written')");
echo json_encode(['synthetic_rows' => $count, 'changed_rows' => $count, 'payload_bytes' => $payloadBytes,
    'base_seconds' => $baseSeconds, 'membership_copy_seconds' => $copySeconds,
    'delta_seconds' => $deltaSeconds, 'peak_php_bytes' => memory_get_peak_usage(true),
    'tables_estimates' => $tables, 'innodb_status' => $locks,
    'published_unchanged' => (int) $store->published()->id === $base], JSON_PRETTY_PRINT)."\n";
