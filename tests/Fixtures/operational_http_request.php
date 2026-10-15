<?php

// Synthetic subprocess fixture. Never runs a worker or an integration command.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.url' => null,
    'database.connections.sqlite.busy_timeout' => 10000,
    'queue.connections.operations.connection' => 'sqlite',
    'cache.default' => 'array',
    'session.driver' => 'array',
    'app.debug' => false,
]);
$app['db']->purge('sqlite');
$session = $app['session']->driver();
$session->start();
$session->put(['informes_authenticated' => true, 'report_user_id' => 1]);
touch($argv[2]);
$deadline = microtime(true) + 15;
while (! is_file($argv[3])) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
$request = Request::create('/informes/alertas-operativas/ejecutar', 'POST', [
    'task_key' => 'salesforce-sync-commercial-reviews',
    'confirmed' => '1',
]);
$request->headers->set('Accept', 'application/json');
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
