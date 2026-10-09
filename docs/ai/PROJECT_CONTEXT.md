# Contexto técnico del proyecto

Actualizado: 2026-10-09.

## ROT-3 — Reservas/Ventas con Opportunity como hecho e Interest como dimensión

- `/informes/reservas-ventas` mantiene Opportunity como hecho y resuelve portal
  en memoria por lotes. El lado directo procede del último run completed de
  `salesforce_opportunity_interest_directs`; el inverso procede del F2 local
  vigente. Solo la coincidencia exacta bidireccional `both_match` habilita
  `Interest.source`.
- La fotografía legacy `salesforce_opportunities.portal_resolved` no es
  autoridad ROT-3. Permanece sin cambios junto con el matching Lead del
  productor compartido porque Campañas aún lo consume; su retirada corresponde
  a ROT-4.
- Direct/F2 se fijan y revalidan por ID, status y cutoff. Evidencia directa
  ausente o obsoleta degrada a reglas propias de Opportunity y queda explícita
  en data quality, sin fallback Lead ni error de negocio ficticio.
- Rendimiento comercial cuenta Interests activos de Venta mediante
  `functional_created_at` (persistencia UTC, mes Europe/Madrid) y atribuye al
  owner actual del último F2. No existe evidencia histórica del owner Interest.
  Las claves `leads` y `lead_to_reservation_*` son aliases internos de
  compatibilidad, no contrato para APIs futuras.

## ROT-2 — atribución CRM de Llamadas mediante Interest

- `/informes/llamadas` conserva Task como hecho, `Task.CreatedDate` como pivote,
  el universo CallObject y todas las reglas operativas. La única atribución CRM
  es `salesforce_calls.what_id = salesforce_interests.salesforce_id` exacta.
- Sync y reproceso cargan únicamente `salesforce_id`, `source` e `is_deleted`
  de los Interests referenciados por cada lote. No existe consulta Salesforce
  `FROM Lead`/`Interes__c`, lookup por WhoId, N+1 ni dependencia runtime F5.
- Task reconocido prevalece; `Interest.source` es fallback exclusivo del Task
  informado pero no clasificable. Lifecycle eliminado permanece auditable y
  no excluye la llamada. Owner y procedencia Interest no alteran identidad ni
  geografía operativas.
- La auditoría existente conserva una fila por Task y publica relación,
  lifecycle y provenance sin PII. La versión vigente es `2026-10-08.1` y el
  historial registra cambios funcionales derivados del Interest aunque no
  cambie la Task. Esa procedencia solo se afirma si cambia la huella Interest
  usada; otros cambios con Task estable quedan `local_classification_changed`.
  `exact_interest` requiere igualdad limpia de ambos IDs y una entrada
  incoherente queda `interest_mismatch` sin usar su source. La conservación
  `historical_preserved` es evidencia auditable, no participación Interest: la
  causalidad exige `exact_interest`, `interest_source_used=true` y cambio real
  en `portal_resolved` o `call_origin`. Overflow, su motivo, duración ajustada,
  equipo, delegación, zona, estado e inclusión no prueban por sí solos
  causalidad Interest.

## ROT-1 — dataset funcional Interest

- `SalesforceInterestDashboardDatasetService` mantiene separado el cutover de
  `SalesforceLeadDashboardDatasetService`: Campañas continúa en Lead, mientras
  `/informes/leads`, Monthly Commercial y Executive consumen Interest.
- Se conservan ruta, permiso `leads` y claves KPI legacy. Un render Interest
  exige F2 `completed` y F5 `completed` ligado al mismo run/cutoff; su caché se
  versiona con F2, F5 y usuarios comerciales. Salesforce solo se invoca en el
  pipeline programado F2 → F5, nunca durante HTTP.
- Los períodos de negocio se definen en `Europe/Madrid`; sus límites se
  convierten explícitamente a UTC antes de consultar `functional_created_at`,
  que se persiste en UTC. Los cutoffs se exponen en ISO-8601 con offset.
- La métrica ejecutiva técnica `leads` es una excepción deliberada al contrato
  operativo: solo necesita F2 estable y cuenta Interests activos, por lo que no
  consulta ni exige F5. Auditorías y dashboard operativo mantienen F2/F5.
- Las exportaciones de auditoría Interest iteran por cursor/chunks. La
  conciliación aplica primero el scope de autorización y después explica la
  exclusión funcional, incluyendo lifecycle eliminado.
- En F5, Task conserva una fecha civil: `ActivityDate` es su único día
  funcional y `CreatedDate` solo ordena Tasks del mismo día. Event usa el
  instante `StartDateTime`. Monthly Commercial deriva sus fechas y
  `fecha_analisis` en `Europe/Madrid`, convirtiendo límites a UTC únicamente al
  consultar `functional_created_at`.

## Autenticación de Informes y recuperación de contraseña

- El acceso real a Informes sigue siendo manual y basado en `ReportUser`; no se
  ha migrado al guard/broker estándar de Laravel ni se usa `App\Models\User`
  para usuarios de Informes.
- La recuperación de contraseña está desplegada y validada operacionalmente en
  producción. Requiere `QUEUE_CONNECTION=database` y un worker Laravel Queue
  dedicado (`laravel-informes-queue-worker`) consumiendo la cola `default`.
- La recuperación de contraseña usa rutas públicas propias, un Job Laravel
  cifrado (`ShouldBeEncrypted`) y una tabla dedicada
  `report_user_password_reset_tokens`. El endpoint HTTP solo valida, aplica
  rate limiting, encola el Job y responde con mensaje genérico; no abre SMTP ni
  consulta existencia de usuario. Los tokens se generan con fuente
  criptográficamente segura dentro del Job, se envían únicamente por Laravel
  Mail, y en base de datos solo se conserva `token_hash` SHA-256, nunca el token
  plano.
