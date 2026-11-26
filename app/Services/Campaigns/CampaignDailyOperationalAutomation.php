<?php

namespace App\Services\Campaigns;

use App\Services\Operations\OperationalTaskService;
use App\Services\Reports\Leads\OperationalInterestSourceReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    public function __construct(
        private readonly CampaignOperationalAttributionBuilder $builder,
        private readonly OperationalTaskService $tasks,
        private readonly OperationalInterestSourceReader $interests,
    ) {}

    /** @return array{month:string,steps:list<string>,attribution:array<string,mixed>,activation:string} */
    public function run(): array
    {
        if (! config('campaign_operational.daily_automation_enabled', false)) {
            throw new RuntimeException('La automatización diaria de Campañas está deshabilitada.');
        }

        $month = CarbonImmutable::now('Europe/Madrid')->format('Y-m');
        $legacyEnabled = match ($month) {
            '2026-10' => config('campaign_operational.october_2026_enabled', false),
            '2026-11' => config('campaign_operational.november_2026_enabled', false),
            default => false,
        };
        if (! preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/D', $month)
            || (! in_array($month, config('campaign_operational.enabled_months', []), true) && ! $legacyEnabled)) {
            throw new RuntimeException('El período operacional de Campañas no tiene contrato habilitado.');
        }

        [$year, $number] = array_map('intval', explode('-', $month));
        $dayStart = CarbonImmutable::now('Europe/Madrid')->startOfDay()->utc();
        $interest = null;
        foreach (self::SOURCE_TASKS as $key) {
            $completedToday = DB::table('operational_task_runs')
                ->where('task_key', $key)->where('status', 'success')
                ->where('finished_at', '>=', $dayStart)->exists();
            if ($interest !== null && in_array($key, ['salesforce-sync-opportunities', 'salesforce-sync-opportunity-interest-direct'], true)) {
                $evidence = app(CampaignPublicationReadiness::class)->operationalOpportunityEvidence($interest['observed_at']);
                $completedToday = $evidence['available']
                    && CarbonImmutable::parse($evidence['opportunity_cutoff_at'] ?? '1970-01-01', 'UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($interest['effective_cutoff_at'], 'UTC'))
                    && CarbonImmutable::parse($evidence['direct_cutoff_at'] ?? '1970-01-01', 'UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($interest['effective_cutoff_at'], 'UTC'));
            }
            if ($completedToday) {
                if ($key === 'salesforce-sync-interest-operational-current') {
                    $interest = $this->interests->open($year, $number, true);
                }

                continue;
            }
            $run = $this->tasks->claim($key, 'scheduler');
            if ($run->status === 'skipped') {
                throw new RuntimeException('Una dependencia operacional ya está reservada.');
            }
            $this->tasks->execute($run->id);
            $run->refresh();
            if ($run->status !== 'success') {
                throw new RuntimeException('Una dependencia operacional no se completó.');
            }
            if ($key === 'salesforce-sync-interest-operational-current') {
                $interest = $this->interests->open($year, $number, true);
            }
        }

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
        $partialPublicationAllowed = config('campaign_operational.daily_automation_allow_partial_publication_enabled', false);
        if (($attribution['status'] ?? null) !== 'prepared'
            || (! ($attribution['opportunity_coverage_complete'] ?? false) && ! $partialPublicationAllowed)) {
            throw new RuntimeException('La atribución no cumple la política de calidad operacional.');
        }

        $activation = 'prepared_pending_approval';
        if (config('campaign_operational.daily_automation_auto_activate_enabled', false)) {
            $activation = $this->builder->activate($year, $number, (int) $attribution['run_id'])['status'] ?? 'unknown';
        }

        return ['month' => $month, 'steps' => self::SOURCE_TASKS, 'attribution' => $attribution, 'activation' => $activation];
    }
}
