<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('report_users', 'password_changed_at')) {
            Schema::table('report_users', function (Blueprint $table): void {
                $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            });
        }

        if (Schema::hasTable('report_user_password_reset_tokens')) {
            return;
        }

        Schema::create('report_user_password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_user_id')->constrained('report_users')->cascadeOnDelete();
            $table->string('email_hash', 64);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['report_user_id', 'consumed_at'], 'report_user_reset_user_consumed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_user_password_reset_tokens');

        if (Schema::hasColumn('report_users', 'password_changed_at')) {
            Schema::table('report_users', function (Blueprint $table): void {
                $table->dropColumn('password_changed_at');
            });
        }
    }
};
