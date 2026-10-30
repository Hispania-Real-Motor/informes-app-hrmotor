<?php

namespace App\Services\Reports\ContactCenterCommissions;

use App\Models\ContactCenterCommissionRosterMember;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactCenterCommissionRosterService
{
    public function membersForDate(CarbonInterface|string $date): Collection
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return ContactCenterCommissionRosterMember::query()
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderBy('display_name')
            ->get();
    }

    public function membersForMonth(CarbonInterface|string $month): Collection
    {
        $start = CarbonImmutable::parse($month)->startOfMonth()->toDateString();
        $end = CarbonImmutable::parse($month)->endOfMonth()->toDateString();

        return ContactCenterCommissionRosterMember::query()
            ->where('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $start))
            ->orderBy('display_name')
            ->get();
    }

    public function trackedUserIds(): array
    {
        return ContactCenterCommissionRosterMember::query()
            ->distinct()
            ->orderBy('salesforce_user_id')
            ->pluck('salesforce_user_id')
            ->all();
    }

    /** @param array{salesforce_user_id:string,display_name:string,effective_from:string,effective_until:?string} $attributes */
    public function save(array $attributes, ?ContactCenterCommissionRosterMember $member = null): ContactCenterCommissionRosterMember
    {
        $from = CarbonImmutable::parse($attributes['effective_from'])->toDateString();
        $until = blank($attributes['effective_until'] ?? null)
            ? null
            : CarbonImmutable::parse($attributes['effective_until'])->toDateString();

        if ($until !== null && $until < $from) {
            throw ValidationException::withMessages(['effective_until' => 'La fecha de fin no puede ser anterior a la fecha de inicio.']);
        }

        return DB::transaction(function () use ($attributes, $from, $until, $member): ContactCenterCommissionRosterMember {
            $query = ContactCenterCommissionRosterMember::query()
                ->where('salesforce_user_id', $attributes['salesforce_user_id'])
                ->lockForUpdate();

            if ($member !== null && $member->salesforce_user_id !== $attributes['salesforce_user_id']) {
                ContactCenterCommissionRosterMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            }

            $overlap = $query
                ->when($member, fn ($rows) => $rows->where('id', '!=', $member->id))
                ->where('effective_from', '<=', $until ?? '9999-12-31')
                ->where(fn ($rows) => $rows->whereNull('effective_until')->orWhere('effective_until', '>=', $from))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => 'La vigencia se solapa con otro tramo de este usuario de Salesforce.']);
            }

            $member ??= new ContactCenterCommissionRosterMember;
            $member->fill([
                'salesforce_user_id' => $attributes['salesforce_user_id'],
                'display_name' => trim($attributes['display_name']),
                'effective_from' => $from,
                'effective_until' => $until,
            ]);
            $member->save();

            return $member;
        }, 3);
    }
}
