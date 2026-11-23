<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionRosterMember;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreManagerTeamRosterEvidenceService
{
    /** @var array<string, array<int, array{salesforce_id: string|null, name: string|null}>> */
    private array $identityCache = [];

    private const SOURCES = [
        'salesforce_export',
        'hr_export',
        'direction_confirmation',
        'it_confirmation',
        'manual_review',
    ];

    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
        private readonly StoreManagerCommissionTargetService $targets,
    ) {}

    /** @param array<string, mixed> $input */
    public function validate(array $input): array
    {
        $month = $this->month($input['month'] ?? null);
        $delegationName = trim((string) ($input['delegation_name'] ?? ''));
        $salesforceUserId = trim((string) ($input['salesforce_user_id'] ?? ''));
        $memberName = trim((string) ($input['member_name'] ?? ''));
        $coverageFrom = $this->dateTime($input['coverage_from'] ?? null);
        $coverageTo = $this->dateTime($input['coverage_to'] ?? null, nullable: true);
        $source = trim((string) ($input['source'] ?? ''));
        $reference = trim((string) ($input['evidence_reference'] ?? ''));
        $recordedBy = trim((string) ($input['recorded_by'] ?? ''));
        $verified = filter_var($input['history_verified'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $errors = [];

        if ($month === null) {
            $errors[] = 'month debe usar Y-m.';
        }
        if ($delegationName === '') {
            $errors[] = 'delegation_name es obligatorio.';
        }
        if (! $this->validSalesforceId($salesforceUserId)) {
            $errors[] = 'salesforce_user_id no es un ID Salesforce valido.';
        }
        if ($salesforceUserId !== '' && ! Schema::hasTable('salesforce_users')) {
            $errors[] = 'No existe el registro local de usuarios Salesforce necesario para verificar la identidad.';
        } elseif ($salesforceUserId !== '') {
            $identityMatches = $this->localIdentities($salesforceUserId);
            if ($identityMatches === []) {
                $errors[] = 'salesforce_user_id no tiene una identidad local verificable; no se acepta solo por su formato.';
            } elseif (count($identityMatches) > 1) {
                $errors[] = 'salesforce_user_id tiene varias identidades locales y es ambiguo.';
            } elseif (blank($identityMatches[0]['name'] ?? null)) {
                $errors[] = 'La identidad local del usuario Salesforce no tiene un nombre verificable.';
            } elseif ($this->normalizeName((string) $identityMatches[0]['name']) !== $this->normalizeName($memberName)) {
                $errors[] = 'member_name no coincide con la identidad local del usuario Salesforce.';
            }
        }
        if ($memberName === '' || mb_strlen($memberName) > 255) {
            $errors[] = 'member_name es obligatorio y no puede superar 255 caracteres.';
        }
        if ($coverageFrom === null) {
            $errors[] = 'coverage_from no es una fecha valida.';
        }
        if (($input['coverage_to'] ?? '') !== '' && $coverageTo === null) {
            $errors[] = 'coverage_to no es una fecha valida.';
        }
        if ($coverageFrom !== null && $coverageTo !== null && $coverageTo->lessThanOrEqualTo($coverageFrom)) {
            $errors[] = 'coverage_to debe ser posterior a coverage_from.';
        }
        if (! in_array($source, self::SOURCES, true)) {
            $errors[] = 'source no esta permitido.';
        }
        if ($reference === '' || mb_strlen($reference) > 255) {
            $errors[] = 'evidence_reference es obligatorio y no puede superar 255 caracteres.';
        }
        if ($recordedBy === '' || mb_strlen($recordedBy) > 255) {
            $errors[] = 'recorded_by es obligatorio y no puede superar 255 caracteres.';
        }
        if ($verified === null) {
            $errors[] = 'history_verified debe ser true o false.';
        }

        $delegationKey = $delegationName === ''
            ? ''
            : $this->formulaConfig->delegationKey($delegationName);
        if ($month !== null && $delegationKey !== '' && Schema::hasTable('store_manager_commission_targets')
            && ! collect($this->targets->forMonth($month))->contains(fn (array $target): bool => $target['store_key'] === $delegationKey)) {
            $errors[] = 'La tienda no tiene un objetivo persistido para el mes indicado.';
        }
        if ($month !== null && $coverageFrom !== null
            && ($coverageFrom->greaterThanOrEqualTo($month->addMonth())
                || ($coverageTo !== null && $coverageTo->lessThanOrEqualTo($month)))) {
            $errors[] = 'El periodo de cobertura no intersecta el mes indicado.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['roster' => $errors]);
        }

        return [
            'month' => $month->format('Y-m'),
            'delegation_key' => $delegationKey,
            'delegation_name' => $delegationName,
            'salesforce_user_id' => $salesforceUserId,
            'member_name' => $memberName,
            'coverage_from' => $coverageFrom,
            'coverage_to' => $coverageTo,
            'source' => $source,
            'evidence_reference' => $reference,
            'recorded_by' => $recordedBy,
            'history_verified' => (bool) $verified,
        ];
    }

    /** @param array<string, mixed> $validated */
    public function record(array $validated, string $importedBy): CommercialCommissionRosterMember
    {
        $identity = [
            'month' => $validated['month'],
            'delegation_key' => $validated['delegation_key'],
            'salesforce_user_id' => $validated['salesforce_user_id'],
            'coverage_from' => $validated['coverage_from'],
        ];
        $existing = CommercialCommissionRosterMember::query()->where($identity)->first();
        if ($existing !== null) {
            $incoming = collect($validated)
                ->map(fn (mixed $value): mixed => $this->auditValue($value))
                ->all();
            $stored = collect($existing->only(array_keys($incoming)))
                ->map(fn (mixed $value): mixed => $this->auditValue($value))
                ->all();
            if ($incoming !== $stored) {
                throw ValidationException::withMessages([
                    'roster' => ['La evidencia existente para la misma adscripción no puede sobrescribirse silenciosamente.'],
                ]);
            }

            return $existing;
        }

        return CommercialCommissionRosterMember::query()->create([
            ...$validated,
            'imported_by' => $importedBy,
        ]);
    }

    /** @param array<int, array<string, mixed>> $validated */
    public function assertNoConflictingCoverage(array $validated): void
    {
        if ($validated === []) {
            return;
        }

        $months = collect($validated)->pluck('month')->unique()->values();
        $userIds = collect($validated)->pluck('salesforce_user_id')->unique()->values();
        $existing = Schema::hasTable('store_manager_commission_roster_members')
            ? CommercialCommissionRosterMember::query()
                ->whereIn('month', $months->all())
                ->whereIn('salesforce_user_id', $userIds->all())
                ->get()
            : collect();
        $records = collect($validated)->map(fn (array $row): array => [
            ...$row,
            '_source' => 'file',
        ])->merge($existing->map(fn (CommercialCommissionRosterMember $row): array => [
            ...$row->toArray(),
            '_source' => 'database',
        ]));

        foreach ($records->groupBy(fn (array $row): string => $row['month'].'|'.$row['salesforce_user_id']) as $group) {
            $group = $group->values();
            foreach ($group as $index => $left) {
                foreach ($group->slice($index + 1) as $right) {
                    if (! $this->intervalsOverlap($left, $right)) {
                        continue;
                    }

                    $sameKey = $left['delegation_key'] === $right['delegation_key']
                        && $this->sameDate($left['coverage_from'] ?? null, $right['coverage_from'] ?? null);
                    if ($sameKey && $left['_source'] !== $right['_source']) {
                        continue;
                    }
                    if ($sameKey) {
                        throw ValidationException::withMessages([
                            'roster' => ['El CSV contiene filas duplicadas para la misma adscripción y cobertura.'],
                        ]);
                    }

                    throw ValidationException::withMessages([
                        'roster' => ['Existen adscripciones solapadas o contradictorias para el mismo usuario Salesforce.'],
                    ]);
                }
            }
        }
    }

    private function intervalsOverlap(array $left, array $right): bool
    {
        $leftFrom = $this->dateTime($left['coverage_from'] ?? null);
        $rightFrom = $this->dateTime($right['coverage_from'] ?? null);
        $leftTo = $this->dateTime($left['coverage_to'] ?? null, nullable: true);
        $rightTo = $this->dateTime($right['coverage_to'] ?? null, nullable: true);
        if ($leftFrom === null || $rightFrom === null) {
            return false;
        }

        $latestFrom = $leftFrom->greaterThan($rightFrom) ? $leftFrom : $rightFrom;
        $earliestTo = match (true) {
            $leftTo === null => $rightTo,
            $rightTo === null => $leftTo,
            $leftTo->lessThan($rightTo) => $leftTo,
            default => $rightTo,
        };

        return $earliestTo === null || $latestFrom->lessThan($earliestTo);
    }

    private function sameDate(mixed $left, mixed $right): bool
    {
        $leftDate = $this->dateTime($left);
        $rightDate = $this->dateTime($right);

        return $leftDate !== null && $rightDate !== null && $leftDate->equalTo($rightDate);
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }

    /** @return array<int, array{salesforce_id: string|null, name: string|null}> */
    private function localIdentities(string $salesforceUserId): array
    {
        if (! array_key_exists($salesforceUserId, $this->identityCache)) {
            $this->identityCache[$salesforceUserId] = SalesforceUser::query()
                ->where('salesforce_id', $salesforceUserId)
                ->get(['salesforce_id', 'name'])
                ->map(fn (SalesforceUser $user): array => [
                    'salesforce_id' => $user->salesforce_id,
                    'name' => $user->name,
                ])
                ->all();
        }

        return $this->identityCache[$salesforceUserId];
    }

    private function auditValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)->toIso8601String()
            : $value;
    }

    public function hasDefinitiveDelegationClosure(string $month): bool
    {
        return Schema::hasTable('commercial_commission_closures')
            && CommercialCommissionClosure::query()
                ->where('month', $month)
                ->where('closure_scope', CommercialCommissionClosure::SCOPE_DELEGATIONS)
                ->where('status', CommercialCommissionClosure::STATUS_DEFINITIVE)
                ->exists();
    }

    private function month(mixed $value): ?CarbonImmutable
    {
        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', trim((string) $value));

            return $month !== false && $month->format('Y-m') === trim((string) $value)
                ? $month->startOfMonth()
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateTime(mixed $value, bool $nullable = false): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($nullable && $value === '') {
            return null;
        }
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validSalesforceId(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]{15}(?:[a-zA-Z0-9]{3})?$/', $value);
    }
}
