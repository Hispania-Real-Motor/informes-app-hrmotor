<?php

/**
 * Opt-in integration probe. Run only against an isolated MariaDB on loopback:
 * CAMPAIGN_TEST_DB_PASSWORD=... php tests/Integration/campaign_closure_mariadb.php
 */

use App\Services\Campaigns\CampaignOpenInvestmentWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$password = getenv('CAMPAIGN_TEST_DB_PASSWORD');
$port = (int) (getenv('CAMPAIGN_TEST_DB_PORT') ?: 33077);
if ($password === false || $password === '' || $port < 1024 || $port > 65535) {
    fwrite(STDERR, "An isolated loopback MariaDB password and high port are required.\n");
    exit(2);
}

$database = 'codex_campaign_closure_test';
$server = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$mode = $argv[1] ?? 'parent';
if ($mode === 'parent') {
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$endpoint = ['host' => '127.0.0.1', 'port' => $port, 'username' => 'root', 'password' => $password];
config()->set('database.connections.campaign_concurrency_probe', [
    'driver' => 'mysql', 'database' => $database,
    'read' => [$endpoint], 'write' => [$endpoint], 'sticky' => false,
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
]);
config()->set('database.default', 'campaign_concurrency_probe');
DB::purge('campaign_concurrency_probe');

if ($mode === 'parent') {
    DB::statement('DROP TABLE IF EXISTS closure_probe');
    DB::statement('DROP TABLE IF EXISTS campaign_investment_closures');
    DB::statement('CREATE TABLE campaign_investment_closures (id BIGINT PRIMARY KEY AUTO_INCREMENT, month DATE NOT NULL UNIQUE, status VARCHAR(20) NOT NULL)');
    DB::statement('CREATE TABLE closure_probe (id BIGINT PRIMARY KEY AUTO_INCREMENT, event VARCHAR(30) NOT NULL, created_at DATETIME(6) NOT NULL)');

    $start = static function (string $role): array {
        $pipes = [];
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['mbstring', 'fileinfo', 'curl', 'openssl', 'intl', 'pdo_mysql', 'zip'] as $extension) {
            $command[] = '-d';
            $command[] = 'extension='.$extension;
        }
        $command[] = __FILE__;
        $command[] = $role;
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start isolated worker.');
        }

        return [$process, $pipes];
    };
    $finish = static function (array $worker): string {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Worker failed: '.$error);
        }

        return trim($output);
    };

    $writer = $start('writer');
    $deadline = microtime(true) + 10;
    while (! DB::table('closure_probe')->where('event', 'entered')->exists() && microtime(true) < $deadline) {
        usleep(50000);
    }
    if (! DB::table('closure_probe')->where('event', 'entered')->exists()) {
        throw new RuntimeException('Writer did not enter its protected interval.');
    }
    $closer = $start('closer');
    $writerResult = $finish($writer);
    $closerWait = (float) $finish($closer);
    $events = DB::table('closure_probe')->orderBy('id')->pluck('event')->all();
    $closedWindow = app(CampaignOpenInvestmentWindow::class)->start(
        CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01')
    );
    if ($events !== ['entered', 'metric_written', 'closed'] || $closerWait < 1.5 || $closedWindow !== null
        || $writerResult !== 'same-write-connection') {
        throw new RuntimeException('MariaDB closure race probe failed: '.json_encode([$events, $closerWait, $writerResult]));
    }
    echo json_encode(['result' => 'passed', 'events' => $events, 'closer_wait_seconds' => round($closerWait, 2),
        'write_connection_pinned' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$window = app(CampaignOpenInvestmentWindow::class);
if ($mode === 'writer') {
    $window->synchronized(function () use ($window): void {
        $lockedConnection = DB::selectOne('SELECT CONNECTION_ID() AS id', [], false)->id;
        $transactionConnection = DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        if ((int) $lockedConnection !== (int) $transactionConnection) {
            throw new RuntimeException('Named lock and transaction use different write connections.');
        }
        if ($window->start(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01')) === null) {
            throw new RuntimeException('Open month was unexpectedly closed.');
        }
        DB::table('closure_probe')->insert(['event' => 'entered', 'created_at' => now()]);
        DB::transaction(function (): void {
            sleep(2);
            DB::table('closure_probe')->insert(['event' => 'metric_written', 'created_at' => now()]);
        });
    });
    echo 'same-write-connection'.PHP_EOL;
    exit(0);
}
if ($mode === 'closer') {
    $started = microtime(true);
    $window->synchronized(function (): void {
        DB::transaction(function (): void {
            DB::table('campaign_investment_closures')->insert(['month' => '2026-10-01', 'status' => 'closed']);
            DB::table('closure_probe')->insert(['event' => 'closed', 'created_at' => now()]);
        });
    });
    echo microtime(true) - $started, PHP_EOL;
    exit(0);
}

throw new RuntimeException('Unknown probe role.');
