<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\CommercialDelegationSnapshot;
use App\Models\SalesforceUser;
use App\Services\Operations\OperationalAlertService;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommercialDelegationSnapshotService
{
    public const SOURCE_OBSERVED = 'salesforce_user_observation';

    public const SOURCE_BUSINESS_BOOTSTRAP = 'business_bootstrap_2026_04';

    private const BOOTSTRAP_START = '2026-04-01 00:00:00';

    private const DATASET_TIMEZONE = 'Europe/Madrid';

    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly OperationalAlertService $alerts,
    ) {}

    public function captureCurrentUsers(?CarbonInterface $observedAt = null): array
    {
        $observedAt = CarbonImmutable::parse($observedAt ?? now())->utc()->startOfSecond();
        $affectsOctoberCutoff = $observedAt->lessThan(CarbonImmutable::parse('2026-10-31 23:00:00', 'UTC'));
        $created = 0;
        $closed = 0;
        $unchanged = 0;

        SalesforceUser::query()
            ->where(function ($query): void {
                $query->whereIn('profile_name', self::COMMERCIAL_PROFILES)
                    ->orWhereIn('salesforce_id', CommercialDelegationSnapshot::query()
                        ->whereNull('observed_until')
                        ->select('salesforce_user_id'));
            })
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($observedAt, $affectsOctoberCutoff, &$created, &$closed, &$unchanged): void {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $observedAt, $affectsOctoberCutoff, &$created, &$closed, &$unchanged): void {
                        SalesforceUser::query()
                            ->whereKey($user->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        $open = CommercialDelegationSnapshot::query()
                            ->where('salesforce_user_id', $user->salesforce_id)
                            ->whereNull('observed_until')
                            ->lockForUpdate()
                            ->latest('observed_from')
                            ->first();

                        $isCommerciallyEligible = $user->is_active
                            && in_array($user->profile_name, self::COMMERCIAL_PROFILES, true);

                        if (! $isCommerciallyEligible) {
                            if ($open !== null) {
                                $open->update(['observed_until' => $observedAt, 'open_marker' => null]);
                                $this->invalidateInterestEvidence($affectsOctoberCutoff, $user->salesforce_id, $observedAt);
                                $closed++;
                            } else {
                                $unchanged++;
                            }

                            return;
                        }

                        $normalized = $this->delegationNormalizer->normalize($user->user_delegation);
                        $delegation = $normalized['is_classified'] ? $normalized['delegation'] : null;
                        $zone = $normalized['is_classified'] ? $normalized['zone'] : null;

                        if ($open !== null && $open->delegation === $delegation && $open->zone === $zone) {
                            $unchanged++;

                            return;
                        }

                        if ($open !== null) {
                            if ($open->observed_from->equalTo($observedAt)) {
                                $this->openOrganisationChangeAlert(
                                    $user->salesforce_id,
                                    $user->name,
                                    $open->delegation,
                                    $open->zone,
                                    $delegation,
                                    $zone,
                                    $observedAt,
                                );
                                $open->update(['delegation' => $delegation, 'zone' => $zone]);
                                $this->invalidateInterestEvidence($affectsOctoberCutoff, $user->salesforce_id, $observedAt);
                                $unchanged++;

                                return;
                            }

                            $open->update(['observed_until' => $observedAt, 'open_marker' => null]);
                            $closed++;

                            $this->openOrganisationChangeAlert(
                                $user->salesforce_id,
                                $user->name,
                                $open->delegation,
                                $open->zone,
                                $delegation,
                                $zone,
                                $observedAt,
                            );
                        }

                        CommercialDelegationSnapshot::query()->create([
                            'salesforce_user_id' => $user->salesforce_id,
                            'delegation' => $delegation,
                            'zone' => $zone,
                            'observed_from' => $observedAt,
                            'open_marker' => 1,
                            'source' => self::SOURCE_OBSERVED,
                        ]);
                        $this->invalidateInterestEvidence($affectsOctoberCutoff, $user->salesforce_id, $observedAt);
                        $created++;
                    });
                }
            });

        return compact('created', 'closed', 'unchanged') + [
            'observed_at' => $observedAt->toIso8601String(),
        ];
    }

    public function bootstrapHistoricalAssignments(): array
    {
        $bootstrapStart = CarbonImmutable::parse(self::BOOTSTRAP_START, self::DATASET_TIMEZONE)->utc();
        $stats = [
            'created' => 0,
            'already_present' => 0,
            'missing_dimensions' => [],
            'conflicting_history' => [],
            'not_initial_cohort' => [],
            'not_applicable' => [],
        ];

        $initialObservedFrom = CommercialDelegationSnapshot::query()
            ->where('source', self::SOURCE_OBSERVED)
            ->min('observed_from');
        if ($initialObservedFrom === null) {
            return $stats;
        }

        $initialObservedAt = CarbonImmutable::parse($initialObservedFrom, 'UTC')->utc();
        $initialObservedKey = $initialObservedAt->toDateTimeString();

        CommercialDelegationSnapshot::query()
            ->where('source', self::SOURCE_OBSERVED)
            ->orderBy('id')
            ->get()
            ->groupBy('salesforce_user_id')
            ->each(function (Collection $observed, string $userId) use ($bootstrapStart, $initialObservedKey, &$stats): void {
                $firstObserved = $observed->sortBy('observed_from')->first();
                $firstObservedAt = CarbonImmutable::parse($firstObserved->getRawOriginal('observed_from'), 'UTC')->utc();

                if ($firstObservedAt->toDateTimeString() !== $initialObservedKey) {
                    $stats['not_initial_cohort'][] = $userId;

                    return;
                }

                if ($firstObservedAt->lessThanOrEqualTo($bootstrapStart)) {
                    $stats['not_applicable'][] = $userId;

                    return;
                }

                if (blank($firstObserved->delegation) || blank($firstObserved->zone)) {
                    $stats['missing_dimensions'][] = $userId;

                    return;
                }

                $existingBootstrap = CommercialDelegationSnapshot::query()
                    ->where('salesforce_user_id', $userId)
                    ->where('source', self::SOURCE_BUSINESS_BOOTSTRAP)
                    ->where('observed_from', $bootstrapStart)
                    ->where('observed_until', $firstObservedAt)
                    ->exists();
                if ($existingBootstrap) {
                    $stats['already_present']++;

                    return;
                }

                $hasConflictingHistory = CommercialDelegationSnapshot::query()
                    ->where('salesforce_user_id', $userId)
                    ->where('source', '!=', self::SOURCE_BUSINESS_BOOTSTRAP)
                    ->where('id', '!=', $firstObserved->id)
                    ->where('observed_from', '<', $firstObservedAt)
                    ->exists();
                if ($hasConflictingHistory) {
                    $stats['conflicting_history'][] = $userId;
                    $this->alerts->open(
                        'commercial_bootstrap_conflict',
                        'low',
                        'salesforce:sync-monthly-commercial',
                        $userId.':business_bootstrap_2026_04',
                        'Bootstrap histórico comercial omitido por evidencia previa contradictoria.',
                        [
                            'salesforce_user_id' => $userId,
                            'bootstrap_start' => $bootstrapStart->toIso8601String(),
                            'first_observed_at' => $firstObservedAt->toIso8601String(),
                        ],
                    );

                    return;
                }

                $inserted = DB::transaction(function () use ($userId, $firstObserved, $bootstrapStart, $firstObservedAt): int {
                    $inserted = CommercialDelegationSnapshot::query()->insertOrIgnore([
                        'salesforce_user_id' => $userId,
                        'delegation' => $firstObserved->delegation,
                        'zone' => $firstObserved->zone,
                        'observed_from' => $bootstrapStart->toDateTimeString(),
                        'observed_until' => $firstObservedAt->toDateTimeString(),
                        'open_marker' => null,
                        'source' => self::SOURCE_BUSINESS_BOOTSTRAP,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if ($inserted === 1) {
                        $this->invalidateInterestEvidence(
                            $firstObservedAt->greaterThanOrEqualTo(CarbonImmutable::parse('2026-10-31 23:00:00', 'UTC')),
                            $userId,
                            $bootstrapStart,
                            $firstObservedAt,
                        );
                    }

                    return $inserted;
                });

                $stats[$inserted === 1 ? 'created' : 'already_present']++;
            });

        return $stats;
    }

    private function invalidateInterestEvidence(
        bool $affectsOctoberCutoff,
        string $ownerId,
        CarbonImmutable $observedAt,
        ?CarbonImmutable $through = null,
    ): void {
        if ($affectsOctoberCutoff && DB::table('salesforce_interest_monthly_integrity_revisions')
            ->where('scope', 'october_2026_provisional')->exists()) {
            app(October2026ProvisionalEvidenceInvalidator::class)
                ->afterContentMutation(October2026ProvisionalEvidenceInvalidator::RECOVERY_ID);
        }

        $ownerKey = hash_hmac('sha256', $ownerId, (string) config('app.key'));
        $scopes = DB::table('salesforce_interest_october_provisional_validations as validation')
            ->join('salesforce_interest_monthly_recoveries as recovery', 'recovery.id', '=', 'validation.monthly_recovery_id')
            ->join('salesforce_interest_october_provisional_owners as owner', 'owner.projection_key', '=', 'validation.projection_key')
            ->where('validation.scope', 'like', 'monthly_recovery_%')
            ->where('owner.owner_key', $ownerKey)
            ->where('recovery.functional_end_at', '>', $observedAt->toDateTimeString())
            ->when($through !== null, fn ($query) => $query->where('recovery.functional_end_at', '<=', $through->toDateTimeString()))
            ->pluck('validation.scope')->all();
        if ($scopes !== []) {
            $updated = DB::table('salesforce_interest_monthly_integrity_revisions')
                ->whereIn('scope', $scopes)
                ->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            if ($updated !== count($scopes)) {
                throw new \RuntimeException('Monthly owner evidence revision is unavailable.');
            }
        }
    }

    private function openOrganisationChangeAlert(
        string $userId,
        ?string $userName,
        ?string $previousDelegation,
        ?string $previousZone,
        ?string $newDelegation,
        ?string $newZone,
        CarbonImmutable $observedAt,
    ): void {
        $technicalIdentifier = implode(':', [
            $userId,
            $observedAt->format('YmdHis'),
            substr(hash('sha256', implode('|', [$previousDelegation, $previousZone, $newDelegation, $newZone])), 0, 12),
        ]);

        $this->alerts->open(
            'commercial_organisation_change',
            'low',
            'salesforce:sync-monthly-commercial',
            $technicalIdentifier,
            'Cambio de delegación o zona comercial observado.',
            [
                'salesforce_user_id' => $userId,
                'commercial_name' => $userName,
                'previous_delegation' => $previousDelegation,
                'previous_zone' => $previousZone,
                'new_delegation' => $newDelegation,
                'new_zone' => $newZone,
                'observed_at' => $observedAt->toIso8601String(),
            ],
        );
    }
}
