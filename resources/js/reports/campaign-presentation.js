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

export function costPresentation(value, row, key, formatMoney) {
    if (row.campaign_source_type === 'salesforce_campaign_without_spend') {
        return { text: 'No aplica', tooltip: 'Métrica no aplicable: campaña Salesforce sin inversión publicitaria atribuible.' };
    }
    if (row.campaign_source_type === 'salesforce_origin') {
        return { text: 'Sin datos', tooltip: 'Procedencia Salesforce sin inversión publicitaria verificable.' };
    }
    if (row.spend === null || row.spend === undefined || row.spend === '' || !Number.isFinite(Number(row.spend))) {
        return { text: 'Sin datos', tooltip: 'Inversión desconocida: no se puede verificar el coste.' };
    }
    const denominator = row[costDenominators[key]];
    if (denominator === null || denominator === undefined || denominator === '' || !Number.isFinite(Number(denominator))) {
        return { text: 'No calculable', tooltip: 'Denominador de resultados no disponible.' };
    }
    if (Number(denominator) === 0) {
        return { text: 'No calculable', tooltip: 'Divisor cero: no hay resultados para calcular el coste.' };
    }
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
        return { text: 'Sin datos', tooltip: 'Valor del coste no disponible en los datos recibidos.' };
    }
    const text = formatMoney(value);
    return { text, tooltip: text, value: Number(value) };
}

export function costDisplay(value, row, key, formatMoney) {
    return costPresentation(value, row, key, formatMoney).text;
}

// Keep gaps in the original sequence; never join points across missing periods.
export function contiguousChartSegments(coordinates) {
    const segments = [];
    let current = [];
    for (const point of coordinates) {
        if (point.value === null) {
            if (current.length) segments.push(current);
            current = [];
        } else {
            current.push(point);
        }
    }
    if (current.length) segments.push(current);
    return segments;
}