- Cada nueva solicitud activa invalida tokens previos no consumidos del mismo
  `ReportUser`. La creación se serializa con `lockForUpdate()` sobre la fila del
  usuario, sin locks globales. El consumo exige token no usado, no caducado,
  usuario activo y email actual coincidente con el hash de email guardado; un
  cambio posterior de email invalida el enlace.
- La expiración centralizada vive en `auth.report_password_reset.expire_minutes`
  y por defecto es de 60 minutos. La solicitud y el consumo aplican rate limit
  con claves que combinan IP y email/token hasheados.
- El cambio de password actualiza `password_changed_at`. El middleware de
  informes compara esa marca con la sesión, por lo que sesiones autenticadas
  antes del cambio se rechazan en su siguiente petición. La cookie remember ya
  queda inválida porque su HMAC incluye el hash actual de contraseña.

## Motor Ejecutivo V1

- `App\Services\Analytics\Executive\ExecutiveMetricRulesEngine` es el core puro
  de EXE-1. Evalúa métricas ejecutivas ya preparadas y es agnóstico de Leads,
  Reservas, Ventas o cualquier módulo futuro.
- El motor no realiza IO: no consulta BD, no usa Eloquent, Salesforce, HTTP,
  Mail, Cache, filesystem, IA, Request, rutas, vistas ni fecha actual.
- El baseline V1 exige D-7, D-14, D-21 y D-28. D-364 es referencia
  complementaria y no altera estado ni dirección. La versión de reglas publicada
  es `executive_metric_rules_v1`.
- La dirección queda definida sin tolerancia: actual mayor que baseline es
  `favorable`, igual es `estable` y menor es `desfavorable`.
- Los estados combinan banda porcentual y puerta absoluta por configuración. Una
  puerta no cumplida degrada al máximo nivel inferior válido o a `correcto`.
  `actual = 0` con baseline evaluable devuelve `critico/desfavorable`.
- `no_evaluable` conserva salud y reason codes, pero no genera alerta de
  negocio. El motor no infiere causas ni recomendaciones; esos campos permanecen
  separados y nulos hasta que exista evidencia o catálogo aprobado.
- `App\Services\Analytics\Executive\ExecutiveDailyDatasetService` es el dataset
  local de EXE-2 para la visión global V1 de Leads, Reservas y Ventas. Resuelve
  el último día cerrado en `Europe/Madrid`, referencias D-7/D-14/D-21/D-28,
  D-364 complementario y MTD hasta el mismo corte.
- EXE-2 reutiliza los servicios canónicos existentes:
  `SalesforceLeadDashboardDatasetService` para Leads y
  `ReservationsSalesDashboardDatasetService` para Reservas/Ventas. No duplica
  thresholds de EXE-1 ni fórmulas de dashboards.
- La salida ejecutiva distingue `0` real de `null` por ausencia de cobertura,
  publica `data_health`, `day_complete`, `data_incident`, `coverage`,
  `source_cutoff` y un `engine_input` listo para EXE-1. No contiene PII ni IDs
  Salesforce y no realiza llamadas Salesforce, HTTP externo, IA, correo,
  scheduler o persistencia diaria.
- Para Reservas/Ventas, la cobertura base ejecutiva se acredita con
  `ReportSyncRun` del dataset `salesforce_opportunities` en modo `period` o
  `all_history` que cubra el rango; un run `modified` no cubre histórico y solo
  puede aportar frescura incremental. `updated_at` local de Opportunities es
  solo diagnóstico. `engine_input.data_health` y `engine_input.day_complete`
  dependen de `current` y D-7/D-14/D-21/D-28, no de D-364 ni del MTD.
- `App\Services\Analytics\Executive\ExecutiveSummaryService` compone EXE-3 para
  `/informes`. Ejecuta EXE-2 una vez, evalúa Leads/Reservas/Ventas con EXE-1 y
  entrega un payload de vista con métricas, alertas, salud agregada y metadatos.
  No crea rutas nuevas, no consulta Salesforce/HTTP, no usa IA, no envía correo
  y no implementa scheduler.
- El Resumen Ejecutivo V1 reutiliza la autorización estratégica existente:
  `ReportUserAccess::canViewReport($request, 'summary')`. Solo Administrador y
  Dirección pueden visualizar `/informes`; los roles operacionales conservan
  su redirección al primer módulo permitido.

## Reconciliación local Interest–Opportunity

- FOUNDATION-4A materializa una vista auditable y unidireccional basada
  exclusivamente en `salesforce_interests.inverse_opportunity_salesforce_id`.
  Presence/lifecycle se valida mediante el snapshot separado
  `salesforce_interest_opportunity_dependencies`, no contra la réplica legacy
  `salesforce_opportunities`. Produce una fila por Interest y solo consulta las
  Opportunities referenciadas. Salesforce ID es la única identidad.
- Cada run exige como fuente el último run FOUNDATION-2, que debe ser completed
  y conservar cutoff. El dependency run debe ser el último, completed, estar
  ligado al mismo ID/cutoff y cubrir cada referencia. Antes de publicar 4A se
  revalidan F2, dependency run y cobertura.
- El dependency sync siembra IDs distinct con SQL, acepta solo IDs REST
  alfanuméricos de 18 caracteres y usa queryAll en lotes máximos de 100.
  Materializa `active`, `deleted`, `missing` e `invalid`, sin PII, dimensiones
  comerciales o raw payload. Su coste depende de las referencias de Interest,
  no de las 42.634 filas Opportunity legacy.
- Relación (`no_inverse`, `inverse_unique`, `inverse_shared`) y presencia
  (`not_applicable`, `present_active`, `present_deleted`, `salesforce_missing`,
  `invalid_reference`, `present_unresolved`) son ejes separados para snapshots
  nuevos. `requires_review` es un
  indicador diagnóstico y no una declaración de cardinalidad o conflicto de
  negocio. Lifecycle de Interest también permanece separado.
