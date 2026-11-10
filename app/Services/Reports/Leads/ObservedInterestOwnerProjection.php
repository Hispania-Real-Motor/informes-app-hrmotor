<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Resolves only observed owner identity and assignment, never historical inference. */
final class ObservedInterestOwnerProjection
{
    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    public function forRows(Collection $rows, string $cutoffUtc, string $projectionKey): array
    {
        $ownerIds = $rows->pluck('owner_salesforce_id')->filter()->unique()->values()->all();
        $observations = $ownerIds === [] ? collect() : DB::table('commercial_delegation_snapshots')
            ->whereIn('salesforce_user_id', $ownerIds)
            ->where('observed_from', '<', $cutoffUtc)
            ->where(function ($query) use ($cutoffUtc): void {
                $query->whereNull('observed_until')->orWhere('observed_until', '>=', $cutoffUtc);
            })
            ->get(['salesforce_user_id', 'delegation', 'zone'])
            ->groupBy('salesforce_user_id');

        $assignments = [];
        $labels = [];
        foreach ($rows as $row) {
            $ownerId = trim((string) $row->owner_salesforce_id);
            $ownerName = trim((string) $row->owner_name);
            $ownerKey = $ownerId === '' ? 'missing' : hash_hmac('sha256', $ownerId, (string) config('app.key'));
            $technical = InterestTechnicalOwnerClassifier::isTechnical($ownerId, $ownerName);
            $observed = $ownerId === '' || $technical ? null : $observations->get($ownerId);
            $assignment = $observed !== null && $observed->count() === 1 ? $observed->first() : null;
            $assignments[(int) $row->id] = [
                'owner_key' => $ownerKey,
                'owner_is_technical' => $technical,
                'commercial_delegation' => $ownerId === '' ? 'Sin propietario observado' : ($technical ? 'Propietario técnico' : ($assignment->delegation ?? 'Asignación no acreditada')),
                'commercial_zone' => $ownerId === '' ? 'Sin propietario observado' : ($technical ? 'Propietario técnico' : ($assignment->zone ?? 'Asignación no acreditada')),
            ];
            if (! isset($labels[$ownerKey])) {
                $labels[$ownerKey] = [
                    'projection_key' => $projectionKey,
                    'owner_key' => $ownerKey,
                    'name_ciphertext' => $ownerId === '' ? null : Crypt::encryptString(mb_substr($ownerName === '' ? 'Propietario sin nombre' : $ownerName, 0, 191)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        return [$assignments, array_values($labels)];
    }
}
