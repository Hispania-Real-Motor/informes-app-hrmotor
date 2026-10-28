<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\InterestGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'interests_generation_benchmark'
    || DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('Only the disposable MariaDB benchmark database is allowed.');
}
$store = app(InterestGenerationStore::class);
if (($argv[1] ?? '') === 'publish') {
    while (microtime(true) < (float) $argv[3]) {
        usleep(10000);
    }
    try {
        $store->publish((int) $argv[2]);
        echo 'published';
    } catch (RuntimeException) {
        echo 'rejected';
    }
    exit;
}
$base = $store->published() ?? throw new RuntimeException('Synthetic base required.');
$ids = [];
foreach ([2, 3] as $hours) {
    $cutoff = CarbonImmutable::parse($base->cutoff_at, 'UTC')->addHours($hours);
    $id = $store->begin($cutoff);
    $run = app(ReportSyncRunService::class)->start('salesforce_interests', 'salesforce', $cutoff, $cutoff, 'UTC');
    $store->bindRun($id, $run);
    $store->finishF2($id);
    app(ReportSyncRunService::class)->complete($run, $cutoff);
    $f5 = SalesforceInterestActivityRun::create(['run_identifier' => (string) Str::uuid(),
        'reason' => 'Synthetic publication race fixture only', 'status' => 'completed',
        'source_interest_sync_run_id' => $run->id, 'source_interest_cutoff_at' => $cutoff,
        'source_cutoff_at' => $cutoff, 'started_at' => now(), 'completed_at' => now()]);
    $store->finishF5($id, $f5);
    $store->certify($id);
    $ids[] = $id;
}
$barrier = (string) (microtime(true) + 5);
$workers = [];
foreach ($ids as $id) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'publish', (string) $id, $barrier],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$results = [];
foreach ($workers as [$process, $pipes]) {
    $results[] = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') {
        throw new RuntimeException('Publication worker failed unexpectedly.');
    }
}
sort($results);
if ($results !== ['published', 'rejected'] || ! in_array((int) $store->published()->id, $ids, true)) {
    throw new RuntimeException('Publication fence failed.');
}
echo json_encode(['workers' => 2, 'results' => $results, 'single_winner' => true], JSON_THROW_ON_ERROR)."\n";
