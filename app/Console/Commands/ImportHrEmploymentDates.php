<?php

namespace App\Console\Commands;

use App\Models\CommercialCommissionClosure;
use App\Models\HrEmploymentDate;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
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
            $row = array_map('trim', $row);
            if (count($row) !== 4 || ($row[0] === '' && $row[1] === '') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[2]) || $row[3] === '') {
                $this->error('Invalid identity or date in HR CSV.');

                return self::FAILURE;
            }

            $date = CarbonImmutable::createFromFormat('!Y-m-d', $row[2]);
            if ($date === false || $date->format('Y-m-d') !== $row[2]) {
                $this->error('Invalid calendar date in HR CSV.');

                return self::FAILURE;
            }

            $user = SalesforceUser::query()
                ->when($row[0] !== '', fn ($query) => $query->where('salesforce_id', $row[0]))
                ->when($row[0] === '' && $row[1] !== '', fn ($query) => $query->whereRaw('LOWER(email) = ?', [strtolower($row[1])]))
                ->first();
            if ($user === null || ($row[1] !== '' && strtolower((string) $user->email) !== strtolower($row[1]))) {
                $this->error('HR identity does not match a single local Salesforce user.');

                return self::FAILURE;
            }

            $key = strtolower($user->salesforce_id.'|'.$date->format('Y-m-d'));
            if (isset($keys[$key])) {
                $this->error('Duplicate HR identity/date row.');

                return self::FAILURE;
            }

            $keys[$key] = true;
            $rows[] = ['salesforce_user_id' => $user->salesforce_id, 'corporate_email' => $user->email ?: $row[1], 'effective_date' => $date->format('Y-m-d'), 'source_reference' => $row[3]];
        }
        fclose($handle);

        $this->info('Validated '.count($rows).' HR rows; no User.CreatedDate is used.');
        if (! $this->option('write')) {
            $this->comment('Dry-run: no database writes performed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $month = CarbonImmutable::parse($row['effective_date'])->startOfMonth()->format('Y-m');
                if (CommercialCommissionClosure::query()->where('month', $month)->where('status', CommercialCommissionClosure::STATUS_DEFINITIVE)->exists()) {
                    throw new \RuntimeException('Cannot import HR dates into a definitive commission period: '.$month);
                }
                HrEmploymentDate::query()->updateOrCreate(
                    ['salesforce_user_id' => $row['salesforce_user_id'], 'effective_date' => $row['effective_date']],
                    [...$row, 'source_label' => 'RR. HH. validado']
                );
            }
        });

        return self::SUCCESS;
    }
}
