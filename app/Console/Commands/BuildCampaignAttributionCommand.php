<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignAttributionBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildCampaignAttributionCommand extends Command
{
    protected $signature = 'campaigns:build-attribution
        {--days=60 : Días hacia atrás de Interests a procesar}
        {--months= : Meses hacia atras que se procesan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva en formato Y-m-d; por defecto ahora}
        {--dry-run : Simula la reconstruccion sin escribir atribuciones}
        {--reason= : Motivo auditable para una reconstruccion historica en modo escritura}
        {--window= : Opcion legacy sin efecto; mantener solo por compatibilidad}';

    protected $description = 'Construye la atribución Interest -> oportunidad -> reserva -> venta por campaña.';

    public function handle(CampaignAttributionBuilderService $builder): int
    {
        $end = $this->periodEnd();
        $start = $this->periodStart($end);
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && filled($this->option('from')) && blank($this->option('reason'))) {
            $this->error('Las reconstrucciones historicas con --from requieren --reason.');

            return self::FAILURE;
        }

        $result = $builder->build($start, $end, $dryRun);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Rango: '.$result['range_start'].' a '.$result['range_end']);
        $this->line('Tabla origen Interests: '.$result['interest_source_table']);
        $this->line('Interests en rango: '.$result['total_interests_in_range']);
        $this->line('Interests con adquisición no null: '.$result['interests_with_acquisition_not_null']);
        $this->line('Interests candidatos válidos: '.$result['candidate_interests']);
        $this->line('Descartados por valores invalidos: '.$result['discarded_invalid_values']);
        $this->line('Interests sin evidencia de adquisición: '.$result['interests_without_acquisition_evidence']);
        $this->line('Campanas excluidas: '.$result['excluded_campaigns']);
        $this->line('Descartados por fecha: '.$result['discarded_by_date']);
        $this->line('Interests procesados: '.$result['processed_interests']);
        $this->line('Atribuciones guardadas: '.$result['saved_attributions']);
        $this->line('Cruces con plataforma: '.$result['matched_to_platform']);
        $this->line('Candidatos con campaign_acquired: '.$result['candidates_with_campaign_acquired']);
        $this->line('Candidatos solo source/medium: '.$result['candidates_only_source_medium']);
        $this->line('Candidatos con acquired_id: '.$result['candidates_with_acquired_id']);
        $this->line('Candidatos con content_acquired: '.$result['candidates_with_content_acquired']);
        $this->line('Match ad_id: '.$result['match_ad_id']);
        $this->line('Match adset/ad_group: '.$result['match_adset_or_adgroup']);
        $this->line('Match campaign_id: '.$result['match_campaign_id']);
        $this->line('Match campaign_name exacto: '.$result['match_campaign_name_exact']);
        $this->line('Match campaign_name flexible: '.$result['match_campaign_name_flexible']);
        $this->line('Match campaign_name: '.$result['match_campaign_name']);
        $this->line('Sin plataforma asociada: '.$result['salesforce_only']);
        $this->line('Salesforce-only por campana: '.$result['source_type_salesforce_campaign_without_spend']);
        $this->line('Salesforce-only por procedencia: '.$result['source_type_salesforce_origin']);
        $this->line('Oportunidades: '.$result['opportunities']);
        $this->line('Reservas: '.$result['reservations']);
        $this->line('Reservas caidas: '.$result['fallen_reservations']);
        $this->line('Ventas: '.$result['sales']);
        $this->line('Ventas atribuidas: '.$result['sales']);
        $this->line('Ventas con opportunity encontrada: '.$result['sales_with_opportunity_found']);
        $this->line('Ventas con opo_for_importe_total > 0: '.$result['sales_with_opo_for_importe_total']);
        $this->line('Ventas con amount > 0: '.$result['sales_with_amount']);
        $this->line('Ventas con sale_amount final > 0: '.$result['sales_with_sale_amount']);
        $this->line('Suma sale_amount: '.number_format((float) $result['sale_amount_sum'], 2, '.', ''));
        $this->line('Campo usado para importe: '.$result['sale_amount_field_used']);
        $this->line('Tiempo total: '.$result['duration_seconds'].'s');
        $this->line('Memoria pico: '.$result['peak_memory_mb'].' MB');

        if ($dryRun) {
            $simulation = $result['simulation'];
            $this->newLine();
            $this->line('SIMULACION SIN ESCRITURA');
            $this->line('Campaign Interests examinados: '.$simulation['campaign_interests_examined']);
            $this->line('Atribuciones actuales: '.$simulation['current_attributions']);
            $this->line('Atribuciones simuladas: '.$simulation['simulated_attributions']);
            foreach ($simulation['sets'] as $label => $data) {
                $this->line($label.': '.$data['count'].' | IDs: '.implode(', ', $data['sample_ids']));
            }
            foreach ($simulation['changes'] as $label => $data) {
                $this->line($label.': '.$data['count'].' | IDs: '.implode(', ', $data['sample_ids']));
            }
            foreach ($simulation['campaign_identity_transitions'] as $transition) {
                $this->line('FROM/TO '.$transition['transition'].': '.$transition['count']);
            }
            foreach ($simulation['campaign_identity_change_details'] as $detail) {
                $this->line('INTEREST '.$detail['interest_id'].' | '.$detail['transition']);
                $this->line('CURRENT '.json_encode($detail['current'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('SIMULATED '.json_encode($detail['simulated'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('INPUT '.json_encode($detail['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            foreach ($simulation['interest_types'] as $label => $count) {
                $this->line('Interest tipo '.$label.': '.$count);
            }
            foreach ($simulation['null_record_type_raw'] as $raw => $count) {
                $this->line('RecordType bruto no normalizable '.$raw.': '.$count);
            }
            foreach ($result['excluded_by_reason'] as $reason => $detail) {
                $this->line($reason.': '.$detail['count'].' | IDs: '.implode(', ', $detail['sample_ids']));
            }
        }

        $this->renderTop('Top 20 campaign_acquired', $result['top_campaign_acquired'] ?? []);
        $this->renderTop('Top 20 source_acquired + medium_acquired', $result['top_source_medium'] ?? []);
        $this->renderTop('Top 20 acquired_id', $result['top_acquired_id'] ?? []);
        $this->renderTop('Top 20 content_acquired', $result['top_content_acquired'] ?? []);
        $this->renderTop('Top 20 plataforma por spend', $result['top_platform_spend'] ?? []);

        return self::SUCCESS;
    }

    private function renderTop(string $title, array $rows): void
    {
        $this->newLine();
        $this->line($title);

        if ($rows === []) {
            $this->line('Sin datos');

            return;
        }

        $this->table(array_keys($rows[0]), $rows);
    }

    private function periodStart(CarbonImmutable $end): CarbonImmutable
    {
        $from = $this->option('from');

        if (filled($from)) {
            return CarbonImmutable::parse($from)->startOfDay();
        }

        $months = $this->option('months');

        if ($months !== null && $months !== '') {
            return $end->subMonthsNoOverflow(max((int) $months, 1))->startOfDay();
        }

        return $end->subDays(max((int) $this->option('days'), 1))->startOfDay();
    }

    private function periodEnd(): CarbonImmutable
    {
        $to = $this->option('to');

        if (filled($to)) {
            return CarbonImmutable::parse($to)->startOfDay();
        }

        return CarbonImmutable::now();
    }
}
