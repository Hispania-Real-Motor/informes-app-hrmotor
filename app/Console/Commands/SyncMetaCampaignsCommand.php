<?php

namespace App\Console\Commands;

use App\Services\Campaigns\MetaCampaignSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncMetaCampaignsCommand extends Command
{
    protected $signature = 'campaigns:sync-meta
        {--days=60 : Dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva explicita en formato Y-m-d}';

    protected $description = 'Sincroniza metricas diarias de Meta Ads para el informe de campanas.';

    public function handle(MetaCampaignSyncService $sync): int
    {
        $end = $this->periodEnd();
        $start = $this->periodStart($end);

        if ($end->lessThanOrEqualTo($start)) {
            $this->error('El rango indicado no es valido: --to debe ser posterior a --from.');

            return self::FAILURE;
        }

        $result = $sync->sync($start, $end);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Meta Ads configurado: '.($result['configured'] ? 'si' : 'no'));
        $this->line('Filas procesadas: '.$result['processed']);
        $this->line('Filas guardadas: '.$result['saved']);
        if (isset($result['range_start'], $result['range_end_exclusive'])) {
            $this->line('Intervalo efectivo: '.$result['range_start'].' a '.$result['range_end_exclusive'].' (exclusivo)');
        }

        return self::SUCCESS;
    }

    private function periodStart(CarbonImmutable $end): CarbonImmutable
    {
        $from = $this->option('from');

        if (filled($from)) {
            return CarbonImmutable::parse($from, 'Europe/Madrid')->startOfDay();
        }

        $months = $this->option('months');

        if ($months !== null && $months !== '') {
            return $end->subMonthsNoOverflow(max((int) $months, 1))->startOfDay();
        }

        return $end->subDays(max((int) $this->option('days'), 1))->startOfDay();
    }

    private function periodEnd(): CarbonImmutable
    {
        $to = $this->option('to');

        if (filled($to)) {
            return CarbonImmutable::parse($to, 'Europe/Madrid')->startOfDay();
        }

        return CarbonImmutable::now('Europe/Madrid');
    }
}
