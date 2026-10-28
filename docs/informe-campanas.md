# Informe de Campañas

Actualizado: 2026-10-27. URL: `/informes/campanas`.

## Contrato funcional ROT-4

La entidad CRM de adquisición es `Interes__c`, replicada localmente en
`salesforce_interests`. El informe solo incluye Interests activos del período
solicitado; usa `functional_created_at` persistido en UTC y convierte los
límites de negocio `Europe/Madrid` a UTC antes de consultar.

Google Ads y Meta Ads siguen aportando inversión, impresiones, clics y métricas
diarias. Opportunity continúa aportando reservas, ventas, compras e importes.
No se consulta Salesforce durante una petición HTTP ni durante el builder.

### Fechas de campaña en la sincronización económica Google Ads v25

`GoogleAdsClient::searchDailyMetrics()` consulta `campaign.start_date_time` y
`campaign.end_date_time` junto a `segments.date` y las mismas métricas de
`googleAds:searchStream`. Google Ads devuelve esas fechas y horas en la zona
horaria de la cuenta cliente; el sincronizador valida el formato
`YYYY-MM-DD HH:MM:SS` y el día/hora real, y guarda solo la fecha civil en los
campos DATE contractuales `campaign_platform_daily_metrics.campaign_start_date`
y `campaign_end_date`. No convierte la zona horaria ni modifica la fecha diaria
`segments.date`. Un valor ausente o vacío queda `NULL`; un valor malformado
aborta el reemplazo transaccional de métricas de esa cuenta e intervalo.

