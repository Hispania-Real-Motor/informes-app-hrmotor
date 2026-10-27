<?php

namespace App\Services\Campaigns;

use App\Models\ReportUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignInvestmentClosureService
{
    public const RULE_VERSION = '2026-08-07.1';

    public function __construct(private readonly CampaignOpenInvestmentWindow $openWindow) {}

    public function status(string $month): array
    {
        $monthKey = $this->month($month)->toDateString();
        $closure = DB::table('campaign_investment_closures')->where('month', $monthKey)->first();

        return [
            'month' => $monthKey,
            'investment_status' => $closure?->status === 'closed' ? 'closed' : 'open',
            'commercial_results_status' => 'open',
            'snapshot_version' => (int) ($closure?->snapshot_version ?? 0),
            'closed_at' => $closure?->closed_at,
            'reopened_at' => $closure?->reopened_at,
        ];
    }

    public function close(string $month, ReportUser $user): array
    {
        return $this->openWindow->synchronized(fn (): array => DB::transaction(function () use ($month, $user): array {
            $monthDate = $this->month($month);
            $closure = DB::table('campaign_investment_closures')->where('month', $monthDate->toDateString())->lockForUpdate()->first();
            if ($closure?->status === 'closed') {
                throw new \LogicException('La inversión del mes ya está cerrada.');
            }

            $version = (int) ($closure?->snapshot_version ?? 0) + 1;
            $rows = $this->economicRows($monthDate);
            $now = now();

            if ($closure) {
                DB::table('campaign_investment_closures')->where('id', $closure->id)->update([
                    'status' => 'closed', 'snapshot_version' => $version, 'closed_by' => $user->id,
                    'closed_at' => $now, 'reopened_by' => null, 'reopened_at' => null, 'reopen_reason' => null, 'updated_at' => $now,
                ]);
                $closureId = $closure->id;
                $from = $closure->status;
            } else {
                $closureId = DB::table('campaign_investment_closures')->insertGetId([
                    'month' => $monthDate->toDateString(), 'status' => 'closed', 'snapshot_version' => $version,
                    'closed_by' => $user->id, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $from = 'open';
            }

            DB::table('campaign_investment_closure_snapshots')->insert([
                'closure_id' => $closureId, 'version' => $version,
                'economic_rows' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'source_cutoff_at' => $now, 'rule_version' => self::RULE_VERSION, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('campaign_investment_closure_events')->insert([
                'closure_id' => $closureId, 'event' => 'closed', 'from_status' => $from, 'to_status' => 'closed',
                'actor_id' => $user->id, 'metadata' => json_encode(['snapshot_version' => $version], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now, 'updated_at' => $now,
            ]);

            $this->invalidateDashboardCache();

            return $this->status($monthDate->toDateString());
        }));
    }

    public function reopen(string $month, string $reason, ReportUser $user): array
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('El motivo de reapertura debe tener al menos 10 caracteres.');
        }

        return $this->openWindow->synchronized(fn (): array => DB::transaction(function () use ($month, $reason, $user): array {
            $closure = DB::table('campaign_investment_closures')->where('month', $this->month($month)->toDateString())->lockForUpdate()->first();
            if (! $closure || $closure->status !== 'closed') {
                throw new \LogicException('La inversión del mes no está cerrada.');
            }
            $now = now();
            DB::table('campaign_investment_closures')->where('id', $closure->id)->update([
                'status' => 'open', 'reopened_by' => $user->id, 'reopened_at' => $now, 'reopen_reason' => trim($reason), 'updated_at' => $now,
            ]);
            DB::table('campaign_investment_closure_events')->insert([
                'closure_id' => $closure->id, 'event' => 'reopened', 'from_status' => 'closed', 'to_status' => 'open',
                'actor_id' => $user->id, 'reason' => trim($reason), 'created_at' => $now, 'updated_at' => $now,
            ]);

            $this->invalidateDashboardCache();

            return $this->status($closure->month);
        }));
    }

    private function economicRows(CarbonImmutable $month): array
    {
        return DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $month->toDateString())
            ->where('metric_date', '<', $month->addMonth()->toDateString())
            ->selectRaw('platform, account_id, MIN(account_name) as account_name, campaign_id, campaign_name,
                MIN(campaign_status) as campaign_status, MIN(campaign_effective_status) as campaign_effective_status,
                MIN(campaign_start_date) as campaign_start_date, MIN(campaign_end_date) as campaign_end_date,
                MIN(advertising_channel_type) as advertising_channel_type, MIN(advertising_channel_sub_type) as advertising_channel_sub_type,
                MAX(CASE WHEN COALESCE(spend, 0) > 0 THEN metric_date ELSE NULL END) as last_spend_date,
                SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks,
                SUM(COALESCE(platform_leads, 0)) as platform_leads,
                SUM(CASE WHEN platform_leads IS NOT NULL THEN 1 ELSE 0 END) as platform_leads_rows,
                SUM(COALESCE(platform_conversions, 0)) as platform_conversions')
            ->groupBy('platform', 'account_id', 'campaign_id', 'campaign_name')
            ->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function month(string $month): CarbonImmutable
    {
        return CarbonImmutable::parse($month)->startOfMonth();
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }
}
