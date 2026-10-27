<?php

namespace App\Console\Commands;

use App\Models\OperationalTaskRun;
use App\Services\Campaigns\CampaignDashboardDatasetService;
use App\Services\Campaigns\CampaignPublicationReadiness;
use App\Services\Operations\OperationalTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Throwable;

class ReconcileCampaignPublicationCommand extends Command
{
    protected $signature = 'campaigns:reconcile-publication';

    protected $description = 'Reintenta una dependencia pendiente de la publicación diaria de Campañas.';

    private const MAX_DAILY_ATTEMPTS = 3;

    private const RETRYABLE_TASKS = [
        'campaigns-sync-meta',
        'campaigns-sync-google',
        'salesforce-sync-interest-reporting',
        'salesforce-sync-opportunities',
        'salesforce-sync-opportunity-interest-direct',
        'campaigns-build-attribution',
        'reports-refresh-campaigns',
    ];

    public function handle(
        CampaignPublicationReadiness $readiness,
        CampaignDashboardDatasetService $dataset,
        OperationalTaskService $tasks,
    ): int {
        $end = CarbonImmutable::now('Europe/Madrid')->endOfDay();
        $start = $end->subDays(120)->startOfDay();
        $sources = $readiness->sources($start, $end);
        if (! $sources['ready']) {
            return $this->retry($sources['retry_task'], $tasks);
        }

        $build = $readiness->attributionReady($sources['ids']);
        if ($build === null || ! $readiness->attributionCovers($build, $start, $end)) {
            return $this->retry('campaigns-build-attribution', $tasks);
        }

        $request = Request::create('/informes/campanas/data/summary', 'GET', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);
        if (! $readiness->published($start, $end->addDay()->startOfDay(), $dataset->filters($request))['ready']) {
            return $this->retry('reports-refresh-campaigns', $tasks);
        }

        $this->line('SKIPPED: la publicación diaria ya está verificada.');

        return self::SUCCESS;
    }

    private function retry(?string $key, OperationalTaskService $tasks): int
    {
        if ($key === null || ! in_array($key, self::RETRYABLE_TASKS, true)) {
            $this->line('SKIPPED: no existe una dependencia abierta recuperable automáticamente.');

            return self::SUCCESS;
        }

        $todayUtc = CarbonImmutable::now('Europe/Madrid')->startOfDay()->utc();
        $attempts = OperationalTaskRun::query()->useWritePdo()
            ->where('task_key', $key)->where('origin', 'scheduler')
            ->where('queued_at', '>=', $todayUtc)->where('status', '<>', 'skipped')->count();
        if ($attempts >= self::MAX_DAILY_ATTEMPTS) {
            $this->line('SKIPPED: límite diario de reintentos de '.$key.' alcanzado.');

            return self::SUCCESS;
        }

        try {
            $run = $tasks->claim($key, 'scheduler');
            if ($run->status === 'skipped') {
                $this->line('SKIPPED: la dependencia '.$key.' ya tiene una ejecución activa.');

                return self::SUCCESS;
            }
            $tasks->execute($run->id);
            if ($run->refresh()->status === 'success') {
                $this->line('Dependencia recuperada: '.$key.'.');

                return self::SUCCESS;
            }
            if ($run->status === 'skipped') {
                $this->line('SKIPPED: la dependencia '.$key.' no requirió nueva escritura.');

                return self::SUCCESS;
            }
        } catch (Throwable) {
            // Provider and persistence errors remain in the child operational run.
        }

        $this->error('No se completó la dependencia '.$key.'.');

        return self::FAILURE;
    }
}
