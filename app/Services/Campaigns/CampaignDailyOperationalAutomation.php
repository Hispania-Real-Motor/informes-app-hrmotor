<?php

namespace App\Services\Campaigns;

use RuntimeException;
use Illuminate\Support\Facades\Artisan;
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

    /** @return array{month:string,steps:list<string>,attribution:array<string,mixed>} */
    public function run(): array
    {
        if (! config('campaign_operational.daily_automation_enabled', false)) {
            throw new RuntimeException('La automatización diaria de Campañas está deshabilitada.');
        }

        foreach (self::SOURCE_TASKS as $key) {
            $exit = Artisan::call('operations:run', ['task' => $key], new NullOutput());
            if ($exit !== 0) {
                throw new RuntimeException('Una dependencia operacional no se completó.');
            }
        }

        $month = now('Europe/Madrid')->format('Y-m');
        [$year, $number] = array_map('intval', explode('-', $month));
        $attribution = $this->builder->build($year, $number);

        return ['month' => $month, 'steps' => self::SOURCE_TASKS, 'attribution' => $attribution];
    }
}
