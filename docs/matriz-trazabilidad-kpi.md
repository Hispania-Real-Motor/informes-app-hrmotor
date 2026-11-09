# Matriz de trazabilidad de KPIs

> Inventario mensual provisional de Interests: `salesforce_interest_monthly_recoveries`
> no es fuente de KPI ni de informes. Solo registra inventario actual por mes,
> con soft deletes observables; no certifica hard deletes, F5 ni cierre histórico.
> La verificación remota mensual guarda solo contadores agregados de discrepancias
> y no habilita ninguna métrica aunque resulte limpia.
> Su evidencia persistente está minimizada a metadatos técnicos y hash canónico;
> no almacena payload Salesforce ni datos personales de la respuesta remota.
> Los fallos del journal se clasifican por etapa con metadatos seguros y
> conservan checkpoint/reanudación; no activan KPI, lectores ni publicación.

### Dependencias de Campañas e Interests durante recuperación

| Sección / evidencia | Condición de disponibilidad | Limitación |
|---|---|---|
| Campañas: summary, detalle y rankings nativos | `CampaignDashboardDatasetService::{economicEvidence,platformAvailability,metricRows}` divide el intervalo civil `Europe/Madrid` en meses cerrados completos y tramos abiertos. Un cierre exige el snapshot vigente no vacío y válido; acredita solo las plataformas que contiene. Un tramo abierto exige por plataforma el run de mayor ID con cobertura completa, `completed` y finalizado; `stats.range_start/range_end_exclusive` presentes son autoritativos y, si uno es inválido/incompleto, bloquean sin fallback a timestamps. En legado sin rango explícito, una única interpretación completa (DATETIME civil Madrid o UTC convertido) debe formar ambos límites de inicio de día; no se elige la más amplia. El fin es exclusivo y la conversión cubre DST, incluidos octubre/noviembre. `availability.platforms.*.evidence` expone `closure_snapshot`, `sync_run` o `mixed`. | El fin de cobertura es exclusivo. Un snapshot ausente/vacío/inválido, un día parcial de mes cerrado, run fallido/incompleto, límites nulos/inválidos o hueco bloquean sin usar filas diarias como sustituto del cierre. `period_end_at=1970-01-01` es una incidencia histórica independiente: no se reinterpreta y solo puede diagnosticarse en lectura antes de una corrección aislada autorizada. Meta y Google se validan por separado; la evidencia económica no certifica Salesforce, atribución ni estado histórico. Leads/conversiones nativos no equivalen a Interests ni conversiones atribuidas. |
| Campañas: Interests, Opportunities, resultados comerciales, auditoría y exportaciones dependientes | Requieren generación certificada de Interests; las auditorías la comprueban explícitamente y la exportación de campañas conserva el camino ordinario. | En recuperación se bloquean los valores dependientes, no se convierten en cero. |
| Interests: informe y auditoría | `InterestGenerationReader` requiere publicación certificada. | El modo parcial de Campañas no publica ni certifica Interests. |

El cambio introduce un contrato de disponibilidad, no audita las fichas KPI.
La comparación de inversión, impresiones, clics, leads de plataforma, CTR y CPC
en el modo parcial y su comportamiento con datos vacíos o filtros se prueba en
`CampaignDashboardTest`; la recuperación sintética cubre snapshot cerrado,
intervalo abierto, fallo y mezcla de evidencia, sin acreditar importes ni
cobertura productiva. No se infieren fórmulas o coberturas externas no
verificadas aquí.

### Interests: recuperación inicial desde estado actual

`InterestInitialCurrentRecoveryService::recover()` construye una candidata
desde una semilla inicial no publicada, reconcilia `Interes__c` con el mapeo
F2 existente y reconstruye F5 para esa candidata. La certificación específica
precede a una publicación separada. Las lecturas actuales F2/F5 no están
acotadas por `SystemModstamp` al watermark: no acreditan fotografía histórica
ni simultaneidad exacta. `InterestGenerationTest` cubre semilla, ausencia de
publicación y autorización explícita con cliente simulado; la cobertura real
y el rendimiento quedan pendientes. Esta nota no audita ni modifica fórmulas,
filtros o universo de los KPIs.

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

