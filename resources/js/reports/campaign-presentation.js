// Presentation only: never filter the shared dataset or export payload.
export const activityMetrics = [
    'spend', 'impressions', 'clicks', 'platform_leads', 'platform_conversions',
    'leads_salesforce', 'interests_salesforce', 'opportunities', 'reservations',
    'live_reservations', 'fallen_reservations', 'sales', 'purchases',
    'appraisals_generated', 'sale_amount', 'appraisal_amount',
];

export function hasDetailActivity(row) {
    return activityMetrics.some((key) => {
        const value = row[key];
        return value !== null && value !== undefined && value !== ''
            && Number.isFinite(Number(value)) && Number(value) !== 0;
    });
}

export const costDenominators = {
    cpc: 'clicks', cost_per_lead: 'leads_salesforce',
    cost_per_opportunity: 'opportunities', cost_per_reservation: 'reservations',
    cost_per_sale: 'sales', cost_per_purchase: 'purchases',
    cost_per_appraisal: 'appraisals_generated', cost_per_result: 'result_count',
};

export function costDisplay(value, row, key, formatMoney) {
    if (row.campaign_source_type === 'salesforce_campaign_without_spend') {
        return 'No aplica';
    }
    if (value !== null && value !== undefined) {
        return formatMoney(value);
    }
    const denominator = row[costDenominators[key]];
    if (row.spend === null || row.spend === undefined) {
        return 'Sin datos';
    }
    return denominator === null || denominator === undefined || Number(denominator) === 0
        ? 'No calculable' : 'Sin datos';
}
