<?php

namespace App\Services\Campaigns;

use App\Models\CampaignPlatformIdentifier;
use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GoogleCampaignSyncService
{
    public function __construct(
        private readonly GoogleAdsClient $client,
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
            $run = $this->syncRuns->start('campaign_platform_daily_metrics', 'google_ads', $start, $end);
            $run->update(['status' => 'skipped', 'completed_at' => now(),
                'stats' => ['reason' => 'closed_period', 'requested_start' => $start->toDateString(),
                    'requested_end_exclusive' => $end->toDateString()]]);

            return ['configured' => true, 'processed' => 0, 'saved' => 0,
                'warnings' => ['Intervalo omitido: no contiene días de inversión abiertos.']];
        }
        if ($openStart->greaterThan($start)) {
            $warnings[] = 'Intervalo acotado al primer día posterior al último cierre de inversión.';
        }
        $run = $this->syncRuns->start('campaign_platform_daily_metrics', 'google_ads', $openStart, $end);
        $hadFailure = false;

        try {
            $customerIds = $this->client->customerIds();
            foreach ($customerIds as $customerId) {
                try {
                    $rows = $this->client->searchDailyMetrics($customerId, $openStart, $end);
                } catch (Throwable $exception) {
                    $hadFailure = true;
                    $warnings[] = 'No se ha podido actualizar la inversion de Google Ads. Error de integración; detalle externo omitido.';
                    Log::warning(end($warnings));

                    continue;
                }

                try {
                    $identifiers = $this->client->searchAttributionIdentifiers($customerId);
                    $this->replaceIdentifiers($customerId, $identifiers);
                } catch (Throwable $exception) {
                    $hadFailure = true;
                    $warnings[] = 'No se ha podido actualizar el inventario auditable de Google Ads. Error de integración; detalle externo omitido.';
                    Log::warning(end($warnings));
                }

                $this->openWindow->synchronized(function () use ($rows, $customerId, $openStart, $end, &$processed, &$saved, &$warnings, &$hadFailure): void {
                    DB::transaction(function () use ($rows, $customerId, $openStart, $end, &$processed, &$saved, &$warnings, &$hadFailure): void {
                        $writeStart = $this->openWindow->start($openStart, $end);
                        if ($writeStart === null) {
                            $hadFailure = true;
                            $warnings[] = 'No se ha podido actualizar la inversión de Google Ads: el intervalo se cerró durante la consulta.';

                            return;
                        }
                        if ($writeStart->greaterThan($openStart)) {
                            $hadFailure = true;
                            $warnings[] = 'No se ha podido actualizar una parte de la inversión de Google Ads: se cerró durante la consulta.';
                        }
                        DB::table('campaign_platform_daily_metrics')
                            ->where('platform', 'google_ads')
                            ->where('account_id', $customerId)
                            ->where('metric_date', '>=', $writeStart->toDateString())
                            ->where('metric_date', '<', $end->toDateString())
                            ->delete();

                        foreach ($rows as $row) {
                            $metricDate = data_get($row, 'segments.date');
                            if (! is_string($metricDate) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $metricDate)
                                || ! checkdate((int) substr($metricDate, 5, 2), (int) substr($metricDate, 8, 2), (int) substr($metricDate, 0, 4))
                                || $metricDate < $openStart->toDateString() || $metricDate >= $end->toDateString()) {
                                throw new RuntimeException('Fecha diaria de Google Ads no válida.');
                            }
                            if ($metricDate < $writeStart->toDateString()) {
                                continue;
                            }
                            $processed++;
                            $this->metrics->upsert([
                                'platform' => 'google_ads',
                                'metric_date' => data_get($row, 'segments.date'),
                                'account_id' => $customerId,
                                'campaign_id' => (string) data_get($row, 'campaign.id'),
                                'campaign_name' => data_get($row, 'campaign.name'),
                                'campaign_status' => data_get($row, 'campaign.status'),
                                'campaign_start_date' => $this->campaignDate(data_get($row, 'campaign.startDateTime')),
                                'campaign_end_date' => $this->campaignDate(data_get($row, 'campaign.endDateTime')),
                                'advertising_channel_type' => data_get($row, 'campaign.advertisingChannelType'),
                                'advertising_channel_sub_type' => data_get($row, 'campaign.advertisingChannelSubType'),
                                'ad_group_id' => null,
                                'ad_group_name' => null,
                                'spend' => round(((float) data_get($row, 'metrics.costMicros', 0)) / 1000000, 2),
                                'impressions' => (int) data_get($row, 'metrics.impressions', 0),
                                'clicks' => (int) data_get($row, 'metrics.clicks', 0),
                                'platform_conversions' => (float) (data_get($row, 'metrics.allConversions') ?? data_get($row, 'metrics.conversions', 0)),
                                'raw_payload' => $row,
                            ]);
                            $saved++;
                        }
                    });
                });
            }
            $run->update(['stats' => ['processed' => $processed, 'saved' => $saved,
                'accounts' => count($customerIds), 'warnings' => count($warnings),
                'range_start' => $openStart->toDateString(), 'range_end_exclusive' => $end->toDateString()]]);
            if ($hadFailure) {
                $this->syncRuns->fail($run, 'Una cuenta o el inventario publicitario no se completó.');
            } else {
                $this->syncRuns->complete($run, $end, $run->stats);
            }
        } catch (Throwable $exception) {
            $this->syncRuns->fail($run, 'La sincronización económica de Google Ads no se completó.');
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

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }

    private function campaignDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\z/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new RuntimeException('Fecha de campaña Google Ads no válida.');
        }

        return substr($value, 0, 10);
    }

    private function replaceIdentifiers(string $customerId, array $rows): void
    {
        DB::transaction(function () use ($customerId, $rows): void {
            DB::table('campaign_platform_identifiers')
                ->where('platform', 'google_ads')
                ->where('account_id', $customerId)
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('campaign_platform_identifiers')->insert(array_map(function (array $row) use ($customerId): array {
                    $payload = [
                        'platform' => 'google_ads',
                        'account_id' => $customerId,
                        'campaign_id' => (string) data_get($row, 'campaign.id'),
                        'campaign_name' => data_get($row, 'campaign.name'),
                        'adset_id' => null,
                        'adset_name' => null,
                        'ad_group_id' => (string) data_get($row, 'adGroup.id'),
                        'ad_group_name' => data_get($row, 'adGroup.name'),
                        'ad_id' => (string) data_get($row, 'adGroupAd.ad.id'),
                        'ad_name' => data_get($row, 'adGroupAd.ad.name'),
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
