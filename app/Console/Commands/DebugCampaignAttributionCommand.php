<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignTypeResolver;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DebugCampaignAttributionCommand extends Command
{
    private const REPORT_TIMEZONE = 'Europe/Madrid';

    protected $signature = 'campaigns:debug-attribution
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final explicita en formato Y-m-d}';

    protected $description = 'Audita la coherencia entre salesforce_interests y campaign_lead_attributions para Campanas.';

    public function handle(CampaignTypeResolver $resolver, LeadRecordTypeNormalizer $typeNormalizer): int
    {
        $startLocal = CarbonImmutable::parse(
            $this->option('from') ?: now(self::REPORT_TIMEZONE)->startOfMonth()->toDateString(),
            self::REPORT_TIMEZONE
        )->startOfDay();
        $endLocalExclusive = CarbonImmutable::parse(
            $this->option('to') ?: now(self::REPORT_TIMEZONE)->toDateString(),
            self::REPORT_TIMEZONE
        )->addDay()->startOfDay();
        $start = $startLocal->utc();
        $end = $endLocalExclusive->utc();

        $interests = DB::table('salesforce_interests')
            ->where('is_deleted', false)
            ->where('functional_created_at', '>=', $start)
            ->where('functional_created_at', '<', $end)
            ->select(['salesforce_id', 'utm_campaign', 'type'])
            ->get()
            ->map(function (object $interest) use ($resolver, $typeNormalizer): array {
                $campaignName = $interest->utm_campaign;
                $reason = $resolver->excludedReason($campaignName);
                $interestType = $typeNormalizer->normalize($interest->type);

                return [
                    'interest_id' => (string) $interest->salesforce_id,
                    'source_campaign_name' => (string) $campaignName,
                    'excluded_reason' => $reason,
                    'campaign_type' => $reason === null
                        ? ($interestType === 'tasacion' ? 'tasacion' : $resolver->sourceCampaignType($campaignName))
                        : null,
                ];
            });

        $validInterests = $interests->filter(fn (array $row): bool => $row['excluded_reason'] === null)->values();
        $attributions = DB::table('campaign_lead_attributions')
            ->where('interest_functional_created_at', '>=', $start)
            ->where('interest_functional_created_at', '<', $end)
            ->select(['interest_id', 'source_campaign_name', 'campaign_name', 'campaign_type'])
            ->get()
            ->map(fn (object $row): array => [
                'interest_id' => (string) $row->interest_id,
                'source_campaign_name' => (string) ($row->source_campaign_name ?? ''),
                'campaign_name' => (string) ($row->campaign_name ?? ''),
                'campaign_type' => (string) ($row->campaign_type ?? ''),
            ])
            ->values();

        $this->line('Periodo local: '.$startLocal->toDateString().' a '.$endLocalExclusive->subDay()->toDateString());
        $this->line('Rango UTC consultado: '.$start->toDateTimeString().' a '.$end->subSecond()->toDateTimeString());
        $this->line('Tabla base: salesforce_interests');
        $this->newLine();

        $this->renderScope('Todas', $validInterests, $attributions);
        $this->renderScope('Venta', $validInterests->where('campaign_type', 'venta')->values(), $attributions->where('campaign_type', 'venta')->values());
        $this->renderScope('Tasacion', $validInterests->where('campaign_type', 'tasacion')->values(), $attributions->where('campaign_type', 'tasacion')->values());

        $this->newLine();
        $this->table(
            ['motivo_exclusion', 'total'],
            $this->groupCounts(
                $interests->filter(fn (array $row): bool => $row['excluded_reason'] !== null),
                'excluded_reason'
            )
        );

        $this->newLine();
        $this->line('Desglose por source_campaign_name valido');
        $this->table(['source_campaign_name', 'total'], $this->groupCounts($validInterests, 'source_campaign_name'));

        $this->newLine();
        $this->line('Desglose visible en campaign_lead_attributions');
        $this->table(['campaign_name', 'total'], $this->groupCounts($attributions, 'campaign_name'));

        $this->newLine();
        $this->line('Desglose por campaign_type en campaign_lead_attributions');
        $this->table(['campaign_type', 'total'], $this->groupCounts($attributions, 'campaign_type'));

        return self::SUCCESS;
    }

    private function renderScope(string $label, Collection $validInterests, Collection $attributions): void
    {
        $validIds = $validInterests->pluck('interest_id');
        $attributionIds = $attributions->pluck('interest_id');

        $this->line(sprintf(
            '[%s] validos=%d | atribuciones=%d | huerfanas=%d | faltantes=%d',
            $label,
            $validInterests->count(),
            $attributions->count(),
            $attributionIds->diff($validIds)->count(),
            $validIds->diff($attributionIds)->count(),
        ));
    }

    private function groupCounts(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row[$field] ?: '[vacio]'))
            ->map(fn (Collection $items, string $value): array => [$field => $value, 'total' => $items->count()])
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