#### Plan económico de Contact Center — roster y evidencia (2026-09)

| Contrato comprobado | Evidencia implementada y límites |
| --- | --- |
| KPI / pestaña | Tabla de agentes Contact Center y bloque independiente `Responsable Contact Center`; se activan desde `2026-09`. Meses anteriores conservan el plan legado. |
| Fuente, réplica y fecha pivote | Agentes: `commercial_commission_appointment_evidences` por fecha de captación y `salesforce_opportunities` por fecha de reserva/firma. Responsable y bonus: Opportunities elegibles por `cv_signed_date` con `EligibleDeliveryOpportunityQuery`. `salesforce_users.created_date` determina el umbral. No hay consulta Salesforce durante el render. |
| Universo, exclusiones e identidad | Sólo los User IDs de `contact_center_commission_roster_members`; no existe fallback por nombre, alias, Lead o teléfono. Los tramos no solapados de un mismo User ID se agregan antes de aplicar la fórmula: cada hecho se deduplica por su ID Salesforce y por mes de su evento, y cuenta solo si cae en alguno de sus tramos. La etiqueta visible es la del tramo más reciente; no divide la comisión. Las entregas de compañía se deduplican por `salesforce_id`; Product2 es conciliación informativa. |
| Fórmula, nulos y redondeo | Conversión entera `reservas*100 >= citas*umbral`, con 5 % antes del tercer aniversario mensual de alta y 7 % desde él. Sin User/CreatedDate o sin objetivo positivo: no certificable y total nulo. El incentivo de entregas es incremental 10/15/20 EUR por bandas 1--9/10--20/21+. El bonus de compañía requiere conversión y aplica 0/50/80/150 EUR en los tramos publicados. Vanesa: <90 %=0, 90--<100 %=350, >=100 %=500 + 5 EUR por excedente; sin cobertura de Opportunities u objetivo válido sus importes dependientes y total son `null`. Porcentajes sólo se redondean al presentar. |
| Readiness, cierre, exportación y auditoría | Los agentes exigen cobertura completada del mes para evidencia de citas y Opportunities; una cobertura válida con cero hechos sigue siendo un cero válido. Vanesa exige solo Opportunities y objetivo mensual, por lo que puede ser certificable cuando falte evidencia de citas. Desde septiembre el cierre exige `appointments` + `sales`, congela `contact_center` y los settings efectivos, y solo aprueba candidato persistido con todas las filas exigibles y Responsable certificables y sin total `null`; la reapertura conserva V1 y genera una versión nueva. El XLSX definitivo consume solo ese snapshot; sin definitivo, un live no certificable devuelve HTTP 409. Un snapshot definitivo malformado o no certificable se rechaza con el mismo 409 y no se sustituye por datos vivos. Se exponen detalle por hecho, User ID, CreatedDate, objetivo, períodos y conciliaciones Product2; `USER_NOT_IN_ROSTER` y `EVENT_OUTSIDE_ROSTER_EFFECTIVE_RANGE` mantienen evento, User ID, referencia y fecha. Julio/agosto mantienen contrato legacy. Pruebas: `ContactCenterCommissionDashboardTest`, `CommissionMonthClosureTest`, `CommercialCommissionDashboardTest` y `ContactCenterCommissionPlanCalculatorTest`. |

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
| Configuración | `config/campaign_current_status.php`, `CAMPAIGN_CURRENT_STATUS_ENABLED=false`; catálogo central cada seis horas minuto37. No activa al desplegar. La primera lectura requiere autorización separada, tarea habilitada y reserva operacional; se ejecuta por `operations:run campaigns-sync-current-status` y se contrasta el run por plataforma/cuenta, las filas locales y `fresh`/`stale`/`unavailable` durante <24 h. No reactiva scheduler, workers ni sincronizaciones económicas. |
| PHP / pruebas | `CampaignCurrentStatusService::{sync,decorate,identifier}`, `CampaignDashboardDatasetService::{summary,campaignRows}`; `CampaignCurrentStatusTest`, regresión `test_current_status_sync_changes_only_additive_metadata_outside_economic_cache_and_csv` y JS real del dashboard. |
| Exportación / auditoría | Metadata solo en summary.campaigns/items; CSV no se amplía. Última observación y último válido separados en tabla; auditoría por cuenta existente reutilizada. |
| Estado / referencia | Verificación focal de implementación y tests sintéticos; auditoría integral KPI y acceso real pendientes. Integración limpia sobre main 89282a32b79060fca671adee4bc49741c954308f (Fase A/PR #85 ya incorporados), rama feat/campaign-current-status-ready; solo commits B, sin repetir dependencia 2f3791e. Pruebas MariaDB locales aisladas adicionales; SHA y resultados finales en HANDOFF, sin promover auditoría KPI. |

Formato: `campaigns-dashboard.js` NumberFormat es-ES con agrupación explícita, moneda dos decimales, porcentajes uno y multiplicadores dos. Los ejes muestran miles completos en vez de k/M; null y guiones mantienen el contrato visual de Fase A. JSON, IDs y ordenación numérica intactos. Evidencia: pruebas JS ejecutan formateadores y renderizadores reales, no solo un auxiliar aislado. [Contrato vigente](informe-campanas.md); evidencia de ejecución en docs/ai/HANDOFF.md.

### Corrección focal de fuentes publicitarias para estado actual (2026-10-27)

Este cambio afecta a la adquisición de metadatos operativos y al endpoint de lectura de métricas Google, no a las fichas económicas auditadas. La trazabilidad integral de inversión y demás KPI de Campañas sigue pendiente. En `MetaAdsClient::currentStatusPages()`, `fields` conserva `id,account_id,name,status,effective_status`, pero la petición ya no incluye el parámetro de filtro `effective_status`, rechazado por la cuenta observada. La validación local en `CampaignCurrentStatusService::sync()` mantiene los códigos reconocidos, identidad textual plataforma/cuenta/Campaign ID, paginación completa y escritura por cuenta en `campaign_current_statuses`; no cambia el criterio de frescura de 24 horas ni el filtro histórico. `CampaignCurrentStatusTest` cubre la petición simulada, campañas pausadas, cuentas con/sin `act_`, respuesta vacía y cursor repetido. La lectura productiva comunicada sin el filtro obtuvo 189 campañas en una página (10 ACTIVE, 179 PAUSED), pero la sincronización operativa completa y la disponibilidad final en el informe no se han verificado en esta tarea.

`config/services.php` cambia el valor **predeterminado** de `GOOGLE_ADS_API_VERSION` de `v22` a `v25`; `GoogleAdsClient::currentStatusPages()` usa `googleAds:search` sin métricas ni fecha y `GoogleAdsClient::search()` usa la misma versión para `googleAds:searchStream`, tanto en métricas diarias como en identificadores de atribución. Una configuración explícita prevalece sobre el predeterminado. `CampaignCurrentStatusTest` y `CampaignDashboardTest` prueban URLs, GAQL, respuesta vacía y preservación textual de Campaign ID/costMicros con HTTP simulado; no certifican la compatibilidad de los datos económicos reales con `v25`. No se modifican normalización económica, fecha `segments.date`, esquema ni semántica de `campaign_platform_daily_metrics`, universo, deduplicación, fórmulas, redondeos, API/CSV ni exportación. La sincronización económica programada existente sí puede escribir las métricas del intervalo autorizado después del despliegue; este cambio no autoriza reconstruir períodos cerrados. El error `UNSUPPORTED_VERSION` simulado produce advertencia sanitizada y cero escrituras económicas; el resultado operacional fallido con código de comando cero ya se interpreta mediante `OperationalTaskOutput` y ahora tiene prueba específica. La fuente, tabla y método de cada KPI permanecen como estaban; no se promueven sus fichas a auditadas.

### Corrección focal posterior: fechas GAQL económicas Google Ads v25 (2026-10-27)

La incompatibilidad adicional comunicada de producción fue `UNRECOGNIZED_FIELD`
en `campaign.start_date` y `campaign.end_date` para la consulta económica
completa. `GoogleAdsClient::gaql()` pide ahora
`campaign.start_date_time`/`campaign.end_date_time`; el JSON usa
`campaign.startDateTime`/`campaign.endDateTime`. El sincronizador
`GoogleCampaignSyncService::campaignDate()` valida fecha y hora civil de la
cuenta y conserva solo `YYYY-MM-DD` en las columnas DATE
`campaign_platform_daily_metrics.campaign_start_date`/`campaign_end_date`.
Ausencia de fecha produce `NULL`, no una fecha inferida. `segments.date` sigue
siendo el pivote diario; `metrics.cost_micros`, impresiones, clics,
`all_conversions` y fallback a `conversions` conservan los campos y fórmulas
anteriores. La identidad textual de Campaign ID y la tabla de identificadores
de atribución tampoco cambian. `GoogleAdsClient::search()` rechaza bloques HTTP
200 incompletos antes de sustituir datos; una fecha inválida revierte la
transacción por cuenta. La respuesta vacía válida mantiene el contrato de cero
filas del intervalo autorizado.

Evidencia: `GoogleAdsClient::{gaql,search}`, `GoogleCampaignSyncService::{sync,campaignDate}`
y `CampaignDashboardTest::{test_google_ads_query_usa_campaign_para_incluir_performance_max,test_google_v25_economic_dates_keep_provider_day_and_existing_metric_semantics,test_google_invalid_v25_datetime_rolls_back_existing_metrics,test_google_incomplete_success_response_preserves_previous_investment,test_google_valid_empty_stream_saves_no_metrics}`.
La verificación es sintética con HTTP simulado. No hay cambio de fuente de KPI,
universo, deduplicación, denominador, redondeo, API/CSV ni cierres; las fichas
económicas completas siguen pendientes. La consulta real v25 completa y la
comparación productiva anterior/posterior requieren autorización operacional.

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

### Impacto focal: guarda de cierres y evidencia de actualización automática

Base `493e6052ef499c74e56d563b937365f4b06f29a0`. La fuente y campos
económicos siguen siendo Meta insights diarios y Google Ads
`googleAds:searchStream` (`segments.date`, `metrics.costMicros`, impresiones,
clics y conversiones), persistidos por `MetaCampaignSyncService` y
`GoogleCampaignSyncService` en `campaign_platform_daily_metrics`. No cambian
normalización de IDs, clasificación Venta/Tasación, fecha pivote diaria,
deduplicación comercial, fórmulas, redondeos, nulos de coste ni exportación.
La novedad es el **intervalo que los escritores pueden sustituir**: la última
fila mensual `closed` anterior al fin en `campaign_investment_closures` fija
el primer día escribible. `CampaignOpenInvestmentWindow` coordina el cierre
con Meta, Google y `CampaignAttributionBuilderService` sin reescribir
`campaign_investment_closure_snapshots`. Los filtros y lecturas de históricos
aprobados mantienen su contrato. La evidencia son
`CampaignClosedPeriodSyncTest` (intervalo mixto/cerrado, conservación de
métrica y snapshot, fallo de paginación/fecha) y las pruebas de Campañas y
comandos de la misma PR. La revisión de cierre económico y concurrencia real
MariaDB está cubierta solo por una prueba aislada de dos procesos; la
verificación productiva y la auditoría económica integral siguen pendientes.
No se elevan fichas KPI a `verificado`.

| Contrato no económico o de auditoría | Cambio contrastado | Límite pendiente |
| --- | --- | --- |
| Runs de inversión Meta/Google | `report_sync_runs` registra resultado y estadísticas del intento iniciado (`range_start`, `range_end_exclusive`, `accounts`, `processed`, `saved`, `warnings`); una cuenta fallida no acredita run completo. Un rango totalmente cerrado queda como `skipped` con motivo `closed_period` y `OperationalTaskOutput` lo marca omitido. | No equivale a comparar datos productivos con el proveedor ni demuestra completitud de una respuesta vacía estructuralmente válida. |
| Aviso Salesforce Interests posterior a atribución | `CampaignDashboardDatasetService::warnings()` exige un F2 completado posterior y evidencia persistida en `salesforce_interests.updated_at`, dentro del período o vinculada a una atribución anterior de ese período. Un F2 sin cambios no crea el aviso ni borra un cambio material previo pendiente. La fuente es `salesforce_interests`/`report_sync_runs`, no Lead legacy. | Cambios de Opportunity y del enlace directo tienen otra procedencia; esta comprobación aislada no certifica su frescura. |
| «Datos actualizados» | `CampaignPublicationReadiness::published(start,end,filters)` exige último snapshot persistido que cubra el período seleccionado, huella SHA-256 de filtros normalizados coincidente (sin fechas), atribución vigente que también cubra ese período, run de publicación `completed` del mismo linaje y últimos Meta/Google con cobertura del tramo abierto, F2, Opportunities y snapshot directo. `campaignDatasetMetadata()` toma su cutoff o deja `dataset_cutoff_at` nulo y añade `dataset_freshness_reason`; la caché versiona los runs. `CampaignDashboardTest` comprueba subperíodo y contexto distinto; `CampaignPublicationReadinessTest` comprueba la huella. | Un período totalmente cerrado, un filtro ajeno al snapshot o un rango fuera de su cobertura no acreditan actualidad operativa en esta marca, aunque los datos históricos siguen consultables. La fecha tampoco certifica integridad externa/productiva ni corrección de Google Venta. |
| Google Ads Venta con gasto y cero comerciales | `CampaignDashboardDatasetService::attributionRows()` aplica `applyCampaignContextFilter()` sobre cada `campaign_lead_attributions` antes de agrupar por plataforma/Campaign ID/nombre; `MIN(campaign_type)` de una campaña con Venta y Tasación ya no puede excluir todas sus atribuciones del contexto Venta. La comparativa, detalle y CSV de campañas comparten el payload; el KPI deduplicado conserva su recorrido independiente. `CampaignDashboardTest` cubre campaña Google mixta, cero Google legítimo y Meta sin regresión. Claves de caché `campaign-dashboard-v8` y `campaign-dashboard-kpi-audit-v2` evitan respuestas anteriores durante el TTL. | El esquema CSV, matching, first touch, fórmulas, atribuciones persistidas y cierres no cambian; los recuentos presentados/CSV pueden corregirse en campañas mixtas. Sin consulta productiva autorizada, ni su existencia en octubre ni la causa del cero ni las cifras esperadas están verificadas; véase el procedimiento agregado de solo lectura en `docs/operaciones-produccion.md`. |

La secuencia operacional sigue F2 horario, Meta/Google y Opportunities en
sus horarios de catálogo, atribución y refresco; la guarda de cierres actúa
también si el comando se invoca manualmente. Los estados, resultados y
consultas del tablero no incorporan una fuente externa nueva ni consultan
proveedores durante HTTP. Las fichas KPI de Campañas siguen pendientes de la
auditoría completa de 23 campos, incluida comparación productiva antes y
después del período abierto.

La reconciliación añadida a `OperationalTaskCatalog` comprueba de 09:20 a
23:20 en Madrid una dependencia por pasada y la reintenta con la reserva de su
tarea original; máximo tres runs diarios por tarea con origen `scheduler` y
estado no omitido, incluida la ejecución ordinaria. `CampaignPublicationRetryTest`
y `OperationalTasksTest` prueban selección, límite, ocupación y estado de
omisión. Es política de recuperación/publicación, no una nueva fuente, filtro,
fórmula o universo económico. No garantiza que la cadena completa termine el
mismo día ni acredita publicación de un contexto filtrado diferente.

Linaje nuevo: `report_sync_runs` de métricas Meta/Google
(`campaign_platform_daily_metrics`), F2 (`salesforce_interests`) y Opportunity
(`salesforce_opportunities`) conservan los últimos intentos; el snapshot
directo añade `reference_fingerprint` a sus estadísticas. El run de builder
`campaign_attributions/campaigns` registra `source_ids` y
`range_start/range_end_exclusive`; el run de publicación
`campaign_report_snapshots/campaigns` registra `snapshot_id`,
`attribution_run_id` y `source_ids`. La sincronización F2 y la de Opportunity
sin cambios materiales no invalidan por su hora una atribución previa; cambios
de referencias directas sí lo hacen mediante huella. El comando automático
exige fuentes listas y salta si el linaje material y la cobertura permanecen
válidos. El refresco con `--store` falla sin cobertura publicitaria del tramo
abierto y sin atribución vigente que cubra el rango; la fecha visible exige
además cobertura del período consultado por el snapshot. Estos son contratos
de publicación/auditoría, no cambios de la
fuente API, fecha pivote, fórmula ni universo de los KPI históricos.

## PR #92 — cambio de trazabilidad de Interest por generaciones

Referencia: `edf2d97b6e5624936ed5cfd12fa790889fea3df2` más diff de esta iteración,
pendiente de SHA final. Registro focalizado de fuente/versionado; no es una ficha
KPI completa ni certifica fórmulas no auditadas.

| Contrato o sección | Cadena contrastada | Estado y límites |
| --- | --- | --- |
| Interests: resumen, comparativas, tablas y auditorías KPI/conciliación | `Interes__c.Id`, `SystemModstamp`, `IN_Lead_Origen_Migracion__c` → `SalesforceInterestSyncService::mapRecord/persistChunk` → versiones/pertenencias → `InterestGenerationReader` → `SalesforceInterestDashboardDatasetService`; `Task/Event.WhatId` → F5 de la misma candidata | Cambia fuente local/versionado, no reglas ROT-1. `generation_id` fija AJAX y CSV. Tests de generaciones, dashboard y JS; fuente externa real no consultada. |
| Métrica ejecutiva `leads` y Monthly Commercial | `executiveLeadTotal` y precheck `RefreshMonthlyCommercialReportCommand` leen F2 de generación publicada, cuyo F5 ya está certificado | Conteo activo por fecha funcional preservado; no consulta detalle de actividades para contar. Test de zona mensual adaptado. |
| Reservas/Ventas: atribución y Rendimiento comercial/auditoría | `OpportunityInterestAttributionService`, `CommercialPerformanceDatasetService::aggregateInterests/interestSourceContext`, `CommercialPerformanceAuditService::appendInterests` → lector publicado | Mismos campos de identidad, fuente y relación inversa; caché Rendimiento incluye generación. Otras fuentes no son parte de la publicación F2/F5. |
| Campañas: atribución, detalle, opciones y readiness | `CampaignAttributionBuilderService::candidateInterests/latestInterestRun`; dashboard y `CampaignPublicationReadiness` → lector y runs publicados | Fórmulas y universos de atribución intactos; cambia trazabilidad local. Auditoría integral de campos API, materializaciones, exportación y cargas compuestas pendiente. |
| Llamadas: procedencia de Interest relacionado | `SalesforceCallSyncService::relatedInterestMatches` y reproceso → `salesforce_id/source/is_deleted` de generación publicada | No cambia fórmula ni clasificación; regresión de consumidores pendiente del cierre de pruebas. |
| SEO: proyección orgánica y estado Salesforce | `SalesforceOrganicInterestProjectionService::aggregate/latestStableInterestRun` → generación publicada | Conserva medio Orgánico, activos y fecha funcional. No certifica GA4/Search Console. |
| Recuperación inicial F2/F5 (operación, no KPI) | Semilla `salesforce_interest_generations` en `validating` → `InterestInitialCurrentRecoveryService` → journal `salesforce_interest_initial_recoveries` (cursores `Id` de extracción y verificación, lease renovado/fenced, cobertura `getDeleted`, contadores/progreso F5) → candidata/versiones/memberships y run F2; F5 aislado `Task/Event.WhatId` → `salesforce_interest_activity_runs`. El cutoff solicitado (`UTC now - 2 min`, al minuto) exige `latestDateCovered` igual o posterior antes de crear candidata: no se degrada al límite devuelto. El `completed` del run y `finishF5` se confirman juntos bajo guardia fenced; `associated_unverified` obliga a completar verificación/certificación al reanudar. | No cambia fuente, universo, fecha funcional, fórmula, filtros, redondeos, nulos, exportación ni presentación de los seis informes. No publica ni mueve el puntero. Un journal heredado con watermark menor al cutoff solicitado no se reanuda ni certifica; una recuperación solo certificada sigue requiriendo evidencia revisada y `publish-initial` explícito. No hay datos Salesforce productivos contrastados ni benchmark acreditado. |
| Inventario y materialización mensual F2/F5 aislados (operación, no KPI) | F2 mensual `Interes__c` de estado actual → `salesforce_interest_monthly_recoveries/_rows`; F2M toma exclusivamente esas IDs y llama por bloques a `SalesforceInterestSyncService::mapRecord()` → `salesforce_interest_monthly_materializations/_materialized_rows`, con relaciones Lead/Account/persona, fechas, owner, estado/tipo, atribución, vehículos y hash compatible. F5 `selective_whatid_v1` toma bloques de hasta 250 IDs F2 → `MonthlyInterestActivityRecoveryService` → `salesforce_interest_monthly_activity_recoveries/_rows`, con checkpoint de Interest y cursor de Activity confirmados bajo el mismo lease. `MonthlyInterestReconciliationService` compara sólo diarios de la misma F2 y expone agregados, hashes y evidencia de ausencia local acotada. | F2M y F5 `completed` solo terminan evidencia operativa. F2M detecta ausencia, cambio, inesperado, duplicado, soft delete y fecha funcional distinta sin ampliar pertenencia. La reconciliación es de solo lectura: no reconstruye discrepancias F5 que sólo se contaron ni atribuye hashes F2 a campos históricos. No conserva `raw_payload`, no escribe generaciones/publicación, ni cambia lectores, fuentes, KPI, universo, filtros, fórmulas, auditorías, CSV o presentación. Sus campos quedan disponibles únicamente para un futuro lector mensual autorizado: Interests/Dirección y KPI comercial requerirán aún generación publicada; Reservas/Ventas y Rendimiento, Campañas, Llamadas y SEO mantienen dependencias de F2/F5 publicado, Opportunities, Calls y proyecciones. Sin fuente as-of, `getDeleted` ni evidencia independiente, certificación histórica es `unavailable`. |
| Lector administrativo provisional de octubre de 2026 (presentación, no KPI) | F2, F2M y F5 mensuales con ID interno fijo `1` y ligadas a la misma F2 → validación local explícita `reports:validate-october-2026-provisional --confirm` → evidencia/snapshot versionado en `salesforce_interest_october_provisional_validations` → `October2026ProvisionalInterestReader` → `/informes/leads?period=october_2026`. La validación comprueba estados, período, vínculos y manifiestos F2M `Id|content_hash|is_deleted` y F5 `Id:is_deleted`; sobre F2M activo/coincidente normaliza `origin_delegation` y agrega delegación/grupo/zona. También agrega estado, tipo (`LeadRecordTypeNormalizer`), fuente, medio y canal: cada dimensión suma `eligible_active_matching`; vacío es `Sin clasificar`. `raw_literal_general` es subcontador no reconocido, no un alias. No infiere owner, comercial, cuenta ni Lead. Las rutas Laravel autorizadas invalidan por lote e incrementan la revisión dentro de su transacción al mutar journals F2/F2M/F5. No hay triggers `FOR EACH ROW`; SQL directo queda fuera de esta garantía y exige revocación operativa y nueva validación. | Sólo administrador y flag apagado por defecto; flag apagado 404, no administrador 403 y 503 sin snapshot validado o revisión estable; `no-store, private`. HTTP sólo lee evidencia y revisión, no journals ni agregados en vivo ni dataset oficial. Los controles visibles no son filtros: muestran una sola distribución precalculada y buscan únicamente entre sus etiquetas, sin recalcular ni combinar dimensiones. Las distribuciones son agregados F2M provisionales, no KPI comercial certificado: comercial/delegación comercial requieren `SalesforceUser` histórico; actividad requiere estado de relación F5 y certificación histórica. Una observación SOQL actual separada no reemplaza F2M ni prueba historia. No expone valor bruto, IDs ni payloads. No certifica historia, no publica ni modifica generación/lectores oficiales. Dirección/KPI/comparativas, Reservas/Ventas/Rendimiento, Campañas, Llamadas, SEO, Comisiones y Stock no consumen esta fuente. |

Los normalizadores funcionales, configuración de clasificaciones, umbrales,
redondeos, nulos de KPIs y fechas pivote no cambian en este diff. No se han
reauditado todas sus cadenas de 23 campos. Origen vacío se normaliza a null en
persistencia; unicidad y cuarentena son por generación. Las pruebas sintéticas
no demuestran que los 270.000 cambios procedieran de una actualización masiva
legítima. Detalle operativo, esquema, evidencias iniciales, borrados y limitaciones
en [generaciones F2/F5](salesforce-interest-generations.md).
