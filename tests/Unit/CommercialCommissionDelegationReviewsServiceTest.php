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
}