- Las métricas `opportunities_present_active`,
  `opportunities_present_deleted`, `opportunities_salesforce_missing`,
  `opportunities_invalid_reference` y `opportunities_present_unresolved` cuentan
  resoluciones/referencias Interest→Opportunity, no Opportunities distintas.
  `distinct_opportunities_referenced` es la métrica explícita de Salesforce IDs
  de Opportunity distintos referenciados por el snapshot.
- Runs y detalle son propios; solo completed publica snapshot. Los detalles
  superseded se retiran por PK en chunks de 1.000 sin borrar métricas históricas.
  El comando es manual, no tiene scheduler ni consumidor funcional.
- Antes de automatizar F2→dependencias Opportunity→4A debe evaluarse un lock
  compartido o serialización equivalente; por ahora cada paso es manual, tiene
  lock propio y revalida su fuente antes de publicar.
- `salesforce_opportunities` no se amplía, no se rellena mediante
  `syncBySalesforceIds()` y no prevalece como autoridad secundaria. Sus runs 4A
  históricos permanecen auditables; su metadata nullable no clasifica nuevas
  resoluciones. El detalle nuevo registra evidence source dependency explícita.
- FOUNDATION-4B conserva separadamente la evidencia confirmada
  `Opportunity.HRM_Interes_Origen__c → Interes__c`. El snapshot directo consulta
  solo Id, lookup, lifecycle, LastModifiedDate y SystemModstamp mediante
  queryAll paginado con cutoff UTC fijo; no copia PII, payloads o dimensiones
  comerciales y no modifica `salesforce_opportunities`.
- La reconciliación 4A↔4B materializa una fila por Opportunity presente en
  cualquiera de las evidencias. Conserva el ID/cutoff del snapshot directo y el
  ID/run/cutoff Interest de 4A, y distingue `both_match`, `direct_only`,
  `inverse_only`, `contradiction`, `inverse_shared` y `unresolved`. Ningún lado
  sobrescribe al otro y una contradicción nunca se resuelve por heurística.
- El lifecycle Opportunity permanece separado como
  `direct_opportunity_is_deleted`, `inverse_opportunity_is_deleted` e
  `inverse_opportunity_presence_status`; diferencias debidas a cutoffs distintos
  son auditables y pueden requerir revisión, pero no se convierten en
  contradicción de identidad. No existe precedencia silenciosa entre 4A y 4B.
  La señal `requires_review` de F4A y sus estados missing/invalid/unresolved se
  conservan en 4B sin alterar la clasificación de identidad.
- El acceso de 4B a `salesforce_interests` queda anclado al run F2 exacto que
  declara 4A. Antes de leer y antes de publicar, el último run por ID de
  `salesforce_interests/salesforce` debe seguir completed y coincidir en ID y
  cutoff. Un F2 posterior running, failed o completed invalida la publicación.
- No se impone unicidad Interest→Opportunity: un Interest directo puede aparecer
  en varias Opportunities y una Opportunity puede tener varias referencias
  inversas. Solo Salesforce ID REST canónico es identidad. Runs y detalles son
  propios, completed es el único snapshot válido, el cleanup es chunked y los
  comandos siguen siendo manuales sin consumidor funcional ni scheduler.

## Evidencia directa Task/Event–Interest

- FOUNDATION-5 mantiene una fuente propia y separada de
  `salesforce_activities` y `salesforce_calls`. Materializa exclusivamente
  relaciones explícitas `Task.What → Interes__c` y
  `Event.What → Interes__c`; Salesforce ID es la única identidad y quedan
  prohibidas inferencias por WhoId, persona, owner, PII, Opportunity o cercanía
  temporal.
- La adquisición remota consta de una consulta queryAll paginada por objeto,
  filtrada mediante `What.Type = 'Interes__c'` y un cutoff UTC fijo. No usa
  `TYPEOF`, prefijos de ID ni lotes remotos por cada Interest. Los `WhatId` de
  cada página se deduplican y resuelven localmente mediante `whereIn` acotados.
- `resolved` acredita que el Interest existe en el snapshot F2 local;
  `interest_not_in_source` conserva una relación directa Salesforce cuyo
  Interest no está en ese F2; `invalid_activity_id` e
  `invalid_interest_reference` conservan evidencia no canónica sin matching
  alternativo. `interest_is_deleted` es nullable para no equiparar ausencia con
  lifecycle activo.
- Task conserva `ActivityDate` y `CreatedDate` por separado. Event conserva
  `StartDateTime` y `CreatedDate`. LastModifiedDate y SystemModstamp son marcas
  técnicas; FOUNDATION-5 no fabrica una fecha funcional ni almacena Subject,
  Description, nombres, payload o PII.
- Cada run registra el ID/cutoff del F2 más reciente, que debe ser completed al
  inicio y seguir siendo exactamente el último antes de publicar. Solo
  completed es snapshot válido; fallos preservan el completed anterior y el
  cleanup elimina detalle superseded por PK en chunks de 1.000.
- El comando es manual, sin scheduler, endpoints ni consumidores funcionales.
  FOUNDATION-5 prepara la fuente para ROT-1, pero no cambia informes ni KPIs.

## Dependencias Lead de Salesforce Interest

- `SalesforceInterestLeadDependencySyncService` construye una fuente local
  mínima y separada para cada migration origin y sus masters. No modifica
  `salesforce_leads`: las tablas propias conservan solo IDs, lifecycle y marcas
  temporales Salesforce, sin PII ni payloads.
- Cada run se ancla al ID y cutoff del run más reciente y estable de
  `salesforce_interests/salesforce`. La fuente se comprueba antes de construir y
  antes de publicar; una sincronización Interest concurrente invalida el run 3A.
