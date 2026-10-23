<?php

namespace App\Services\Campaigns;

use App\Models\ReportSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Operational metadata only. Never writes historical metrics or attribution. */
class CampaignCurrentStatusService
{
    public const TASK = 'campaigns-sync-current-status';

    public const DATASET = 'campaign_current_status';

    private const LABELS = [
        'meta' => ['ACTIVE' => 'Activa', 'PAUSED' => 'Pausada', 'DELETED' => 'Eliminada', 'ARCHIVED' => 'Archivada', 'IN_PROCESS' => 'En proceso', 'WITH_ISSUES' => 'Con incidencias'],
        'google_ads' => ['ENABLED' => 'Activa', 'PAUSED' => 'Pausada', 'REMOVED' => 'Eliminada'],
    ];

    public function __construct(private readonly MetaAdsClient $meta, private readonly GoogleAdsClient $google) {}

    public static function identifier(mixed $value, string $platform, bool $account = false): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Identificador no válido.');
        }
        if ($account) {
            if ($platform === 'meta') {
                $value = preg_replace('/^act_/', '', $value);
            } elseif (preg_match('/\A[0-9]{3}-[0-9]{3}-[0-9]{4}\z/D', $value)) {
                $value = str_replace('-', '', $value);
            }
        }
        if (! preg_match('/\A[0-9]{1,30}\z/D', $value)) {
            throw new RuntimeException('Identificador no válido.');
        }

        return $value;
    }

    public function sync(int $operationalRun): array
    {
        if (! config('campaign_current_status.enabled')) {
            throw new RuntimeException('Tarea deshabilitada.');
        }
        $authorized = DB::table('operational_task_runs as r')
            ->join('operational_task_states as s', 's.active_run_id', '=', 'r.id')
            ->where('r.id', $operationalRun)->where('r.task_key', self::TASK)->where('r.status', 'running')->exists();
        if (! $authorized) {
            throw new RuntimeException('Se requiere una reserva operacional activa.');
        }
        // Also prevents concurrent invocations using the same operational reservation.
        $lock = Cache::lock('campaign-current-status-sync', 14400);
        if (! $lock->get()) {
            throw new RuntimeException('Sincronización ya en curso.');
        }
        try {
            $stats = ['accounts' => 0, 'failed' => 0, 'campaigns' => 0];
            foreach (['meta' => $this->meta->adAccountIds(), 'google_ads' => $this->google->customerIds()] as $platform => $accounts) {
                if (count($accounts) > 10) {
                    throw new RuntimeException('Revisar límite operacional de cuentas.');
                }
                foreach (array_unique($accounts) as $account) {
                    $account = self::identifier($account, $platform, true);
                    $stats['accounts']++;
                    $run = ReportSyncRun::query()->create([
                        'dataset' => self::DATASET, 'source' => $platform.':'.$account,
                        'status' => 'running', 'started_at' => CarbonImmutable::now('UTC'), 'timezone' => 'Europe/Madrid',
                        'stats' => ['operational_run_id' => $operationalRun],
                    ]);
                    try {
                        $pages = $platform === 'meta' ? $this->meta->currentStatusPages($account) : $this->google->currentStatusPages($account);
                        $rows = [];
                        $pagesRead = 0;
                        foreach ($pages as $page) {
                            $pagesRead++;
                            foreach ($page as $item) {
                                if (! is_array($item) || ! array_key_exists('status', $item)
                                    || ($platform === 'meta' && ! array_key_exists('effective_status', $item))) {
                                    throw new RuntimeException('Faltan atributos del inventario.');
                                }
                                $campaign = self::identifier($item['id'] ?? null, $platform);
                                if (isset($rows[$campaign]) || count($rows) >= 50000 || self::identifier($item['account_id'] ?? null, $platform, true) !== $account) {
                                    throw new RuntimeException('Inventario incompleto o ambiguo.');
                                }
                                $rows[$campaign] = [
                                    'raw_status' => $this->statusCode($item[$platform === 'meta' ? 'effective_status' : 'status'] ?? null),
                                    'configured_status' => $this->statusCode($item['status'] ?? null),
                                ];
                            }
                        }
                        // No write until every page of this account has been validated.
                        $verified = CarbonImmutable::now('UTC');
                        DB::transaction(function () use ($rows, $account, $platform, $run, $verified, $pagesRead, $operationalRun): void {
                            $previous = DB::table('campaign_current_statuses')->where('platform', $platform)->where('account_id', $account)->get()->keyBy('campaign_id');
                            $updates = [];
                            foreach ($rows as $id => $row) {
                                $valid = isset(self::LABELS[$platform][$row['raw_status']]);
                                $old = $previous->get($id);
                                $updates[] = array_merge($row, [
                                    'platform' => $platform, 'account_id' => $account, 'campaign_id' => (string) $id,
                                    'sync_source' => $run->source, 'report_sync_run_id' => $run->id,
                                    'last_observed_at' => $verified,
                                    'last_verified_at' => $valid ? $verified : $old?->last_verified_at,
                                    'last_valid_raw_status' => $valid ? $row['raw_status'] : $old?->last_valid_raw_status,
                                    'last_valid_configured_status' => $valid ? $row['configured_status'] : $old?->last_valid_configured_status,
                                    'created_at' => $verified, 'updated_at' => $verified,
                                ]);
                            }
                            foreach (array_chunk($updates, 500) as $chunk) {
                                DB::table('campaign_current_statuses')->upsert($chunk, ['platform', 'account_id', 'campaign_id'], [
                                    'sync_source', 'raw_status', 'configured_status', 'last_valid_raw_status', 'last_valid_configured_status',
                                    'last_observed_at', 'last_verified_at', 'report_sync_run_id', 'updated_at',
                                ]);
                            }
                            $recognized = count(array_filter($rows, fn (array $row): bool => isset(self::LABELS[$platform][$row['raw_status']])));
                            $run->update(['status' => 'completed', 'completed_at' => $verified, 'stats' => [
                                'operational_run_id' => $operationalRun, 'campaigns' => count($rows),
                                'recognized' => $recognized, 'unverifiable' => count($rows) - $recognized, 'pages' => $pagesRead,
                            ]]);
                        });
                        $stats['campaigns'] += count($rows);
                    } catch (Throwable) {
                        $stats['failed']++;
                        $run->update(['status' => 'failed', 'completed_at' => CarbonImmutable::now('UTC'), 'error_message' => 'No se pudo verificar el inventario completo de la cuenta.']);
                    }
                }
            }

            return $stats;
        } finally {
            $lock->release();
        }
    }

    private function statusCode(mixed $code): ?string
    {
        if ($code === null) {
            return null;
        }
        if (! is_string($code) || ! preg_match('/\A[A-Z_]{1,64}\z/D', $code)) {
            throw new RuntimeException('Código de estado no válido.');
        }

        return $code;
    }

    /** Decorate after the economic cache; never called by CSV/exportRows. */
    public function decorate(array $rows): array
    {
        $identities = [];
        foreach ($rows as $row) {
            try {
                $platform = $row['platform'] ?? '';
                if (! isset(self::LABELS[$platform])) {
                    continue;
                }
                $account = self::identifier($row['account_id'] ?? null, $platform, true);
                $id = self::identifier($row['campaign_id'] ?? null, $platform);
                $identities[$platform.'|'.$account.'|'.$id] = [$platform, $account, $id];
            } catch (RuntimeException) {
                // Never guess an account for attribution-only rows.
            }
        }
        $states = [];
        if ($identities !== []) {
            $sources = array_unique(array_map(fn (array $identity): string => $identity[0].':'.$identity[1], $identities));
            $platforms = array_unique(array_column($identities, 0));
            $accounts = array_unique(array_column($identities, 1));
            $latestRuns = DB::table('report_sync_runs')->select('source')->selectRaw('MAX(id) as latest_id')->where('dataset', self::DATASET)->whereIn('source', $sources)->groupBy('source');
            // Bound SQL parameters and read only IDs needed by this response.
            foreach (array_chunk(array_unique(array_column($identities, 2)), 500) as $ids) {
                $found = DB::table('campaign_current_statuses as c')
                    ->leftJoinSub($latestRuns, 'latest', fn ($join) => $join->on('latest.source', '=', 'c.sync_source'))
                    ->leftJoin('report_sync_runs as latest_run', 'latest_run.id', '=', 'latest.latest_id')
                    ->whereIn('c.platform', $platforms)
                    ->whereIn('c.account_id', $accounts)
                    ->whereIn('c.campaign_id', $ids)
                    ->select('c.*', 'latest.latest_id', 'latest_run.status as latest_status')->get();
                foreach ($found as $state) {
                    $states[$state->platform.'|'.$state->account_id.'|'.$state->campaign_id] = $state;
                }
            }
        }
        foreach ($rows as &$row) {
            $metadata = ['availability' => 'unavailable', 'label' => 'Estado no disponible', 'verified_at' => null,
                'raw_status' => null, 'configured_status' => null, 'observed_at' => null, 'last_valid_raw_status' => null,
                'platform' => $row['platform'] ?? null, 'account_id' => null, 'campaign_id' => null, 'fresh_hours' => 24];
            try {
                $platform = $row['platform'] ?? '';
                if (! isset(self::LABELS[$platform])) {
                    $row['current_status'] = $metadata;

                    continue;
                }
                $metadata['account_id'] = self::identifier($row['account_id'] ?? null, $platform, true);
                $metadata['campaign_id'] = self::identifier($row['campaign_id'] ?? null, $platform);
                $key = $platform.'|'.$metadata['account_id'].'|'.$metadata['campaign_id'];
                $state = $states[$key] ?? null;
                if ($state) {
                    $metadata['raw_status'] = $state->raw_status;
                    $metadata['configured_status'] = $state->configured_status;
                    $metadata['last_valid_raw_status'] = $state->last_valid_raw_status;
                    $metadata['observed_at'] = $state->last_observed_at ? CarbonImmutable::parse($state->last_observed_at, 'UTC')->toIso8601String() : null;
                    $metadata['verified_at'] = $state->last_verified_at ? CarbonImmutable::parse($state->last_verified_at, 'UTC')->toIso8601String() : null;
                    $label = self::LABELS[$platform][$state->raw_status] ?? null;
                    if ($label && $metadata['verified_at']) {
                        $age = CarbonImmutable::parse($metadata['verified_at'])->diffInSeconds(CarbonImmutable::now('UTC'), false);
                        if ($age < 0) {
                            $row['current_status'] = $metadata;

                            continue;
                        }
                        $fresh = $age < 86400 && $state->latest_status === 'completed'
                            && $state->report_sync_run_id !== null && $state->latest_id !== null
                            && (int) $state->latest_id === (int) $state->report_sync_run_id;
                        $metadata['availability'] = $fresh ? 'fresh' : 'stale';
                        $metadata['label'] = $fresh ? $label : 'Estado no actualizado';
                    }
                }
            } catch (RuntimeException) {
                // Unverifiable identity remains neutral.
            }
            $row['current_status'] = $metadata;
        }
        unset($row);

        return $rows;
    }
}
