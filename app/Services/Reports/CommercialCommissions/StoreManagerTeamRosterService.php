<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionRosterMember;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
        $evidenceRows = CommercialCommissionRosterMember::query()
            ->where('month', $month->format('Y-m'))
            ->where('coverage_from', '<', $monthEnd)
            ->where(fn ($query) => $query->whereNull('coverage_to')->orWhere('coverage_to', '>', $month))
            ->orderBy('coverage_from')
            ->get();
        $rows = $evidenceRows->groupBy('delegation_key');

        $conflictsByDelegation = [];
        $conflictingUserIdsByDelegation = [];
        $evidenceRows->groupBy('salesforce_user_id')->each(function ($userRows, string $userId) use (&$conflictsByDelegation, &$conflictingUserIdsByDelegation): void {
            $userRows = $userRows->values();

            foreach ($userRows as $index => $left) {
                foreach ($userRows->slice($index + 1) as $right) {
                    if (! $this->intervalsOverlap($left, $right)) {
                        continue;
                    }

                    $message = 'El usuario Salesforce '.$userId.' tiene adscripciones solapadas entre '
                        .$left->delegation_name.' y '.$right->delegation_name.'.';
                    $conflictsByDelegation[$left->delegation_key][] = $message;
                    $conflictsByDelegation[$right->delegation_key][] = $message;
                    $conflictingUserIdsByDelegation[$left->delegation_key][] = $userId;
                    $conflictingUserIdsByDelegation[$right->delegation_key][] = $userId;
                }
            }
        });

        $identityIssuesByUser = [];
        $identitiesByUser = Schema::hasTable('salesforce_users')
            ? SalesforceUser::query()
                ->whereIn('salesforce_id', $evidenceRows->pluck('salesforce_user_id')->filter()->unique()->values()->all())
                ->get(['salesforce_id', 'name'])
                ->groupBy('salesforce_id')
            : collect();
        $evidenceRows->groupBy('salesforce_user_id')->each(function ($userRows, string $userId) use ($identitiesByUser, &$identityIssuesByUser): void {
            $identities = $identitiesByUser->get($userId, collect())->values();
            $issues = [];
            if ($identities->isEmpty()) {
                $issues[] = 'El usuario Salesforce '.$userId.' no tiene una identidad local verificable.';
            } elseif ($identities->count() > 1) {
                $issues[] = 'El usuario Salesforce '.$userId.' tiene varias identidades locales y es ambiguo.';
            } elseif (blank($identities->first()->name)) {
                $issues[] = 'El usuario Salesforce '.$userId.' no tiene un nombre local verificable.';
            } else {
                $localName = $this->normalizeName((string) $identities->first()->name);
                if ($userRows->contains(fn (CommercialCommissionRosterMember $row): bool => $this->normalizeName((string) $row->member_name) !== $localName)) {
                    $issues[] = 'El nombre del roster no coincide con la identidad local del usuario Salesforce '.$userId.'.';
                }
            }
            if ($issues !== []) {
                $identityIssuesByUser[$userId] = $issues;
            }
        });

        return $keys->mapWithKeys(function (string $key) use ($rows, $conflictsByDelegation, $conflictingUserIdsByDelegation, $identityIssuesByUser): array {
            $evidence = $rows->get($key, collect());
            $issues = [
                ...($conflictsByDelegation[$key] ?? []),
                ...$evidence->flatMap(fn (CommercialCommissionRosterMember $row): array => $identityIssuesByUser[(string) $row->salesforce_user_id] ?? [])->all(),
            ];
            if ($evidence->isEmpty()) {
                return [$key => $this->pendingResult('Adscripción histórica del equipo no verificada para el periodo.')];
            }

            if ($evidence->contains(fn (CommercialCommissionRosterMember $row): bool => ! $row->history_verified)) {
                $issues[] = 'La adscripción del equipo contiene evidencia no verificada.';
            }
            if ($evidence->contains(fn (CommercialCommissionRosterMember $row): bool => blank($row->source) || blank($row->evidence_reference))) {
                $issues[] = 'La adscripción del equipo carece de procedencia o referencia auditable.';
            }

            $conflictingUserIds = array_values(array_unique(array_map(
                'strval',
                $conflictingUserIdsByDelegation[$key] ?? [],
            )));
            $members = $evidence
                ->filter(fn (CommercialCommissionRosterMember $row): bool => $row->history_verified
                    && filled($row->salesforce_user_id)
                    && ! isset($identityIssuesByUser[(string) $row->salesforce_user_id]))
                ->groupBy(fn (CommercialCommissionRosterMember $row): string => (string) $row->salesforce_user_id)
                ->mapWithKeys(function ($memberRows, string $userId) use ($conflictingUserIds): array {
                    /** @var Collection<int, CommercialCommissionRosterMember> $memberRows */
                    $first = $memberRows->first();

                    return [$userId => [
                        'salesforce_user_id' => $userId,
                        'member_name' => $first?->member_name,
                        'coverage_conflict' => in_array($userId, $conflictingUserIds, true),
                        'coverage_intervals' => $memberRows->map(fn (CommercialCommissionRosterMember $row): array => [
                            'from' => $row->coverage_from?->toIso8601String(),
                            'to' => $row->coverage_to?->toIso8601String(),
                        ])->values()->all(),
                    ]];
                })
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

    private function normalizeName(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
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

    private function intervalsOverlap(CommercialCommissionRosterMember $left, CommercialCommissionRosterMember $right): bool
    {
        if ($left->coverage_from === null || $right->coverage_from === null) {
            return false;
        }

        $leftTo = $left->coverage_to;
        $rightTo = $right->coverage_to;
        $latestFrom = $left->coverage_from->greaterThan($right->coverage_from)
            ? $left->coverage_from
            : $right->coverage_from;
        $earliestTo = match (true) {
            $leftTo === null => $rightTo,
            $rightTo === null => $leftTo,
            $leftTo->lessThan($rightTo) => $leftTo,
            default => $rightTo,
        };

        return $earliestTo === null || $latestFrom->lessThan($earliestTo);
    }
}