- Origins se siembran con SQL bulk y se procesan como cola por PK, sin OFFSET.
  queryAll recibe como máximo 100 IDs REST canónicos de exactamente 18
  caracteres alfanuméricos por llamada y descubre masters recursivamente. Un ID
  de 15 caracteres se clasifica invalid sin consulta ni conversión. Estados
  finales: active, deleted, missing e invalid; pending no puede existir en un
  completed.
- Solo el último run 3A completed y ligado al snapshot F2 current puede alimentar
  FOUNDATION-3. Toda migration origin debe tener fila. Lifecycle y masters 3A
  prevalecen sobre legacy para ese Interest; Leads sin Interest siguen usando
  `salesforce_leads`. La evidencia queda materializada por run y resolución;
  `is_deleted` es false para active, true para deleted y null para
  missing/invalid.
- Publicación, parciales failed, cleanup por 1.000 PKs, lock y riesgo de SIGKILL
  siguen la política de FOUNDATION-3. El comando es manual y no tiene scheduler.
- Antes de automatizar F2→3A→3 debe evaluarse un lock compartido o mecanismo
  equivalente que serialice el pipeline. Esta deuda no cambia los locks locales
  ni el flujo manual actual.
- La certificación shadow del run 3A número 1, ligado al run F2 número 2688 y su
  cutoff `2026-09-29T11:28:13+00:00`, resolvió los 4 migration origins que no
  pertenecían al universo legacy: 3 activos y 1 eliminado. Se descubrió además
  1 master activo; no hubo missing, invalid, pending ni errores. La evidencia se
  obtuvo sin ampliar `salesforce_leads`.

## Reconciliación local Lead–Interest

- `SalesforceInterestReconciliationService` identifica exclusivamente mediante
  Salesforce ID. Para migration origins usa el snapshot FOUNDATION-3A; para
  Leads legacy sin Interest conserva `salesforce_leads`. Ambas fuentes son
  read-only y la salida vive en runs/resoluciones sin PII ni payloads.
- El snapshot se construye por cursor PK y chunks de 200. Una fila representa
  cada Lead y solo se añaden filas de tipo Interest para origen nulo o ausente.
  Las cadenas de master se cargan por fronteras en lote, con límite de 100
  saltos y estados explícitos para ausencia, self-reference, ciclo y exceso.
  El algoritmo mantiene conjuntos separados `loaded`/`expanded`, por lo que un
  master ya cargado también descubre su siguiente salto en una frontera batch.
- Solo las resoluciones asociadas a un run `completed` forman un snapshot
  válido. Un fallo conserva su parcial para diagnóstico bajo `failed`; al
  fallar otro run se elimina el parcial fallido anterior. Al completar un run
  se retiran todos los detalles superseded. Así coexisten como máximo dos
  snapshots detallados —completed vigente y failed reciente— y tras éxito solo
  uno. La limpieza recorre PKs en chunks de 1.000 y transacciones acotadas, sin
  OFFSET ni DELETE monolítico; protege por ID el run actual y, en la ruta
  failed, el último completed válido. El resto de runs finalizados son
  candidatos, incluidos completed residuales. El histórico agregado se conserva.
- La publicación precede al garbage collection. Si el cleanup posterior falla,
  el nuevo run continúa `completed`, su snapshot íntegro sigue válido y
  `cleanup_errors` registra el incidente sin detalle sensible. El siguiente run
  vuelve a intentar los restos superseded aunque su reconciliación falle.
- `has_conflict` procede de una única matriz: contradicción canónica, master
  inválido, origen huérfano, alignment `other` o vínculo todavía al origen
  cuando ya existe master resuelto. Ausencia de Interest, ausencia de migration
  origin y `no_current_lead` no bastan por sí solas para afirmar conflicto.
- La capa no valida Accounts, no infiere el universo `DuplicateReviewed__c`, no
  fusiona Interests por persona/Account y no tiene consumidores funcionales.
- La certificación shadow del run 3 produjo 1.059.486 resoluciones sobre
  1.059.421 Leads y 65 Interests. Los 4 migration origins quedaron `exact` con
  evidencia FOUNDATION-3A y `interest_origin_missing_lead=0`; 3 estaban activos,
  1 eliminado y uno tenía master directo. El único conflicto fue alignment
  `other`, conservado como hallazgo de integridad. `lead_merged=5904` coincide
  con 5.387 masters directos y 517 cadenas.
- Tras publicar el run certificado solo permaneció su detalle; el snapshot
  superseded fue retirado y sus métricas agregadas se conservaron. No existe
  scheduler ni consumidor funcional y producción permanece intacta.
- El máximo de dos snapshots detallados corresponde a rutas gestionadas. Un
  segundo fallo del propio cleanup puede exceder temporalmente esa cota sin
  destruir el último completed. Un `SIGKILL`, caída del host o terminación
  abrupta puede dejar un run `running` y su parcial hasta intervención
  operativa; FOUNDATION-3 no añade recovery automático ni scheduler.

## Sincronización local de Salesforce Interest

- `SalesforceInterestSyncService` mantiene una réplica read-only mediante
  bootstrap completo o incremental UTC por `SystemModstamp`. El incremental
  usa como watermark solo `source_cutoff_at` de un `report_sync_runs`
  completado, aplica un solape configurable y fija el límite superior al inicio
  de cada ejecución.
- Activos y eliminados se procesan por páginas. El endpoint queryAll aporta los
  eliminados confirmados; `SystemModstamp` se reserva como cursor/evidencia de
  borrado y `LastModifiedDate` mantiene su columna semántica independiente.
- Cada array se materializa antes de persistirse en chunks máximos de 200. El
  persister específico evita `upsert()`: precarga identidades/orígenes, usa
  `insert()` bulk para altas y actualiza cambios exclusivamente por PK local.
  Así ningún UNIQUE alternativo de MySQL puede seleccionar otra fila. Los runs
  fallidos no avanzan el watermark y los errores seguros pueden auditarse por
  run, fase y Salesforce ID sin almacenar SQL, bindings, tokens, SOQL ni
  payloads en logs. La FK local elimina la auditoría subordinada al podar el run.
