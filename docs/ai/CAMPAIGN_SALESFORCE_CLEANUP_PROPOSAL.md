# Propuesta pendiente: saneamiento de identidades Salesforce en Campañas

Estado: **inventario y diseño de solo lectura; sin autorización de borrado**.
Fase C restringe entidades de campaña expuestas a Meta/Google, pero conserva la
evidencia comercial. No existe una tabla independiente de catálogo que pueda
vaciarse. Ninguna cifra o registro productivo se ha inspeccionado en esta tarea.

## Inventario contrastado con esquema y código

| Persistencia | Significado | Decisión vigente |
| --- | --- | --- |
| `campaign_platform_daily_metrics` | Métricas de cuentas Meta/Google y origen de opciones de campaña publicitaria | No modificar. |
| `campaign_attributions` | Relación Interest↔campaña/procedencia y resultados comerciales; `CampaignAttributionBuilderService::salesforceOnlyCampaign()` puede persistir `platform=salesforce` y método `salesforce_only` | Conservar íntegra; borrar estas filas alteraría atribución y KPIs. |
| `campaign_lead_attributions` | Proyección comercial de Interest con oportunidades y resultados; puede conservar `platform=salesforce` | Conservar íntegra. |
| `campaign_report_snapshots.campaigns` | JSON de entidades del snapshot del informe | Los snapshots **nuevos** filtran entidades; preservar antiguos, resumen, rankings y evidencia de auditoría. |
| `campaign_operational_classifications` | Clasificaciones manuales por plataforma/cuenta/ID, con motivo, actor y fecha | El endpoint nuevo solo acepta Meta/Google; las filas Salesforce anteriores requieren inventario y análisis de referencias antes de proponer eliminación. |
| `campaign_current_statuses` | Estado operativo independiente de Meta/Google | No usar para representar procedencias Salesforce ni limpiar por inferencia. |

El builder sigue generando atribución `salesforce_only` para intereses sin
campaña publicitaria inequívoca: impedir esa persistencia perdería información
económica. `CampaignDashboardDatasetService::campaignEntityRows()` evita que
la atribución se promocione a entidad campaña en API/CSV/rankings, y
`RefreshCampaignsReportCommand` aplica la misma regla a snapshots nuevos. El
resumen agregado y `source_reconciliation` siguen incluyendo intereses de esa
procedencia. No son registros artificiales eliminables por tener la cadena
`salesforce`.

## Dry-run propuesto antes de decidir un cambio de datos

Un operador con acceso de **solo lectura** debe registrar únicamente recuentos
por tabla y período, sin IDs personales ni payloads. Primero comprobar que las
tablas existen y el tamaño aproximado. Después contar por separado
`platform='salesforce'` en ambas tablas de atribución y en clasificaciones;
contar snapshots antiguos cuyo arreglo JSON `campaigns` contenga esa
plataforma, sin leerlos ni reescribirlos; y comparar los totales de intereses,
oportunidades, resultados y cierres con el dashboard y sus auditorías. Muestrear
relaciones y dependencias bajo permisos de auditoría, nunca mediante un borrado
de prueba. Guardar fecha, versión de código, período, filtros, consultas
utilizadas y recuentos agregados como evidencia de la simulación. Repetir el
dry-run debe devolver los mismos candidatos si los datos no cambiaron.

No se entrega `--apply` porque **no hay hoy un conjunto de filas cuya
eliminación física esté demostrada como económicamente inocua**. Si el
inventario identificara clasificaciones obsoletas separables, diseñar otra
propuesta con clave exacta, precondiciones, autorización, transacción por lotes,
idempotencia, conteos antes/después y registro agregado sin datos sensibles.
Prohibir cascadas, rechazar identidades ambiguas y detenerse ante cualquier
referencia desde atribuciones, cierres, snapshots o auditorías. Un borrado de
atribuciones comerciales o la reescritura de snapshots requiere decisión de
negocio y control histórico aparte; esta propuesta no lo autoriza.

## Verificación de la fase segura

Pruebas sintéticas verifican que una atribución Salesforce-only permanece en
`campaign_attributions` y `campaign_lead_attributions` y suma al KPI, mientras
`summary.campaigns`, `data/campaigns`, rankings y CSV de **entidades** no la
exponen. Reconstruir la atribución no debe reinsertarla en el catálogo visible.
Un snapshot nuevo conserva el KPI de interés y guarda `campaigns=[]` si no hay
campaña publicitaria. Los snapshots históricos no se modifican. Estos tests no
certifican recuentos ni dependencias productivas.
