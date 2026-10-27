<?php

namespace App\Services\Campaigns;

use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MetaAdsClient
{
    /** Independent operational inventory; fixed endpoint, bounded complete pagination. */
    public function currentStatusPages(string $accountId): \Generator
    {
        if (! $this->configured()) {
            throw new RuntimeException('Meta Ads no configurado.');
        }
        $accountId = CampaignCurrentStatusService::identifier($accountId, 'meta', true);
        $url = sprintf('https://graph.facebook.com/%s/act_%s/campaigns', config('services.meta_ads.api_version', 'v25.0'), $accountId);
        $params = ['fields' => 'id,account_id,name,status,effective_status', 'limit' => 500];
        $seen = [];
        $started = microtime(true);
        for ($page = 0; $page < 100; $page++) {
            if (microtime(true) - $started > 540) {
                throw new RuntimeException('Tiempo máximo de inventario superado.');
            }
            $response = Http::withToken(config('services.meta_ads.access_token'))->connectTimeout(5)->timeout(30)
                ->retry(2, 250, throw: false)->get($url, $params);
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || isset($payload['error']) || ! isset($payload['data']) || ! is_array($payload['data']) || ! array_is_list($payload['data'])) {
                throw new RuntimeException('No se pudo leer el inventario de Meta Ads.');
            }
            yield $payload['data'];
            if (empty($payload['paging']['next'])) {
                return;
            }
            $cursor = $payload['paging']['cursors']['after'] ?? null;
            if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 4096 || isset($seen[$cursor])) {
                throw new RuntimeException('Paginación de Meta Ads incompleta.');
            }
            $seen[$cursor] = true;
            $params['after'] = $cursor;
        }
        throw new RuntimeException('Límite de paginación de Meta Ads superado.');
    }

    public function configured(): bool
    {
        return filled(config('services.meta_ads.access_token'))
            && config('services.meta_ads.ad_account_ids') !== [];
    }

    public function missingConfigurationWarnings(): array
    {
        if ($this->configured()) {
            return [];
        }

        return ['Las credenciales de Meta Ads no estan configuradas. Se usara la inversion cacheada si existe.'];
    }

    public function adAccountIds(): array
    {
        return config('services.meta_ads.ad_account_ids', []);
    }

    public function insights(string $accountId, CarbonInterface $start, CarbonInterface $end): array
    {
        $until = $end->toDateString();

        if ($start->toDateString() === $until) {
            return [];
        }

        $apiVersion = trim((string) config('services.meta_ads.api_version', 'v25.0'));
        $accessToken = trim((string) config('services.meta_ads.access_token'));

        $accountId = trim($accountId);
        $account = str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;

        if (blank($accessToken)) {
            throw new RuntimeException('Meta Ads API error: META_ACCESS_TOKEN no está configurado.');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/insights',
            $apiVersion,
            $account
        );

        $baseParams = [
            'level' => 'campaign',
            'time_increment' => 1,
            'time_range' => json_encode([
                'since' => $start->toDateString(),
                'until' => $end->subDay()->toDateString(),
            ]),
            'fields' => implode(',', [
                'date_start',
                'date_stop',
                'account_id',
                'account_name',
                'campaign_id',
                'campaign_name',
                'spend',
                'impressions',
                'clicks',
            ]),
            'limit' => 500,
        ];

        $rows = [];
        $after = null;

        do {
            $params = $baseParams;

            if (filled($after)) {
                $params['after'] = $after;
            }

            $response = Http::withToken($accessToken)
                ->timeout(120)
                ->retry(2, 1000, throw: false)
                ->get($url, $params);

            if ($response->failed()) {
                Log::error('Meta Ads response error', [
                    'integration' => 'meta_ads',
                    'status' => $response->status(),
                    'account_resolved' => $account,
                    'level' => $params['level'] ?? null,
                    'error_type' => 'remote_http_error',
                ]);

                throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                    'Meta Ads insights',
                    $response->status(),
                    $response->json()
                ));
            }

            $payload = $response->json();

            $rows = array_merge($rows, $payload['data'] ?? []);

            $after = data_get($payload, 'paging.cursors.after');
            $hasNextPage = filled(data_get($payload, 'paging.next')) && filled($after);
        } while ($hasNextPage);

        return $rows;
    }

    public function campaigns(string $accountId): array
    {
        $apiVersion = trim((string) config('services.meta_ads.api_version', 'v25.0'));
        $accessToken = trim((string) config('services.meta_ads.access_token'));

        $accountId = trim($accountId);
        $account = str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;

        if (blank($accessToken)) {
            throw new RuntimeException('Meta Ads API error: META_ACCESS_TOKEN no esta configurado.');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/campaigns',
            $apiVersion,
            $account
        );

        $baseParams = [
            'fields' => implode(',', [
                'id',
                'name',
                'status',
                'effective_status',
                'start_time',
                'stop_time',
            ]),
            'limit' => 500,
        ];

        $rows = [];
        $after = null;

        do {
            $params = $baseParams;

            if (filled($after)) {
                $params['after'] = $after;
            }

            $response = Http::withToken($accessToken)
                ->timeout(120)
                ->retry(2, 1000, throw: false)
                ->get($url, $params);

            if ($response->failed()) {
                throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                    'Meta Ads campañas',
                    $response->status(),
                    $response->json()
                ));
            }

            $payload = $response->json();
            $rows = array_merge($rows, $payload['data'] ?? []);

            $after = data_get($payload, 'paging.cursors.after');
            $hasNextPage = filled(data_get($payload, 'paging.next')) && filled($after);
        } while ($hasNextPage);

        return $rows;
    }

    /**
     * Devuelve el inventario de IDs publicitarios necesario para explicar cada cruce.
     * La inversion sigue consultandose a nivel campana para no multiplicar importes.
     */
    public function attributionIdentifiers(string $accountId): array
    {
        $apiVersion = trim((string) config('services.meta_ads.api_version', 'v25.0'));
        $accessToken = trim((string) config('services.meta_ads.access_token'));
        $account = str_starts_with(trim($accountId), 'act_') ? trim($accountId) : 'act_'.trim($accountId);

        if (blank($accessToken)) {
            throw new RuntimeException('Meta Ads API error: META_ACCESS_TOKEN no esta configurado.');
        }

        $url = sprintf('https://graph.facebook.com/%s/%s/ads', $apiVersion, $account);
        $baseParams = [
            'fields' => 'id,name,adset{id,name},campaign{id,name}',
            'limit' => 500,
        ];
        $rows = [];
        $after = null;

        do {
            $params = $baseParams;
            if (filled($after)) {
                $params['after'] = $after;
            }

            $response = Http::withToken($accessToken)
                ->timeout(120)
                ->retry(2, 1000, throw: false)
                ->get($url, $params);
            if ($response->failed()) {
                throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                    'Meta Ads identificadores',
                    $response->status(),
                    $response->json()
                ));
            }

            $payload = $response->json();
            $rows = array_merge($rows, $payload['data'] ?? []);
            $after = data_get($payload, 'paging.cursors.after');
            $hasNextPage = filled(data_get($payload, 'paging.next')) && filled($after);
        } while ($hasNextPage);

        return $rows;
    }
}