- `salesforce_interests.raw_payload` aplica la política transversal vigente de
  dos meses: se anula el JSON, nunca la fila ni sus columnas normalizadas.
- El comando es manual y no está programado en scheduler. Esta capa no alimenta
  todavía ningún informe ni inicia reconciliaciones o cutover.
- La lectura real se certificó contra Salesforce SandboxRefreshed mediante
  OAuth `client_credentials` y un usuario técnico `Run As` de mínimo privilegio.
  `Interes__c` es consultable pero no permite create/update/delete para ese
  usuario. Los 26 campos requeridos, query estándar, queryAll y `Owner.Name`
  quedaron verificados; no hubo escrituras Salesforce. Los métodos genéricos de
  escritura del cliente no sustituyen esta barrera efectiva de permisos.
- El bootstrap full certificado fijó cutoff UTC
  `2026-09-28T14:31:28+00:00` y procesó 65 registros en dos páginas: 65 altas y
  cero cambios, eliminados, reactivados o errores. El run quedó `completed`, sin
  error y con watermark persistido. El primer incremental real cubrió
  `2026-09-28T14:26:28+00:00` → `2026-09-28T15:06:10+00:00`: los 300 segundos
  entre el watermark previo y el inicio certifican el overlap. Recorrió dos
  páginas sin registros modificados, terminó `completed`, sin error, avanzó el
  cutoff a `15:06:10 UTC` y mantuvo 65 Interests activos, ninguno eliminado y
  cero errores de sync. Quedan certificados full e incremental, watermark,
  overlap, query/queryAll, lifecycle, persistencia, auditoría y read-only.
- Históricamente, cuatro Leads referenciados por migration origin no estaban en
  `salesforce_leads`. FOUNDATION-3A confirmó mediante queryAll que existían en
  SandboxRefreshed —tres activos y uno eliminado— y los materializó en su fuente
  separada; el bloqueo quedó resuelto sin alterar el sincronizador de Interest ni
  el universo legacy.

## Foundation local de Salesforce Interest

- `salesforce_interests` representa de forma aditiva `Interes__c` mediante PK
  local y Salesforce ID externo. Ningún informe consume todavía esta tabla y
  las estructuras legacy conservan íntegramente su semántica.
- La identidad analítica de persona se materializa en el propio Interest:
  Account prevalece sobre Lead y la ausencia se representa con `NULL`. No se ha
  creado una tabla Persona ni se utiliza teléfono, email o nombre como identidad.
- La fecha funcional se materializa como fecha de creación de origen con
  fallback a `Interes__c.CreatedDate`. El cálculo de persona y fecha está
  centralizado en `SalesforceInterestFoundationResolver::materialize()`. El
  evento `SalesforceInterest::saving` lo aplica a escrituras Eloquent como
  safety net. Toda escritura bulk debe invocarlo antes de `insert`/`upsert`, ya
  que esas operaciones no ejecutan eventos Eloquent.
- Las relaciones con Lead, Account, Product2 y Opportunity se conservan como
  Salesforce IDs sin FK locales. La foundation no incorpora acceso Salesforce,
  SOQL, sincronización, reconciliación ni cambios de dashboard.

## Autoridad Salesforce y lifecycle vigente

- El refactor técnico de campos Salesforce está desplegado. Leads resuelve
  fuente, canal, medio y delegación de forma independiente: el campo nuevo gana
  cuando no es null, vacío o whitespace; cualquier placeholder no vacío es
  autoritativo y el fallback conserva la prioridad legacy de cada informe.
- Campañas mantiene su gate legacy y, una vez admitido el Lead, resuelve las
  cinco parejas UTM nuevo → legacy. Llamadas separa clasificación visible de
  reglas operativas. Opportunities mantiene la precedencia Opportunity
  conclusiva → Lead relacionado → fuente de Opportunity → fallbacks existentes.
  El índice local de teléfonos solo descubre Lead IDs; Salesforce vivo sigue
  siendo la fuente funcional final.
- El lifecycle de Opportunities está reconciliado en producción.
  `query_all_deleted` representa borrado confirmado y se excluye de los
  consumidores dependientes de Opportunity; `presence_reconciliation_missing`
  es una ausencia diagnóstica y continúa reportable. Solo el mapper completo de
  `SalesforceOpportunitySyncService` puede reactivar una fila.
- El API Name real es `SystemModstamp`. Su valor se guarda exclusivamente en
  `salesforce_deleted_at` como evidencia técnica de modificación detectada, no
  como fecha contractual de borrado. `salesforce_last_modified_at` conserva la
  semántica de `Opportunity.LastModifiedDate`.
- Fase 7A y Fase 7B aportan herramientas históricas terminadas, pero no consta
  su ejecución. Esa operación pendiente no reabre el refactor de código.

## Resumen Dirección de Reservas / Ventas

- El Resumen separa Producción, Cohorte de creación y Estado actual. Producción
  imputa reservas por `reservation_date` y ventas firmadas no perdidas de tipo
  Venta/Cambio por `cv_signed_date`; la cohorte se fija siempre por
  `created_date` y muestra los resultados actuales de esas oportunidades.
  Tasación, otros tipos y tipo ausente no son venta producida, sin alterar el
  KPI legacy. Reservas vivas actuales de todas las fechas es contexto
  independiente.
- Los períodos usan `[start,end)` y publican metadata técnica aditiva de inicio,
  fin exclusivo y timezone, manteniendo las fechas visibles y las claves JSON
  legacy. El criterio temporal legacy no gobierna el Resumen, pero sigue activo
  en las pestañas de desglose.
