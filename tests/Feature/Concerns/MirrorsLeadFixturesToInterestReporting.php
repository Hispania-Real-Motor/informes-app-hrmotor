<?php

namespace Tests\Feature\Concerns;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Illuminate\Support\Str;

trait MirrorsLeadFixturesToInterestReporting
{
    private SalesforceInterestActivityRun $interestActivityFixtureRun;

    protected function setUpMirrorsLeadFixturesToInterestReporting(): void
    {
        $cutoff = now()->addDay();
        $f2 = ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'source_cutoff_at' => $cutoff,
            'started_at' => $cutoff->subMinute(),
            'completed_at' => $cutoff,
            'timezone' => 'UTC',
            'stats' => [],
        ]);
        $this->interestActivityFixtureRun = SalesforceInterestActivityRun::query()->create([
            'run_identifier' => (string) Str::uuid(),
            'reason' => 'Aligned test snapshot for Interest reporting regressions',
            'status' => 'completed',
            'source_interest_sync_run_id' => $f2->id,
            'source_interest_cutoff_at' => $f2->source_cutoff_at,
            'source_cutoff_at' => $cutoff,
            'started_at' => $cutoff->subMinute(),
            'completed_at' => $cutoff,
            'stats' => [],
        ]);

        SalesforceLead::created(function (SalesforceLead $lead): void {
            $ownerId = $lead->owner_id;
            $ownerName = $lead->owner_name;
            if ($lead->status === 'Convertido' && filled($lead->persona_que_trabajo_id)) {
                $ownerId = $lead->persona_que_trabajo_id;
                $ownerName = $lead->persona_que_trabajo_name;
            } elseif ($lead->status === 'Descartado' && filled($lead->propietario_descarte_id)) {
                $ownerId = $lead->propietario_descarte_id;
                $ownerName = $lead->propietario_descarte_name;
            }

            $interest = new SalesforceInterest;
            $interest->forceFill([
                'salesforce_id' => $lead->salesforce_id,
                'salesforce_created_at' => $lead->created_date,
                'salesforce_last_modified_at' => $lead->salesforce_last_modified_at ?? $lead->created_date,
                'owner_salesforce_id' => $ownerId,
                'owner_name' => $ownerName,
                'status' => $lead->status,
                'type' => $lead->record_type_name,
                'source' => $lead->fuente_nuevo ?: $lead->portal_text,
                'original_source' => $lead->fuente_original,
                'medium' => $lead->medio_nuevo,
                'channel' => $lead->medio_nuevo,
                'origin_delegation' => $lead->delegacion_encargada_bueno
                    ?: ($lead->delegacion_encargada ?: $lead->delegacion_encargada_text),
                'synced_at' => $lead->synced_at,
                'is_deleted' => (bool) $lead->is_deleted,
                'salesforce_deleted_at' => $lead->salesforce_deleted_at,
                'deletion_detection_source' => $lead->deletion_detection_source,
            ]);
            $interest->save();
        });

        SalesforceLeadActivitySummary::saved(function (SalesforceLeadActivitySummary $summary): void {
            SalesforceInterestActivity::query()
                ->where('activity_run_id', $this->interestActivityFixtureRun->id)
                ->where('interest_salesforce_id', $summary->lead_salesforce_id)
                ->delete();

            if ((int) $summary->total_actividades === 0) {
                return;
            }

            foreach (range(1, (int) $summary->total_actividades) as $sequence) {
                SalesforceInterestActivity::query()->create([
                    'activity_run_id' => $this->interestActivityFixtureRun->id,
                    'activity_salesforce_id' => substr('00T'.sha1($summary->lead_salesforce_id.'-'.$sequence), 0, 18),
                    'interest_salesforce_id' => $summary->lead_salesforce_id,
                    'activity_kind' => 'Task',
                    'relationship_status' => 'resolved',
                    'activity_is_deleted' => false,
                    'interest_is_deleted' => false,
                    'activity_date' => $summary->fecha_ultima_actividad?->toDateString(),
                    'salesforce_created_at' => $summary->fecha_ultima_actividad,
                ]);
            }
        });
    }
}
