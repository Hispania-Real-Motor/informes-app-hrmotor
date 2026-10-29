<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\InterestGenerationReader;
use App\Services\Salesforce\InterestGenerationStore;
use App\Services\Salesforce\SalesforceAuthService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestActivitySyncService;
use App\Services\Salesforce\SalesforceInterestReportingPipelineService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InterestGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function store(): InterestGenerationStore
    {
        return app(InterestGenerationStore::class);
    }

    public function test_second_extractor_cannot_take_over_candidate_or_leave_its_run_running(): void
    {
        $id = $this->candidate();
        $owner = $this->store()->generation($id)->interest_run_id;
        Http::preventStrayRequests();
        try {
            app(SalesforceInterestSyncService::class)->sync('full', CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'), 300, $id);
            $this->fail('A second extractor must be rejected.');
        } catch (RuntimeException) {
            $this->assertEquals($owner, $this->store()->generation($id)->interest_run_id);
            $this->assertSame('syncing', $this->store()->generation($id)->status);
            $this->assertSame('failed', ReportSyncRun::latest('id')->firstOrFail()->status);
            Http::assertNothingSent();
        }
    }

    private function row(string $id = 'a01000000000000001', ?string $origin = null, string $status = 'new'): array
    {
        return ['salesforce_id' => $id, 'migration_origin_lead_id' => $origin,
            'status' => $status, 'is_deleted' => false, 'raw_payload' => '{"synthetic":true}',
            'salesforce_created_at' => '2026-10-01 00:00:00',
            'salesforce_last_modified_at' => '2026-10-01 00:00:00',
            'functional_created_at' => '2026-10-01 00:00:00'];
    }

    private function candidate(string $cutoff = '2026-10-28 10:00:00'): int
    {
        $date = CarbonImmutable::parse($cutoff, 'UTC');
        $id = $this->store()->begin($date, $this->store()->published() === null);
        $run = app(ReportSyncRunService::class)->start('salesforce_interests', 'salesforce', $date, $date, 'UTC');
        $this->store()->bindRun($id, $run);

        return $id;
    }

    private function complete(int $id): void
    {
        $g = $this->store()->generation($id);
        $this->store()->finishF2($id);
        app(ReportSyncRunService::class)->complete(ReportSyncRun::find($g->interest_run_id), CarbonImmutable::parse($g->cutoff_at));
        $f5 = SalesforceInterestActivityRun::create([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic generation fixture',
            'status' => 'completed', 'source_interest_sync_run_id' => $g->interest_run_id,
            'source_interest_cutoff_at' => $g->cutoff_at, 'source_cutoff_at' => $g->cutoff_at,
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $this->store()->finishF5($id, $f5);
    }

    private function certify(int $id): void
    {
        $manifest = $this->store()->manifest($id);
        $g = $this->store()->generation($id);
        $this->store()->certify($id, ['source' => 'isolated_salesforce_reconstruction',
            'manifest_sha256' => $manifest['sha256'], 'manifest' => $manifest,
            'f5' => ['run_id' => $g->activity_run_id, 'cutoff_at' => $g->cutoff_at],
            'external' => ['source' => 'salesforce_export_review_20261028',
                'identifiers_sha256' => $manifest['identifiers_sha256'], 'rows' => $manifest['rows'],
                'deleted' => $manifest['deleted'], 'relationships_sha256' => hash('sha256', 'synthetic-relations'),
                'coverage_sha256' => hash('sha256', 'synthetic-coverage')],
            'checks' => array_fill_keys(['identifiers', 'counts', 'deletions', 'relationships', 'coverage', 'f5'], true)]);
    }

    private function publishBase(): int
    {
        $id = $this->candidate();
        $this->store()->persist($id, [$this->row(origin: '00Q000000000000001')]);
        $this->complete($id);
        $this->certify($id);
        $this->store()->publish($id);

        return $id;
    }

    public function test_initial_recovery_seed_reuses_versions_without_changing_source(): void
    {
        $source = $this->candidate();
        $this->store()->persist($source, [$this->row()]);
        $this->complete($source);
        $before = $this->store()->generation($source);
        $member = DB::table('salesforce_interest_members')->where('generation_id', $source)->first();

        $recovery = $this->store()->beginInitialRecovery($source, CarbonImmutable::parse('2026-10-28 11:00:00', 'UTC'));

        $this->assertEquals($before, $this->store()->generation($source));
        $this->assertNull($this->store()->generation($recovery)->base_generation_id);
        $this->assertDatabaseHas('salesforce_interest_members', [
            'generation_id' => $recovery, 'salesforce_id' => $member->salesforce_id, 'version_id' => $member->version_id,
        ]);
        $this->assertDatabaseCount('salesforce_interest_versions', 1);
        $this->assertNull($this->store()->published());
    }

    public function test_initial_recovery_rejects_an_existing_publication(): void
    {
        $source = $this->publishBase();
        $this->expectException(RuntimeException::class);
        $this->store()->beginInitialRecovery($source, CarbonImmutable::parse('2026-10-28 11:00:00', 'UTC'));
    }

    public function test_failure_and_partial_writes_do_not_change_published_rows_or_watermark(): void
    {
        $base = $this->publishBase();
        $next = $this->candidate('2026-10-28 11:00:00');
        $this->store()->persist($next, [$this->row(origin: '00Q000000000000001', status: 'changed')]);
        $this->store()->fail($next);
        $this->assertSame('new', $this->store()->rows($base)->first()->status);
        $this->assertSame('changed', $this->store()->rows($next)->first()->status);
        $this->assertEquals($base, $this->store()->published()->id);
        $this->assertDatabaseHas('salesforce_interest_publication', ['watermark_at' => '2026-10-28 10:00:00']);
        $this->assertDatabaseCount('salesforce_interests', 0);
    }

    public function test_base_collision_is_auditable_and_other_records_are_persisted(): void
    {
        $this->publishBase();
        $id = $this->candidate('2026-10-28 11:00:00');
        $rows = [$this->row('a01000000000000002', '00Q000000000000001'), $this->row('a01000000000000003')];
        $stats = $this->store()->persist($id, $rows);
        $this->store()->persist($id, $rows);
        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame(1, $stats['inserted']);
        $this->assertSame(2, $this->store()->rows($id)->count());
        $this->assertDatabaseCount('salesforce_interest_conflicts', 1);
        $evidence = DB::table('salesforce_interest_conflicts')->first();
        $this->assertSame('base_owner_conflict', $evidence->kind);
        $this->assertStringNotContainsString('synthetic', $evidence->evidence);
        $this->assertStringContainsString('synthetic', Crypt::decryptString($evidence->evidence));
        $this->expectException(RuntimeException::class);
        $this->store()->finishF2($id);
    }

    public function test_same_block_collision_quarantines_both_without_choosing_an_owner(): void
    {
        $id = $this->candidate();
        $stats = $this->store()->persist($id, [$this->row(origin: '00Q000000000000001'), $this->row('a01000000000000002', '00Q000000000000001')]);
        $this->assertSame(2, $stats['conflicts']);
        $this->assertSame(0, $this->store()->rows($id)->count());
        $this->assertDatabaseCount('salesforce_interest_conflicts', 2);
    }

    public function test_cross_page_collision_blocks_certification(): void
    {
        $id = $this->candidate();
        $this->store()->persist($id, [$this->row(origin: '00Q000000000000001')]);
        $this->store()->persist($id, [$this->row('a01000000000000002', '00Q000000000000001')]);
        $this->assertDatabaseHas('salesforce_interest_conflicts', ['kind' => 'duplicate_between_blocks']);
        $this->expectException(RuntimeException::class);
        $this->store()->certify($id);
    }

    public function test_retry_is_idempotent_and_blank_origin_is_null(): void
    {
        $id = $this->candidate();
        $row = $this->row(origin: '  ');
        $this->store()->persist($id, [$row]);
        $stats = $this->store()->persist($id, [$row]);
        $this->assertSame(1, $stats['unchanged']);
        $this->assertDatabaseCount('salesforce_interest_versions', 1);
        $this->assertNull($this->store()->rows($id)->first()->migration_origin_lead_id);
    }

    public function test_deleted_and_reactivated_versions_preserve_published_history(): void
    {
        $base = $this->publishBase();
        $id = $this->candidate('2026-10-28 11:00:00');
        $row = $this->row(origin: '00Q000000000000001');
        $this->store()->persist($id, [[...$row, 'is_deleted' => true]]);
        $this->assertFalse((bool) $this->store()->rows($base)->first()->is_deleted);
        $this->assertTrue((bool) $this->store()->rows($id)->first()->is_deleted);
        $this->store()->persist($id, [$row]);
        $this->assertFalse((bool) $this->store()->rows($id)->first()->is_deleted);
    }

    public function test_publication_is_fenced_against_competing_base_and_reader_remains_pinned(): void
    {
        $base = $this->publishBase();
        $reader = new InterestGenerationReader($this->store());
        $this->assertEquals($base, $reader->generation()->id);
        $one = $this->candidate('2026-10-28 11:00:00');
        $two = $this->candidate('2026-10-28 12:00:00');
        foreach ([$one, $two] as $id) {
            $this->complete($id);
            $this->certify($id);
        }
        $this->store()->publish($one);
        $this->store()->publish($one); // Idempotent acknowledgement retry.
        $this->assertEquals($base, $reader->generation()->id);
        $this->assertSame('new', $reader->eloquent()->first()->status);
        $this->expectException(RuntimeException::class);
        $this->store()->publish($two);
    }

    public function test_published_candidate_cannot_be_written(): void
    {
        $id = $this->publishBase();
        $this->expectException(RuntimeException::class);
        $this->store()->persist($id, [$this->row(status: 'forbidden')]);
    }

    public function test_initial_generation_requires_verifiable_source_and_explicit_review(): void
    {
        $id = $this->candidate();
        $this->complete($id);
        $this->expectException(RuntimeException::class);
        $this->store()->certify($id, ['source' => 'legacy_table']);
    }

    public function test_manifest_is_deterministic_across_non_contiguous_multi_chunk_version_ids(): void
    {
        $id = $this->candidate();
        $rows = [];
        foreach (range(1, 1001) as $number) {
            $row = $this->row(
                id: sprintf('a01%015d', $number * 3),
                origin: sprintf('00Q%015d', $number * 3),
                status: $number % 2 === 0 ? 'deleted' : 'new',
            );
            $row['is_deleted'] = $number % 2 === 0;
            $rows[] = $row;
        }
        $this->assertSame(500, collect($rows)->where('is_deleted', true)->count());
        $this->store()->persist($id, array_slice($rows, 0, 500));
        DB::table('salesforce_interest_versions')->insert([
            'generation_id' => $id, 'batch_token' => (string) Str::uuid(),
            'content_hash' => hash('sha256', 'orphan-version-1'), 'salesforce_id' => 'a01999999999999991',
            'salesforce_created_at' => '2026-10-01 00:00:00', 'salesforce_last_modified_at' => '2026-10-01 00:00:00',
            'functional_created_at' => '2026-10-01 00:00:00', 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('salesforce_interest_versions')->insert([
            'generation_id' => $id, 'batch_token' => (string) Str::uuid(),
            'content_hash' => hash('sha256', 'orphan-version-2'), 'salesforce_id' => 'a01999999999999992',
            'salesforce_created_at' => '2026-10-01 00:00:00', 'salesforce_last_modified_at' => '2026-10-01 00:00:00',
            'functional_created_at' => '2026-10-01 00:00:00', 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->store()->persist($id, array_slice($rows, 500));

        $first = $this->store()->manifest($id);
        $second = $this->store()->manifest($id);
        $ordered = $this->store()->rows($id)
            ->select(['member.version_id', 'version.salesforce_id', 'version.content_hash', 'version.is_deleted'])
            ->orderBy('member.version_id')->get();
        $versionIds = $ordered->pluck('version_id')->values()->all();
        $this->assertCount(1001, $versionIds);
        $this->assertCount(1001, array_unique($versionIds));
        $this->assertTrue(collect($versionIds)->zip(collect($versionIds)->skip(1))
            ->contains(fn ($pair): bool => $pair[1] > $pair[0] + 1));
        $full = hash_init('sha256');
        $identities = hash_init('sha256');
        hash_update($full, json_encode([$id, $first['cutoff_at'], $this->store()->generation($id)->interest_run_id,
            $this->store()->generation($id)->activity_run_id], JSON_THROW_ON_ERROR));
        foreach ($ordered as $row) {
            hash_update($full, $row->salesforce_id.'|'.$row->content_hash."\n");
            hash_update($identities, $row->salesforce_id.'|'.$row->content_hash.'|'.(int) $row->is_deleted."\n");
        }

        $this->assertSame(1001, $first['rows']);
        $this->assertSame(500, $first['deleted']);
        $this->assertSame(hash_final($full), $first['sha256']);
        $this->assertSame(hash_final($identities), $first['identifiers_sha256']);
        $this->assertSame($first, $second);
    }

    public function test_initial_generation_rejects_boolean_review_without_matching_manifest_and_f5_evidence(): void
    {
        $id = $this->candidate();
        $this->complete($id);
        $manifest = $this->store()->manifest($id);
        $this->expectException(RuntimeException::class);
        $this->store()->certify($id, [
            'source' => 'isolated_salesforce_reconstruction',
            'manifest_sha256' => $manifest['sha256'],
            'checks' => array_fill_keys(['identifiers', 'counts', 'deletions', 'relationships', 'coverage', 'f5'], true),
        ]);
    }

    public function test_absence_of_certified_generation_is_unavailable_even_with_legacy_rows(): void
    {
        DB::table('salesforce_interests')->insert($this->row());
        $this->expectException(InterestDatasetUnavailable::class);
        app(InterestGenerationReader::class)->query()->count();
    }

    public function test_f5_failure_prevents_publication(): void
    {
        $id = $this->candidate();
        $this->store()->finishF2($id);
        $this->expectException(RuntimeException::class);
        $this->store()->publish($id);
    }

    public function test_running_or_failed_candidate_does_not_hide_certified_dashboard(): void
    {
        $base = $this->publishBase();
        $next = $this->candidate('2026-10-28 11:00:00');
        $this->store()->fail($next);
        $this->getJson('/informes/leads/data/summary')
            ->assertOk()->assertJsonPath('generation_id', $base);
        $this->getJson('/informes/leads/data/summary', ['X-Interest-Generation' => (string) $next])
            ->assertStatus(503)->assertJsonPath('error', 'interest_dataset_unavailable');
    }

    public function test_explicit_generation_token_keeps_http_request_on_previous_publication(): void
    {
        $base = $this->publishBase();
        $next = $this->candidate('2026-10-28 11:00:00');
        $this->complete($next);
        $this->certify($next);
        $this->store()->publish($next);
        $this->getJson('/informes/leads/data/summary', ['X-Interest-Generation' => (string) $base])
            ->assertOk()->assertJsonPath('generation_id', $base);
    }

    public function test_initial_cli_refuses_unapproved_extraction_without_remote_access(): void
    {
        $this->artisan('salesforce:interest-generation', ['action' => 'prepare-initial'])->assertFailed();
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
    }

    public function test_misaligned_f5_cutoff_cannot_certify_candidate(): void
    {
        $id = $this->candidate();
        $this->complete($id);
        $f5 = SalesforceInterestActivityRun::findOrFail($this->store()->generation($id)->activity_run_id);
        $f5->update(['source_cutoff_at' => '2026-10-28 12:00:00']);
        $this->expectException(RuntimeException::class);
        $this->certify($id);
    }

    public function test_initial_manifest_cannot_be_reused_for_a_different_candidate(): void
    {
        $one = $this->candidate();
        $two = $this->candidate('2026-10-28 11:00:00');
        $this->complete($one);
        $this->complete($two);
        $this->expectException(RuntimeException::class);
        $this->store()->certify($two, ['source' => 'isolated_salesforce_reconstruction',
            'manifest_sha256' => $this->store()->manifest($one)['sha256'],
            'checks' => array_fill_keys(['identifiers', 'counts', 'deletions', 'relationships', 'coverage', 'f5'], true)]);
    }

    public function test_database_failure_rolls_back_the_entire_candidate_chunk(): void
    {
        $id = $this->candidate();
        $fired = false;
        DB::listen(function ($query) use (&$fired): void {
            if (! $fired && str_contains($query->sql, 'insert into') && str_contains($query->sql, 'salesforce_interest_members')) {
                $fired = true;
                throw new RuntimeException('Synthetic storage failure');
            }
        });
        try {
            $this->store()->persist($id, [$this->row()]);
            $this->fail('Expected storage failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic storage failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('salesforce_interest_versions', 0);
        $this->assertDatabaseCount('salesforce_interest_members', 0);
        $this->store()->persist($id, [$this->row()]);
        $this->assertSame(1, $this->store()->rows($id)->count());
    }

    public function test_hard_delete_log_creates_only_a_candidate_tombstone(): void
    {
        $base = $this->publishBase();
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return ['earliestDateAvailable' => $start->toIso8601String(), 'latestDateCovered' => $end->toIso8601String(),
                    'deletedRecords' => [['id' => 'a01000000000000001', 'deletedDate' => '2026-10-28T10:30:00Z']]];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $result = app(SalesforceInterestSyncService::class)->sync('incremental', CarbonImmutable::parse('2026-10-28 11:00:00', 'UTC'));
        $this->assertFalse((bool) $this->store()->rows($base)->first()->is_deleted);
        $this->assertTrue((bool) $this->store()->rows($result['generation_id'])->first()->is_deleted);
        $this->assertEquals($base, $this->store()->published()->id);
    }

    public function test_real_pipeline_publishes_f2_and_f5_together(): void
    {
        $base = $this->publishBase();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 11:05:02', 'UTC'));
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return ['earliestDateAvailable' => $start->toIso8601String(), 'latestDateCovered' => $end->toIso8601String(), 'deletedRecords' => []];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $result = app(SalesforceInterestReportingPipelineService::class)->run();
        $published = $this->store()->published();
        $this->assertNotEquals($base, $published->id);
        $this->assertEquals($result['interest_run']->id, $published->interest_run_id);
        $this->assertEquals($result['activity_run']->id, $published->activity_run_id);
        $this->assertTrue($result['interest_run']->source_cutoff_at->equalTo($result['activity_run']->source_cutoff_at));
        $this->travelBack();
    }

    public function test_missing_deletion_log_coverage_never_advances_watermark(): void
    {
        $base = $this->publishBase();
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return ['earliestDateAvailable' => $end->toIso8601String(), 'latestDateCovered' => $end->toIso8601String(), 'deletedRecords' => []];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        try {
            app(SalesforceInterestSyncService::class)->sync('incremental', CarbonImmutable::parse('2026-10-28 11:00:00', 'UTC'));
            $this->fail('Incomplete coverage must fail.');
        } catch (RuntimeException) {
            $this->assertEquals($base, $this->store()->published()->id);
            $this->assertDatabaseHas('salesforce_interest_generations', ['status' => 'failed']);
        }
    }

    public function test_malformed_or_truncated_salesforce_pages_are_rejected(): void
    {
        $auth = \Mockery::mock(SalesforceAuthService::class);
        $auth->shouldReceive('accessToken')->andReturn(['access_token' => 'synthetic', 'instance_url' => 'https://example.invalid']);
        Http::preventStrayRequests();
        foreach ([['records' => []], ['records' => [], 'totalSize' => 1, 'done' => true],
            ['records' => [], 'totalSize' => 1, 'done' => false]] as $page) {
            Http::fake(['*' => Http::response($page)]);
            try {
                iterator_to_array((new SalesforceClient($auth))->queryPages('SELECT Id FROM Interes__c'));
                $this->fail('Expected incomplete page rejection.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Salesforce query', $exception->getMessage());
            }
        }
    }

    public function test_real_f2_and_f5_services_build_only_an_explicit_candidate_with_same_cutoff(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                if (str_contains($soql, 'FROM Interes__c') && ! $includeDeleted) {
                    yield [['Id' => 'a01000000000000001', 'CreatedDate' => '2026-10-01T00:00:00Z',
                        'LastModifiedDate' => '2026-10-01T00:00:00Z']];
                } else {
                    yield [];
                }
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $cutoff = CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC');
        $id = $this->store()->begin($cutoff, true);
        $f2 = app(SalesforceInterestSyncService::class)->sync('full', $cutoff, 300, $id);
        $f5 = app(SalesforceInterestActivitySyncService::class)->sync('Synthetic generation test', $id);
        $this->assertEquals($f2['run']->id, $f5['run']->source_interest_sync_run_id);
        $this->assertTrue($f5['run']->source_cutoff_at->equalTo($cutoff));
        $this->assertDatabaseCount('salesforce_interests', 0);
        $this->assertNull($this->store()->published());
        $this->certify($id);
        $this->store()->publish($id);
        $this->assertSame(1, app(InterestGenerationReader::class)->eloquent()->count());
    }
}
