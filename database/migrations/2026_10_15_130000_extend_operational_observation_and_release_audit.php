<?php

use App\Services\Operations\OperationalTaskCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_task_states', function (Blueprint $table): void {
            $table->timestamp('monitoring_started_at')->nullable();
            $table->unsignedBigInteger('last_completed_id')->nullable();
        });

        // Activation is recorded once, never inferred from process uptime or
        // reset on each monitor run. No integration is invoked by this catalog.
        $activatedAt = now();
        foreach (array_keys((new OperationalTaskCatalog)->all()) as $key) {
            DB::table('operational_task_states')->insertOrIgnore([
                'task_key' => $key, 'monitoring_started_at' => $activatedAt,
                'created_at' => $activatedAt, 'updated_at' => $activatedAt,
            ]);
        }
        // Migrate only existing evidence pointers, never scan execution history.
        $states = DB::table('operational_task_states')->get();
        $ids = $states->flatMap(fn ($state) => [$state->last_run_id, $state->last_success_id, $state->last_failure_id])->filter()->unique();
        $runs = DB::table('operational_task_runs')->whereIn('id', $ids)->whereNotNull('finished_at')->get()->keyBy('id');
        foreach ($states as $state) {
            $completed = collect([$state->last_run_id, $state->last_success_id, $state->last_failure_id])
                ->filter(fn ($id) => $runs->has($id))->max();
            DB::table('operational_task_states')->where('task_key', $state->task_key)
                ->update([
                    'last_completed_id' => $completed,
                    // Existing task observation must not receive a new grace period.
                    'monitoring_started_at' => $state->monitoring_started_at ?? $state->created_at ?? $activatedAt,
                ]);
        }

        Schema::create('operational_task_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('task_key', 100);
            $table->uuid('run_correlation_id');
            $table->string('actor_type', 30);
            $table->unsignedBigInteger('actor_identifier')->nullable();
            $table->string('reason', 40);
            $table->string('reference', 20)->nullable();
            $table->timestamp('released_at');
            $table->index(['task_key', 'released_at'], 'ops_releases_task_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_task_releases');
        Schema::table('operational_task_states', function (Blueprint $table): void {
            $table->dropColumn(['monitoring_started_at', 'last_completed_id']);
        });
    }
};
