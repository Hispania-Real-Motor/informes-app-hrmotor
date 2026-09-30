<?php

namespace App\Services\Auth;

use App\Models\ReportUser;
use App\Models\ReportUserPasswordResetToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportUserPasswordResetService
{
    public function createTokenFor(ReportUser $user): string
    {
        $plainToken = Str::random(64);
        $emailHash = $this->emailHash($user->email);

        DB::transaction(function () use ($user, $plainToken, $emailHash): void {
            ReportUserPasswordResetToken::query()
                ->where('report_user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            ReportUserPasswordResetToken::query()->create([
                'report_user_id' => $user->id,
                'email_hash' => $emailHash,
                'token_hash' => $this->tokenHash($plainToken),
                'expires_at' => now()->addMinutes($this->expireMinutes()),
            ]);
        });

        return $plainToken;
    }

    public function tokenRecord(string $plainToken): ?ReportUserPasswordResetToken
    {
        if ($plainToken === '') {
            return null;
        }

        $record = ReportUserPasswordResetToken::query()
            ->with('reportUser')
            ->where('token_hash', $this->tokenHash($plainToken))
            ->whereNull('consumed_at')
            ->first();

        if (! $record || $record->expires_at->isPast()) {
            return null;
        }

        $user = $record->reportUser;

        if (! $user || ! $user->is_active || ! hash_equals($record->email_hash, $this->emailHash($user->email))) {
            return null;
        }

        return $record;
    }

    public function resetPassword(string $plainToken, string $password): bool
    {
        return DB::transaction(function () use ($plainToken, $password): bool {
            $record = ReportUserPasswordResetToken::query()
                ->where('token_hash', $this->tokenHash($plainToken))
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if (! $record || $record->expires_at->isPast()) {
                return false;
            }

            $user = ReportUser::query()
                ->whereKey($record->report_user_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $user || ! hash_equals($record->email_hash, $this->emailHash($user->email))) {
                return false;
            }

            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
            ])->save();

            ReportUserPasswordResetToken::query()
                ->where('report_user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return true;
        });
    }

    public function pruneExpired(): int
    {
        return ReportUserPasswordResetToken::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();
    }

    public function expireMinutes(): int
    {
        return max(1, (int) config('auth.report_password_reset.expire_minutes', 60));
    }

    public function tokenHash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function emailHash(string $email): string
    {
        return hash('sha256', Str::lower(trim($email)));
    }
}
