<?php

namespace Tests\Support;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivityRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic fixtures only. Production initialization must use the reviewed reconstruction CLI. */
final class InterestGenerationFixture
{
    public static function listenForNewRows(): void
    {
        if (app()->bound('tests.interest-generation-fixture-listener')) {
            return;
        }
        app()->instance('tests.interest-generation-fixture-listener', true);
        SalesforceInterest::created(fn (SalesforceInterest $row) => self::row($row));
    }

    public static function published(ReportSyncRun $f2, ?SalesforceInterestActivityRun $f5 = null): int
    {
        $f5 ??= SalesforceInterestActivityRun::create([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic certified generation fixture',
            'status' => 'completed', 'source_interest_sync_run_id' => $f2->id,
            'source_interest_cutoff_at' => $f2->source_cutoff_at,
            'source_cutoff_at' => $f2->source_cutoff_at, 'started_at' => now(), 'completed_at' => now(),
        ]);
        $id = DB::table('salesforce_interest_generations')->insertGetId([
            'interest_run_id' => $f2->id, 'activity_run_id' => $f5->id,
            'cutoff_at' => $f2->source_cutoff_at, 'status' => 'published',
            'started_at' => now(), 'completed_at' => now(), 'published_at' => now(),
            'f2_complete' => true, 'f5_complete' => true,
            'validation' => '{"synthetic_fixture":true}',
        ]);
        DB::table('salesforce_interest_publication')->where('id', 1)->update([
            'generation_id' => $id, 'watermark_at' => $f2->source_cutoff_at,
        ]);
        foreach (SalesforceInterest::query()->get() as $row) {
            self::row($row, $id);
        }

        return $id;
    }

    public static function row(SalesforceInterest $interest, ?int $id = null): void
    {
        $id ??= DB::table('salesforce_interest_publication')->where('id', 1)->value('generation_id');
        if ($id === null) {
            return;
        }
        $row = $interest->getAttributes();
        unset($row['id']);
        $version = DB::table('salesforce_interest_versions')->insertGetId([
            ...$row, 'generation_id' => $id, 'batch_token' => (string) Str::uuid(),
            'content_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
        ]);
        DB::table('salesforce_interest_members')->insert([
            'generation_id' => $id, 'salesforce_id' => $interest->salesforce_id,
            'version_id' => $version, 'migration_origin_lead_id' => $interest->migration_origin_lead_id,
        ]);
    }
}
