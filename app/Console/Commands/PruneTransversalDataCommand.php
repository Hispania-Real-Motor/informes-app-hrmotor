<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PruneTransversalDataCommand extends Command
{
    private const RAW_PAYLOAD_TABLES = [
        'salesforce_users',
        'salesforce_activities',
        'salesforce_calls',
        'campaign_platform_daily_metrics',
        'salesforce_reviews',
        'salesforce_vehicles',
        'salesforce_logistics',
        'campaign_platform_identifiers',
        'salesforce_interests',
    ];

    protected $signature = 'reports:prune-transversal-data
        {--dry-run : Calcula el alcance sin modificar datos}
        {--chunk=500 : Filas procesadas por transacción}';

    protected $description = 'Aplica las políticas aprobadas de retención sobre entidades transversales verificadas.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = min(5000, max(50, (int) $this->option('chunk')));
        $now = now();
        $metrics = [];

        foreach (self::RAW_PAYLOAD_TABLES as $tableName) {
            $metrics['raw_payloads.'.$tableName] = $this->nullPayloads(
                $tableName,
                $now->copy()->subMonthsNoOverflow(2),
                $chunkSize,
                $dryRun,
            );
        }

        $metrics['sync_runs.completed'] = $this->deleteInChunks(
            DB::table('report_sync_runs')
                ->where('status', 'completed')
                ->where('completed_at', '<', $now->copy()->subMonthNoOverflow()),
            'id',
            $chunkSize,
            $dryRun,
        );
        $metrics['sync_runs.failed'] = $this->deleteInChunks(
            DB::table('report_sync_runs')
                ->where('status', 'failed')
                ->where('completed_at', '<', $now->copy()->subDays(14)),
            'id',
            $chunkSize,
            $dryRun,
        );
        $metrics['queue.failed_jobs'] = $this->deleteInChunks(
            DB::table('failed_jobs')->where('failed_at', '<', $now->copy()->subDays(14)),
            'id',
            $chunkSize,
            $dryRun,
        );
        $metrics['queue.completed_batches'] = $this->deleteInChunks(
            DB::table('job_batches')
                ->where('failed_jobs', 0)
                ->whereNotNull('finished_at')
                ->where('finished_at', '<', $now->copy()->subMonthNoOverflow()->getTimestamp()),
            'id',
            $chunkSize,
            $dryRun,
        );
        $metrics['queue.failed_batches'] = $this->deleteInChunks(
            DB::table('job_batches')
                ->where('failed_jobs', '>', 0)
                ->whereNotNull('finished_at')
                ->where('finished_at', '<', $now->copy()->subDays(14)->getTimestamp()),
            'id',
            $chunkSize,
            $dryRun,
        );
        $metrics['alerts.operational_resolved'] = $this->deleteInChunks(
            DB::table('operational_alerts')
                ->where('state', 'resolved')
                ->where('resolved_at', '<', $now->copy()->subMonthNoOverflow()),
            'id',
            $chunkSize,
            $dryRun,
        );
        $metrics['alerts.stock_resolved'] = $this->deleteInChunks(
            DB::table('stock_availability_alerts')
                ->where('state', 'resolved')
                ->where('resolved_at', '<', $now->copy()->subMonthNoOverflow()),
            'id',
            $chunkSize,
            $dryRun,
        );

        $this->table(
            ['Entidad', $dryRun ? 'Filas candidatas' : 'Filas modificadas'],
            collect($metrics)->map(fn (int $count, string $name): array => [$name, $count])->values()->all(),
        );
        $this->info($dryRun ? 'Dry-run completado; no se han modificado datos.' : 'Retención transversal completada.');

        return self::SUCCESS;
    }

    private function nullPayloads(string $tableName, mixed $cutoff, int $chunkSize, bool $dryRun): int
    {
        $query = DB::table($tableName)
            ->whereNotNull('raw_payload')
            ->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $modified = 0;

        while (true) {
            $ids = (clone $query)->orderBy('id')->limit($chunkSize)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $modified += DB::transaction(fn (): int => DB::table($tableName)
                ->whereIn('id', $ids->all())
                ->whereNotNull('raw_payload')
                ->update(['raw_payload' => null]));
        }

        return $modified;
    }

    private function deleteInChunks(
        Builder $query,
        string $keyColumn,
        int $chunkSize,
        bool $dryRun,
    ): int {
        if ($dryRun) {
            return $query->count();
        }

        $deleted = 0;

        while (true) {
            $ids = (clone $query)->orderBy($keyColumn)->limit($chunkSize)->pluck($keyColumn);

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::transaction(fn (): int => (clone $query)
                ->whereIn($keyColumn, $ids->all())
                ->delete());
        }

        return $deleted;
    }
}
