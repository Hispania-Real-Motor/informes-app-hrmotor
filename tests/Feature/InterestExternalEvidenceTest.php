<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestExternalEvidenceService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class InterestExternalEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_evidence_is_read_only_deterministic_and_paged(): void
    {
        DB::table('salesforce_interest_generations')->insert([
            'id' => 91, 'base_generation_id' => null, 'cutoff_at' => '2026-10-28 15:57:00',
            'status' => 'validating', 'f2_complete' => true, 'f5_complete' => true,
            'started_at' => '2026-10-28 16:00:00',
        ]);
        $client = Mockery::mock(SalesforceClient::class);
        $sync = Mockery::mock(SalesforceInterestSyncService::class);
        $sync->shouldReceive('activeSoql')->once()->andReturn('active');
        $sync->shouldReceive('deletedSoql')->once()->andReturn('deleted');
        $pages = [
            'active' => [[['Id' => 'a01AAA000000000001', 'IsDeleted' => false]], [['Id' => 'a01AAA000000000002', 'IsDeleted' => false]]],
            'deleted' => [[['Id' => 'a01AAA000000000003', 'IsDeleted' => true]]],
            'task' => [[['Id' => '00TAAA000000000001', 'WhatId' => 'a01AAA000000000001', 'IsDeleted' => false]]],
            'event' => [[['Id' => '00UAAA000000000001', 'WhatId' => 'a01AAA000000000001', 'IsDeleted' => false]]],
        ];
        $client->shouldReceive('queryPages')->times(8)->andReturnUsing(function (string $soql) use (&$pages): \Generator {
            $key = match ($soql) { 'active' => 'active', 'deleted' => 'deleted', default => str_contains($soql, 'FROM Task') ? 'task' : 'event' };
            foreach ($pages[$key] as $page) {
                yield $page;
            }
        });
        $service = new InterestExternalEvidenceService($client, $sync);

        $before = DB::table('salesforce_interest_generations')->count();
        $first = $service->collect(91);
        $second = $service->collect(91);

        $this->assertSame($first, $second);
        $this->assertSame(3, $first['rows']);
        $this->assertSame(1, $first['deleted']);
        $this->assertSame($before, DB::table('salesforce_interest_generations')->count());
        $this->assertSame(64, strlen($first['identifiers_sha256']));
        $this->assertSame(64, strlen($first['relationships_sha256']));
        $this->assertSame(64, strlen($first['coverage_sha256']));
    }

}
