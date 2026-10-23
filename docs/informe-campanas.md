# Informe de Campañas

Actualizado: 2026-10-23. URL: `/informes/campanas`.

## Contrato funcional ROT-4

La entidad CRM de adquisición es `Interes__c`, replicada localmente en
`salesforce_interests`. El informe solo incluye Interests activos del período
solicitado; usa `functional_created_at` persistido en UTC y convierte los
límites de negocio `Europe/Madrid` a UTC antes de consultar.

Google Ads y Meta Ads siguen aportando inversión, impresiones, clics y métricas
diarias. Opportunity continúa aportando reservas, ventas, compras e importes.
No se consulta Salesforce durante una petición HTTP ni durante el builder.

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
