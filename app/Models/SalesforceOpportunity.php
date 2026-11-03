<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunity extends Model
{
    public const ACTIVE_SCOPE = 'salesforce_opportunity_active';

    public const DELETION_SOURCE_QUERY_ALL = 'query_all_deleted';

    public const PRESENCE_SOURCE_MISSING = 'presence_reconciliation_missing';

    protected $fillable = [
        'salesforce_id',
        'name',
        'created_date',
        'salesforce_last_modified_at',
        'close_date',
        'amount',
        'opo_for_importe_total',
        'stage_name',
        'record_type_name',
        'owner_id',
        'owner_name',
        'appointment_setter_id',
        'appointment_setter_name',
        'owner_is_active',
        'owner_delegation',
        'report_owner_delegation',
        'delivery_store',
        'account_id',
        'account_name',
        'account_phone',
        'account_person_email',
        'account_company_email',
        'shared_delivery_id',
        'shared_delivery_name',
        'garantia_total',
        'beneficio_financiacion_comercial',
        'importe_financiado',
        'financial_commission',
        'financial_discount',
        'interest_rate',
        'financial_zone',
        'opportunity_record_type_formula',
        'financing_paid',
        'financing_paid_date',
        'financed_amount_ratio',
        'gestion_de_venta',
        'management_cost',
        'logistics_cost',
        'transfer_cost',
        'logistics_discount',
        'opo_div_descuento',
        'informe_rentabilidad',
        'rentabilidad_financiera',
        'vehicle_interest_id',
        'appraised_vehicle_id',
        'appraised_vehicle_plate',
        'trade_in_amount',
        'vehicle_sale_price',
        'financed_vehicle_sale_price',
        'vehicle_only_financed',
        'contract_vehicle_sale_amount',
        'plan_auto_plus_amount',
        'cae_amount',
        'vehicle_purchase_price',
        'vehicle_purchase_source',
        'vehicle_purchase_date',
        'vehicle_buyer_id',
        'vehicle_buyer_name',
        'vehicle_plate',
        'vehicle_entry_date',
        'vehicle_days_in_stock',
        'portal_original',
        'opportunity_source_raw',
        'opportunity_source_normalized',
        'portal_resolved',
        'portal_resolution_source',
        'portal_resolution_lead_id',
        'portal_resolution_debug',
        'reservation',
        'reservation_date',
        'cv_signed',
        'cv_signed_date',
        'raw_payload',
        'is_deleted',
        'salesforce_deleted_at',
        'deletion_detection_source',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'salesforce_last_modified_at' => 'datetime',
        'close_date' => 'date',
        'amount' => 'decimal:2',
        'opo_for_importe_total' => 'decimal:2',
        'owner_is_active' => 'boolean',
        'garantia_total' => 'decimal:2',
        'beneficio_financiacion_comercial' => 'decimal:2',
        'importe_financiado' => 'decimal:2',
        'financial_commission' => 'decimal:2',
        'financial_discount' => 'decimal:2',
        'financing_paid' => 'boolean',
        'financing_paid_date' => 'date',
        'financed_amount_ratio' => 'decimal:6',
        'gestion_de_venta' => 'boolean',
        'management_cost' => 'decimal:2',
        'logistics_cost' => 'decimal:2',
        'transfer_cost' => 'decimal:2',
        'logistics_discount' => 'decimal:2',
        'opo_div_descuento' => 'decimal:2',
        'informe_rentabilidad' => 'decimal:2',
        'rentabilidad_financiera' => 'decimal:6',
        'vehicle_sale_price' => 'decimal:2',
        'financed_vehicle_sale_price' => 'decimal:2',
        'vehicle_only_financed' => 'boolean',
        'contract_vehicle_sale_amount' => 'decimal:2',
        'plan_auto_plus_amount' => 'decimal:2',
        'cae_amount' => 'decimal:2',
        'trade_in_amount' => 'decimal:2',
        'vehicle_purchase_price' => 'decimal:2',
        'vehicle_purchase_date' => 'date',
        'vehicle_entry_date' => 'date',
        'vehicle_days_in_stock' => 'integer',
        'reservation' => 'boolean',
        'reservation_date' => 'date',
        'cv_signed' => 'boolean',
        'cv_signed_date' => 'date',
        'portal_resolution_debug' => 'array',
        'raw_payload' => 'array',
        'is_deleted' => 'boolean',
        'salesforce_deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(self::ACTIVE_SCOPE, function (Builder $builder): void {
            $builder->where(function (Builder $query) use ($builder): void {
                $source = $builder->getModel()->qualifyColumn('deletion_detection_source');

                $query->where($builder->getModel()->qualifyColumn('is_deleted'), false)
                    ->orWhereNull($source)
                    ->orWhere($source, '<>', self::DELETION_SOURCE_QUERY_ALL);
            });
        });
    }

    public function isConfirmedDeleted(): bool
    {
        return $this->is_deleted
            && $this->deletion_detection_source === self::DELETION_SOURCE_QUERY_ALL;
    }
}
