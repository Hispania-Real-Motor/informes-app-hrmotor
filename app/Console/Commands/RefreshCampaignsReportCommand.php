<?php

namespace App\Console\Commands;

use App\Models\CampaignReportSnapshot;
use App\Services\Campaigns\CampaignDashboardDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshCampaignsReportCommand extends Command
{
    protected $signature = 'reports:refresh-campaigns
        {--days=30 : Dias del periodo del informe}
        {--months= : Meses del periodo del informe; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--window= : Opcion legacy sin efecto; mantener solo por compatibilidad}
        {--store : Guarda snapshot en base de datos}';

    protected $description = 'Calcula el informe de campanas y opcionalmente guarda un snapshot.';

    public function handle(CampaignDashboardDatasetService $dataset): int
    {
        $end = CarbonImmutable::now()->endOfDay();
        $start = $this->periodStart($end);

        try {
            $this->invalidateCache();

            $request = Request::create('/informes/campanas/data/summary', 'GET', [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]);

            $payload = $dataset->payload($request);
            $campaigns = $dataset->campaignEntityRows($payload['campaigns']);

            foreach ($payload['summary']['warnings'] ?? [] as $warning) {
                $this->warn($warning);
            }

            if ($this->option('store')) {
                $snapshot = CampaignReportSnapshot::query()->create([
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'filters_hash' => md5(json_encode($request->query())),
                    'summary' => $payload['summary'],
                    'campaigns' => $campaigns,
                    'rankings' => $payload['rankings'],
                    'warnings' => $payload['summary']['warnings'] ?? [],
                ]);

                $this->line('Snapshot id: '.$snapshot->id);
            }

            $kpis = $payload['summary']['kpis'];
            $this->line('Campanas: '.count($campaigns));
            $this->line('Inversion total: '.$kpis['spend']);
            $this->line('Leads Salesforce: '.$kpis['leads_salesforce']);
            $this->line('Reservas: '.$kpis['reservations']);
            $this->line('Ventas: '.$kpis['sales']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error refrescando informe de campanas.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }

    private function periodStart(CarbonImmutable $end): CarbonImmutable
    {
        $from = $this->option('from');

        if (filled($from)) {
            return CarbonImmutable::parse($from)->startOfDay();
        }

        $months = $this->option('months');

        if ($months !== null && $months !== '') {
            return $end->subMonthsNoOverflow(max((int) $months, 1))->startOfDay();
        }

        return $end->subDays(max((int) $this->option('days'), 1))->startOfDay();
    }
}
