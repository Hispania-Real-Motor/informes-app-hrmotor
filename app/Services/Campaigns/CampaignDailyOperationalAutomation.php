<?php

namespace App\Services\Campaigns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

final class CampaignDailyOperationalAutomation
{
    /** @var list<string> */
    private const SOURCE_TASKS = [
        'campaigns-sync-meta',
        'campaigns-sync-google',
        'salesforce-sync-interest-operational-current',
        'salesforce-sync-opportunities',
        'salesforce-sync-opportunity-interest-direct',
        'campaigns-sync-current-status',
    ];

    public function __construct(private readonly CampaignOperationalAttributionBuilder $builder) {}

    /** @return array{month:string,steps:list<string>,attribution:array<string,mixed>,activation:string} */
    public function run(): array
    {
        if (! config('campaign_operational.daily_automation_enabled', false)) {
            throw new RuntimeException('La automatización diaria de Campañas está deshabilitada.');
        }

        $month = CarbonImmutable::now('Europe/Madrid')->format('Y-m');
        if (! in_array($month, ['2026-10', '2026-11'], true)
            || ! config('campaign_operational.'.($month === '2026-10' ? 'october_2026_enabled' : 'november_2026_enabled'), false)) {
            throw new RuntimeException('El período operacional de Campañas no tiene contrato habilitado.');
        }

        $dayStart = CarbonImmutable::now('Europe/Madrid')->startOfDay()->utc();
        foreach (self::SOURCE_TASKS as $key) {
            $completedToday = DB::table('operational_task_runs')
                ->where('task_key', $key)->where('status', 'success')
                ->where('finished_at', '>=', $dayStart)->exists();
            if ($completedToday) {
                continue;
            }
            $exit = Artisan::call('operations:run', ['task' => $key], new NullOutput);
            $run = DB::table('operational_task_runs')->where('task_key', $key)->latest('id')->first();
            if ($exit !== 0 || $run === null || $run->status !== 'success') {
                throw new RuntimeException('Una dependencia operacional no se completó.');
            }
        }

        [$year, $number] = array_map('intval', explode('-', $month));
        $attribution = $this->builder->build($year, $number);
        $persisted = DB::table('campaign_operational_runs')->where('id', (int) ($attribution['run_id'] ?? 0))->first([
            'status', 'opportunity_coverage_complete', 'incidents_count', 'activated_at',
        ]);
        if ($persisted === null) {
            throw new RuntimeException('No existe evidencia persistida de la atribución.');
        }
        if (! array_key_exists('opportunity_coverage_complete', $attribution)) {
            $attribution += [
                'status' => $persisted->status,
                'opportunity_coverage_complete' => (bool) $persisted->opportunity_coverage_complete,
                'incidents_count' => (int) $persisted->incidents_count,
            ];
        }
        if ($persisted->status === 'completed' && $persisted->activated_at !== null) {
            return ['month' => $month, 'steps' => self::SOURCE_TASKS, 'attribution' => $attribution, 'activation' => 'unchanged'];
        }
        if (($attribution['status'] ?? null) !== 'prepared' || ! ($attribution['opportunity_coverage_complete'] ?? false)) {
            throw new RuntimeException('La atribución no cumple la política de calidad operacional.');
        }

        $activation = 'prepared_pending_approval';
        if (config('campaign_operational.daily_automation_auto_activate_enabled', false)) {
            $activation = $this->builder->activate($year, $number, (int) $attribution['run_id'])['status'] ?? 'unknown';
        }

        return ['month' => $month, 'steps' => self::SOURCE_TASKS, 'attribution' => $attribution, 'activation' => $activation];
    }
}
