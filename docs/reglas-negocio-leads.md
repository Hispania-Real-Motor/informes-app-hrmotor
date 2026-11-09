# Informe de Interests — contrato ROT-1

## Nota técnica — materialización mensual F2 provisional

La materialización F2 mensual aislada preserva, para una futura consulta
autorizada, campos normalizados de identidad, persona/Lead/Account, fecha
funcional, owner, estado/tipo, procedencia y UTM. No es un lector de este
informe ni modifica ROT-1, filtros, KPI, AJAX, CSV o el contrato de 503: el
dashboard continúa leyendo solo la generación F2/F5 publicada. Sus hashes,
ausencias, soft deletes y discrepancias son evidencia operativa; octubre no se
certifica ni publica por materializar sus campos.

> La ruta `/informes/leads`, el permiso `leads` y varias claves técnicas
> `leads_*` se conservan por compatibilidad. Desde ROT-1 el hecho funcional del
> informe es Salesforce `Interes__c`, no `Lead`.

Actualizado: 2026-10-28 (PR #92, implementación en revisión).

## Fuentes y persistencia vigentes

El informe usa exclusivamente estas fuentes locales:

- `salesforce_interest_versions` y `salesforce_interest_members`: versiones de `Interes__c` fijadas por la generación publicada (FOUNDATION-2);
- `salesforce_interest_activity_runs` y `salesforce_interest_activities`:
  evidencia FOUNDATION-5 de Task/Event relacionados directamente mediante
  `WhatId → Interes__c`;
- `salesforce_users`: elegibilidad y dimensión comercial del owner. Su
  `created_date` materializa `User.CreatedDate` para trazabilidad futura; no
  participa actualmente en la elegibilidad, filtros, universos, KPI, cálculos
  ni presentación del informe;
- `report_sync_runs`: estado, run y cutoff F2 auditables.

### Nota técnica PR #111 — inventario mensual provisional

La reconstrucción mensual de Interests mantiene inventarios actuales aislados
por período funcional. `SalesforceInterestSyncService::functionalPeriodSoql()`
admite opcionalmente incluir soft deletes para ese flujo; su valor por defecto
conserva la consulta anterior. No modifica la generación publicada ni habilita
este informe o sus KPI: F5 no está certificado y los hard deletes históricos no
están acreditados.
La instrumentación diagnóstica mensual clasifica los fallos por etapa sin
exponer mensajes, SQL ni payloads; conserva checkpoint y reanudación, sin
habilitar ningún lector oficial.

### Nota técnica — F5 mensual aislada (2026-11-07)

La F5 mensual relaciona `Task` y `Event` exclusivamente cuando `WhatId` existe
en la F2 mensual de origen. Sus filas, checkpoints y manifiesto son evidencia
administrativa aislada: no alimentan este dashboard ni sus KPI. F5 `completed`
no verifica ni certifica F2; una F2 provisional sigue sin ser publicable.
La estrategia selectiva posterior recorre bloques de `WhatId` de esa F2 con
checkpoints separados de Interest y Activity; su reconciliación es de solo
lectura y tampoco puede convertir la evidencia en fuente oficial.

El render HTTP no consulta Salesforce. No recupera atributos desde Lead,
Account o Contact y no utiliza PII como fallback.

El refresco mensual de Users puede incluir en un batch adicional los Salesforce
User IDs configurados en el roster de Contact Center. Ese roster no altera los
perfiles ni la elegibilidad de Interests y no tiene FK física a
`salesforce_users`, pues admite preparación previa al primer sync. Sus rangos de
vigencia y nombres visibles son metadatos del roster de Comisiones; no se usan
en filtros, universos, KPI, cálculos ni presentación de Interests.

## Disponibilidad del dashboard

La fuente es la generación publicada mediante puntero transaccional, con F2/F5
completos y alineados. Una candidata en curso, fallida o con conflictos no cambia
las filas publicadas ni el watermark. Summary devuelve `generation_id`; AJAX y
CSV lo fijan para evitar mezclas. La fecha visible es el corte certificado y se
marca desactualizado después de 7.200 segundos por defecto. Un token inválido,
una generación inexistente o ausencia de publicación produce HTTP 503,
`interest_dataset_unavailable` y `Cache-Control: no-store`, sin KPIs parciales.
Recargar permite iniciar una carga coherente; una generación antigua publicada
sigue siendo consultable mientras se conserve.

F2 persiste versiones y pertenencias candidatas por bloques, aísla colisiones
individuales con evidencia cifrada y procesa las filas válidas. Cualquier
conflicto bloquea certificación. F5 debe pertenecer al candidato exacto; solo la
publicación conjunta avanza el watermark. La tabla viva heredada no es una base
certificada. Pruebas: `InterestGenerationTest`,
`SalesforceInterestDashboardDatasetTest` y `leads-unavailable.test.mjs`.
Véase [arquitectura, recuperación y límites](salesforce-interest-generations.md).

La falta de una generación certificada mantiene indisponible el informe de
Interests. Campañas puede mostrar datos nativos de plataforma bajo condiciones
de cobertura propias; esa respuesta parcial no certifica Interests ni habilita
atribución o resultados comerciales. Véase [Campañas](informe-campanas.md#disponibilidad-con-interests-no-certificado).

Existe además una [vista provisional administrativa](salesforce-interest-generations.md#vista-provisional-administrativa),
desactivada por defecto y separada del informe oficial. Muestra recuentos de
la semilla no certificada para septiembre y octubre de 2026; el total incluye
eliminados, que se muestran también por separado. No representa el KPI activo
ni habilita los endpoints, comparativas o exportaciones oficiales.
Un mes sin filas devuelve cero en ambas columnas; los límites mensuales se
interpretan en `Europe/Madrid`.

La opción de período `october_2026` en `/informes/leads` entrega una vista
administrativa provisional distinta del dashboard oficial. Sólo puede resumir
la F2, F2M y F5 mensuales fijadas para octubre de 2026 después de validar su
misma procedencia, estado terminado y manifiestos. Con flag apagado devuelve
404; sin rol administrador, 403; y con evidencia incompleta, 503. La respuesta
es `no-store, private`. No es Summary, AJAX ni CSV: no carga el dataset oficial
ni habilita KPI, comparativas, auditorías o exportaciones. F2/F2M/F5 son IDs
internos fijos sin parámetros ni configuración que pueda redirigir la fuente.
La pantalla sólo está disponible tras `reports:validate-october-2026-provisional
--confirm`: HTTP lee el snapshot local de la misma revisión de journals y falla
503 si está pendiente, obsoleto o cambia durante la lectura. Este comando no
consulta Salesforce ni habilita el dataset, AJAX, CSV, auditoría o KPI oficial.
El control fail-closed distingue la evidencia fuente de F2M
(`Id|content_hash|is_deleted`) de la de F5 (`Id:is_deleted`); una huella de un
journal no puede validar al otro ni alterar el informe oficial.

Para octubre provisional, los filtros de tipo, delegación, grupo, zona, fuente,
medio, canal y estado se aplican conjuntamente sobre la proyección categórica
validada, no sobre F2/F2M/F5. La página y sus endpoints AJAX sólo leen la misma
clave de proyección y revisión; una revocación, clave no coincidente o revisión
cambiante devuelve 503. Los valores disponibles proceden del snapshot y no
desencadenan SOQL, escaneo de journals ni mezcla con la generación publicada.

### Clasificación de procedencia en la vista provisional de octubre (Fase A)

Durante esa misma validación administrativa, y nunca desde HTTP, se normaliza
`origin_delegation` de la única población apta: filas F2M activas cuyo contenido
sigue coincidiendo con F2. El total clasificado más el no clasificado debe
igualar el recuento de cobertura `eligible_active_matching`. Se reutiliza
`LeadDelegationNormalizer`: expone recuentos por delegación, grupo y zona, pero
no infiere procedencia desde owner, comercial, cuenta ni Lead relacionado.

`Sin clasificar` separa valor vacío de valor no reconocido. Para este último el
snapshot conserva sólo frecuencia y huella SHA-256 estable, sin el valor bruto;
la huella es trazabilidad operativa, no anonimización ni dato de procedencia
utilizable por los informes oficiales. La vista sigue sin mostrar IDs ni
payloads, y esta calidad de clasificación no habilita Summary, AJAX, CSV,
auditoría, comparativas ni KPI oficiales.

Como diagnóstico separado, una consulta SOQL de estado actual comunicada para
esta revisión observó 34.212 Interests: 21.238 con
`IN_Delegacion_Procedencia__c` vacío y 12.974 informado, de los cuales 1.394
contenían literalmente `General`. No es el journal de octubre ni sustituye el
universo F2M válido de 33.811; no es una fotografía histórica ni altera los
agregados publicados. En el snapshot F2M, `General` permanece no reconocido y
se contabiliza sólo como subcontador `raw_literal_general` de los informados no
reconocidos: no se añade alias ni se convierte en delegación, grupo o zona.

### Agregados funcionales de octubre (Fase B)

La misma validación administrativa agrega únicamente las filas F2M activas y
con contenido coincidente por estado, tipo, fuente, medio y canal. Cada una de
las cinco distribuciones debe sumar el universo `eligible_active_matching`; los
valores vacíos se presentan como `Sin clasificar`. El tipo se obtiene con
`LeadRecordTypeNormalizer`; estado, fuente, medio y canal se conservan como
etiqueta materializada, sin completar valores desde otras entidades. HTTP sólo
devuelve este snapshot ya validado y no recalcula los agregados al abrir la
pantalla.

Son agregados provisionales de campos F2M, no los KPI comerciales oficiales.
Comerciales elegibles y delegaciones comerciales permanecen indisponibles por
no existir instantánea histórica de `SalesforceUser`; los KPI de actividad
siguen indisponibles porque no hay estado de relación F5 ni certificación
histórica. No habilitan Dirección, comparativas, Reservas/Ventas, Rendimiento,
Campañas, Llamadas, SEO, CSV ni auditorías oficiales.

La proyección conserva una fila por Interest F2M apto sólo con las categorías
filtrables y normalizadas (sin ID de Interest, `raw_payload` ni datos de
Salesforce sin agregar). Permite total, Convertidos, Descartados, Potenciales,
porcentajes de conversión/descarte y Sin clasificar dentro del resultado
filtrado. Permanecen como `No disponible` comercial elegible, delegación
comercial, owner/no asignados, potenciales sin trabajar, gestionados, llamadas,
formularios, detalle de actividad y comparativas: exigirían una instantánea
histórica de usuarios o evidencia F5/relación certificada que esta fuente no
conserva.

La generación publicada mantiene precedencia: sin período explícito sólo se
usa octubre provisional para administrador, flag activo y proyección vigente
cuando no existe generación oficial disponible. La selección explícita
`period=october_2026` sigue siendo administrativa y no publica ni redirige los
lectores de Reservas/Ventas, Rendimiento, Campañas, Llamadas, SEO, Comisiones o
Stock.

La presentación separa las tarjetas oficiales de las provisionales: estas
últimas identifican su período validado y mantienen como `No disponible` las
métricas sin evidencia histórica, sin reutilizar las descripciones de KPI
oficiales. Al abandonar octubre provisional mediante otro período o al
restablecer filtros, el navegador hace una carga completa del dashboard oficial
con ese período; no conserva filtros, estado ni respuesta AJAX provisional.

La recuperación inicial excepcional puede certificar una candidata mediante
reconciliación del estado actual y F5 nuevo, antes de una publicación separada.
Sus lecturas no prueban una fotografía histórica al watermark; los límites y
las comprobaciones operativas figuran en el documento de generaciones.

La recuperación reanudable conserva candidata, checkpoints y evidencia F2/F5
aislados hasta completar su certificación y la publicación explícita posterior.
Aunque el journal indique F5 asociado o candidata certificada, no se convierte
en fuente del dashboard, métricas, comparativas, AJAX ni CSV mientras el
puntero de publicación no la seleccione. No cambian KPI, filtros, fecha
funcional, redondeo ni comportamiento de indisponibilidad de Interests.

Ajuste de disponibilidad posterior del núcleo: si falta el run F2 o F5 que una
generación publicada referencia, `InterestGenerationReader::f2()/f5()` devuelve
el error controlado `InterestDatasetUnavailable` (503), sin una excepción de
modelo inexistente ni lectura alternativa de la tabla legacy. Es una condición
de evidencia incompleta que requiere revisión, no una certificación automática.

## Período funcional

- La fecha del hecho es `salesforce_interest_versions.functional_created_at`.
- La persistencia es UTC.
- Los períodos visibles y calendarios de negocio se interpretan en
  `Europe/Madrid`; sus límites se convierten explícitamente a UTC antes de
  consultar la columna persistida.
- Los cutoffs se publican con instante y offset inequívocos.

## Estado y lifecycle

Los estados funcionales proceden de `salesforce_interest_versions.status`: Convertido,
Descartado y Potencial mantienen la semántica vigente de KPI. Un estado no
reconocido permanece auditable y no se fuerza a otro estado.

`salesforce_interest_versions.is_deleted = true` excluye el Interest del KPI activo. El
registro sigue disponible en conciliación con `salesforce_deleted_at` y
`deletion_detection_source`; nunca se presenta como incluido en el dataset
activo.

## Tipo

El tipo procede exclusivamente de `salesforce_interest_versions.type`.
`LeadRecordTypeNormalizer` mantiene la normalización compatible para filtros y
KPIs (`tasacion`, `venta`, `venta_con_cambio`), pero ROT-1 no recupera
RecordType ni otro atributo desde Lead.

## Fuente, medio y canal

Los campos vigentes son:

- Fuente: `source`;
- fuente original: `original_source`;
- medio: `medium`;
- canal: `channel`.

`portal` continúa únicamente como alias técnico compatible. El informe ROT-1
no aplica heurísticas basadas en `Portal_Text__c`, `Fuente_Nuevo__c`,
`LEA_SEL_Fuente_Origen__c` o campos Lead equivalentes.

## Comercial y procedencia

- El comercial candidato es el owner del Interest.
- Su elegibilidad se determina con `salesforce_users`: usuario activo y perfil
  `Compra/Venta` o `Comerciales Partner Community`.
- La delegación y zona comercial proceden de la dimensión del owner elegible.
- La procedencia del Interest es `origin_delegation` y se normaliza con
  `LeadDelegationNormalizer` por compatibilidad técnica.

No se usa persona que trabajó el Lead, propietario al descarte ni fallback de
delegación Lead.

## Actividad directa FOUNDATION-5

Solo cuenta actividad:

- del snapshot F5 `completed` ligado al mismo ID y cutoff F2;
- con `relationship_status = resolved`;
- no eliminada;
- relacionada directamente por `WhatId → Interes__c`.

Para Task, `ActivityDate` determina el día funcional en `Europe/Madrid` y
`CreatedDate` solo desempata Tasks del mismo `ActivityDate`; nunca mueve una
Task a otro día. Una Task sin `ActivityDate` cuenta como evidencia existente,
pero no demuestra recencia mediante una fecha inventada. Para Event,
`StartDateTime` es el instante funcional. Los timestamps técnicos de sync no
sustituyen esas fechas.

Potencial sin trabajar significa potencial no asignado técnicamente y sin
actividad directa reciente en los tres días anteriores al corte. Gestionado es
convertido, descartado o potencial con actividad directa reciente.

## Exposición

ROT-1 no ofrece filtro funcional de Exposición porque Interest no dispone de
una dimensión canónica equivalente. No se infiere desde Fuente ni desde Lead.

## Auditoría y permisos

- JSON KPI: `/informes/leads/data/kpi-audit`;
- CSV KPI: `/informes/leads/export/kpi-audit.csv`;
- CSV conciliación: `/informes/leads/export/reconciliation-audit.csv`;
- inspección puntual: `/informes/leads/data/lead-audit?ids[]=...`, máximo 200
  IDs por compatibilidad de ruta.

La auditoría es Interest-centric y no expone nombre de cliente, teléfono,
móvil ni email. Conserva F2/F5, lifecycle, tipo, fuente, medio, canal,
procedencia, owner/comercial, actividad directa y motivos de inclusión o
exclusión. El scope autorizado se aplica antes de devolver filas. Los CSV se
emiten mediante cursor y chunks.

## Operación

El pipeline ROT-1 es:

```bash
php artisan salesforce:sync-interest-reporting
```

Antes del cutover requiere la inicialización explícita revisada del runbook y un F5 alineado
con el mismo run/cutoff. Después, el comando ejecuta el flujo incremental F2 →
F5 con lock y está programado cada hora. El dashboard operativo exige F2/F5
alineados; la métrica ejecutiva técnica `leads`, que solo cuenta Interests
activos, lee F2 de una generación ya certificada junto con F5, sin consultar detalle de actividades para ese conteo.

## Referencia histórica — anterior a las rotaciones y generaciones

Descripción histórica de ROT-1, no aplicable al estado actual de PR #92: Campañas y otros consumidores expresamente fuera de aquella fase continuaban usando
`SalesforceLeadDashboardDatasetService`, `salesforce_leads`,
`salesforce_activities` y `salesforce_lead_activity_summaries` bajo sus reglas
Lead históricas. Esa semántica no aplica al informe `/informes/leads`, a Monthly
Commercial ni a la métrica ejecutiva rotada. ROT-1 no modifica Campañas,
Llamadas ni Reservas/Ventas.

## Presentación durante indisponibilidad de datos certificados

Cuando el backend responde HTTP 503 con el código
`interest_dataset_unavailable`, el frontend de Intereses presenta
un estado accesible de indisponibilidad temporal.

- No muestra indicadores ni tablas procedentes de cargas anteriores.
- Distingue entre carga, indisponibilidad de datos certificados y errores genéricos.
- Permite reintentar manualmente sin polling automático.
- Respeta las solicitudes canceladas y el pinning de generación.
- Mantiene disponible la navegación general de la aplicación.
- No utiliza la tabla legacy ni generaciones pendientes de publicación.

El cambio es exclusivamente visual: no altera las fuentes, filtros,
fórmulas, universos ni trazabilidad de los KPIs.