- La deduplicación conserva vehículo + fecha de hito y fallback a Opportunity.
  Las clasificaciones contradictorias de una venta se excluyen y se auditan
  como incidencia, sin elegir por orden técnico. La auditoría JSON/CSV admite
  `cv_firmados_periodo` sin añadir PII.
- El dataset usa `reservas-ventas-dashboard-v7`; el catálogo de filtros une las
  dimensiones relevantes de Producción y Cohorte después de aplicar el scope
  de servidor. La identidad de caché usa fechas canónicas estables y el payload
  conserva sus límites técnicos exactos. No cambia la caché V4 de Rendimiento
  comercial.

## Rendimiento comercial de Reservas / Ventas

- Permisos vigentes: Administrador tiene lectura global, auditoría y edición del
  objetivo; Director tiene lectura global y auditoría con objetivo de solo
  lectura; Area Manager tiene lectura limitada en servidor a su zona, objetivo
  de solo lectura y sin auditoría. Los parámetros HTTP no amplían su ámbito y,
  sin zona configurada, recibe 403. No se amplía el acceso a otros roles.
- El objetivo es un único valor por mes aplicado individualmente a todos los
  comerciales evaluables; Zona, Delegación y Comercial no cambian el objetivo
  almacenado y solo Administrador puede editarlo.
- La mecánica retroactiva quedó validada satisfactoriamente en producción, en
  solo lectura y sin PII: una Opportunity permanece única y conserva su fecha de
  reserva original, el estado actual puede reclasificar el mes original como
  caída y excluirlo del cumplimiento, y la cancelación histórica permanece en
  el mes de `transitioned_at`. La comprobación valida la mecánica, no certifica
  todo el histórico.

- `CommercialPerformanceDatasetService` agrega cuatro meses de actividad local
  por fecha propia de Lead, Opportunity, reserva, firma y cancelación; la unidad
  es Salesforce User ID + mes, nunca delegación, y no altera la cohorte legacy.
- La evaluación mensual se resuelve después de agregar hechos: exige identidad,
  actividad real (Lead, Opportunity, reserva total, venta válida o caída) y
  asignación `observed` o `bootstrap_approved`. El roster sin actividad solo se
  conserva como exclusión auditable. La comparación de equipo y ranking usan
  exclusivamente esas filas evaluables, se preagrupan en memoria por delegación
  tras Zona/Delegación y antes de Comercial; Comercial solo limita las filas
  visibles. El cumplimiento global se expone en `universe`, separado de
  `summary`, e Incidencia de datos no se renderiza como comercial.
- El selector de Rendimiento comercial abre en el último mes natural cerrado de
  `Europe/Madrid`; el mes actual sigue seleccionable y se identifica en la UI
  como resultado provisional. Esta distinción es solo de presentación: objetivo
  completo, cumplimiento, semáforo, ranking y comparativas no se prorratean ni
  proyectan.
- La base cacheada usa `reservas-ventas-commercial-performance-base-v4` y no
  persiste objetos: `rowsByMonth` y calidad son arrays de escalares. Las
  Collections se reconstruyen en presentación, manteniendo
  `cache.serializable_classes=false` también con stores persistentes. La calidad
  se conserva por mes de hito/grupo y el payload público expone solo el mes
  seleccionado. Auditoría recibe Zona, Delegación y Comercial y los aplica en
  memoria tras atribución, antes de ordenar/paginar.
- `salesforce_opportunities` conserva lifecycle mediante `is_deleted`,
  `salesforce_deleted_at` y `deletion_detection_source`. El modelo aplica scope
  activo por defecto solo para `query_all_deleted`; una ausencia conciliada no
  equivale a borrado y continúa reportable. Solo el mapper completo del sync
  canónico puede reactivar una fila. Stock puede localizarla sin scope, pero no
  limpia lifecycle; invalida snapshots por borrado confirmado y mantiene
  `unchecked` una ausencia real de la réplica. Rendimiento excluye también
  transiciones pertenecientes a una Opportunity con borrado confirmado.
- `salesforce_opportunity_stage_transitions` materializa cambios demostrables de
  `OpportunityHistory` hacia Cerrada Perdida con estado de calidad; solo cuentan
  si la reserva no es posterior. `salesforce_opportunity_history_sync_intervals`
  acredita cobertura continua antes de devolver cero cancelaciones. El mes
  actual termina en el último cutoff diario certificado; meses cerrados exigen
  el mes completo. Una Opportunity local ausente invalida el intervalo para KPI
  y queda auditada, sin transformar la dependencia en cero. Durante el comando,
  los IDs locales ausentes se recuperan en lotes mediante el mapeo canónico de
  Opportunities y se reclasifican en la misma ejecución.
- `commercial_delegation_snapshots` mantiene intervalos observados por Salesforce
  User y un bootstrap de negocio distinguible (`business_bootstrap_2026_04`)
  desde 2026-04-01 cuando la primera asignación fiable carece de contradicciones.
  Bootstrap y observación son evaluables; los períodos sin intervalo completo y
  estable quedan no certificables. Los cambios abren una alerta operacional y
  nunca fuerzan una delegación mensual. El roster conserva comerciales sin
  actividad únicamente para explicar su exclusión; no forman parte del universo
  evaluable final. La captura periódica solo crea observaciones; el bootstrap se
  solicita una vez y de forma explícita con
  `--bootstrap-performance-history`. Auditoría y calidad distinguen
  `observed`, `bootstrap_approved` y `not_certifiable`, además de publicar por
  separado el inicio evaluable, observado y bootstrap.
  Una reejecución solo considera usuarios cuyo primer snapshot observado tenga
  el `observed_from` mínimo global de la fotografía inicial; altas posteriores
  se informan como `not_initial_cohort` y nunca se retroatribuyen.
- `commercial_performance_monthly_targets` materializa el objetivo efectivo al
  primer uso y distingue default congelado de edición explícita.
