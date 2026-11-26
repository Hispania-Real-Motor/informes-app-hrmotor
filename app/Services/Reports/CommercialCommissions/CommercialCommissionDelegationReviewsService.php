<?php

namespace App\Services\Reports\CommercialCommissions;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CommercialCommissionDelegationReviewsService
{
    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {}

    public function forMonthAndDelegations(CarbonImmutable $month, Collection $delegationLabels, bool $refresh = false): array
    {
        $normalizedLabels = $delegationLabels
            ->filter()
            ->map(fn (mixed $label) => (string) $label)
            ->unique()
            ->values();
        $endpoint = trim((string) config('services.internal_reviews.endpoint', ''));
        $user = trim((string) config('services.internal_reviews.user', ''));
        $password = (string) config('services.internal_reviews.password', '');

        if ($endpoint === '' || $user === '' || $password === '') {
            Log::warning('Endpoint interno de reseñas no configurado.', [
                'integration' => 'internal_reviews',
                'status' => 'not_configured',
                'month' => $month->format('Y-m'),
            ]);

            return $normalizedLabels
                ->mapWithKeys(fn (string $label) => [$label => $this->emptyPayload('not_configured')])
                ->all();
        }

        $monthParam = $month->format('m-y');
        $timeout = min(5, max(1, (int) config('services.internal_reviews.timeout', 5)));
        $results = [];
        $ttl = $this->cacheTtlForMonth($month);
        $uncachedLabels = collect();

        foreach ($normalizedLabels as $delegationLabel) {
            $cacheKey = $this->cacheKey($monthParam, $delegationLabel);
            $cached = $refresh ? null : Cache::get($cacheKey);

            if (is_array($cached)) {
                $results[$delegationLabel] = $this->normalizePayload($cached);

                continue;
            }

            $uncachedLabels->push($delegationLabel);
        }

        if ($uncachedLabels->isNotEmpty()) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($uncachedLabels, $endpoint, $monthParam, $user, $password, $timeout): array {
                    $requests = [];

                    foreach ($uncachedLabels as $delegationLabel) {
                        $location = $this->formulaConfig->googleReviewsLocationForDelegation($delegationLabel);

                        if ($location === null) {
                            continue;
                        }

                        $requests[$delegationLabel] = $pool
                            ->as($delegationLabel)
                            ->acceptJson()
                            ->connectTimeout($timeout)
                            ->timeout($timeout)
                            ->withBasicAuth($user, $password)
                            ->get($endpoint, [
                                'month' => $monthParam,
                                'location' => $location,
                            ]);
                    }

                    return $requests;
                });
            } catch (\Throwable $exception) {
                Log::warning('Fallo controlado al consultar reseñas internas.', [
                    'integration' => 'internal_reviews',
                    'status' => 'transport_error',
                    'exception_type' => $exception::class,
                    'month' => $month->format('Y-m'),
                ]);

                foreach ($uncachedLabels as $delegationLabel) {
                    $results[$delegationLabel] = $this->emptyPayload('transport_error');
                }

                return $results;
            }

            foreach ($uncachedLabels as $delegationLabel) {
                $cacheKey = $this->cacheKey($monthParam, $delegationLabel);
                $response = $responses[$delegationLabel] ?? null;

                if (! $response instanceof Response) {
                    $results[$delegationLabel] = $this->emptyPayload('not_mapped');

                    continue;
                }

                if (! $response->successful()) {
                    Log::warning('Respuesta no satisfactoria de reseñas internas.', [
                        'integration' => 'internal_reviews',
                        'status' => 'remote_error',
                        'http_status' => $response->status(),
                        'month' => $month->format('Y-m'),
                    ]);
                    $results[$delegationLabel] = $this->emptyPayload('remote_error');

                    continue;
                }

                $payload = $this->normalizePayload([
                    ...(array) $response->json(),
                    'fetched_at' => now()->toIso8601String(),
                ]);
                $results[$delegationLabel] = $payload;
                Cache::put($cacheKey, $payload, $ttl);
            }
        }

        return $results;
    }

    private function cacheKey(string $monthParam, string $delegationLabel): string
    {
        return 'commercial-commissions:delegation-reviews:v3:'.$monthParam.':'.$this->formulaConfig->delegationKey($delegationLabel);
    }

    private function cacheTtlForMonth(CarbonImmutable $month): \DateTimeInterface
    {
        $minutes = max(1, (int) config('services.internal_reviews.cache_minutes', 15));

        return now()->addMinutes($minutes);
    }

    private function normalizePayload(array $payload): array
    {
        $rawCount = data_get($payload, 'reviews_count');
        $rawRating = data_get($payload, 'average_rating');
        $technicalStatus = 'available';
        if (! is_numeric($rawCount) || ($rawCount < 0) || ($rawCount == 0 && $rawRating !== null) || ($rawCount > 0 && ! is_numeric($rawRating))) {
            $technicalStatus = 'invalid_payload';
        }

        return [
            'reviews_count' => is_numeric($rawCount) ? max(0, (int) $rawCount) : null,
            'average_rating' => is_numeric($rawRating)
                ? (float) $rawRating
                : null,
            'technical_status' => $technicalStatus,
            'fetched_at' => filled($payload['fetched_at'] ?? null) ? (string) $payload['fetched_at'] : null,
        ];
    }

    private function emptyPayload(string $technicalStatus): array
    {
        return [
            'reviews_count' => 0,
            'average_rating' => null,
            'technical_status' => $technicalStatus,
            'fetched_at' => null,
        ];
    }
}
