<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionRosterMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StoreManagerTeamRosterEvidenceService
{
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
    public function record(array $validated): CommercialCommissionRosterMember
    {
        return CommercialCommissionRosterMember::query()->updateOrCreate(
            [
                'month' => $validated['month'],
                'delegation_key' => $validated['delegation_key'],
                'salesforce_user_id' => $validated['salesforce_user_id'],
                'coverage_from' => $validated['coverage_from'],
            ],
            $validated,
        );
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
