<?php

namespace App\Services\Campaigns;

use App\Models\CampaignPlatformIdentifier;
use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaCampaignSyncService
{
    public function __construct(
        private readonly MetaAdsClient $client,
        private readonly CampaignMetricsRepository $metrics,
        private readonly CampaignOpenInvestmentWindow $openWindow,
        private readonly ReportSyncRunService $syncRuns,
    ) {}

    public function sync(CarbonInterface $start, CarbonInterface $end): array
    {
        $warnings = $this->client->missingConfigurationWarnings();

        if ($warnings !== []) {
            foreach ($warnings as $warning) {
                Log::warning($warning);
            }

            return [
                'configured' => false,
                'processed' => 0,
                'saved' => 0,
                'warnings' => $warnings,
            ];
        }

        $processed = 0;
        $saved = 0;
        $openStart = $this->openWindow->start($start, $end);
        if ($openStart === null) {
            $run = $this->syncRuns->start('campaign_platform_daily_metrics', 'meta', $start, $end);
            $run->update(['status' => 'skipped', 'completed_at' => now(),
                'stats' => ['reason' => 'closed_period', 'requested_start' => $start->toDateString(),
                    'requested_end_exclusive' => $end->toDateString()]]);

            return ['configured' => true, 'processed' => 0, 'saved' => 0,
                'warnings' => ['Intervalo omitido: no contiene días de inversión abiertos.']];
        }
        if ($openStart->greaterThan($start)) {
            $warnings[] = 'Intervalo acotado al primer día posterior al último cierre de inversión.';
        }
        $run = $this->syncRuns->start('campaign_platform_daily_metrics', 'meta', $openStart, $end);
        $hadFailure = false;

        try {
            $accountIds = $this->client->adAccountIds();
            foreach ($accountIds as $accountId) {
                try {
                    $rows = $this->client->insights($accountId, $openStart, $end);
                } catch (Throwable $exception) {
                    $hadFailure = true;
                    $warnings[] = 'No se ha podido actualizar la inversion de Meta Ads. Error de integración; detalle externo omitido.';
                    Log::warning(end($warnings));

                    continue;
                }

                try {
                    $campaigns = collect($this->client->campaigns($accountId))->keyBy('id');
                } catch (Throwable $exception) {
                    $campaigns = collect();
                    $hadFailure = true;
                    $warnings[] = 'No se ha podido actualizar el estado de campanas de Meta Ads. Error de integración; detalle externo omitido.';
                    Log::warning(end($warnings));
                }

                try {
                    $identifiers = $this->client->attributionIdentifiers($accountId);
                    $this->replaceIdentifiers($accountId, $identifiers);
                } catch (Throwable $exception) {
                    $hadFailure = true;
                    $warnings[] = 'No se ha podido actualizar el inventario auditable de Meta Ads. Error de integración; detalle externo omitido.';
                    Log::warning(end($warnings));
                }

                $this->openWindow->synchronized(function () use ($rows, $campaigns, $accountId, $openStart, $end, &$processed, &$saved, &$warnings, &$hadFailure): void {
                    DB::transaction(function () use ($rows, $campaigns, $accountId, $openStart, $end, &$processed, &$saved, &$warnings, &$hadFailure): void {
                        $writeStart = $this->openWindow->start($openStart, $end);
                        if ($writeStart === null) {
                            $hadFailure = true;
                            $warnings[] = 'No se ha podido actualizar la inversión de Meta Ads: el intervalo se cerró durante la consulta.';

                            return;
                        }
                        if ($writeStart->greaterThan($openStart)) {
                            $hadFailure = true;
                            $warnings[] = 'No se ha podido actualizar una parte de la inversión de Meta Ads: se cerró durante la consulta.';
                        }
                        DB::table('campaign_platform_daily_metrics')
                            ->where('platform', 'meta')
                            ->whereIn('account_id', $this->accountDeleteCandidates($accountId, $rows))
                            ->where('metric_date', '>=', $writeStart->toDateString())
                            ->where('metric_date', '<', $end->toDateString())
                            ->delete();

                        foreach ($rows as $row) {
                            $metricDate = data_get($row, 'date_start');
                            if (! is_string($metricDate) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $metricDate)
                                || ! checkdate((int) substr($metricDate, 5, 2), (int) substr($metricDate, 8, 2), (int) substr($metricDate, 0, 4))
                                || $metricDate < $openStart->toDateString() || $metricDate >= $end->toDateString()) {
                                throw new \RuntimeException('Fecha diaria de Meta Ads no válida.');
                            }
                            if ($metricDate < $writeStart->toDateString()) {
                                continue;
                            }
                            $campaign = $campaigns->get((string) data_get($row, 'campaign_id'));
                            $processed++;
                            $this->metrics->upsert([
                                'platform' => 'meta',
                                'metric_date' => data_get($row, 'date_start'),
                                'account_id' => data_get($row, 'account_id') ?: $accountId,
                                'account_name' => data_get($row, 'account_name'),
                                'campaign_id' => data_get($row, 'campaign_id'),
                                'campaign_name' => data_get($row, 'campaign_name'),
                                'campaign_status' => data_get($campaign, 'status'),
                                'campaign_effective_status' => data_get($campaign, 'effective_status'),
                                'campaign_start_date' => $this->dateOnly(data_get($campaign, 'start_time')),
                                'campaign_end_date' => $this->dateOnly(data_get($campaign, 'stop_time')),
                                'adset_id' => data_get($row, 'adset_id'),
                                'adset_name' => data_get($row, 'adset_name'),
                                'ad_id' => data_get($row, 'ad_id'),
                                'ad_name' => data_get($row, 'ad_name'),
                                'spend' => (float) data_get($row, 'spend', 0),
                                'impressions' => (int) data_get($row, 'impressions', 0),
                                'clicks' => (int) data_get($row, 'clicks', 0),
                                'platform_leads' => $this->extractLeads(data_get($row, 'actions', [])),
                                'currency' => data_get($row, 'account_currency'),
                                'raw_payload' => $row,
                            ]);
                            $saved++;
                        }
                    });
                });
            }
            $run->update(['stats' => ['processed' => $processed, 'saved' => $saved,
                'accounts' => count($accountIds), 'warnings' => count($warnings),
                'range_start' => $openStart->toDateString(), 'range_end_exclusive' => $end->toDateString()]]);
            if ($hadFailure) {
                $this->syncRuns->fail($run, 'Una cuenta o el inventario publicitario no se completó.');
            } else {
                $this->syncRuns->complete($run, $end, $run->stats);
            }
        } catch (Throwable $exception) {
            $this->syncRuns->fail($run, 'La sincronización económica de Meta Ads no se completó.');
            throw $exception;
        }

        $this->invalidateCache();

        return [
            'configured' => true,
            'processed' => $processed,
            'saved' => $saved,
            'warnings' => $warnings,
            'range_start' => $openStart->toDateString(),
            'range_end_exclusive' => $end->toDateString(),
        ];
    }

    private function extractLeads(mixed $actions): ?int
    {
        if (! is_array($actions)) {
            return null;
        }

        $leadActionTypes = [
            'lead',
            'onsite_conversion.lead_grouped',
            'onsite_conversion.messaging_conversation_started_7d',
            'offsite_conversion.fb_pixel_lead',
        ];

        $total = 0;
        $found = false;

        foreach ($actions as $action) {
            if (! in_array((string) data_get($action, 'action_type'), $leadActionTypes, true)) {
                continue;
            }

            $total += (int) data_get($action, 'value', 0);
            $found = true;
        }

        return $found ? $total : null;
    }

    private function dateOnly(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }

    private function accountDeleteCandidates(string $accountId, array $rows): array
    {
        return collect([
            $accountId,
            preg_replace('/^act_/i', '', $accountId),
            collect($rows)->pluck('account_id')->filter()->first(),
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function replaceIdentifiers(string $accountId, array $rows): void
    {
        DB::transaction(function () use ($accountId, $rows): void {
            DB::table('campaign_platform_identifiers')
                ->where('platform', 'meta')
                ->whereIn('account_id', [$accountId, preg_replace('/^act_/i', '', $accountId)])
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('campaign_platform_identifiers')->insert(array_map(function (array $row) use ($accountId): array {
                    $payload = [
                        'platform' => 'meta',
                        'account_id' => preg_replace('/^act_/i', '', $accountId),
                        'campaign_id' => (string) data_get($row, 'campaign.id'),
                        'campaign_name' => data_get($row, 'campaign.name'),
                        'adset_id' => (string) data_get($row, 'adset.id'),
                        'adset_name' => data_get($row, 'adset.name'),
                        'ad_group_id' => null,
                        'ad_group_name' => null,
                        'ad_id' => (string) data_get($row, 'id'),
                        'ad_name' => data_get($row, 'name'),
                        'raw_payload' => json_encode($row),
                        'synced_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $payload['unique_key'] = CampaignPlatformIdentifier::uniqueKey($payload);

                    return $payload;
                }, $chunk));
            }
        });
    }
}
