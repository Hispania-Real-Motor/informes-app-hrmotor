<?php

namespace App\Console\Commands;

use App\Services\Reports\Leads\OperationalInterestKpiProjector;
use Illuminate\Console\Command;
use Throwable;

final class ProjectOperationalInterestKpisCommand extends Command
{
    protected $signature = 'reports:project-operational-interest-kpis
        {month : Functional month in YYYY-MM format}
        {--confirm : Build a private, version-pinned local KPI projection}';

    protected $description = 'Prepare operational Interest KPI evidence without querying Salesforce or changing the official generation.';

    public function handle(OperationalInterestKpiProjector $projector): int
    {
        $month = (string) $this->argument('month');
        if (! $this->option('confirm') || ! preg_match('/^2026-(10|11)$/', $month)) {
            $this->error('An allowed October or November 2026 month and --confirm are required.');

            return self::FAILURE;
        }

        try {
            $result = $projector->build((int) substr($month, 0, 4), (int) substr($month, 5, 2));
        } catch (Throwable) {
            $this->error('Operational KPI projection was rejected; the previous evidence remains unchanged.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