- El scheduler monitorizado ejecuta `salesforce:sync-opportunities --days=2
  --modified` a las 07:10 Europe/Madrid. Solo el sync mensual captura intervalos
  de delegación y su scheduler no ejecuta bootstrap. El sync refresca por ID usuarios conocidos que salgan del
  perfil comercial, mantiene su `IsActive` real y cierra su snapshot. Render,
  auditoría y filtros consumen solo tablas locales.

## Comisiones financieras

- `FinancialCommissionDashboardService` construye un unico universo mensual de
  Opportunities firmadas Venta/Cambio y deriva resumen por responsable,
  agregados por delegacion, detalle por Opportunity y diagnostico.
- El responsable financiero se identifica mediante claves estables de zona
  (`zona_carlos`, `zona_cristina`, `zona_irene`, `zona_nuria`). `OwnerId` sigue
  siendo el comercial propietario y no selecciona reglas financieras.
- Carlos/Cristina usan los tres bloques configurables. Desde 2026-06,
  Irene/Nuria usan exclusivamente `(comision financiera - descuento) * 0.005`.
- Una zona explicita desconocida no usa fallback: con impacto economico deja el
  payload no conciliado y bloquea exportaciones; sin impacto queda excluida y
  visible solo en el diagnostico autorizado.
- El sincronizador de Opportunities permite un unico reintento sin el email
  opcional de Account cuando Salesforce rechaza la consulta; nunca elimina
  campos financieros ni consulta Salesforce durante el render.

## SEO/Analytics

- `App\Services\SeoAnalytics` separa clientes HTTP, sincronización/persistencia
  y dataset de render. `GET /informes/seo-analytics` solo lee BD local y config.
- Search Console conserva agregados diarios exactos finales separados de
  rankings top 7/28/90 reemplazables. Salesforce SEO usa una proyección propia
  de `Medio_origen__c = 'Orgánico'`, sin alterar `salesforce_leads.medio_origen`.
- GA4 persiste `keyEvents` Organic Search/web como decimal, con totales ALL/ESP
  separados del detalle España por evento. Usa timezone de property, lag
  operativo y rolling refresh; nunca se suma con Leads Salesforce. Cada página
  Data API supera una quality gate de thresholding, data loss y sampling antes
  de que una ausencia pueda convertirse en cero. Sus strings `TYPE_FLOAT` se
  normalizan a escala 6 mediante aritmética decimal textual, sin redondeo ni
  conversión IEEE-754.
- Las fuentes se sincronizan por comandos independientes y scheduler monitorizado;
  cada cutoff visible procede del último `ReportSyncRun` completado. La
  disponibilidad de KPI exige además cobertura diaria local completa.
  Resumen/Tráfico usan el cutoff común mínimo; rankings usan el periodo propio
  de Search Console y su property configurada.
- Salud técnica está implementada como monitor acotado y persistido. El motor
  comparativo transversal persiste snapshots diarios SEO con D-7/D-14/D-21/
  D-28, mínimo 3/4 y D-364 opcional usando el cutoff propio de cada fuente. El
  Lote 6 mantiene esos hechos intactos y añade evaluaciones versionadas locales,
  configurables por Administrador/Director, sin scoring IA. SISTRIX AI permanece
  fuera. Contrato: `docs/ai/SEO_ANALYTICS.md`.

### Snapshots analíticos transversales

- `App\Services\Analytics\SameWeekdayComparisonEngine` es un core sin queries,
  modelos ni conceptos SEO. `same_weekday_v1` conserva ausencia distinta de
  cero y deja sin porcentaje las referencias cero.
- `analytical_metric_snapshots` es una proyección transversal e idempotente con
  rolling upsert, no un histórico append-only. SEO es el primer adaptador con
  seis métricas y properties aisladas mediante una identidad técnica y su hash
  SHA-256.
- `seo:build-analytical-snapshots --days=30` carga una serie por fuente local,
  hace rolling rebuild sin borrar historia y se ejecuta a las 06:15 Madrid.
  Estos snapshots quedan fuera de pruning hasta aprobar una política propia.
  El builder admite 1–90 días y su default operativo consume la configuración
  interna de 30; los comandos de ingesta mantienen su contrato separado de
  1–480 días y scheduler de 120.

### Evaluaciones analíticas SEO

- `AnalyticalEvaluationEngine` es un core transversal sin Eloquent, Request ni
  conceptos SEO. Recibe snapshot y regla resueltos y devuelve estado, dirección,
  banda y reason code cerrados.
- `analytical_rule_sets` y `analytical_metric_rules` conservan versiones
  inmutables; `analytical_metric_evaluations` mantiene auditoría por snapshot y
  versión. `seo_rules_v1` contiene exactamente seis reglas.
- Cada evaluación captura sus cuatro magnitudes factuales, evaluabilidad, motivo
  y fingerprint SHA-256. Una revisión rolling invalida temporalmente la unión
  visible hasta reevaluar; las señales históricas leen la captura, no el
  snapshot mutable. Recalcular solo timestamps o D-364 no invalida v1.
- La configuración vive en BD, no `.env`. Solo Administrador/Director pueden
  crear la siguiente versión; cada cambio exige motivo y reevalúa el estado
  actual sin reescribir el histórico.
- `seo:evaluate-analytical-snapshots` es local, idempotente y se ejecuta a las
  06:30 Madrid. El panel de señales limita la lectura a 30 días/50 filas y usa
  las properties actualmente configuradas.

### Correo ejecutivo SEO

- `SeoExecutiveDailyReportDatasetService` compone solo las seis comparativas,
  la frescura compartida de Search Console/Salesforce/GA4 y Salud técnica
  factual. No construye el dashboard descriptivo ni llama proveedores.
