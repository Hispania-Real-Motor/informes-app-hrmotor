<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionRosterMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class StoreManagerTeamRosterService
{
    private const TABLE = 'store_manager_commission_roster_members';

    public function __construct(private readonly CommercialCommissionFormulaConfigService $formulaConfig) {}

    /**
     * Resolve the evidence-backed team roster for a month in one query.
     * Operation owners are intentionally not used as a substitute for this
     * evidence; the dashboard can show them as provisional components only.
     *
     * @param  array<int, string>  $delegationLabels
     * @return array<string, array{status: string, members: array<string, array<string, mixed>>, evidence_count: int, issues: array<int, string>}>
     */
    public function resolve(CarbonImmutable $month, array $delegationLabels): array
    {
        $keys = collect($delegationLabels)
            ->filter()
            ->map(fn (string $label): string => $this->formulaConfig->delegationKey($label))
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return [];
        }

        if (! Schema::hasTable(self::TABLE)) {
            return $keys->mapWithKeys(fn (string $key): array => [$key => $this->pendingResult('Falta el esquema de adscripción histórica del equipo.')])->all();
        }

        $monthEnd = $month->addMonth();
        $rows = CommercialCommissionRosterMember::query()
            ->where('month', $month->format('Y-m'))
            ->whereIn('delegation_key', $keys)
            ->where('coverage_from', '<', $monthEnd)
            ->where(fn ($query) => $query->whereNull('coverage_to')->orWhere('coverage_to', '>', $month))
            ->orderBy('coverage_from')
            ->get()
            ->groupBy('delegation_key');

        return $keys->mapWithKeys(function (string $key) use ($rows): array {
            $evidence = $rows->get($key, collect());
            $issues = [];
            if ($evidence->isEmpty()) {
                return [$key => $this->pendingResult('Adscripción histórica del equipo no verificada para el periodo.')];
            }

            if ($evidence->contains(fn (CommercialCommissionRosterMember $row): bool => ! $row->history_verified)) {
                $issues[] = 'La adscripción del equipo contiene evidencia no verificada.';
            }
            if ($evidence->contains(fn (CommercialCommissionRosterMember $row): bool => blank($row->source) || blank($row->evidence_reference))) {
                $issues[] = 'La adscripción del equipo carece de procedencia o referencia auditable.';
            }

            $members = $evidence
                ->filter(fn (CommercialCommissionRosterMember $row): bool => $row->history_verified
                    && filled($row->salesforce_user_id))
                ->mapWithKeys(fn (CommercialCommissionRosterMember $row): array => [(string) $row->salesforce_user_id => [
                    'salesforce_user_id' => (string) $row->salesforce_user_id,
                    'member_name' => $row->member_name,
                ]])
                ->all();

            if ($members === []) {
                $issues[] = 'La adscripción histórica no contiene miembros verificables.';
            }

            return [$key => [
                'status' => $issues === [] ? 'verified' : 'pending',
                'members' => $members,
                'evidence_count' => $evidence->count(),
                'issues' => array_values(array_unique($issues)),
            ]];
        })->all();
    }

    /** @return array{status: string, members: array<string, array<string, mixed>>, evidence_count: int, issues: array<int, string>} */
    private function pendingResult(string $issue): array
    {
        return [
            'status' => 'pending',
            'members' => [],
            'evidence_count' => 0,
            'issues' => [$issue],
        ];
    }
}
