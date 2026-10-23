<?php

namespace App\Services\Campaigns;

use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAdsClient
{
    /** Attribute-only GAQL: includes removed campaigns, without dates or metrics. */
    public function currentStatusPages(string $customerId): \Generator
    {
        $customerId = CampaignCurrentStatusService::identifier($customerId, 'google_ads', true);
        $token = $this->accessToken();
        $url = sprintf('https://googleads.googleapis.com/%s/customers/%s/googleAds:search', config('services.google_ads.api_version', 'v22'), $customerId);
        $headers = ['Authorization' => 'Bearer '.$token, 'developer-token' => config('services.google_ads.developer_token')];
        if (filled(config('services.google_ads.login_customer_id'))) {
            $headers['login-customer-id'] = CampaignCurrentStatusService::identifier((string) config('services.google_ads.login_customer_id'), 'google_ads', true);
        }
        $params = ['query' => "SELECT customer.id, campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.status IN ('ENABLED', 'PAUSED', 'REMOVED')"];
        $seen = [];
        $started = microtime(true);
        for ($page = 0; $page < 100; $page++) {
            if (microtime(true) - $started > 540) {
                throw new RuntimeException('Tiempo máximo de inventario superado.');
            }
            $response = Http::withHeaders($headers)->connectTimeout(5)->timeout(30)->retry(2, 250, throw: false)->post($url, $params);
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || isset($payload['error'])
                || (! isset($payload['results']) && ! is_string($payload['fieldMask'] ?? null))
                || (isset($payload['results']) && (! is_array($payload['results']) || ! array_is_list($payload['results'])))) {
                throw new RuntimeException('No se pudo leer el inventario de Google Ads.');
            }
            $rows = [];
            foreach ($payload['results'] ?? [] as $result) {
                if (! is_array($result['campaign'] ?? null) || ! array_key_exists('status', $result['campaign'])) {
                    throw new RuntimeException('Faltan atributos del inventario de Google Ads.');
                }
                $rows[] = ['id' => $result['campaign']['id'] ?? null, 'account_id' => $result['customer']['id'] ?? null, 'status' => $result['campaign']['status'] ?? null];
            }
            yield $rows;
            $cursor = $payload['nextPageToken'] ?? null;
            if ($cursor === null || $cursor === '') {
                return;
            }
            if (! is_string($cursor) || strlen($cursor) > 4096 || isset($seen[$cursor])) {
                throw new RuntimeException('Paginación de Google Ads incompleta.');
            }
            $seen[$cursor] = true;
            $params['pageToken'] = $cursor;
        }
        throw new RuntimeException('Límite de paginación de Google Ads superado.');
    }

    public function configured(): bool
    {
        return filled(config('services.google_ads.developer_token'))
            && filled(config('services.google_ads.client_id'))
            && filled(config('services.google_ads.client_secret'))
            && filled(config('services.google_ads.refresh_token'))
            && config('services.google_ads.customer_ids') !== [];
    }

    public function missingConfigurationWarnings(): array
    {
        if ($this->configured()) {
            return [];
        }

        return ['Las credenciales de Google Ads no estan configuradas. Se usara la inversion cacheada si existe.'];
    }

    public function customerIds(): array
    {
        return config('services.google_ads.customer_ids', []);
    }

    public function searchDailyMetrics(string $customerId, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->search($customerId, $this->gaql($start, $end));
    }

    public function searchAttributionIdentifiers(string $customerId): array
    {
        return $this->search($customerId, <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  ad_group_ad.ad.id,
  ad_group_ad.ad.name
FROM ad_group_ad
WHERE ad_group_ad.status != 'REMOVED'
GAQL);
    }

    private function search(string $customerId, string $query): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Google Ads no configurado.');
        }

        $token = $this->accessToken();
        $apiVersion = config('services.google_ads.api_version', 'v22');
        $url = sprintf(
            'https://googleads.googleapis.com/%s/customers/%s/googleAds:searchStream',
            $apiVersion,
            preg_replace('/\D+/', '', $customerId)
        );

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'developer-token' => config('services.google_ads.developer_token'),
        ];

        if (filled(config('services.google_ads.login_customer_id'))) {
            $headers['login-customer-id'] = preg_replace('/\D+/', '', (string) config('services.google_ads.login_customer_id'));
        }

        $response = Http::timeout(120)
            ->withHeaders($headers)
            ->post($url, [
                'query' => $query,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'Google Ads API',
                $response->status(),
                $response->json()
            ));
        }

        return collect($response->json())
            ->flatMap(fn (array $chunk) => $chunk['results'] ?? [])
            ->values()
            ->all();
    }

    private function accessToken(): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Google Ads no configurado.');
        }

        $response = Http::asForm()
            ->timeout(60)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_ads.client_id'),
                'client_secret' => config('services.google_ads.client_secret'),
                'refresh_token' => config('services.google_ads.refresh_token'),
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'Google OAuth',
                $response->status(),
                $response->json()
            ));
        }

        return (string) $response->json('access_token');
    }

    private function gaql(CarbonInterface $start, CarbonInterface $end): string
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return <<<GAQL
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.start_date,
  campaign.end_date,
  campaign.advertising_channel_type,
  campaign.advertising_channel_sub_type,
  metrics.impressions,
  metrics.clicks,
  metrics.conversions,
  metrics.all_conversions,
  metrics.cost_micros
FROM campaign
WHERE
  segments.date >= '{$startDate}'
  AND segments.date < '{$endDate}'
ORDER BY segments.date ASC
GAQL;
    }
}
