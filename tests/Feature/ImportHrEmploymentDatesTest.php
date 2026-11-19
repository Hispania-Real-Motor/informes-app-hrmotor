<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\HrEmploymentDate;
use App\Models\ReportUser;
use App\Models\SalesforceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportHrEmploymentDatesTest extends TestCase
{
    use RefreshDatabase;

    private string $relativePath = 'storage/framework/testing/hr-employment-dates.csv';

    protected function tearDown(): void
    {
        File::delete(base_path($this->relativePath));
        parent::tearDown();
    }

    public function test_dry_run_validates_a_unique_salesforce_identity_without_writing(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005000000000001',
            'name' => 'Persona fixture',
            'email' => 'persona@example.test',
        ]);
        $this->writeCsv("salesforce_user_id,corporate_email,effective_date,source_reference\n005000000000001,persona@example.test,2026-01-15,hr-fixture\n");

        $this->artisan('commissions:import-hr-employment-dates', ['file' => $this->relativePath])
            ->assertExitCode(0);

        $this->assertDatabaseCount('hr_employment_dates', 0);
    }

    public function test_write_requires_an_audited_report_user(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005000000000001',
            'name' => 'Persona fixture',
            'email' => 'persona@example.test',
        ]);
        $this->writeCsv("salesforce_user_id,corporate_email,effective_date,source_reference\n005000000000001,persona@example.test,2026-01-15,hr-fixture\n");

        $this->artisan('commissions:import-hr-employment-dates', [
            'file' => $this->relativePath,
            '--write' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('hr_employment_dates', 0);
    }

    public function test_malformed_email_is_rejected_before_any_write(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005000000000001',
            'name' => 'Persona fixture',
            'email' => 'persona@example.test',
        ]);
        $this->writeCsv("salesforce_user_id,corporate_email,effective_date,source_reference\n005000000000001,no-es-un-email,2026-01-15,hr-fixture\n");

        $this->artisan('commissions:import-hr-employment-dates', [
            'file' => $this->relativePath,
            '--write' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('hr_employment_dates', 0);
    }

    public function test_duplicate_identity_and_date_is_rejected_without_partial_import(): void
    {
        $actor = ReportUser::query()->create([
            'name' => 'HR duplicate actor',
            'email' => 'hr-duplicate@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => '005000000000001',
            'name' => 'Persona fixture',
            'email' => 'persona@example.test',
        ]);
        $this->writeCsv("salesforce_user_id,corporate_email,effective_date,source_reference\n005000000000001,persona@example.test,2026-01-15,hr-fixture-a\n005000000000001,persona@example.test,2026-01-15,hr-fixture-b\n");

        $this->artisan('commissions:import-hr-employment-dates', [
            'file' => $this->relativePath,
            '--write' => true,
            '--imported-by' => $actor->id,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('hr_employment_dates', 0);
    }

    public function test_write_persists_the_actor_and_protects_a_definitive_period(): void
    {
        $actor = ReportUser::query()->create([
            'name' => 'HR fixture actor',
            'email' => 'hr-actor@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => '005000000000001',
            'name' => 'Persona fixture',
            'email' => 'persona@example.test',
        ]);
        $this->writeCsv("salesforce_user_id,corporate_email,effective_date,source_reference\n005000000000001,persona@example.test,2026-01-15,hr-fixture\n");

        $this->artisan('commissions:import-hr-employment-dates', [
            'file' => $this->relativePath,
            '--write' => true,
            '--imported-by' => $actor->id,
        ])->assertExitCode(0);
        $this->assertSame($actor->id, HrEmploymentDate::query()->value('imported_by'));

        CommercialCommissionClosure::query()->create([
            'month' => '2026-02',
            'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'component_statuses' => [],
            'snapshot_version' => 1,
            'formula_version' => 'fixture',
            'data_cutoff_at' => now(),
        ]);
        HrEmploymentDate::query()->delete();

        $this->expectException(\RuntimeException::class);
        $this->artisan('commissions:import-hr-employment-dates', [
            'file' => $this->relativePath,
            '--write' => true,
            '--imported-by' => $actor->id,
        ]);
    }

    private function writeCsv(string $contents): void
    {
        File::ensureDirectoryExists(dirname(base_path($this->relativePath)));
        File::put(base_path($this->relativePath), $contents);
    }
}
