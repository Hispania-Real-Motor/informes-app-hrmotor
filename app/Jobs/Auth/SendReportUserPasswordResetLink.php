<?php

namespace App\Jobs\Auth;

use App\Mail\ReportUserPasswordResetMail;
use App\Models\ReportUser;
use App\Services\Auth\ReportUserPasswordResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendReportUserPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $email,
    ) {}

    public function handle(ReportUserPasswordResetService $tokens): void
    {
        $user = ReportUser::query()
            ->where('email', $this->email)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return;
        }

        $plainToken = $tokens->createTokenFor($user);

        Mail::to($user->email)->send(new ReportUserPasswordResetMail(
            resetUrl: route('password.reset', ['token' => $plainToken], true),
            expireMinutes: $tokens->expireMinutes(),
        ));
    }
}
