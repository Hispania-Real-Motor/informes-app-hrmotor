<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_center_commission_roster_members', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_user_id', 18);
            $table->string('display_name');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->unique(['salesforce_user_id', 'effective_from'], 'cc_roster_member_start_unique');
            $table->index(['salesforce_user_id', 'effective_from', 'effective_until'], 'cc_roster_member_range_idx');
        });

        $now = now();
        DB::table('contact_center_commission_roster_members')->insertOrIgnore(array_map(
            fn (array $member): array => $member + ['created_at' => $now, 'updated_at' => $now],
            [
                ['salesforce_user_id' => '005Qx00000GTjweIAD', 'display_name' => 'Maria Paz Vidal', 'effective_from' => '2026-09-01', 'effective_until' => null],
                ['salesforce_user_id' => '005Qx00000Hf7gYIAR', 'display_name' => 'Estefany Taborda', 'effective_from' => '2026-09-01', 'effective_until' => null],
                ['salesforce_user_id' => '005Qx00000I63X7IAJ', 'display_name' => 'Rafael Polanco', 'effective_from' => '2026-09-01', 'effective_until' => null],
                ['salesforce_user_id' => '005Qx00000JfwwLIAR', 'display_name' => 'Aaron Bordas', 'effective_from' => '2026-09-01', 'effective_until' => null],
                ['salesforce_user_id' => '005Qx00000HauJdIAJ', 'display_name' => 'Maria German', 'effective_from' => '2026-09-01', 'effective_until' => null],
                ['salesforce_user_id' => '005Qx00000HEQyXIAX', 'display_name' => 'Yuleidis Garcia', 'effective_from' => '2026-09-01', 'effective_until' => null],
                ['salesforce_user_id' => '005Qx00000JfETuIAN', 'display_name' => 'Sandra Royo', 'effective_from' => '2026-09-01', 'effective_until' => null],
            ]
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_center_commission_roster_members');
    }
};