- Los destinatarios (1–10) viven en `seo_executive_email_settings`; solo
  Administrador/Director los gestionan. SMTP y remitente permanecen en `MAIL_*`.
- `seo_executive_daily_reports` congela un payload por fecha y
  `seo_executive_email_deliveries` aporta ledger idempotente individual. Un
  retry no reconstruye el contenido ni reenvía estados `sent`/`sending`. El
  retorno correcto del SMTP cierra la fase reintentable: si la confirmación
  local de `sent` queda incierta, el ledger conserva `sending` y requiere
  reconciliación manual.
- `seo:send-executive-daily-email` usa Laravel Mail síncrono, registra
  `ReportSyncRun` y se ejecuta a las 08:00 Madrid con lock de 30 minutos. Solo el
  fallo técnico del scheduler participa en `OperationalAlert`.

### Salud técnica SEO

- Un comando programado monitoriza únicamente Home, configuración estratégica
  y páginas del ranking local Search Console; no recorre enlaces ni infiere URLs
  de Stock.
- Robots y sitemap describen infraestructura y membership del conjunto
  seleccionado. Los checks HTTP diarios persisten hechos técnicos, no severidad
  analítica.
- Todo fetch usa allowlist exacta, host DNS ASCII canónico, todas las IP
  globales, proxy desactivado, pin `CURLOPT_RESOLVE`, TLS verificado y redirects
  manuales. La lectura streaming es acotada y un body parcial nunca acredita
  conclusiones negativas de noindex/canonical. El dashboard sigue leyendo
  exclusivamente BD/config y queda fuera del common cutoff.

## Design System de informes

- `resources/css/reports/design-system.css` define tokens `--report-ui-*` y
  primitives `report-ui-*` aislados del CSS legacy. Se carga antes del CSS del
  Application Shell y no contiene selectores globales de elementos o clases
  genéricas.
- Los componentes Blade visuales viven en `resources/views/components/reports/ui`.
  Son presentacionales, conservan el escape de Blade y no consultan datos.
- Los patrones analíticos compartidos viven en el mismo bundle: KPI strip, data
  panel, section header, tabla densa, tabs lineales, filter bar, highlight neutral
  y source status. No incluyen datos, comportamiento JavaScript ni taxonomías
  funcionales propias.
- Resumen y SEO/Analytics son las primeras pantallas migradas. Los seis
  dashboards conservan sus estilos internos hasta lotes específicos.
- Los estados analíticos oficiales son `ok`, `observation`, `deviation`,
  `critical` y `not-evaluable`; cualquier clave desconocida usa el último como
  fallback seguro. El contrato operativo está en `docs/ai/DESIGN_SYSTEM.md`.

## Application shell de informes

- Las paginas autenticadas de informes y administracion usan el componente
  Blade anonimo `x-reports.app-shell`. El componente centraliza `head`,
  branding, topbar, usuario, logout, sidebar y contenedor de contenido; cada
  pagina aporta titulo, modulo activo, clases de `body`, assets y contenido.
- `resources/css/reports/app-shell.css` consume los tokens compartidos y mantiene
  la estructura responsive. No existe modo oscuro todavia. El contenido analitico
  no recibe un `max-width` global; los limites de lectura deben seguir siendo
  especificos de cada pagina cuando sean necesarios.
- `resources/js/reports/app-shell.js` gestiona exclusivamente la sidebar. En
  escritorio persiste el estado abierto/cerrado en `localStorage`; en movil es
  un drawer superpuesto. Los fallos de almacenamiento se ignoran de forma
  segura y no existe estado de navegacion en servidor.
- La navegacion se resuelve en servidor mediante `ReportUserAccess` y se
  materializa una sola vez por request. Ocultar un enlace no sustituye al
  middleware o control de autorizacion de la ruta.

## Operación transversal

- GitHub Actions es la CI canónica: PHP 8.4, Composer bloqueado, audit runtime
  `composer audit --locked --no-dev`, SQLite de testing, suite, Pint, Vite y
  `git diff --check` con permisos de solo lectura.
- Producción no dispone de Node/npm; despliega `public/build` ya construido.
- Las APIs internas entrantes usan credenciales de entorno identificables por
  integración/versión, rate limit por integración y audit log diario sin body.
- `OperationalAlert` centraliza alertas técnicas deduplicadas visibles solo a
  administradores. No se usan email, Slack, SMS ni Salesforce como canales.
- `reports:prune-transversal-data` es la única entrada de retención de datos:
  chunks, dry-run e índices dedicados. Solo anula los ocho payloads sin lecturas
  funcionales. Los cinco payloads aún consumidos quedan bloqueados y documentados.
- `/up` es liveness, no readiness de dependencias.
- En producción, Laravel está detrás de terminación TLS y solo confía en `X-Forwarded-*` de las IP/CIDR declaradas en `TRUSTED_PROXIES`. El proxy debe enviar `X-Forwarded-Proto: https`; no se usa confianza global ni `forceScheme`.

## Convenciones de exportación auditada

- Los CSV con valores compuestos deben usar `App\Support\CsvValueSerializer`;
  no deben pasar arrays u objetos directamente a `fputcsv`.
- Las exportaciones voluminosas deben escribir directamente al stream mediante
  cursor o lotes, con ámbitos resueltos en servidor antes de producir filas.
- KPI, JSON de auditoría y CSV deben consumir la misma resolución de cohorte o
  de evento, según la semántica temporal explícita de la métrica.
- Los CSV estándar de auditoría no deben seleccionar datos personales que no
  sean imprescindibles para explicar la métrica.
# Comisiones: cierres y responsables temporales

Los cierres económicos de Comisiones son independientes para `commercials`, `delegations`, `area_manager`, `financials`, `call_center` y `contact_center`. Los responsables de delegación se sincronizan en `salesforce_delegation_manager_history`; el dashboard nunca consulta Salesforce bajo demanda.
