# Matriz de trazabilidad de KPIs

Estado: **estructura pendiente de auditoría inicial**. Esta entrega no certifica
ningún KPI ni traslada reglas históricas sin comprobarlas con código, pruebas y
fuentes. Las fichas se incorporarán en una tarea posterior, una por KPI y
siempre asociadas a una pestaña o sección real.

## Contrato de ficha KPI

Cada ficha verificada debe contener estos campos, con referencias concretas y
valor `No aplica` justificado cuando corresponda:

1. Informe.
2. Pestaña o sección real.
3. Identificador estable del KPI.
4. Nombre visible del KPI.
5. Descripción y significado.
6. Fuente externa.
7. Objeto y campo API de Salesforce, si aplica.
8. Tabla y columna local, si aplica.
9. Sincronizador responsable.
10. Fecha pivote y zona horaria.
11. Universo de registros.
12. Filtros e inclusiones.
13. Exclusiones.
14. Deduplicación y clave de identidad.
15. Fórmula exacta explicada, sin copiar el archivo PHP.
16. Redondeos, umbrales y coeficientes.
17. Tratamiento de nulos y división por cero.
18. Configuración y vigencia de parámetros.
19. Archivo y método PHP responsables.
20. Tests que validan el cálculo.
21. Exportación o auditoría disponible.
22. Estado de verificación: pendiente, verificado o discrepancia.
23. Commit de referencia comprobado.

Un cambio funcional que altere KPI, fuente, universo, filtro, fórmula o
trazabilidad debe actualizar el documento del informe y esta matriz en el mismo
diff. Si todavía no existe ficha verificada, se registra la evidencia y el
impacto como pendiente; no se crea una ficha de 23 campos por conjetura. Si el
cambio documentado no afecta a ningún KPI, la descripción de la PR declara
`Docs-Matrix-Impact: unchanged` con razón técnica y ruta de evidencia revisable.
La matriz indexa la evidencia y no crea una segunda especificación
contradictoria. Una discrepancia conserva referencias a las dos evidencias y
queda pendiente de decisión humana.

## Índice de informes y secciones pendientes

