<?php

namespace App\Console\Commands;

use App\Models\HrEmploymentDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportHrEmploymentDates extends Command
{
    protected $signature = 'commissions:import-hr-employment-dates
        {file : CSV with salesforce_user_id,corporate_email,effective_date,source_reference}
        {--write : Persist validated rows; otherwise run as a dry-run}';

    protected $description = 'Validate and optionally import HR employment/re-employment dates';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));
        if (! is_file($path)) {
            $this->error('HR file not found.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        if ($header !== ['salesforce_user_id', 'corporate_email', 'effective_date', 'source_reference']) {
            $this->error('Invalid HR CSV header.');

            return self::FAILURE;
        }

        $rows = [];
        $keys = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== 4 || ($row[0] === '' && $row[1] === '') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[2])) {
                $this->error('Invalid identity or date in HR CSV.');

                return self::FAILURE;
            }

            $key = strtolower(trim($row[0].'|'.$row[1].'|'.$row[2]));
            if (isset($keys[$key])) {
                $this->error('Duplicate HR identity/date row.');

                return self::FAILURE;
            }

            $keys[$key] = true;
            $rows[] = ['salesforce_user_id' => $row[0] ?: null, 'corporate_email' => $row[1] ?: null, 'effective_date' => $row[2], 'source_reference' => $row[3]];
        }
        fclose($handle);

        $this->info('Validated '.count($rows).' HR rows; no User.CreatedDate is used.');
        if (! $this->option('write')) {
            $this->comment('Dry-run: no database writes performed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                HrEmploymentDate::query()->updateOrCreate(
                    ['salesforce_user_id' => $row['salesforce_user_id'], 'effective_date' => $row['effective_date']],
                    [...$row, 'source_label' => 'RR. HH. validado']
                );
            }
        });

        return self::SUCCESS;
    }
}
