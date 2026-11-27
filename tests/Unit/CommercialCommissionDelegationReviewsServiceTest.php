<?php

namespace Tests\Unit;

use App\Services\Reports\CommercialCommissions\CommercialCommissionDelegationReviewsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommercialCommissionDelegationReviewsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.internal_reviews.endpoint', 'https://reviews.example.test/monthly');
        config()->set('services.internal_reviews.user', 'fixture-user');
        config()->set('services.internal_reviews.password', 'fixture-password');
        config()->set('services.internal_reviews.cache_minutes', 15);
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_zero_reviews_is_confirmed_without_inventing_a_positive_average(): void
    {
        Http::fake([
            '*' => Http::response(['reviews_count' => 0, 'average_rating' => null], 200),
        ]);

        $payload = app(CommercialCommissionDelegationReviewsService::class)
            ->forMonthAndDelegations(CarbonImmutable::parse('2026-10-01'), collect(['Alicante']))['Alicante'];

        $this->assertSame(0, $payload['reviews_count']);
        $this->assertNull($payload['average_rating']);
        $this->assertSame('available', $payload['technical_status']);
        $this->assertNotNull($payload['fetched_at']);
    }

    public function test_zero_reviews_with_a_positive_average_is_invalid(): void
    {
        Http::fake([
            '*' => Http::response(['reviews_count' => 0, 'average_rating' => 4.2], 200),
        ]);

        $payload = app(CommercialCommissionDelegationReviewsService::class)
            ->forMonthAndDelegations(CarbonImmutable::parse('2026-10-01'), collect(['Alicante']))['Alicante'];

        $this->assertSame('invalid_payload', $payload['technical_status']);
        $this->assertSame(4.2, $payload['average_rating']);
    }

    public function test_review_average_preserves_precision_below_the_positive_threshold(): void
    {
        Http::fake([
            '*' => Http::response(['reviews_count' => 3, 'average_rating' => 3.995], 200),
        ]);

        $payload = app(CommercialCommissionDelegationReviewsService::class)
            ->forMonthAndDelegations(CarbonImmutable::parse('2026-10-01'), collect(['Alicante']))['Alicante'];

        $this->assertSame('available', $payload['technical_status']);
        $this->assertSame(3.995, $payload['average_rating']);
    }

    public function test_missing_location_mapping_is_distinguished_from_a_valid_zero(): void
    {
        Http::fake();

        $payload = app(CommercialCommissionDelegationReviewsService::class)
            ->forMonthAndDelegations(CarbonImmutable::parse('2026-10-01'), collect(['Delegación no mapeada']))['Delegación no mapeada'];

        $this->assertSame('not_mapped', $payload['technical_status']);
        $this->assertNull($payload['fetched_at']);
        Http::assertNothingSent();
    }

    public function test_explicit_refresh_bypasses_the_short_term_cache_without_changing_the_contract(): void
    {
        Http::fakeSequence()
            ->push(['reviews_count' => 1, 'average_rating' => 4.1], 200)
            ->push(['reviews_count' => 2, 'average_rating' => 4.3], 200);
        $service = app(CommercialCommissionDelegationReviewsService::class);
        $month = CarbonImmutable::parse('2026-10-01');

        $first = $service->forMonthAndDelegations($month, collect(['Alicante']))['Alicante'];
        $cached = $service->forMonthAndDelegations($month, collect(['Alicante']))['Alicante'];
        $refreshed = $service->forMonthAndDelegations($month, collect(['Alicante']), true)['Alicante'];

        $this->assertSame(1, $first['reviews_count']);
        $this->assertSame(1, $cached['reviews_count']);
        $this->assertSame(2, $refreshed['reviews_count']);
        Http::assertSentCount(2);
    }
}