La respuesta `searchStream` debe contener bloques estructuralmente válidos;
una respuesta HTTP 200 incompleta se rechaza con error genérico y conserva las
métricas anteriores. Una respuesta válida sin resultados sigue representando
cero filas para el intervalo consultado. `costMicros`, impresiones, clics y
conversiones mantienen su procesamiento anterior; tampoco cambian las
atribuciones, fórmulas, API, CSV, cierres ni snapshots. Las pruebas usan HTTP
simulado: la recuperación efectiva de la sincronización económica en cuentas
reales y la integridad de un intervalo productivo quedan pendientes de una
verificación operacional autorizada. No se autoriza por ello reprocesar meses
cerrados. La [referencia oficial de campos de Campaign v25](https://developers.google.com/google-ads/api/fields/v25/campaign)
documenta los campos y el formato horario del proveedor.

Los campos de adquisición proceden exclusivamente del Interest:

- `utm_campaign`, `utm_id`, `utm_source`, `utm_medium`, `utm_content` y
  `utm_term`;
- `source`, `original_source`, `medium` y `channel`;
- `status`, `type`, `origin_delegation` y owner actual;
- `sale_vehicle_salesforce_id` para Venta y
  `appraisal_vehicle_salesforce_id` para Tasación.

No existe fallback a Lead, Account o Contact para completar dimensiones del
Interest. Tampoco existe matching por nombre, email, teléfono o cualquier otra
PII.

## Persistencia y compatibilidad

Las tablas `campaign_attributions` y `campaign_lead_attributions` mantienen sus
nombres históricos, pero las nuevas filas ROT-4 usan columnas Interest
explícitas: identidad, fecha funcional, status, tipo, procedencia, owner,
lifecycle, run/cutoff F2 y estado de relación Opportunity. Las columnas Lead
legacy permanecen `NULL`; nunca almacenan un Interest ID disfrazado de Lead.

`leads_salesforce`, `lead_type`, `lead_status` y otras claves técnicas heredadas
se conservan temporalmente como aliases de compatibilidad. Su semántica ROT-4
es Interest. Las superficies visibles y los CSV muestran Interés/Intereses.

`campaign_salesforce_leads`, `CampaignLeadSyncService` y el comando
`salesforce:sync-campaign-leads` se conservan únicamente como infraestructura
legacy/de rollback. Ya no participan en el pipeline funcional ni están
programados.

## Matching de campañas

Se preservan los métodos auditables:

- `ad_id_match`;
- `adset_or_adgroup_id_match`;
- `campaign_id_match`;
- `campaign_name_exact_match`;
- `campaign_name_flexible_match`;
- `salesforce_only`;
- exclusión y ambigüedad.

La traza registra el campo Interest real ganador, su valor, candidatos, método,
confianza y versión de reglas. La admisión exige al menos una evidencia válida
en `utm_campaign`, `utm_id` o `utm_content`. `source`, `original_source`,
`medium` y `channel` permanecen como contexto auditable, pero no crean por sí
solos una campaña ni una pseudo-campaña `salesforce_origin`.

Meta Instant Forms solo se identifica cuando el nombre de campaña aporta la
evidencia explícita `instantforms`/Formulario Directo Meta. La antigua
inferencia Lead `Portal_Text__c = Meta` + Facebook no tiene equivalente
Interest demostrado y no se reproduce mediante una heurística nueva.

## Interest ↔ Opportunity y first touch

La relación CRM exacta reutiliza `OpportunityInterestAttributionService`. Solo
`both_match` autoriza la asociación exacta. `direct_only`, `inverse_only`,
`contradiction`, `inverse_shared`, `unresolved` y `no_reference` nunca eligen
un Interest por sí solos.

Cuando no existe relación exacta, el first touch histórico por Account se
mantiene separado:

1. compara únicamente `Interest.account_salesforce_id` con
   `Opportunity.account_id`;
2. aplica la precedencia de calidad de campaña existente;
3. ordena por `functional_created_at`;
4. deja ambiguos los candidatos incompatibles de igual precedencia;
5. persiste un método `account_first_touch`/equivalente y confianza inferior a
   la relación exacta, conservando por separado el estado CRM original.

Esta heurística no se presenta como relación CRM bidireccional y no utiliza PII.
`contradiction` e `inverse_shared` no pueden seleccionar un ganador mediante
first touch; quedan visibles como evidencia CRM conflictiva.

## Estado, tipo, owner y lifecycle

- Estado visible: `SalesforceInterest.status`.
- Tipo visible: `SalesforceInterest.type`, normalizado mediante el contrato
  compartido de informes Interest.
- Comercial: owner actual del Interest en el último F2
  (`current_interest_owner_at_last_sync`), no owner histórico.
- Procedencia: `origin_delegation`, sin fallback Lead.
- Lifecycle: Interests eliminados quedan fuera del KPI activo.

`Tipo de campaña` y `Tipo del Interest` son filtros distintos. La clasificación
de campaña no reescribe el tipo CRM.

## Snapshot, caché y fallo seguro

El builder captura el último run F2 de `salesforce_interests/salesforce`, exige
`completed` y cutoff, y lo revalida antes de publicar. También captura y valida
el contexto Opportunity↔Interest directo/inverso. Si cualquiera cambia durante
la construcción, la transacción se revierte y no se mezcla evidencia.

La caché incluye IDs, estados, cutoffs, disponibilidad y razón del contexto de
atribución. Una misma fila que pasa `running → completed` produce una identidad
de caché distinta.

El procesamiento usa cursor PK y lotes; los lookups Opportunity e Interest son
bulk, sin N+1 ni funciones SQL sobre IDs Salesforce indexados.

## Métricas y auditoría

Interests, Opportunities y resultados se deduplican como entidades distintas.
Salesforce-only conserva trazabilidad sin presentar costes inexistentes como
cero. Las auditorías JSON/CSV publican identidad Interest, fecha funcional,
status, tipo, source/medium/UTM, campaña, método, confianza, candidatos,
Opportunity, relación, owner actual, lifecycle y F2; no incluyen PII.

Endpoints:

- JSON KPI: `/informes/campanas/data/kpi-audit`;
- CSV KPI: `/informes/campanas/export/kpi-audit.csv`;
- CSV campañas: `/informes/campanas/export/campaigns.csv`;
- CSV atribuciones: `/informes/campanas/export/attributions.csv`.

## Operación

El scheduler usa `Europe/Madrid`:

- Meta: 01:30;
- Google: 01:45;
- Opportunity legacy: 07:10;
- snapshot directo Opportunity→Interest: 07:35;
- F2/F5 canónicos: cadencia horaria en minuto `:05`, incluido 08:05;
- atribución Campañas: 08:30;
- snapshot del informe: 09:00.

No cambia la cadencia ordinaria de las fuentes ni de la atribución/refresco.
`campaigns-reconcile-publication` comprueba cada hora de 09:20 a 23:20
(`Europe/Madrid`) qué dependencia impide publicar. En cada pasada puede iniciar
**una sola** tarea pendiente, por orden de fuentes Meta, Google, F2 y
Opportunities, snapshot directo Opportunity→Interest, atribución y refresco.
La tarea hija se reserva mediante `OperationalTaskService` con su clave y
bloqueo persistente existentes; no se crea otro canal de escritura ni se
ejecutan varios proveedores en una pasada. Cuenta los intentos no omitidos
con origen `scheduler` desde el comienzo del día civil de Madrid y permite
como máximo **tres por tarea y día**, incluidas las ejecuciones ordinarias del
catálogo. Las ejecuciones manuales autorizadas no entran en ese contador. Una
reserva ocupada, un límite agotado o una dependencia sin tramo abierto
recuperable se omiten; un fallo deja pendiente la siguiente pasada. El ciclo
acaba a las 23:20 y puede no completar toda la cadena ese día.

### Protección de escritura y evidencia de actualización (iteración 2026-10-27)

Los comandos económicos programados conservan su ventana solicitada de 120
días, pero `CampaignOpenInvestmentWindow::start()` consulta
`campaign_investment_closures` en la conexión de escritura y acota el comienzo
al primer día posterior al **último mes cerrado anterior al fin del rango**.
La regla también rige las invocaciones manuales de Meta, Google y del builder
de atribuciones. Un rango totalmente cerrado no consulta al proveedor ni
reemplaza métricas: deja un `report_sync_runs` con estado `skipped` y motivo
`closed_period`. El builder en modo escritura lo rechaza, mientras
`--dry-run` permite leer y comparar históricos sin publicar cambios. Cerrar o
reabrir un mes y escribir métricas/atribuciones comparten un bloqueo asesor
MariaDB/MySQL y el comienzo se revalida dentro de la transacción antes de
reemplazar filas. La ventana efectiva se informa en la salida CLI y, para
sincronizaciones iniciadas, en `report_sync_runs.stats`. Este límite protege
las escrituras ordinarias de métricas y atribución; no sustituye la revisión
económica previa a un cierre ni reescribe snapshots existentes.

Si hay cierres no consecutivos, una solicitud ordinaria que alcance un mes
cerrado posterior comienza **después del último cierre**; no cruza hacia un
mes anterior reabierto. Ese mes reabierto solo puede procesarse mediante un
rango aislado que termine antes del siguiente cierre, con autorización
económica expresa. Reabrir una fila no reconstruye datos automáticamente.

Meta valida la paginación de `insights` y rechaza una respuesta incompleta
antes del reemplazo; Google conserva la validación estructural de
`searchStream`. Ambos sincronizadores validan `metric_date`/`date_start` como
día real dentro de la ventana y sustituyen por cuenta en transacción. Un fallo
de proveedor o inventario queda advertido sin renovar la ejecución como
completa; una fecha inválida revierte la cuenta. Una respuesta vacía **válida**
mantiene la semántica anterior de intervalo sin filas, por lo que el operador
debe contrastar su alcance antes de recuperar octubre. No se han consultado
proveedores ni datos productivos para este cambio.

El aviso de Interest posterior a la atribución deja de depender solo de la
hora de ejecución F2: exige una modificación persistida posterior a la
atribución en `salesforce_interests`, bien de un Interest actualmente dentro
del período o de uno previamente atribuido a él que haya cambiado de fecha
funcional. Un run horario sin cambios materiales no genera el aviso ni oculta
un cambio anterior todavía pendiente. La caché del
dashboard versiona el último run F2 y el estado operacional de Meta/Google;
no debe reciclar una marca de actualización verificada después de un fallo.

`CampaignPublicationReadiness` reúne los últimos runs persistidos y completos
de Meta, Google, F2 Interests y Opportunities en `report_sync_runs`, más el
snapshot directo Opportunity–Interest completo. Una ejecución manual válida
cuenta igual que una programada; un intento posterior fallido no acredita la
fuente. Los runs Meta/Google deben cubrir el tramo económico abierto del
rango solicitado; el día corriente puede ser parcial y no se presume
cobertura futura. La atribución automática usa `--require-ready`: exige esas fuentes,
reconstruye solo si cambia la evidencia material o falta cobertura del rango,
y registra en `report_sync_runs` la identidad de los runs fuente y el rango
efectivo. Un F2 posterior sin cambios persistidos y una sync de Opportunities
con `material_changed=0` y sin bajas no obligan por sí solos a reconstruir;
el snapshot directo compara su huella de referencias, no solo su ID/fecha.
Un run sin estadísticas suficientes queda conservadoramente pendiente.

`reports:refresh-campaigns --store` solo publica si encuentra fuentes
completas, una atribución vigente que cubre el rango abierto solicitado y el
mismo linaje antes/después del cálculo. Persiste el snapshot y su run de
publicación en una transacción; la ejecución fallida no acredita un dataset
nuevo. Si la publicación del contexto predeterminado ya es vigente, el comando
omite un snapshot duplicado. Guarda en `campaign_report_snapshots.filters_hash`
una huella SHA-256 de los filtros normalizados por
`CampaignDashboardDatasetService::filters()`, excluyendo `start_date` y
`end_date`; las fechas se comprueban por cobertura separada. Este cambio no
altera los filtros aplicados, las cifras ni el contenido económico del JSON o
CSV. `dataset_cutoff_at` y la etiqueta «Datos actualizados» derivan del
último **snapshot publicado** con linaje de fuentes y atribución verificable,
no de la hora de renderizado ni de `MAX(synced_at)`. Si falta evidencia, el
campo es nulo, `dataset_freshness_reason` expone un motivo genérico y la UI
muestra «Actualización del informe no verificada». La fecha usa el mínimo de
los cutoffs fuente del linaje válido. El snapshot debe cubrir además el
período seleccionado; para un período totalmente cerrado esta marca de
actualidad operativa queda no verificada sin impedir consultar el histórico
cerrado. Los campos de diagnóstico individuales
pueden conservar fechas anteriores. Esto acredita la publicación local y sus
dependencias registradas; la integridad real de datos productivos y el cero
Google Venta requieren contraste operacional separado.

La consulta del dashboard solo acredita esa marca cuando los filtros
normalizados coinciden con la huella del snapshot y el período seleccionado
está cubierto por el snapshot **y** la atribución vigente. Un subperíodo con el
mismo contexto puede aprovechar la publicación; otro contexto o un rango fuera
de cobertura queda neutral. No se reutiliza una publicación general para
certificar una selección filtrada que no se publicó. Los snapshots antiguos con
otra huella no se reescriben: permanecen consultables pero no certifican la
marca nueva.

La retención transversal existente elimina runs `completed` con más de un mes
y `failed`/`unverified` con más de 14 días. Si desaparece un run necesario
para acreditar el linaje, la marca de frescura se neutraliza o el builder
requiere una reconstrucción conservadora; no se amplía la retención en esta
iteración. Conservar evidencia de auditoría bajo el procedimiento autorizado
antes de cualquier purga que afecte una investigación.

La inversión Google positiva y los resultados comerciales cero en Venta no
autorizan una atribución nueva por nombre o por importe. El resultado depende
de la evidencia `utm_id`/`utm_content`/`utm_campaign` del Interest, el
inventario publicitario, la resolución de ambigüedades, el enlace
Opportunity–Interest y los filtros de período y tipo. Sin consulta productiva
autorizada no puede localizarse el paso en que se pierden registros ni afirmar
que el cero sea incorrecto. Seguir el [diagnóstico agregado de solo lectura](operaciones-produccion.md#diagnóstico-de-google-ads-venta-con-inversión-y-cero-resultados) antes de proponer cambios de matching o KPI.

**Corrección de presentación por contexto (2026-10-27).** En
`CampaignDashboardDatasetService::attributionRows()`, el filtro funcional
`applyCampaignContextFilter()` se aplica a cada registro de
`campaign_lead_attributions` **antes** del `GROUP BY` por plataforma, Campaign
ID y nombre. Anteriormente una campaña con Interests de Venta y Tasación
podía quedar etiquetada por `MIN(campaign_type)` como Tasación y excluirse
entera de la comparativa y del detalle Venta, aunque su gasto publicitario
permaneciera visible y el KPI deduplicado sí contara la venta. El test
sintético conserva el gasto Google y la atribución de Venta de esa campaña
mixta; otro test mantiene en cero Google sin Interests y conserva Meta. Cambian
las filas agregadas servidas a comparativa, detalle y exportación CSV de
campañas para ese contexto; las columnas y autorización del CSV permanecen.
No cambian matching, first touch, deduplicación, fórmulas, atribuciones e
importes persistidos, snapshots, cierres ni filtros seleccionables. Los KPIs
deduplicados usan su propio recorrido de atribuciones; los tests sintéticos
verifican la coherencia del caso mixto, no invariancia de todos los valores
productivos. Las claves de caché del payload y la auditoría KPI pasan
respectivamente a `campaign-dashboard-v8` y `campaign-dashboard-kpi-audit-v2`
para no servir resultados anteriores durante el TTL de diez minutos. **No se
ha comprobado** que el caso mixto exista en octubre productivo ni que explique
el cero comunicado.

Comandos locales del pipeline ROT-4:

```bash
php artisan campaigns:sync-meta --days=120
php artisan campaigns:sync-google --days=120
php -d memory_limit=512M artisan campaigns:build-attribution --days=120
php artisan reports:refresh-campaigns --days=120 --store
```

`campaigns:build-attribution --dry-run` reutiliza el builder real, compara por
`interest_id` y no escribe ninguna de las tres tablas de atribución/ambigüedad
ni invalida caché. El diagnóstico `excluded_by_reason` conserva por cada motivo
el conteo completo y hasta 20 Salesforce Interest IDs como muestra; esas
muestras son acotadas y no contienen PII. La CLI tolera además la ausencia de
una muestra diagnóstica sin derribar una simulación funcional completada. Antes
de habilitar ROT-4 en un
entorno debe aplicarse la migración aditiva, disponer de un F2 completo/estable,
un snapshot directo coherente y reconstruir el período bajo procedimiento
operacional revisado. Esta implementación no ejecuta backfill ni despliegue.


## Antecedentes de presentación (2026-10-21; detalle sustituido por Fase A de 2026-10-23)

La columna Estado histórico muestra Activa (histórico) o Inactiva (histórico) usando la etiqueta del backend cuando existe evidencia publicitaria local para el criterio de la plataforma. Sin esa evidencia, con etiqueta ausente, estados desconocidos o procedencias Salesforce, muestra Estado no disponible. Ninguna etiqueta garantiza el estado remoto vigente. El filtro conserva su criterio histórico por aprobación expresa; no es una consulta de estado vigente. MIN y el fallback legacy siguen limitados al contrato histórico y no se usan para verificar el estado mostrado. No se alteran universos económicos ni cierres.

Solo el detalle visual oculta filas sin ninguna actividad cuantitativa: inversión, impresiones, clics, leads/conversiones de plataforma, intereses, oportunidades, reservas, ventas, compras, tasaciones e importes asociados. Cualquier indicador distinto de cero mantiene la fila, con independencia del estado. Un null no se convierte en cero. Dataset, exportaciones, rankings y KPIs permanecen completos.

Los costes conocidos cero muestran 0,00 €; un denominador cero/ausente con inversión conocida muestra No calculable; inversión de origen desconocido muestra Sin datos; costes Salesforce-only muestran No aplica. No cambia ningún valor JSON ni fórmula económica. Los gráficos preservan null como ausencia de cálculo. Coste por resultado conserva ventas/compras/oportunidades/intereses según tipo funcional, y máximo para Otros; la columna sigue oculta por diseño en Venta/Tasación y otros contextos específicos.

Procedimiento para las dos campañas solicitadas, límites, evidencia y despliegue: [diagnóstico de presentación](ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md).


## Revisión de presentación posterior a 681bfea (2026-10-21)

Se distinguen los dos tipos reales de procedencia: salesforce_campaign_without_spend muestra No aplica para costes publicitarios; salesforce_origin muestra Sin datos porque su inversión no está verificada, incluso si el JSON contiene un coste cero. Para otras procedencias, un cero solo se presenta como conocido con inversión conocida y denominador disponible distinto de cero. El valor JSON no se modifica.

El helper costPresentation centraliza texto, motivo y valor exclusivamente visual. Los tooltips distinguen divisor cero, denominador ausente, inversión desconocida, procedencia sin inversión verificable y métrica no aplicable. Los costes sin evidencia tampoco dibujan puntos cero en gráficos.

Las líneas se generan por tramos contiguos: un período null interrumpe la línea, incluso entre dos valores válidos. Una serie enteramente nula no dibuja líneas ni puntos; un final null no reutiliza el último valor conocido. Ceros legítimos y valores negativos se conservan. El recorrido sigue siendo lineal, sin consultas ni llamadas remotas.

El selector se titula Estado histórico (filtro) y sus opciones indican según criterio histórico. Sus valores active/inactive y su lógica permanecen idénticos; Estado no disponible sigue significando que NO se ha implementado la identificación del estado remoto vigente. Sin cambios de API, KPIs, fórmulas, snapshots, atribuciones o permisos.


## Visibilidad de la clasificación histórica (2026-10-22)

Se elimina la sustitución incondicional por Estado no disponible. El frontend traduce únicamente las etiquetas Activa/Inactiva ya recibidas; no replica campaignIsActive ni deduce actividad de intereses. Exige evidencia local de campaign_effective_status para Meta o campaign_status para Google; valores vacíos, UNKNOWN o UNSPECIFIED no justifican la etiqueta. Campañas Salesforce-only y salesforce_origin permanecen neutrales: no acreditan estado publicitario. El tooltip aclara que la clasificación no consulta actualmente Meta Ads ni Google Ads.

Se ha verificado que mergeRows asigna campaign_status_label antes de sumar atribuciones: una fila sin estados puede tener etiqueta Inactiva o null, mientras campaignIsActive final considera activa la fila por su fallback de intereses. Cambiar esa etiqueta afectaría el contrato API/CSV; no se modifica. Estas filas se muestran neutrales en lugar de reproducir una etiqueta potencialmente contradictoria. El selector histórico, sus valores active/inactive y todos los filtros permanecen intactos. Las filas con estado publicitario y etiqueta disponible conservan la coherencia con el filtro final. No se implementa consulta de estado remoto vigente.

Los assets de campañas se generan mediante npm run build y se incluyen junto con su referencia actualizada en public/build/manifest.json. Durante un despliegue autorizado se deben publicar bundle y manifest del mismo release de forma conjunta/atómica, comprobar que la URL del manifest responde y revisar el texto histórico servido. No se debe desplegar solo el código fuente o un manifest sin su bundle. No se ha ejecutado ningún despliegue.


## Lista explícita de estados históricos reconocidos (2026-10-22)

La evidencia se valida con conjuntos explícitos en memoria, usando los campos que las integraciones existentes persisten directamente: Meta campaign_effective_status desde effective_status y Google campaign_status desde campaign.status. Meta admite ACTIVE, PAUSED, DELETED, ARCHIVED, IN_PROCESS y WITH_ISSUES; Google admite ENABLED, PAUSED y REMOVED. Una vez validado el código, se conserva la etiqueta Activa/Inactiva del backend; el frontend no recalcula esa clasificación.

Se exige una cadena canónica exacta: valores vacíos, UNKNOWN, UNSPECIFIED, códigos de otra plataforma, códigos futuros, texto arbitrario, minúsculas, espacios añadidos y tipos no textuales muestran Estado no disponible, incluso cuando el backend envía Inactiva. No se convierte ni normaliza un valor malformado para aceptarlo. Las procedencias Salesforce siguen siendo neutrales. Nuevos códigos deben revisarse explícitamente antes de ampliarse la lista; esta cautela puede ocultar temporalmente una clasificación histórica, nunca confirmar un estado no reconocido. No se consulta el estado remoto vigente.

Fuentes oficiales contrastadas sin llamadas a cuentas publicitarias: [Meta Campaign.EffectiveStatus (SDK oficial)](https://github.com/facebook/facebook-python-business-sdk/blob/main/facebook_business/adobjects/campaign.py) y [Google CampaignStatus](https://developers.google.com/google-ads/api/reference/rpc/v26/CampaignStatusEnum.CampaignStatus). La documentación Google define UNKNOWN/UNSPECIFIED, pero no se admiten como evidencia de clasificación. Sin cambios en filtros, API/CSV, economía o sincronizaciones.


## Fase A vigente: detalle publicitario y campañas a revisar (2026-10-23)

Esta sección reemplaza, únicamente para el detalle publicitario, las reglas anteriores de visibilidad por cualquier actividad y texto monetario. Las reglas económicas, los históricos y el resto de bloques no se sustituyen.

### Detalle de campañas

advertisingEligibility() y advertisingDetailRows(), en resources/js/reports/campaign-presentation.js, admiten exclusivamente filas meta o google_ads, sin procedencia salesforce_campaign_without_spend ni salesforce_origin, con spend numérico decimal finito estrictamente mayor que cero en el período y después de los filtros funcionales existentes. Se admiten números o cadenas decimales, incluida notación exponencial; booleanos, hexadecimal, objetos, vacíos y valores no finitos no constituyen evidencia. La verificación se limita al dato local recibido, sin nueva certificación externa del gasto.

Inversión cero, negativa, ausente o inválida excluye visualmente la fila aunque tenga intereses, oportunidades o ventas. Gasto positivo sin conversiones o con datos parciales conserva la fila. No se infiere inversión desde actividad CRM. Los intereses Salesforce atribuidos a campañas publicitarias siguen siendo métricas de esas campañas. El detalle muestra un conteo agregado de exclusiones por motivo (otra plataforma, procedencia no verificable, inversión desconocida, inválida, negativa o cero), solo en el DOM: no crea registros, logs ni métricas persistidas. Es un conteo del conjunto recibido tras los filtros, no del total de la base de datos. Los gastos negativos quedan disponibles en respuestas y auditorías para diagnóstico; no se corrigen importes.

Se retiran result_count (Resultados) y cost_per_result (Coste por resultado) del detalle y su selector. Permanecen en backend, API/CSV, rankings, tarjetas y gráficos donde ya se utilizan. La única columna Coste por compra utiliza cost_per_purchase: inversión dividida entre compras según el backend. No se renombra el resultado funcional. Está disponible en Todos, Tasación y Otros; se conservan las restricciones de Venta, Exposición y Branding y sus subcontextos. Una selección v6 explícita puede ocultarla voluntariamente.

Las preferencias de columnas migran de hrmotor_campaign_columns_v5 a hrmotor_campaign_columns_v6: se descartan claves no definidas, se deduplican y se añade Coste por compra una sola vez en la migración. Si v6 ya existe se respeta. No se borran preferencias ajenas, de rankings, contexto o gráficos; la clave anterior se conserva para compatibilidad de rollback.

Solo las celdas monetarias del detalle usan — cuando no hay cálculo válido: importe/coste ausente, inversión desconocida, denominador cero o desconocido y métrica no aplicable. El tooltip conserva el motivo concreto. Los valores válidos positivos, negativos o cero conservan el formato EUR existente; no se transforma null en cero ni se recalcula ningún coste. Una fila de inversión negativa se excluye aunque el formateador pueda representar importes negativos. Tarjetas, gráficos y exportaciones conservan sus formateadores previos.

### Campañas a revisar

CampaignDashboardDatasetService::reviewCampaigns() aplica elegibilidad publicitaria con inversión numérica finita positiva antes de calcular medias por tipo y seleccionar motivos/top 8. Excluye Salesforce y las dos procedencias sin gasto verificable. El frontend vuelve a comprobar plataforma e inversión antes de renderizar. Puede mostrar menos de ocho campañas; nunca se completa con Salesforce. Se conserva el orden de motivos, fórmulas, umbrales y prioridad por impacto económico. La media utiliza los costes por resultado positivos no nulos del tipo, redondeados a cuatro decimales como antes; el umbral Coste alto sigue siendo mayor que el doble de esa media.

Este cambio altera deliberadamente solo el contenido y benchmarks de este bloque. No altera el dataset general ni las filas económicas exportadas. La caché de payload pasa de campaign-dashboard-v4 a campaign-dashboard-v5 para no servir el bloque anterior desde caché; no se reconstruyen snapshots.

### Estado y fase pendiente

La columna y filtro siguen siendo históricos; no se ha implementado Estado actual. Se mantienen validación conservadora de estados reconocidos y tooltips sin promesa de vigencia remota. El estado actual requiere persistencia independiente y autorización explícita. Véase la [propuesta de Fase B](ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md), no implementada.

### Evidencia y entrega

Regresiones en tests/js/campaign-presentation.test.mjs cubren elegibilidad, conteos, gasto sin resultados, escape HTML, columnas, preferencias y guiones monetarios. tests/Feature/CampaignDashboardTest.php cubre benchmarks/top 8 sin Salesforce y conserva las pruebas de API/CSV, atribuciones y cierres. Son pruebas sintéticas; no certifican datos productivos. Resultados de ejecución en [HANDOFF](ai/HANDOFF.md). La trazabilidad focalizada queda registrada como [pendiente de auditoría integral](matriz-trazabilidad-kpi.md).

Despliegue humano: publicar código y assets generados por npm run build con public/build/manifest.json del mismo release; comprobar que el bundle referenciado existe y se sirve. No requiere migraciones, nuevas variables, sincronizaciones ni operaciones sobre datos. Rollback: release anterior completo con su manifest/assets o reversión del commit y recompilación. No borrar ni regenerar cierres. Verificar visualmente columnas, conteos, filtros y tooltips.

## Fase B vigente — estado operativo y números (2026-10-23)

Esta sección sustituye el estado pendiente de Fase A. El encargo posterior autoriza implementar persistencia y automatización deshabilitada; no autoriza su activación ni un despliegue. La integración limpia se prepara en `feat/campaign-current-status-ready` desde `origin/main` 89282a32b79060fca671adee4bc49741c954308f, que ya contiene Fase A y la reconciliación documental del PR #85. Solo se trasladan los commits exclusivos B mediante cherry-pick con referencia de origen; no se incorpora de nuevo 2f3791e. La rama dependiente anterior se conserva intacta como antecedente. Los antecedentes anteriores se conservan como historia, no como contrato vigente.

### Estado actual independiente

El detalle muestra **Estado actual** mediante `current_status`, sin reinterpretar `campaign_status`, `campaign_effective_status` ni `campaign_status_label`. El selector **Estado** conserva exactamente sus valores y universo históricos: una campaña históricamente activa puede aparecer actualmente pausada. No se usa gasto ni actividad Salesforce para acreditar el estado operativo. Una fila sin cuenta/ID verificables o de Salesforce permanece neutral.

`MetaAdsClient::currentStatusPages()` consulta por cuenta `id,account_id,name,status,effective_status`, priorizando `effective_status`: ACTIVE → Activa; PAUSED → Pausada; DELETED → Eliminada; ARCHIVED → Archivada; IN_PROCESS → En proceso; WITH_ISSUES → Con incidencias. No restringe el inventario mediante el parámetro de consulta `effective_status`: la API de la cuenta productiva lo rechazó (HTTP 400, código 100, subcódigo 1815001), mientras la lectura sin él respondió HTTP 200 e incluyó campañas activas y pausadas. Se conserva la validación local de estados reconocidos y la normalización de cuentas `act_`. `GoogleAdsClient::currentStatusPages()` usa `googleAds:search` con `customer.id,campaign.id,campaign.name,campaign.status`, sin fechas ni métricas, incluyendo ENABLED/PAUSED/REMOVED: Activa/Pausada/Eliminada. UNKNOWN, UNSPECIFIED y códigos futuros no acreditan vigencia. La cobertura real de cuentas y permisos requiere verificación operacional autorizada; los tests simulados no la certifican.

`CampaignCurrentStatusService::sync()` persiste el inventario únicamente en `campaign_current_statuses`, con identidad única textual `(platform,account_id,campaign_id)`. El prefijo `act_` de cuenta Meta y guiones de cuenta Google se normalizan; no se unen nombres ni se convierten identificadores a números. La tabla guarda observación bruta/configurada, último par válido y sus fechas UTC. `last_observed_at`, `last_verified_at`, `created_at` y `updated_at` son DATETIME con valores civiles UTC (los dos últimos mediante `$table->datetimes()`), escritos por el servicio en UTC: se evita la conversión implícita de TIMESTAMP al cambiar la zona de sesión MariaDB. Las fechas de frescura no dependen de created_at/updated_at ni de la zona de la conexión. Un código nuevo conserva el último válido pero se presenta no disponible. Una respuesta incompleta o malformada no actualiza ninguna fila de esa cuenta; otras cuentas pueden completar. Una campaña ausente no se elimina ni se interpreta como eliminada.

La auditoría reutiliza `report_sync_runs`: dataset `campaign_current_status`, source `platform:account_id`, resultado por cuenta, conteos de páginas/campañas/estados reconocidos/no verificables, operational_run_id y error genérico. No se crea una segunda tabla de ejecuciones. La migración aditiva incorpora índice único de identidad, índice `sync_source` y referencia nullable a `report_sync_runs` con `nullOnDelete`; no modifica históricos económicos.

### Frescura y contrato aditivo

`CampaignDashboardDatasetService::summary()` decora solo `campaigns`; `campaignRows()` decora `items`, después de recuperar el payload económico cacheado. `exportRows()` y CSV conservan sus contratos. No se incorporan observaciones de estado actual a los rankings ni al dataset económico cacheado; este último conserva únicamente la marca de identidad ambigua calculada al agregar métricas (véase la protección posterior a 46f283f). La unión filtra identidades solicitadas en lotes de hasta 500 Campaign IDs (una consulta hasta ese límite), sin escanear el inventario completo de las cuentas, sin red durante la petición ni consultas por fila.

Cada fila incorpora `current_status` con `availability` (`fresh`, `stale`, `unavailable`), `label`, `verified_at` ISO UTC o null, `raw_status`, `configured_status`, `observed_at`, `last_valid_raw_status`, `platform`, `account_id`, `campaign_id` y `fresh_hours:24`. La identidad de la proyección es canónica textual, o null si no es verificable; la identidad legacy de la fila permanece intacta. Los campos existentes permanecen intactos.

Solo es fresco un estado reconocido con antigüedad `0 <= edad < 86400` segundos y cuya referencia de ejecución, no nula, coincide con el último intento registrado de la cuenta, también no nulo y completado (`completed`). Exactamente 24 horas, una ejecución posterior fallida/en curso o un inventario posterior que omite la campaña producen **Estado no actualizado**. Código no reconocido, ausencia de verificación o fecha futura producen **Estado no disponible**. Los fallos no renuevan la fecha previa. La conservación del último válido es diagnóstica, nunca un fallback de vigencia. El frontend vuelve a evaluar la edad al renderizar y muestra la fecha en Europe/Madrid, incluso en cambios de horario. El tooltip distingue estado observado, última verificación y filtro histórico. Si la auditoría desaparece por retención o la referencia queda nula, el dato se degrada a Estado no actualizado aunque la fecha sea reciente; un run fallido con el mismo ID tampoco acredita vigencia. No se modifica la retención en esta entrega.

### Formato numérico del informe

Los formateadores reales de `campaigns-dashboard.js` usan `Intl.NumberFormat('es-ES', {useGrouping:true})` para agrupar también cuatro cifras: 999 → 999; 1000 → 1.000; 1234 → 1.234; 9999 → 9.999; 1234567 → 1.234.567. Moneda EUR mantiene dos decimales y el espacio propio de Intl: 1234.56 → 1.234,56 €; -1234.5 → -1.234,50 €. Porcentajes mantienen una cifra decimal y multiplicadores dos; solo cambia presentación/localización, no fórmulas.

Se aplica a detalle, tarjetas, rankings, revisión, tablas auxiliares, contadores, tooltips y ejes. Los ejes dejan las abreviaturas k/M para mostrar miles explícitos. Las parejas numéricas de revisión también se agrupan. El guion monetario del detalle y sus motivos de Fase A se mantienen; otros bloques conservan su semántica de nulos. Identificadores, fechas, parámetros, valores JSON y ordenación numérica no se formatean ni alteran. Preferencias v7 sustituyen selectivamente `campaign_status_label` por `current_status` desde v6/v5; no borran preferencias ajenas y respetan la selección v6 de Coste por compra.

Operación, despliegue, rollback y resultados de pruebas: [validación de Fase B](ai/CAMPAIGN_PHASE_B_VALIDATION.md) y [operaciones](operaciones-produccion.md). No se han ejecutado integraciones ni migraciones productivas.

La interfaz mantiene un temporizador único hasta la próxima caducidad de las celdas frescas y reevalúa al recuperar visibilidad de pestaña. Solo modifica textContent/title de las celdas, sin fetch, reconstruir la tabla, recalcular métricas ni mutar el payload. Así una página abierta no mantiene una confirmación después del umbral. La regresión JavaScript cubre expiración con página abierta; la batería actual completa cuenta 20 pruebas correctas, comunicadas por el agente principal.

El tooltip genérico de celdas numéricas reutiliza el texto ya formateado (1.234, no1234); los tooltips explicativos de costes y estado se conservan y los valores originales no se mutan. La regresión ejecuta la tabla real y verifica el tooltip agrupado junto al motivo divisor cero.

### Completitud de atributos y contrato Meta contrastado

La lectura Meta solicita `effective_status` entre los campos de respuesta, pero no lo envía como parámetro de filtrado; tampoco usa `filtering` ni rango temporal. La regresión HTTP simulada verifica campos, inclusión de pausadas, cuenta con o sin `act_`, respuesta vacía y rechazo de cursores repetidos sin renovar estados previos. La evidencia productiva comunicada fue una página con 189 campañas (10 ACTIVE y 179 PAUSED) al omitir el filtro; no acredita por sí sola la cobertura de otras cuentas ni una ejecución completa de la sincronización de estados.

Cada elemento debe incluir la clave `status` y, en Meta, `effective_status`. Google valida que campaign incluya status antes de decodificar. Un atributo requerido ausente invalida el inventario completo de la cuenta y conserva sus filas previas. Un null explícito es distinto: constituye observación no verificable, no confirma estado ni renueva el último válido. Esta distinción se aplica exclusivamente a metadatos operativos.

### Alcance de la validación MariaDB de integración

La migración y el contrato B se verifican en MariaDB 11.4.13 local aislado: cuatro pruebas Integration/22 aserciones y focales B 67/844 correctas, con proveedores simulados. La batería amplia Dashboard no pasa completa en MariaDB: 19 errores se reproducen también en main 89282a3 sobre base nueva (8 por fechas ISO8601 con offset en DATETIME legacy interest_sync_cutoff_at; 11 por IDs sintéticos de más de 18 caracteres en interest_id/salesforce_id). El error adicional de la fixture B fue corregido sin cambiar lógica económica. Estos casos previos de compatibilidad/fixtures quedan fuera de Fase B para revisión separada; no acreditan una incidencia productiva ni se ocultan como éxito global. Véase el cierre de validación para la suite general SQLite.

La validación general definitiva de integración (Unit y Feature, SQLite) termina correctamente: 1458 pruebas/13447 aserciones, sin errores, fallos ni omitidas. Las pruebas específicas MariaDB se ejecutan aparte; la limitación legacy descrita no queda resuelta por el éxito SQLite. Resultados detallados, intento previo y evidencia sintética en el registro de validación B.

### Protección de identidad del estado actual (posterior a 46f283f)

La agrupación económica `rowKey()` permanece intacta: puede unir métricas de varias cuentas si coinciden plataforma, Campaign ID y nombre normalizado. `mergeRows()` registra, durante esa agregación existente, las cuentas canónicas de las contribuciones publicitarias. No cuenta como otra cuenta la atribución Salesforce que carece de cuenta publicitaria. Si hay más de una cuenta distinta, añade la marca `current_status.identity_ambiguous:true`. Una cuenta no normalizable se registra como desconocida: junto con una cuenta conocida también neutraliza el estado; una fila exclusivamente sin cuenta sigue sin estado verificable. Nombres diferentes mantienen las filas separadas según la regla histórica existente; no se identifica inventario operativo por nombre.

`CampaignCurrentStatusService::decorate()` omite el lookup operativo de las filas marcadas y devuelve `availability:unavailable`, `label:Estado no disponible`, `identity_ambiguous:true` y `reason:ambiguous_account_identity`. No devuelve cuenta, Campaign ID, estados ni fechas de verificación operativa para esa fila ambigua. El tooltip explica que la fila económica combina varias cuentas y no confirma el estado individual. Las identidades inequívocas mantienen la verificación por plataforma/cuenta/ID y la frescura de 24 horas.

La marca es información de presentación; no corrige ni divide importes, no reasigna atribuciones y no cambia filtros o fórmulas. El payload económico usa la clave de caché `campaign-dashboard-v6` para evitar reutilizar filas v5 sin esa marca. La lectura actual sigue fuera de la caché económica. `exportRows()` retira la metadata `current_status` antes de exportar, conservando el contrato CSV. No se añade persistencia, consulta por fila ni llamada remota. Las pruebas sintéticas reproducen inversión 120+230=350 de dos cuentas con nombres normalizados iguales y estados ACTIVE/PAUSED, con KPIs, filas económicas, CSV y métricas persistidas conservados; el filtro de una cuenta sigue devolviendo su inversión 230 y estado Pausada.

## Iteración visual y diagnóstico de sincronización (2026-10-26)

En `/informes/campanas`, la comparativa por plataforma aparece inmediatamente bajo los filtros. Solo se dibujan tarjetas de proveedores presentes en la respuesta (`meta` y/o `google_ads`); si falta uno, no se fabrica una tarjeta de importes cero. Los acentos visuales distinguen Meta en azul y Google en dorado. Los filtros conservan sus etiquetas, opciones y semántica, pero retiran las ayudas visibles y reducen su altura. «Auditar KPI» se sitúa en la cabecera superior derecha de cada tarjeta cuando el usuario ya dispone de ese permiso; mantiene el destino y la autorización existentes.

Se elimina del DOM «Conciliación por origen» y su renderizador. `source_reconciliation` sigue en el JSON del resumen para preservar consumidores existentes y conciliación económica; no se retiran sus cálculos del backend ni se modifican exportaciones, filtros, KPIs o atribuciones. La retirada es estrictamente visual. La presentación usa los formateadores `es-ES` del informe con agrupación explícita: 4.959, 5.040, 301.331 y 43.286,42 €; números pequeños, cero, negativos y precisión decimal siguen sus contratos. Identificadores técnicos y valores fuente permanecen literales. Se amplían pruebas de los renderizadores reales, no se cambia la fórmula ni el JSON numérico.

El panel administrativo de diagnóstico distingue ahora dos evidencias por proveedor. `last_meta_sync` y `last_google_sync` conservan su significado anterior: máximo `synced_at` de **filas de métricas** en `campaign_platform_daily_metrics`, que puede ser antiguo aunque una ejecución posterior no haya escrito filas. Los nuevos campos aditivos `meta_sync_operation` y `google_sync_operation` proceden de una única lectura local de `operational_task_states` unida a `operational_task_runs` para `campaigns-sync-meta` y `campaigns-sync-google`. Cada objeto presenta `verified_at` (ISO UTC o `null`) únicamente si `last_success_id` referencia una ejecución `success`, código de salida 0 y fecha de finalización; `last_attempt_status` indica el estado reconocido de `last_run_id` o `null`. La interfaz rotula la primera fecha «Última ejecución correcta», muestra el último intento y presenta el antiguo `synced_at` aparte como «Último dato de métricas». Convierte las fechas UTC a `Europe/Madrid`; tras 36 horas añade «Antigua». Sin ejecución acreditada indica «Sin ejecución verificada» y nunca promociona el `synced_at` de una fila a éxito operacional.

La consulta operacional se efectúa solo al solicitar diagnósticos con el permiso administrativo existente, después de leer el payload económico cacheado. Así no hereda su TTL ni añade consultas por campaña o llamadas a Meta/Google durante HTTP. El nuevo diagnóstico no interviene en `current_status`, cuya política de frescura de 24 horas es independiente, ni en las métricas históricas. Una ejecución directa fuera del sistema operacional puede no quedar acreditada en este panel; su ausencia no demuestra que el proveedor no se haya consultado. No se ha comprobado una cuenta productiva ni se ha ejecutado sincronización real: si Google parece desfasado, contrastar el último intento operacional, su resultado y la fecha de la última fila antes de diagnosticar el proveedor.

Cada apertura del panel solicita nuevamente el diagnóstico con caché HTTP deshabilitada; solo se comparte una petición aún en curso. De este modo, repetir la consulta con los mismos filtros no conserva indefinidamente un éxito o fallo anterior en el navegador.

Pruebas sintéticas: `CampaignDashboardTest::test_sync_diagnostic_uses_live_operational_evidence_without_changing_economics_or_csv` comprueba éxito Google, intento fallido posterior, ausencia Meta, caché económica, KPIs, campañas, `source_reconciliation` y CSV; `campaign-presentation.test.mjs` cubre casos de fecha antigua/ausente/fallo, agrupación numérica y tarjetas. Los resultados finales están en [HANDOFF](ai/HANDOFF.md). Despliegue futuro: publicar código, bundle Vite y manifest del mismo build; no se requieren migraciones, variables nuevas, activación ni reconstrucción histórica. Rollback: restaurar el release anterior completo con sus assets; reaparecerá la presentación previa del diagnóstico. Verificar escritorio/móvil y la fuente operacional con datos autorizados antes de interpretar el desfase de Google.

## Fase C — entidades publicitarias y procedencias comerciales (2026-10-26)

El catálogo expuesto como **campañas** se limita ahora a `meta` y `google_ads`.
`CampaignDashboardDatasetService::campaignEntityRows()` aplica esta condición
también a `summary.campaigns`, `data/campaigns`, CSV de campañas, rankings y
universos de entidades. Excluye además los tipos de procedencia
`salesforce_campaign_without_spend` y `salesforce_origin`. La clave
`campaign_universes.salesforce_only` se conserva por compatibilidad como lista
vacía; no debe interpretarse como ausencia de intereses de origen Salesforce.
Las opciones de nombre e ID de campaña se leen de
`campaign_platform_daily_metrics` para Meta/Google. La clasificación operativa
de nuevas entidades solo admite esas dos plataformas. La caché económica y de
opciones usa versiones `v7` y `v3`, respectivamente, para no reutilizar
catálogos anteriores. El snapshot **nuevo** de `reports:refresh-campaigns
--store` filtra su campo JSON `campaigns` por la misma elegibilidad; su resumen
y rankings siguen sus contratos propios. Los snapshots antiguos no se
reescriben ni se certifica que estén libres de identidades Salesforce.

El builder todavía persiste atribuciones de intereses sin plataforma real con
`platform=salesforce` y método `salesforce_only`. Esas filas son evidencia
comercial, no un catálogo físico de campañas. Se mantienen en
`campaign_attributions` y `campaign_lead_attributions`, así como en KPIs,
auditorías y `source_reconciliation`. No se borran ni se recalculan por esta
iteración. Los tests sintéticos comprueban que un interés Salesforce-only sigue
en ambas tablas y en los totales aunque no aparezca en API/CSV de entidades;
una reconstrucción de atribuciones no lo vuelve a mostrar como campaña. La
[propuesta de saneamiento](ai/CAMPAIGN_SALESFORCE_CLEANUP_PROPOSAL.md) separa
estas identidades legítimas de posibles clasificaciones y snapshots antiguos;
no hay limpieza física autorizada ni ejecutada.

La etiqueta visible del selector `campaignStatus` es **Estado**. Sus opciones
`active` e `inactive` siguen diciendo «según criterio histórico» y conservan
exactamente su filtro previo; la columna **Estado actual** usa únicamente
`current_status` y su frescura de 24 horas. El encabezado de cada tarjeta de
comparativa muestra solo Meta Ads o Google Ads, sin subtítulo lateral; permanece
el subtítulo general de la sección. El enlace «Auditar KPI» conserva permiso y
destino, con `min-height:10px`, `padding:0 9px` y `line-height:22px` para
alinear las cifras sin perder foco visible. La comprobación visual real en
escritorio/móvil queda pendiente del despliegue autorizado.

`Estado no disponible` es una respuesta correcta cuando no hay lectura local
verificable, el estado es desconocido, la identidad es ambigua o falta el run
válido; no acredita un fallo concreto de Meta/Google. La tarea de estado
actual está deshabilitada por defecto (`CAMPAIGN_CURRENT_STATUS_ENABLED=false`).
Esta iteración no inspecciona producción ni consulta proveedores; seguir el
[procedimiento operativo de diagnóstico](operaciones-produccion.md#fase-c--diagnóstico-y-saneamiento-controlado-de-campañas) antes de atribuir una causa o autorizar una primera lectura. Ninguna etiqueta histórica sustituye un estado remoto actual.

### Límite de integridad de la fuente compartida F2

La atribución y las consultas de Campañas que usan `salesforce_interests` dependen de la tabla viva F2. Cada bloque de persistencia F2 se ejecuta en una transacción y un fallo general ya no repite filas con escrituras para diagnóstico. Un fallo posterior puede dejar persistidos bloques anteriores; no existe aislamiento por generaciones. El último run completado por sí solo no certifica el contenido actual de la tabla tras una ejecución fallida. La respuesta HTTP 503 específica del dashboard de Interests no se extiende por este cambio a este informe. No cambian las reglas de atribución, los filtros, fórmulas ni exportaciones de este informe. Véase [disponibilidad de Interests](reglas-negocio-leads.md#disponibilidad-del-dashboard).

## Fuente Interest por generación — PR #92 (2026-10-28)

Builder, consultas auxiliares del dashboard, diagnóstico y readiness obtienen
Interests y F2 mediante `InterestGenerationReader`. La selección deja de depender
del último intento F2 y usa la generación publicada fijada. El análisis de cambios
materiales para readiness considera runs F2 publicados; la caché de opciones
incluye generación. No cambian first touch, `functional_created_at`, filtros,
atribución, ratios ni deduplicación, y no se reabren snapshots económicos.

Esta garantía corresponde a la fuente Interest. Las atribuciones materializadas,
fuentes publicitarias y otras fuentes conservan sus contratos de build/readiness;
no se afirma una transacción global sobre ellas ni un token de carga multipetición
para Campañas. Las regresiones integrales deben verificar esa composición y las
exportaciones. El texto anterior de la fase de transacciones por bloque describe
el estado previo a generaciones.
[Arquitectura y operación](salesforce-interest-generations.md).