### Intereses (ruta histórica Leads)

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [reglas vigentes de Intereses](reglas-negocio-leads.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

### Reservas / Ventas

Auditoría inicial pendiente. Pestañas y KPIs concretos por verificar contra la
interfaz actual. Referencias: [informe de Reservas / Ventas](informe-reservas-ventas.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

### Llamadas

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [informe de Llamadas](informe-llamadas.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

### Campañas

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [informe de Campañas](informe-campanas.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

#### Registro focalizado: Detalle y Campañas a revisar

Base contrastada: 01d3aa8ad428ad413f184741d9a4f2314696f1fe más diff Fase A.

| Contrato afectado | Evidencia implementada | Impacto y estado de auditoría |
| --- | --- | --- |
| Universo del detalle | advertisingEligibility()/advertisingDetailRows(), resources/js/reports/campaign-presentation.js; renderCampaignTables(), campaigns-dashboard.js | Meta/Google, sin procedencias Salesforce sin gasto atribuible y spend finito > 0; solo presentación. No cambia API/CSV/atribución. Auditada la exclusión visual sintética; trazabilidad completa de adquisición pendiente. |
| Coste por compra | CampaignDashboardDatasetService::withRatios()/divide(); cost_per_purchase = spend / purchases | Fórmula existente intacta; denominador cero o null produce null; divide redondea a cuatro decimales. Solo selección y presentación monetaria cambian. Auditoría integral de compras/origen/fecha/deduplicación pendiente. |
| Resultados y coste por resultado | resultCountForRow()/costPerResultForRow(); columnDefinitions y columnAllowedInContext() JS | Se retiran solo del detalle/selector; permanecen fórmulas funcionales y datos de API/CSV y otros bloques. No equivale a compras. |
| Benchmark y top 8 de Campañas a revisar | CampaignDashboardDatasetService::reviewCampaigns() | Elegibilidad publicitaria antes de medias por tipo; promedio de costes por resultado positivos no nulos, cuatro decimales; Coste alto > 2 × media. Mismos motivos y prioridad; universo cambia solo aquí. |
| Estado publicitario | historicalStatusPresentation(); MetaCampaignSyncService, GoogleCampaignSyncService y clientes | Sigue histórico, no remoto vigente. Fase B pendiente de aprobación, sin nueva fuente implementada. |

##### Fuentes contrastadas y límites de esta revisión

MetaAdsClient consulta insights y campañas; MetaCampaignSyncService persiste
metric_date, spend, campaign_status y campaign_effective_status en métricas
diarias. GoogleAdsClient consulta campaign.status con segmentos/métricas;
GoogleCampaignSyncService persiste metric_date y campaign_status. El dataset
usa el período solicitado y snapshots aprobados cuando proceden; no se cambian
fechas pivote, zonas, identidades, reglas de atribución, first touch ni deduplicación.
La fuente CRM canónica documentada es Interest (aliases técnicos leads_salesforce
no significan Lead); no se ha hecho una auditoría nueva de los campos API CRM
ni se ha consultado Salesforce. La clasificación funcional no se deduce aquí del
nombre. Configuración y sincronizaciones permanecen intactas.

El costo visible conserva el valor backend; Intl.NumberFormat es-ES/EUR muestra
dos decimales y los inválidos del detalle usan — con motivo. No se convierte null
en cero. El conjunto recibido no se muta. exportRows() y los CSV conservan filas
ocultas del detalle. Pruebas: CampaignDashboardTest (integridad cerrada y
exportación, denominadores aprobados, benchmarks/top 8 publicitarios) y
campaign-presentation.test.mjs (elegibilidad, formato y migración de columnas).

Pendiente: fichas completas por KPI (fuente/campo API, tabla/columna, fecha
pivote, universo/deduplicación y configuración de extremo a extremo) y auditoría
de otros informes, fuera del alcance. Este registro no certifica esos contratos.
La propuesta de estado actual se detalla en
[CAMPAIGN_CURRENT_STATUS_PROPOSAL](ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md).
Reglas funcionales vigentes: [Informe de Campañas](informe-campanas.md).


### Comisiones

Auditoría inicial pendiente en cada una de las seis pestañas reales:

| Pestaña | Estado | Referencia funcional |
| --- | --- | --- |
| Comerciales | Pendiente | [Comisiones](informe-comisiones.md) y [cálculo comercial](Calculo_comisiones_comerciales.txt) |
| Delegaciones | Pendiente | [Comisiones](informe-comisiones.md) |
| Call Center | Pendiente | [Comisiones](informe-comisiones.md) |
| Contact Center | Pendiente | [Comisiones](informe-comisiones.md) |
| Área Manager | Pendiente | [Comisiones](informe-comisiones.md) |
| Financieros | Pendiente | [Comisiones](informe-comisiones.md) y [guía financiera](informe-comisiones-financieras.md) |

### Stock

Auditoría inicial pendiente. Secciones y KPIs concretos por verificar contra
la interfaz actual. Referencias: [informe de Stock](informe-stock.md)
y [documentación general](Documentacion_general_informes_y_contraste_salesforce.md).

## Fichas verificadas

Ninguna todavía. La auditoría inicial de los seis informes es una tarea
posterior independiente. No se deben promover fichas a `verificado` sin
comprobar implementación, fuentes y tests.

## Decisiones y discrepancias

Consultar [decisiones de negocio pendientes](decisiones-negocio-pendientes.md).
Las discrepancias nuevas se registrarán aquí con evidencia y responsable de
resolución, sin alterar retrospectivamente reglas históricas.

## Impacto focal Fase B — metadatos y presentación de Campañas

No se audita aquí una nueva fórmula económica ni se promueven fichas KPI a verificadas. Cambia la fuente de la **columna no KPI Estado actual** y el formato visual de números; inversión, intereses, oportunidades, reservas, ventas, compras, importes, costes, ROAS y ROI conservan universo, fecha pivote, denominadores y valores. Las reglas de elegibilidad de Fase A siguen acotadas a Detalle/Campañas a revisar.

| Campo del contrato | Evidencia focal comprobada |
| --- | --- |
| Informe / sección | Campañas / Detalle de campañas, columna Estado actual; formatos compartidos por tarjetas, rankings, gráficos, revisión y auxiliares del mismo informe. |
| Identificador / nombre / significado | `current_status` / Estado actual / observación operativa local verificable, no KPI ni estado histórico. |
| Fuente externa / campo Salesforce | Meta Campaign `id,account_id,status,effective_status`; Google `customer.id,campaign.id,campaign.status`. Salesforce: no aplica, no se consulta para estado publicitario. |
| Persistencia / sincronizador | `campaign_current_statuses` raw/configured/last_valid/last_observed_at/last_verified_at; `CampaignCurrentStatusService::sync`, clientes `currentStatusPages`. Auditoría `report_sync_runs` dataset `campaign_current_status`, source plataforma:cuenta. |
| Fecha pivote / zona | Tiempo real de consulta satisfactoria en DATETIME civil UTC; las cuatro fechas nuevas usan DATETIME, sin conversión por zona de sesión MariaDB; tooltip Europe/Madrid. No usa fecha de inversión ni `functional_created_at` de Interest. |
| Universo / filtros / exclusiones | Cuentas configuradas Meta/Google, lectura sin período ni métricas. Códigos explícitos; identificadores ausentes/malformados y Salesforce neutrales. Filtros históricos intactos. Identidad no inferida por nombre ni intereses. |
| Deduplicación / identidad | Única plataforma + cuenta canónica + Campaign ID textual; duplicado de campaña en inventario invalida cuenta. No modifica deduplicación económica. |
| Fórmula / umbral / redondeo | No aplica fórmula económica. Fresco si edad UTC desde último válido >=0 y <86400 s, código reconocido y referencias no nulas de fila/último run de cuenta coincidentes, con último run completed. Ausencia de auditoría o run fallido: stale. A 24h: stale. Sin redondeo de edad. |
| Nulos | Desconocido/nunca verificado/fecha futura: unavailable; antiguo o intento posterior: stale. No fallback histórico ni conversión a cero. |
| Configuración | `config/campaign_current_status.php`, `CAMPAIGN_CURRENT_STATUS_ENABLED=false`; catálogo central cada seis horas minuto37. No activa al desplegar. |
| PHP / pruebas | `CampaignCurrentStatusService::{sync,decorate,identifier}`, `CampaignDashboardDatasetService::{summary,campaignRows}`; `CampaignCurrentStatusTest`, regresión `test_current_status_sync_changes_only_additive_metadata_outside_economic_cache_and_csv` y JS real del dashboard. |
| Exportación / auditoría | Metadata solo en summary.campaigns/items; CSV no se amplía. Última observación y último válido separados en tabla; auditoría por cuenta existente reutilizada. |
| Estado / referencia | Verificación focal de implementación y tests sintéticos; auditoría integral KPI y acceso real pendientes. Integración limpia sobre main 89282a32b79060fca671adee4bc49741c954308f (Fase A/PR #85 ya incorporados), rama feat/campaign-current-status-ready; solo commits B, sin repetir dependencia 2f3791e. Pruebas MariaDB locales aisladas adicionales; SHA y resultados finales en HANDOFF, sin promover auditoría KPI. |

Formato: `campaigns-dashboard.js` NumberFormat es-ES con agrupación explícita, moneda dos decimales, porcentajes uno y multiplicadores dos. Los ejes muestran miles completos en vez de k/M; null y guiones mantienen el contrato visual de Fase A. JSON, IDs y ordenación numérica intactos. Evidencia: pruebas JS ejecutan formateadores y renderizadores reales, no solo un auxiliar aislado. [Contrato vigente](informe-campanas.md); evidencia de ejecución en docs/ai/HANDOFF.md.

### Corrección focal de fuentes publicitarias para estado actual (2026-10-27)

Este cambio afecta a la adquisición de metadatos operativos y al endpoint de lectura de métricas Google, no a las fichas económicas auditadas. La trazabilidad integral de inversión y demás KPI de Campañas sigue pendiente. En `MetaAdsClient::currentStatusPages()`, `fields` conserva `id,account_id,name,status,effective_status`, pero la petición ya no incluye el parámetro de filtro `effective_status`, rechazado por la cuenta observada. La validación local en `CampaignCurrentStatusService::sync()` mantiene los códigos reconocidos, identidad textual plataforma/cuenta/Campaign ID, paginación completa y escritura por cuenta en `campaign_current_statuses`; no cambia el criterio de frescura de 24 horas ni el filtro histórico. `CampaignCurrentStatusTest` cubre la petición simulada, campañas pausadas, cuentas con/sin `act_`, respuesta vacía y cursor repetido. La lectura productiva comunicada sin el filtro obtuvo 189 campañas en una página (10 ACTIVE, 179 PAUSED), pero la sincronización operativa completa y la disponibilidad final en el informe no se han verificado en esta tarea.

`config/services.php` cambia el valor **predeterminado** de `GOOGLE_ADS_API_VERSION` de `v22` a `v25`; `GoogleAdsClient::currentStatusPages()` usa `googleAds:search` sin métricas ni fecha y `GoogleAdsClient::search()` usa la misma versión para `googleAds:searchStream`, tanto en métricas diarias como en identificadores de atribución. Una configuración explícita prevalece sobre el predeterminado. `CampaignCurrentStatusTest` y `CampaignDashboardTest` prueban URLs, GAQL, respuesta vacía y preservación textual de Campaign ID/costMicros con HTTP simulado; no certifican la compatibilidad de los datos económicos reales con `v25`. No se modifican normalización económica, fecha `segments.date`, esquema ni semántica de `campaign_platform_daily_metrics`, universo, deduplicación, fórmulas, redondeos, API/CSV ni exportación. La sincronización económica programada existente sí puede escribir las métricas del intervalo autorizado después del despliegue; este cambio no autoriza reconstruir períodos cerrados. El error `UNSUPPORTED_VERSION` simulado produce advertencia sanitizada y cero escrituras económicas; el resultado operacional fallido con código de comando cero ya se interpreta mediante `OperationalTaskOutput` y ahora tiene prueba específica. La fuente, tabla y método de cada KPI permanecen como estaban; no se promueven sus fichas a auditadas.

### Protección focal posterior a 46f283f — identidad de Estado actual

Columna no KPI del Detalle de campañas: `CampaignDashboardDatasetService::mergeRows()` registra cuentas normalizadas de contribuciones métricas por la misma clave económica; `rowKey()` no cambia. Varias cuentas (incluida desconocida junto con conocida) producen `current_status.identity_ambiguous:true`. `CampaignCurrentStatusService::decorate()` omite la consulta de esa identidad y devuelve unavailable/reason `ambiguous_account_identity`, sin cuenta, estado bruto o fechas operativas. Tooltip neutral en `currentStatusPresentation()`. Las filas inequívocas mantienen su contrato; Salesforce no acredita estado publicitario.

No cambian fuentes, fechas pivote, universos, deduplicación, atribución, denominadores, importes, redondeos ni fórmulas de KPIs. Payload v6 evita reutilizar filas v5 sin marca; `exportRows()` excluye metadata y preserva CSV. Evidencia focal: `CampaignDashboardTest::test_current_status_is_neutral_for_merged_accounts_without_changing_economics`, `test_same_campaign_id_with_different_names_keeps_independent_account_identity`, `CampaignCurrentStatusTest::test_ambiguous_identity_does_not_query_an_arbitrary_account` y prueba JS de tooltip ambiguo. No se promueve ninguna ficha a auditoría integral; datos productivos no consultados.

### Impacto focal Fase C — universo de entidades de campaña

El registro de Fase A anterior describe su contrato en aquel momento. En el
contrato nuevo, `CampaignDashboardDatasetService::campaignEntityRows()` restringe
**entidades campaña** a Meta/Google y descarta procedencias Salesforce. Afecta
`summary.campaigns`, `data/campaigns`, exportación de campañas, rankings y
universos de entidades; `campaign_universes.salesforce_only` queda vacío por
compatibilidad. El snapshot nuevo de `reports:refresh-campaigns --store`
filtra su arreglo `campaigns`, sin modificar snapshots anteriores. Las opciones
de ID/nombre salen de `campaign_platform_daily_metrics` de Meta/Google;
`CampaignDashboardController::classify()` impide nuevas clasificaciones
operativas Salesforce. Versiones de caché `campaign-dashboard-v7` y
`campaign-dashboard-filter-options-v3` separan el catálogo anterior.

Esto **sí cambia el universo de filas de entidades, rankings y CSV de campañas**;
no debe describirse como un ajuste meramente visual ni como un CSV idéntico.
Los KPI agregados y auditorías de atribución siguen tomando los registros
comerciales legítimos de `campaign_attributions` y
`campaign_lead_attributions`, incluidos `platform=salesforce` y los aliases
históricos `leads_salesforce` que significan Interest. `source_reconciliation`
permanece en JSON. No cambia fuente CRM `Interes__c`, sincronizador F2, fecha
funcional UTC, ventana, first touch, deduplicación, fórmulas, nulos,
redondeos, cierres ni importes por esta regla de elegibilidad. La identidad de
campaña real continúa siendo plataforma/cuenta/ID, con agregación económica
`rowKey()` sin cambios. Evidencia sintética:
`CampaignDashboardTest::test_salesforce_origin_remains_attributed_but_is_not_a_campaign_entity`
y `CampaignCommandsTest::test_refresh_snapshot_keeps_salesforce_attribution_without_storing_it_as_campaign`.
No hay contraste productivo ni auditoría integral de esos KPI; las fichas
pendientes conservan su estado. [Contrato funcional](informe-campanas.md);
propuesta de saneamiento pendiente: `docs/ai/CAMPAIGN_SALESFORCE_CLEANUP_PROPOSAL.md`.

La etiqueta del filtro pasa a **Estado**, pero los valores `active`/`inactive`,
el universo económico y el criterio histórico son los mismos. La columna
independiente `current_status` no cambia ni gana cobertura real por esta
iteración. Retirar el subtítulo local de cada tarjeta y ajustar el botón de
auditoría tampoco modifican fuentes ni cálculos. Esas afirmaciones se apoyan
en la vista, el renderizador y los tests PHP/JS de Fase C, no en una consulta
a cuentas publicitarias.
