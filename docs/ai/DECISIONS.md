# Decisiones técnicas

## 2026-09-30 — Motor ejecutivo V1 puro y dirección sin tolerancia

- EXE-1 se implementa como un core puro en
  `App\Services\Analytics\Executive\ExecutiveMetricRulesEngine`. No consulta BD,
  no usa Eloquent, HTTP, Salesforce, Cache, filesystem, Mail, IA ni fecha actual.
  Recibe valores y configuración ya preparados; EXE-2 será quien adapte fuentes
  canónicas locales cuando se apruebe.
- La versión de reglas queda fijada como `executive_metric_rules_v1` y viaja en
  cada resultado para que calibraciones futuras no reinterpreten históricos.
- La dirección queda definitivamente definida sin tolerancia: `actual >
  baseline` es `favorable`, `actual == baseline` es `estable` y `actual <
  baseline` es `desfavorable`. No existe dirección `revisar` en V1.
- El baseline exige las cuatro referencias D-7, D-14, D-21 y D-28. D-364 es
  complementaria y no participa en estado, dirección ni alerta.
- Los estados se calculan combinando magnitud porcentual y puertas absolutas.
  Un nivel solo se alcanza cuando se cumplen ambas condiciones; si no se cumple
  su puerta absoluta, se degrada al mayor nivel inferior válido y, si ninguno
  aplica, a `correcto`. La regla especial `actual = 0` con baseline evaluable
  prevalece y devuelve `critico/desfavorable`.
- `no_evaluable` conserva salud del dato y reason codes estables, pero nunca
  genera alerta de negocio. Las incidencias de datos tampoco se transforman en
  alertas de negocio.
- EXE-1 no infiere causalidad ni recomendaciones de negocio. El contrato separa
  `confirmed_cause`, `possible_cause_to_review` y `recommended_action_key`, que
  permanecen `null` hasta que exista evidencia/catálogo aprobado.

## 2026-09-30 — Reset dedicado para `ReportUser`

- No se usa el broker estándar `users` ni `password_reset_tokens` para Informes,
  porque el login productivo opera sobre `report_users` mediante sesión manual y
  `App\Models\User` pertenece a otro dominio. Convertir `ReportUser` al flujo
  nativo habría ampliado la superficie de regresión del login.
- Se crea una tabla dedicada `report_user_password_reset_tokens` con FK a
  `report_users`, hash SHA-256 del token, hash del email normalizado, expiración
  y marca de consumo. Una nueva solicitud consume tokens previos del mismo
  usuario para evitar enlaces antiguos válidos.
- El token plano solo existe en memoria y en el enlace enviado por Laravel Mail.
  No se registra en logs ni se persiste. El formulario de solicitud siempre
  devuelve el mismo mensaje público para usuarios activos, inexistentes o
  inactivos.
- El envío de email no se realiza en el request HTTP. La solicitud encola un Job
  Laravel dedicado para cualquier email válido no limitado, y el Job es quien
  comprueba existencia/actividad, genera token y envía. El Job implementa
  `ShouldBeEncrypted` para que el email del solicitante no quede en claro en el
  payload persistido de la cola.
- La creación de tokens bloquea la fila del `ReportUser` con `lockForUpdate()`
  antes de consumir tokens previos y crear el nuevo, evitando dos tokens activos
  ante solicitudes concurrentes del mismo usuario sin bloquear usuarios
  distintos.
- Se añade `password_changed_at` a `report_users` para invalidar sesiones de
  Informes anteriores al cambio sin rediseñar el sistema de autenticación. Las
  cookies remember previas ya quedan inválidas al cambiar el hash de password,
  porque el HMAC existente lo incluye.

## 2026-09-30 — Fuente separada de dependencias Opportunity para FOUNDATION-4A

- Las Opportunities requeridas por
  `salesforce_interests.inverse_opportunity_salesforce_id` se consultan mediante
  queryAll read-only y se materializan en una fuente mínima separada ligada al
  ID/cutoff F2 exacto. Salesforce ID REST canónico de 18 caracteres es la única
  identidad; no se usan Lead, Account, vehículo, PII ni heurísticas.
- `salesforce_opportunities` no se amplía ni se rellena con
  `syncBySalesforceIds()`. La auditoría shadow demostró 20 referencias activas
  en SandboxRefreshed ausentes de la réplica legacy y una fila legacy de control
  ausente del mismo org; no se presume la causa, pero legacy deja de ser
  autoridad de presence/lifecycle para 4A.
- Cada reconciliación nueva conserva `opportunity_dependency_run_id` y cada
  detalle `opportunity_evidence_source`. El dependency snapshot prevalece sobre
  cualquier fila legacy coincidente o contradictoria. Los runs 4A históricos
  permanecen intactos mediante columnas legacy nullable.
- `missing` Salesforce se proyecta como `salesforce_missing` e ID inválido como
  `invalid_reference`; ambos requieren revisión. `active` no requiere revisión
  por su mera ausencia legacy. FOUNDATION-4B continúa separada y bloqueada.

## 2026-09-29 — Fuente separada para dependencias Lead de Interest

- Los Leads históricos requeridos por `migration_origin_lead_id` no se añaden a
  `salesforce_leads`, porque ampliar esa réplica alteraría silenciosamente
  universos legacy. Se materializan en tablas 3A separadas, sin PII, mediante
  queryAll read-only y Salesforce ID como única identidad.
- FOUNDATION-3A acepta únicamente Salesforce IDs REST canónicos de 18
  caracteres alfanuméricos. Los IDs de 15 caracteres se materializan como
  `invalid`, no se envían a Salesforce y no se convierten localmente, porque la
  fuente FOUNDATION-2 procede de respuestas REST de 18 caracteres.
- El snapshot 3A queda anclado por ID y cutoff al run exacto de FOUNDATION-2. Se
  exige que el run F2 y el run 3A más recientes estén completed y coincidan; la
  cobertura incompleta o un cambio concurrente bloquean la publicación o la
  reconciliación.
- Para una migration origin, lifecycle y cadena master de 3A prevalecen sobre
  `salesforce_leads`; para un Lead sin Interest continúa la autoridad legacy. La
  procedencia queda materializada en `lead_evidence_source` y
  `lead_dependency_run_id`. El lifecycle conserva `null` para missing/invalid;
  la presencia de una fila legacy activa no convierte esa ausencia de evidencia
  autoritativa en `false`.
- Los runs 3A conservan metadata aunque `report_sync_runs` se pode; por eso
  `source_interest_sync_run_id` no usa FK. La FK desde reconciliation run al run
  3A sí es estable porque la política elimina detalles superseded, no metadata
  de runs 3A.

## 2026-09-28 — Snapshot local de reconciliación Lead–Interest

- La identidad histórica fuerte es únicamente el Salesforce ID de
  `migration_origin_lead_id`; su evidencia procede de FOUNDATION-3A sin ampliar
  `salesforce_leads`. Se prohíben heurísticas de PII, Account o afinidad temporal
  y no se infiere `DuplicateReviewed__c`.
- Se usan runs dedicados porque `report_sync_runs` expresa ventanas/cutoffs que
  no existen en este cálculo local. Las resoluciones se ligan al run: solo
  `completed` publica snapshot y `failed` mantiene parciales diagnósticos.
- Estados de relación: `exact`, `lead_without_interest`,
  `interest_origin_missing_lead`, `interest_without_migration_origin`. Estados
  master: `none`, `direct`, `chain`, `missing`, `self_reference`, `cycle`,
  `depth_exceeded`, `not_applicable`. Persona: `coherent_account`,
  `coherent_lead`, `coherent_none`, `inconsistent`, `not_applicable`.
- El origen histórico nunca se sustituye por el master. `immediate_master` y
  `resolved_master` son evidencia separada; cadenas se recorren con límite y
  ciclos protegidos. La expansión distingue nodos cargados de nodos ya
  expandidos: un master presente en el chunk todavía se inspecciona para
  descubrir por lote el siguiente salto externo. `converted_account_id` no es
  una regla contractual.
- La idempotencia es lógica: un run completado reemplaza las filas del snapshot
  completado anterior, mantiene métricas históricas y nunca confunde parciales
  fallidos con el estado válido.
- La regla única de conflicto marca: persona `inconsistent`; master `missing`,
  `self_reference`, `cycle` o `depth_exceeded`; origen de migración sin Lead
  local; alignment `other`; y `matches_origin` cuando existe master válido
  `direct/chain`. No marca por sí sola `lead_without_interest`,
  `interest_without_migration_origin` ni `no_current_lead`.
- El detalle materializado está acotado: durante un fallo pueden coexistir como
  máximo el último snapshot `completed` y el parcial del último `failed`; tras
  éxito queda solo el completed actual. Runs anteriores conservan métricas. La
  limpieza protege por ID el run actual y, si éste falla, el último `completed`;
  elimina detalles del resto de runs finalizados, con cursor por PK, lotes de
  1.000 y transacciones pequeñas. Así la ruta `failed` también reintenta restos
  de completed superseded dejados por un garbage collection anterior.
- Publicación y garbage collection son fases separadas. Una vez marcado el run
  `completed`, un fallo posterior de cleanup incrementa `cleanup_errors` pero
  no degrada el run ni invalida su snapshot íntegro; una ejecución posterior
  —termine completed o failed— reintenta los detalles superseded restantes. Si
  ese segundo cleanup también falla, la cota física puede excederse de forma
  temporal sin poner en riesgo el último snapshot válido.

## 2026-09-28 — Persistencia inequívoca del sync de Interest

- `salesforce_interests` no usa `upsert()`: MySQL/MariaDB ignora `uniqueBy` y
  puede resolver `ON DUPLICATE KEY UPDATE` mediante cualquiera de sus índices
  UNIQUE. Con `salesforce_id` y `migration_origin_lead_id` independientes, eso
  podría actualizar un Interest distinto al entrante.
- El persister específico precarga ambos identificadores por chunk, rechaza
  colisiones antes de escribir, inserta altas mediante `insert()` bulk y dirige
  cada modificación real por PK local. `migration_origin_lead_id UNIQUE` se
  conserva como defensa final ante carreras; una violación siempre falla el run.
- Un fallo de la operación inicial del chunk es fatal. El replay individual es
  exclusivamente diagnóstico: puede conservar escrituras parciales y auditar
  IDs, pero nunca convierte el run original en completado.

## 2026-09-25 — Watermark y lifecycle del sync de Interest

- `report_sync_runs` es la autoridad del watermark: solo un run
  `salesforce_interests/salesforce` completado puede aportar
  `source_cutoff_at`. Cada ejecución fija su cutoff UTC al comenzar; el
  incremental vuelve a consultar desde watermark menos un solape configurable.
- La lectura activa y de eliminados es paginada. Eliminados usa queryAll y
  materializa `query_all_deleted`; una observación activa posterior limpia el
  lifecycle. `SystemModstamp` gobierna la ventana y documenta la detección de
  borrado, pero no sustituye `LastModifiedDate`.
- Cada fila se materializa antes de persistirla. El timestamp local de sync no
  participa en detección de cambios y las páginas se dividen en chunks locales
  de 200 antes de la escritura.
- Los errores por registro viven en una tabla aditiva vinculada lógicamente al
  run mediante FK local con cascade. Persistencia usa mensaje genérico y código
  técnico acotado; remoto usa el sanitizador común. No se conservan SQL,
  bindings, respuestas completas, SOQL, tokens, payloads ni datos de contacto.

## 2026-09-25 — Interest como capa local aditiva y materializada

- Se crea `salesforce_interests` en lugar de reinterpretar
  `salesforce_leads`. Mantiene PK local + Salesforce ID externo y no usa FK a
  tablas Salesforce locales, porque futuras cargas podrán llegar fuera de orden
  o contener referencias eliminadas, fusionadas o aún no sincronizadas.
- No se crea tabla Persona. Cada Interest materializa
  `canonical_person_type` y `canonical_person_salesforce_id`: Account prevalece
  sobre Lead; si ambos faltan, ambos quedan `NULL`. Esto evita matching inseguro
  por contacto y permite agrupar varios Interests sin fusionarlos.
- `functional_created_at` materializa
  `COALESCE(IN_Fecha_Creacion_Origen__c, CreatedDate)`. Así no se recalcula en
  cada request ni se usa la fecha técnica local como fecha funcional. Null,
  vacío y whitespace se consideran ausencia de fecha de origen.
- `SalesforceInterestFoundationResolver::materialize()` es la única API
  canónica para derivar persona y fecha. El evento `saving` del modelo es solo
  un safety net para `create`/`save`/`update`. Los futuros procesos bulk deben
  materializar cada array antes de `insert`/`upsert`, porque Eloquent no emite
  eventos de modelo para esas operaciones; no se sustituirá el procesamiento
  bulk por escrituras registro a registro.
- Estado, tipo, fuente, medio y canal se almacenan sin enum/check ni
  normalización implícita. Valores nuevos se conservan y su control de calidad
  pertenece a fases posteriores.
- El Lead de migración es único y nullable conforme al contrato Salesforce.
  Opportunity inversa no es única, para no ocultar incoherencias futuras.
- Se aplazan columnas de descarte hasta confirmar sus tipos y los índices de
  dimensiones hasta disponer de datasets y planes de consulta reales.

## 2026-09-23 - Contrato temporal y dual de Resumen Dirección

Resumen Dirección distingue de forma estable **Producción del período** de la
**Cohorte de oportunidades creadas en el período**. Producción imputa Reservas
por `reservation_date` y Ventas de RecordType Venta/Cambio por
`cv_signed_date`; la cohorte pertenece siempre a `created_date` y sus reservas,
caídas y firmas describen el estado o resultado actual de esas oportunidades,
aunque el hito sea posterior. Tasación, otros tipos y tipo ausente no son venta
producida, sin redefinir por ello el KPI legacy `kpis.cv_firmados`. Reservas vivas
actuales de todas las fechas permanece como contexto separado y no se suma a
ninguno de los dos bloques.

Todos los rangos internos son semiabiertos `[start, end)` en la zona horaria de
la aplicación. Las fechas visibles `inicio`/`fin` conservan compatibilidad y
cada período añade metadata técnica con inicio incluido, fin excluido,
semántica y timezone. El criterio de fecha legacy se oculta solo en Resumen y
continúa operativo en las otras pestañas; no puede redefinir Producción ni la
cohorte de creación.

El contrato JSON es aditivo: `produccion_periodo` y `cohorte_creacion` no
cambian la semántica de `kpis`, `comparativa`, `universe_date_criterion`,
`universe_date_label` ni `periods`. La auditoría existente admite la métrica
explícita `cv_firmados_periodo` y comparte con el KPI la selección temporal y la
deduplicación. Un grupo de venta con clasificación incompatible no se resuelve
por precedencia técnica: se excluye del total y se identifica como incidencia
de calidad. No se incorporan nuevos datos personales.

El catálogo de filtros se forma con la unión relevante de Producción y Cohorte
bajo el scope ya resuelto en servidor. El dataset de Resumen avanza a la caché
`reservas-ventas-dashboard-v7`; su identidad usa límites visibles/canónicos
estables y el payload conserva la metadata técnica exacta del cálculo cacheado.
V6 expira por TTL y la caché V4 de Rendimiento comercial no cambia.

## 2026-09-23 - Contrato funcional del Resumen Ejecutivo V1

El primer Resumen Ejecutivo tendrá visión global y acceso exclusivo para
Administrador y Dirección. La autorización y los datasets deberán admitir en el
futuro scopes de Area Manager y Manager sin rehacer la lógica, pero V1 no concede
esos accesos. El informe evaluará el último día cerrado y mostrará el mes en curso
hasta ese mismo corte solo como contexto informativo. Sus métricas iniciales son
Leads, Reservas y Ventas.

La referencia principal será la media de D-7, D-14, D-21 y D-28, con las cuatro
referencias obligatorias. D-364 será complementaria y no podrá cambiar por sí
sola el estado. Los estados funcionales son Correcto, Atención, Desviación,
Crítico y No evaluable; la dirección es Favorable, Desfavorable o Estable; la
salud del dato es Actualizado, Parcial, Desactualizado o Incidencia. Si falta una
de las cuatro referencias, el día está incompleto o existe una incidencia de
sincronización, el resultado será No evaluable y no generará alerta de negocio.
Las incidencias de datos tampoco se convierten en alertas de negocio.

El contrato conceptual calcula:

```text
variacion_pct = ((actual - baseline) / baseline) * 100
magnitud_variacion_pct = abs(variacion_pct)
diferencia_absoluta = abs(actual - baseline)
```

Las bandas de estado se aplican sobre `magnitud_variacion_pct`: Correcto es menor
del 15 %; Atención, desde 15 % incluido hasta 25 % excluido; Desviación, desde
25 % incluido hasta 40 % incluido; y Crítico, superior al 40 %. Los mínimos de
Leads, Reservas y Ventas se evalúan con `diferencia_absoluta`. Para alcanzar
Atención, Desviación o Crítico debe cumplirse simultáneamente el mínimo del nivel:
Leads exige respectivamente 30, 50 y 100, con baseline mínimo evaluable 100;
Reservas y Ventas exigen 3, 5 y 8, con baseline mínimo evaluable 5.

El estado y la dirección son dimensiones separadas. Una subida grande puede ser
Crítico/Favorable y una caída grande, Crítico/Desfavorable. No se fija todavía
una tolerancia para Estable: su contrato exacto debe definirse mediante
especificación y pruebas antes de implementar EXE-1. Si `actual = 0` y el
baseline alcanza su mínimo evaluable, el estado es Crítico y la dirección
Desfavorable. La especificación ejecutable deberá cubrir todos los límites antes
de implementar el motor.

Se publicarán como máximo cinco alertas: primero por severidad, después las
desfavorables antes que las favorables y, a continuación, por impacto económico
solo cuando exista un dato fiable; si no existe, por volumen absoluto. Nunca se
inventará impacto económico. Las acciones recomendadas serán fijas, versionadas,
con clave única y auditables. Una causa solo se afirmará como confirmada cuando
los datos la demuestren; en otro caso se mostrará «Posible causa a revisar».

V1 no utilizará IA. SISTRIX y GEO/IA quedan fuera y ocultos. El correo ejecutivo
general se enviará durante el piloto únicamente a `carlos.torres@hrmotor.es` a
las 08:00 `Europe/Madrid`. El correo SEO existente se mantiene independiente
mientras SEO no se incorpore expresamente al correo ejecutivo general.

## 2026-09-22 - Permisos y cierre productivo de Rendimiento comercial

Administrador mantiene lectura global, auditoría y edición del objetivo
mensual. Director mantiene lectura global y auditoría, pero el objetivo es de
solo lectura. Area Manager dispone de lectura y objetivo de solo lectura
exclusivamente para su zona, con scope forzado en servidor, sin auditoría y con
respuesta 403 si no tiene zona configurada. Los parámetros HTTP no amplían el
ámbito y no se concede acceso a otros roles.

El objetivo sigue siendo único por mes y se aplica individualmente a todos los
comerciales evaluables, sin convertirse en objetivo por zona, delegación o
comercial. La validación productiva de solo lectura, registrada sin PII,
confirmó la retroactividad de las caídas: la Opportunity permanece única, la
fecha original de reserva no cambia, el mes original se reclasifica y deja de
aportar al cumplimiento, mientras la cancelación histórica permanece imputada
al mes de `transitioned_at`. Esta evidencia valida la mecánica, no certifica
todo el histórico ni modifica los estados de cobertura.

## 2026-09-14 - Último mes cerrado por defecto en Rendimiento comercial

La pestaña usa el mes natural anterior de `Europe/Madrid` como valor inicial y
de reset, calculado una sola vez en Blade. El mes actual se expone al JavaScript
como metadata descriptiva del servidor, para no depender de la zona horaria del
navegador. Cuando el payload corresponde a ese mes se muestra el aviso de
resultado provisional; loading/error lo limpian.

No se modifica ninguna fórmula: objetivos individuales/globales siguen siendo
mensuales completos y los umbrales del semáforo, cumplimiento, ranking y equipo
usan actividad acumulada sin prorrateo ni proyección.

## 2026-09-14 - Caché V4 serializable y calidad mensual de Rendimiento comercial

La base cacheada de Rendimiento comercial guarda exclusivamente arrays y
escalares bajo `reservas-ventas-commercial-performance-base-v4`; las
`Collection` quedan fuera de la frontera de caché y se reconstruyen solo para
presentar. Se mantiene `cache.serializable_classes=false`, por lo que un store
persistente no puede convertir clases de Laravel en `__PHP_Incomplete_Class`.
No se limpian entradas V3: expiran con su TTL normal.

Los contadores de calidad se acumulan por mes de hito/grupo y el payload expone
solo el bloque del mes seleccionado. Esta separación evita mezclar cuatro meses
en la UI sin cambiar funnel, deduplicación, incidencia de datos, universo,
objetivo o ranking. La auditoría aplica filtros organizativos sobre atribución
resuelta antes de la paginación.

## 2026-09-11 - Universo evaluable posterior a la agregación en Rendimiento comercial

La certificación histórica de delegación sigue resolviéndose en el roster, pero
la evaluabilidad se decide únicamente tras agregar los hechos mensuales. Esto
evita que un registro de roster sin actividad consuma objetivo o altere ranking
y medias. La actividad real se limita a Leads, Opportunities, reservas totales,
ventas válidas y ventas caídas; una cancelación aislada no basta.

Los agregados de equipo se preagrupan en memoria por delegación y solo contienen
filas evaluables. El payload expone un bloque `universe` separado de `summary`
para que el cumplimiento global no mezcle actividad visible, incidencias ni el
filtro Comercial. Las incidencias de datos se mantienen reconciliables en un
bloque independiente, sin renderizarlas como comerciales.

## 2026-09-10 - Semántica de funnel en Rendimiento comercial

- Las caídas de Reserva/Venta se derivan del estado actual local de la
  Opportunity (`Cerrada Perdida`), no de OpportunityHistory. Reserva caída se
  ancla a `reservation_date`; Venta caída se ancla a esa fecha o, si no existe,
  a `cv_signed_date`.
- El cumplimiento usa solo reservas del mes cuya Opportunity no está perdida.
  Se conserva el cálculo a nivel de reserva original para no mezclar ventas
  firmadas de otros meses. Las cancelaciones históricas siguen separadas para
  cobertura y auditoría.
- Ante duplicados con clasificación contradictoria no existe precedencia por
  estado ni por orden de llegada: el grupo se atribuye a Incidencia de datos y
  solo conserva el hecho demostrable. El conflicto de firma usa vehículo +
  `cv_signed_date`; no altera la imputación de Venta caída por
  `reservation_date`. Las métricas derivadas no duplican incidencias.

## 2026-09-08 - Base cacheada para Rendimiento comercial

La construcción de cuatro meses de Rendimiento comercial se separa de los
filtros interactivos y se identifica solo por mes y versiones O(1) de sus
fuentes. Los filtros organizativos se resuelven sobre filas agregadas ya
cacheadas, preservando la población de ranking. Los Leads con tipo materializado
se agregan en SQL por mes y responsable efectivo; los legacy nulos siguen el
fallback PHP para preservar semántica.

## 2026-09-07 - Referencia única de equipo en Rendimiento comercial

Las comparativas de Rendimiento comercial reutilizan de forma estricta la
población `ranking_eligible=true` del universo ya acotado por Zona y Delegación.
Sin filtros organizativos no se agrupa por delegación: la referencia es todo el
conjunto evaluable. El filtro Comercial se aplica después y solo reduce las
filas mostradas, por lo que no puede alterar ranking, media ni ratios de
referencia. Esta única definición evita denominadores inconsistentes y mantiene
la comparación en O(n) sobre datos locales, sin consultas adicionales.

## 2026-08-26 - Dirección Comercial como derivación canónica de Area Manager

La comisión de Oscar Ortega se identifica por el Salesforce User ID estable
`0057R00000B2SGg`; el nombre no participa en la decisión económica. No se modela
como Area Manager porque no tiene delegaciones, objetivos ni KPI propios.

`AreaManagerCommissionDashboardService` calcula una sola vez el 40 % de la suma
global de `final_total` y lo publica como `commercial_director`. Blade, XLSX y el
snapshot consumen ese bloque sin fórmulas paralelas. Los builds por zona lo dejan
en nulo para impedir exposición o cálculos parciales. Esta decisión evita que un
cambio de nombre o un refactor del export eliminen silenciosamente la comisión.

## 2026-08-25 - Actividad mensual y hechos históricos verificables

La pestaña Rendimiento comercial se separa del dataset de cohorte existente. Un
hito pertenece al mes de su propia fecha y los ratios pueden superar el 100 %;
no se intenta vincular reservas/ventas al Lead del mismo mes ni crear scoring.

La separación de datasets no implica duplicar filtros: el bloque superior es la
única fuente DOM para zona, delegación y comercial. El modo de la pestaña decide
qué controles adicionales son visibles y si un cambio ejecuta el dataset legacy
o el mensual. Una selección ajena al nuevo universo se elimina antes de renderizar
y provoca una sola recarga correctiva.

La fecha de cancelación procede únicamente de una transición demostrable en
`OpportunityHistory`. `OpportunityFieldHistory` no aportó filas. Se conserva el
ID del historial, etapa previa, etapa nueva y timestamp; candidatos sin etapa
previa quedan no evaluables. `CloseDate` y `LastModifiedDate` se rechazan como
aproximaciones funcionales, aunque el segundo se usa para descubrir registros
antiguos modificados en la ingesta incremental.

`Delegacion_del_propietario__c` es una fórmula de la delegación actual del owner
y no acredita historia. Como tampoco existe `UserFieldHistory`, se adoptan
intervalos append-only observados desde la implantación. Como excepción de
negocio aprobada el 27/08/2026, la primera asignación fiable, normalizada y sin
evidencias contradictorias puede materializar un intervalo cerrado desde
`2026-04-01 00:00 Europe/Madrid` hasta la primera observación real. Su source es
`business_bootstrap_2026_04`: es evaluable para filtros, objetivo y ranking,
pero nunca se denomina observación o certificación Salesforce. El dato observado
no se mueve ni sobrescribe; sin dimensiones o con contradicción, el período
permanece no certificable. Una contradicción abre además una alerta operacional
durable `commercial_bootstrap_conflict` basada únicamente en IDs técnicos.

El bootstrap es una operación inicial controlada, no una consecuencia de la
captura periódica. `captureCurrentUsers()` solo persiste observaciones reales.
`salesforce:sync-monthly-commercial` lo ejecuta exclusivamente con la opción
manual `--bootstrap-performance-history`; el scheduler de quince minutos no
incluye esa opción. Una reejecución manual sigue siendo idempotente para los
usuarios ya materializados, mientras que un alta posterior nunca recibe meses
anteriores por una captura normal.

La cohorte bootstrap queda fijada por el mínimo `observed_from` de todos los
snapshots `salesforce_user_observation`. Solo son bootstrapables los usuarios
cuyo primer snapshot observado coincide exactamente con ese instante de la
fotografía inicial. Una primera observación posterior se clasifica para siempre
como `not_initial_cohort`, incluso ante una reejecución manual. Si el primer
snapshot del lote inicial carece de dimensiones, una observación posterior no
se usa para rellenarlo retrospectivamente.

La unidad mensual se fija en Salesforce User ID. La delegación no forma parte de
la clave: cobertura incompleta o más de una delegación en el mes deshabilita
media/ranking, pero conserva una única fila y objetivo. Un intervalo abierto
certifica el corte transcurrido del mes actual; meses cerrados exigen cobertura
continua completa. El roster cero solo nace de esa cobertura demostrable.

El objetivo efectivo se materializa al primer acceso con `is_explicit=false`.
Esto congela el default vigente sin impedir una edición posterior auditada.
El cumplimiento agregado divide las reservas operativas incluidas por la suma
de objetivos de las filas comerciales incluidas. Las incidencias no son personas
comerciales y por ello tienen objetivo, cumplimiento, semáforo y ranking nulos.

Los identificadores de esquema largos no se dejan a la convención de Laravel:
la FK del actualizador se denomina `commercial_perf_target_updated_user_fk` y el
UNIQUE del historial `sf_opp_stage_history_uq`. Así se conserva la semántica y se
cumple el máximo de 64 caracteres de MySQL sin renombrar tablas o columnas.

Una consulta de `OpportunityHistory` solo acredita cobertura después de que
consultas y persistencia local terminan correctamente. Intervalos solapados se
unen al leer; cualquier hueco produce N/A, no cero. Las transiciones con reserva
posterior se conservan como `reservation_after_transition` para auditoría y no
cuentan. La captura de delegaciones pertenece únicamente al sync mensual y una
clave única `(salesforce_user_id, open_marker)` impide dos intervalos abiertos.
Para el mes actual el horizonte funcional termina en el último cutoff del sync,
no en `now()`. El sync captura el cutoff al iniciar, limita consultas e intervalos
a ese instante y la UI publica el corte persistido; el reloj no lo amplía.
Un intervalo con `opportunity_not_local` persiste la incidencia y se marca no
apto para KPI hasta que un backfill solucione la dependencia y el rango se repita.
Una candidata Cerrada Perdida sin etapa previa demostrable se persiste como
`previous_stage_not_demonstrated` y bloquea igualmente; permanecer en Cerrada
Perdida desde una fila anterior no es una transición y no bloquea.

Las dependencias visibles se derivan del estado actual de las transiciones, no
de sumar intervalos históricos solapados. Una reejecución que actualiza la
calidad y aporta cobertura certificada elimina la deuda ya resuelta sin ocultar
incidencias que continúen pendientes.

Cuando `OpportunityHistory` referencia una Opportunity local ausente, el mismo
sync solicita únicamente esos IDs, en lotes de 100, mediante el SELECT y el
`updateOrCreate` canónicos de `SalesforceOpportunitySyncService`. La transición
se reclasifica en la misma ejecución. Un ID no devuelto conserva
`opportunity_not_local` y bloquea la certificación.

Un cambio observado de delegación o zona cierra/abre intervalos y crea una
alerta operacional `low` deduplicada por usuario, instante y dimensiones. Si el
cambio divide un mes, la actividad individual se conserva, pero el mes completo
no recibe una delegación arbitraria ni participa en ranking de equipo.

El filtro principal de usuarios sigue limitado al universo existente. Para
detectar una salida de perfil sin falsear `IsActive`, se refrescan por Salesforce
ID únicamente usuarios locales relevantes o con snapshot abierto. El servicio
de snapshots cierra el intervalo si el usuario está inactivo o ya no pertenece a
los perfiles comerciales; las reglas de Leads, Comisiones, Llamadas y Area
Manager no cambian.
Para reconstruir meses históricos, la identidad también se carga si existe un
snapshot solapado aunque el perfil actual sea no comercial. El snapshot acredita
el pasado; el perfil actual limita únicamente pertenencia presente y futura.
Leads, Opportunities, reservas, ventas y cancelaciones comparten esa misma
elegibilidad: un responsable no presente en el universo verificable se agrega a
incidencia y queda auditado como `non_commercial_responsible`, sin contaminar
objetivos ni comparaciones de equipo.

El sync diario de Opportunities se sitúa a las 07:10: queda después de Campañas,
refresco, Stock —que también escribe Opportunities— y el bloque SEO, evitando
una fotografía concurrente conocida sin alterar reglas de otros módulos.

## 2026-08-24 - Responsable financiero por dimension de zona

El responsable financiero no se deriva de `Opportunity.OwnerId`, porque ese ID
pertenece al comercial de la operacion. Se adopta una clave tecnica estable por
zona financiera y la delegacion normalizada como segundo nivel auditable. Esto
permite agregar distintos owners comerciales sin nombres humanos como clave y
mantiene una sola coleccion para resumen, delegaciones y trazabilidad.

Las reglas de Irene y Nuria se asocian a `zona_irene` y `zona_nuria` desde
2026-06 y sustituyen por completo los bloques estandar con comision neta por
`0.005`. El redondeo se reconcilia a centimos en el ultimo agregado de
delegacion para que su suma coincida con el total del responsable; las bases
economicas y la comision financiera nunca se ajustan. El ajuste distinto de cero
se expone en la fila y en el diagnostico administrativo.

Una zona financiera explicita desconocida nunca cae al mapping de delegacion. Si
contiene comision o descuento, el payload queda `ready=false` y las exportaciones
se bloquean; si no tiene impacto economico permanece excluida y auditable. Esta
politica evita asignaciones silenciosas sin ampliar el catalogo por aproximacion.

La sincronizacion puede reintentar una sola vez sin un campo opcional de Account
si la primera SOQL es rechazada. Un segundo fallo se propaga. Se prefiere este
fallback acotado frente a impedir la actualizacion de todos los campos
financieros por una dependencia no funcional del modulo.

## 2026-08-21 — Correo ejecutivo SEO congelado e idempotente

El resumen ejecutivo SEO se envía siempre a las 08:00 Europe/Madrid y consume
las evaluaciones versionadas existentes; no tiene scoring, thresholds ni motor
analítico propio. Los destinatarios se configuran en BD exclusivamente por
Administrador/Director, mientras transporte y secretos SMTP permanecen en el
entorno.

El contenido se congela por fecha antes del primer envío y cada destinatario
recibe un mensaje individual. Un ledger con unique fecha+destinatario y claim
atómico evita duplicados y permite reintentar solo fallos. Las entregas que
quedan `sending` no se reintentan automáticamente porque el SMTP pudo aceptar el
mensaje antes de que el proceso cayera. El fallo SMTP y la confirmación local se
tratan como fases separadas: después de un retorno correcto del transporte, una
confirmación local fallida o de cero filas permanece `sending` salvo que la BD
demuestre que ya está `sent`; nunca vuelve automáticamente a `failed`.

## 2026-08-11 — Hardening transversal y recuperabilidad

Se conserva Basic Auth de Comisiones por compatibilidad, pero las credenciales
se modelan en configuración de entorno como entradas con `integration`,
`credential_id`, `username`, `password` y revocación. Esto permite coexistencia
durante rotación, identificación auditable y rate limit por integración sin
almacenar secretos en base de datos.

Las alertas técnicas se representan mediante `operational_alerts`, deduplicadas
por fingerprint y accesibles solo a Administrador. El estado de Stock existente
se conserva como entidad de dominio, pero deja de generar email y escrituras en
Salesforce. Los fallos del scheduler abren alertas y los éxitos posteriores las
resuelven.

La retención pone a `NULL` únicamente los ocho `raw_payload` sin lectores
funcionales, en vez de borrar la fila normalizada. Cinco payloads aún leídos por
informes/reconstrucciones quedan bloqueados hasta materializar esas dependencias.
Las ejecuciones y alertas resueltas sí se borran por chunks. Snapshots, cierres,
atribuciones e historiales quedan fuera. La migración añade índices exactamente
para estas consultas; por su coste DDL se despliega en ventana controlada y no
se considera reversible cuando `operational_alerts` ya contenga datos.

El rollback operativo preferido es código compatible o forward fix. Se prohíbe
documentar `migrate:rollback --step=N` como mecanismo genérico.

## 2026-08-06 — Cohorte única y auditoría minimizada

Se adopta una única resolución de cohorte por informe para alimentar KPI y
auditoría de Reservas/Ventas. Los decoradores de auditoría pueden enriquecer la
misma fila, pero no aplicar filtros funcionales adicionales.

La exportación estándar se considera un artefacto de conciliación, no una
extracción CRM: se retiran `Opportunity.Name`, `Account.Name` y datos de
contacto porque no son necesarios para reconstruir KPI o duplicados. Cualquier
consumidor futuro de PII requerirá un flujo distinto y aprobación explícita.

Para Llamadas, los valores estructurados se representan mediante JSON explícito
y la cardinalidad se define por `Task.Id`, incluidas las Tasks excluidas de KPI.

## 2026-08-07 — Tipo Venta y contenido descriptivo

La clave funcional Venta agrupa Venta, Venta con cambio, Lead y Ayvens. Como el
tipo normalizado está materializado en `salesforce_leads`, la modificación se
acompaña de un comando de reproceso idempotente y con modo simulación; no se
reconstruyen automáticamente los snapshots de Campañas.

Reservas/Ventas no utiliza IA ni un contrato de recomendaciones. Sus
comparativas y porcentajes se limitan a información descriptiva de cohortes.

## 2026-08-07 — Clasificación operativa de Llamadas

La exclusión de `Pruebas comunidad comercial` se materializa con el perfil
Salesforce verificable de la identidad operativa y el motivo estable
`excluded_test_profile`; `missing_call_object` conserva prioridad. La regla de
duración 5/10 se centraliza en `CallClassificationRules` y la ausencia de equipo
se representa como `unassigned`, sin convertir equipos en geografía.
## 2026-08-07 — Cierre mensual de inversión de Campañas

El cierre congela exclusivamente inversión agregada por campaña y conserva un
snapshot versionado. Reabrir exige motivo sin borrar snapshots. Las atribuciones
ambiguas no se degradan a Salesforce-only: se auditan y no entran en KPI.
## 2026-08-07 — Métricas Salesforce-only de Campañas

Salesforce-only puede contribuir Leads, Oportunidades y su ratio derivado. Las
métricas de resultados comerciales, importes y economía se consideran no
aplicables y no se agregan en KPIs de Campañas.
## 2026-08-07 — Simulación no persistente de atribución de Campañas

El dry-run usa `CampaignAttributionBuilderService`, no un motor paralelo. No
borra, inserta, actualiza ni invalida caché; exige conciliación de particiones
y unicidad de atribución KPI antes de permitir aprobar una reconstrucción.

## 2026-08-13 - Shell comun y modulos estrategicos fijos

Resumen y SEO y Analytics se modelan separadamente de los seis informes con
minimo configurable. Su autorizacion es fija en servidor para Administrador y
Director; las filas historicas de `report_access_settings` no participan en
esa decision y la pantalla de permisos no los edita. `/informes` renderiza el
Resumen para esos dos roles y conserva la redireccion al primer informe
operativo autorizado para el resto.

La estructura exterior se implementa como componente Blade anonimo, CSS y
JavaScript propios, sin introducir un framework frontend. La preferencia de
sidebar es local al navegador y no forma parte del modelo de usuario. Esta
separacion permite incorporar paginas futuras aportando solo metadatos, assets
y contenido, sin duplicar autenticacion, branding o navegacion.

## 2026-08-14 - Design System namespaced y migracion progresiva

Los nuevos contratos visuales usan tokens `--report-ui-*`, clases
`report-ui-*` y componentes Blade presentacionales. No se modifican selectores
legacy de forma transversal: cada dashboard se migrara en un lote separado con
validacion funcional y visual. Resumen y SEO/Analytics validan la capa comun sin
datos ficticios. Los estados analiticos tienen cinco claves cerradas y cualquier
valor desconocido se representa como `not-evaluable`. Superficies y controles
usan radios discretos; los pills se reservan a badges, estados y chips con esa
semantica. Esta politica no se aplica globalmente al CSS de dashboards legacy,
que se migraran progresivamente. Los KPI analiticos se agrupan preferentemente
en strips continuos, las tabs usan texto y linea activa, y las tablas densas son
el patron principal para datos detallados. Referencias externas solo orientan
estructura y densidad; colores, marca e identidad permanecen HR Motor.

## 2026-08-17 - Readiness e ingesta SEO desacoplados del render

La pantalla SEO solo interpreta configuración local y no llama proveedores. La
verificación real y la ingesta son procesos CLI/scheduler read-only y
sanitizados. Search
Console y GA4 comparten únicamente el flujo técnico OAuth; sus credenciales,
scopes y properties son independientes y no reutilizan Google Ads. Salesforce
Lead orgánico y GA4 Key Events conservan cardinalidades separadas.

Los agregados diarios exactos de Search Console se almacenan separados de sus
rankings top, porque estos últimos no son exhaustivos y no pueden alimentar KPI
ni ceros. SEO preserva la semántica legacy
`LEA_SEL_Medio_Origen__c -> salesforce_leads.medio_origen`: su métrica orgánica
vive en una proyección propia basada en `Medio_origen__c = 'Orgánico'`. Cada
fuente conserva cutoff y timezone. El dataset publica únicamente cutoffs de
ejecuciones completadas y solo habilita KPI con cobertura diaria local completa;
el resumen conjunto usa el menor cutoff y los rankings conservan el periodo
propio de Search Console.

GA4 define Conversiones web orgánicas exclusivamente como `keyEvents` con
`defaultChannelGroup = Organic Search` y `platform = web`; España usa
`countryId = ES`. Conserva crédito decimal y permanece separado de Lead orgánico
Salesforce, sin suma ni deduplicación. Su cutoff es operativo, basado en timezone
de property y lag configurable, y se refresca mediante ventana móvil: no se
declara final como Search Console.

## 2026-08-18 - Salud técnica SEO acotada y factual

Salud técnica no es un crawler: monitoriza únicamente Home, URLs estratégicas
configuradas y páginas del ranking local Search Console. Sitemap se utiliza para
infraestructura y membership de esos candidatos, nunca para rastrear todo el
sitio. No se infieren URLs de Stock mientras no exista un mapping público
verificable.

Las comprobaciones HTTP persisten hechos técnicos (respuesta, redirects,
noindex, canonical y sitemap). La clasificación de severidad y las alertas
analíticas pertenecen a un motor posterior. Todo fetch pasa por allowlist exacta
de hosts, validación DNS pública y redirects manuales; el dashboard nunca accede
a la red.

## 2026-08-19 - Comparación factual transversal mediante snapshots

Se adopta un motor transversal sin Eloquent ni conocimiento de SEO. La versión
`same_weekday_v1` compara el día cerrado con D-7/D-14/D-21/D-28 exactos, exige
3/4 referencias y conserva D-364 como dato opcional. Cada métrica usa el cutoff
cerrado de su propia fuente; una ausencia nunca se convierte en cero y una
referencia cero nunca produce una variación infinita.

Los resultados se materializan en snapshots idempotentes separados de scoring,
thresholds y severidad. SEO es el primer adaptador y aísla properties mediante
identidades técnicas no secretas y SHA-256. El rolling rebuild no borra
históricos. No se aplica pruning a snapshots hasta aprobar una retención
específica, ni se extiende esta decisión a CSS o lógica de dashboards legacy.

## 2026-08-21 - Evaluaciones analíticas separadas y versionadas

Los snapshots factuales no incorporan estado ni severidad. La interpretación se
persiste aparte, vinculada a una versión inmutable de reglas. SEO inicia este
contrato con 10/20/35 % más puertas de materialidad para volúmenes; CTR y
Posición se evalúan por unidades absolutas. Las mejoras materiales se señalan
como oportunidad favorable, nunca automáticamente como Crítico.

Administrador y Director pueden crear una nueva versión desde la pantalla SEO,
con motivo, actor y protección frente a edición concurrente. No se actualiza ni
reactiva una versión histórica y un cambio no reinterpreta automáticamente el
histórico: solo reevalúa los snapshots actuales. El ID del actor queda como
referencia histórica sin FK para respetar la eliminación física de usuarios.

Los snapshots son proyecciones rolling y pueden revisarse mediante upsert. Por
ello cada evaluación captura los inputs factuales usados y su SHA-256 canónico.
La UI solo considera vigente una evaluación cuyo fingerprint coincide con la
proyección actual; mientras no coincida muestra un estado pendiente neutral. El
histórico presenta los valores capturados por la evaluación, evitando mezclar
hechos revisados con una clasificación anterior. D-364 y timestamps se excluyen
del hash porque no alteran la regla v1.
# 2026-08-28 - Responsables temporales y cierres por ámbito

- Las metas de entregas se editan desde julio exclusivamente en las asignaciones de Area Manager; Delegaciones deriva su target al leer configuración, sin compartir el universo real.
- El jefe de tienda se persiste como historial efectivo local basado exclusivamente en `Delegacion__c.DEL_BUS_Jefe_Tienda__c` y su historial Salesforce. Una observación actual sin evidencia histórica se marca como no verificable.
- Los seis dominios económicos reutilizan `CommercialCommissionClosure` y snapshots por `closure_scope`; no se introduce un segundo sistema de aprobación.
- El Auditor consume una proyección final-only dedicada. Ocultar columnas en Blade no se considera control de acceso suficiente.
- La evidencia histórica de responsables se modela como intervalos de cobertura. Un periodo solo es verificable si la unión de intervalos confiables cubre el mes completo sin huecos.
- Las confirmaciones históricas manuales requieren fuente y referencia trazables mediante un comando CLI específico; nunca se infieren desde el responsable actual.
- Los tres scopes de cierre nuevos tienen fecha efectiva 2026-07 y no se habilitan retroactivamente.

## 2026-08-28 - Candidato preparado y evidencia temporal separada

- La preparación crea una versión candidata inmutable del snapshot. La aprobación no recalcula fuentes: convierte en definitiva exactamente la versión preparada. Tras reapertura, una nueva preparación crea una versión superior y conserva la anterior para auditoría.
- El responsable al cierre y la cobertura de rotaciones son evidencias distintas sobre la misma tabla temporal. `month_end` cubre solo el instante final y nunca verifica el histórico mensual; `full_month` exige confirmación documental de todo el periodo. Esta separación evita ocultar un jefe conocido y evita afirmar ausencia de rotaciones sin evidencia.
- Readiness es server-side y se basa en metadata de ejecuciones o estado técnico de la fuente real. El recuento cero no prueba fallo. Las reseñas de Delegaciones se validan contra el endpoint interno/caché, no contra `Resena__c`.
- El Auditor usa navegación por scope: seis estados baratos y un único cálculo final-only por petición. Los fallos se aíslan por scope y nunca se proyectan como importes cero.

## 2026-08-31 - HTTPS público detrás de proxy explícitamente confiable

- Una extensión mínima del middleware oficial `TrustProxies` carga desde configuración cacheable la lista `TRUSTED_PROXIES`. Laravel interpreta `X-Forwarded-*` únicamente cuando `REMOTE_ADDR` pertenece a esa lista explícita. Producción debe configurar las IP/CIDR reales de la red del reverse proxy; no se admite `*` en una aplicación expuesta a Internet.
- Se confían las cabeceras estándar de Symfony para origen, host, puerto, protocolo y prefijo. No se implementa parser propio, no se hardcodea el host público y no se aplica `URL::forceScheme`, por lo que local puede seguir funcionando por HTTP.
- El proxy debe enviar `X-Forwarded-Proto: https` y producción debe mantener `APP_URL=https://informes.app.hrmotor.com`. `APP_URL` no sustituye la frontera de confianza del proxy para URLs derivadas de la request.

## 2026-09-03 - Backfill de atribución limitado por universo local

- Los backfills históricos de atribución de Lead parten de IDs ya existentes en
  las tablas locales; nunca descubren ni insertan Leads a partir de un rango
  Salesforce. El rango funcional se evalúa sobre `created_date` local.
- La persistencia usa UPDATE masivo de filas bloqueadas y existentes. Cada lote
  confirma conjuntamente el before/after técnico y las actualizaciones, de modo
  que un fallo revierte ambos y permite reanudar por Salesforce ID.
- El histórico específico contiene solo campos de atribución aprobados. Cuando
  cambia `raw_payload`, guarda únicamente las claves consultadas y no el payload
  completo. Dry-run no crea ejecuciones ni histórico y no invalida cachés.
- La identidad de conciliación Salesforce se define por los primeros 15
  caracteres case-sensitive; no se normaliza físicamente la clave local. El
  cursor conserva el orden literal para que la reanudación no omita ninguna de
  las representaciones coexistentes.
- Las respuestas remotas se obtienen antes de abrir la transacción. En apply,
  candidato, merge, diff e histórico se calculan exclusivamente después de
  releer las filas bajo `lockForUpdate()`. Un mutex atómico de caché con TTL de
  seis horas evita dos apply simultáneos, mientras el lock de filas mantiene la
  coherencia con sincronizadores que no participan en ese mutex.

## 2026-09-03 - Reproceso de portales de Opportunity acotado y auditable

- El reproceso conserva el comando existente y limita su universo a Opportunities
  locales en un rango de `created_date`; la PK local numérica es el cursor
  estable. No descubre, inserta ni elimina Opportunities.
- Dry-run y apply son modos mutuamente excluyentes. Apply exige motivo, usa un
  mutex de caché de seis horas y confirma por chunk el UPDATE junto al histórico
  before/after. La caché se versiona una sola vez si existe algún commit efectivo.
- El matching Lead remoto se completa antes de abrir la transacción. Después se
  releen filas bajo `lockForUpdate()` y se valida una huella de los inputs; si
  cambian, se libera el lock, se repite la consulta Lead y se limita a tres
  intentos. No se mantiene HTTP bajo locks DB.
- La auditoría específica conserva solo los seis campos de atribución y filtra
  el debug mediante whitelist. Se omiten PII y `raw_payload` completo, y no se
  añade FK para que la evidencia técnica sobreviva a la eventual retirada de la
  réplica local.

## 2026-09-04 - Reparación separada de metadatos temporales de Opportunity

- Las filas locales sin `created_date` no se recuperan mediante el sincronizador
  completo: una herramienta dedicada consulta únicamente `Id`, `CreatedDate` y
  `LastModifiedDate` por IDs locales `006` validados y solo puede escribir las
  dos columnas temporales correspondientes.
- La reparación usa cursor por PK local, lotes de 100, mutex apply y bloqueo de
  filas antes del UPDATE bulk. `created_date IS NULL` permanece como guarda en
  la escritura para no sobrescribir una reparación concurrente.
- Dry-run no persiste. Apply exige motivo y crea una auditoría mínima por
  ejecución, separada del historial de atribución porque no cambia portal,
  fuente ni reglas funcionales. No se copian payloads ni PII a esa auditoría.

## 2026-09-04 - Identidad de contacto determinista para Opportunity → Lead

- El conjunto de candidatos se define por email normalizado y por la clave
  telefónica canónica que ya usa Reservas/Ventas, no por la representación bruta
  aportada accidentalmente por otros elementos del batch.
- La clave telefónica se materializa e indexa en `salesforce_leads`. Esa tabla
  es únicamente un índice de descubrimiento de IDs activos: los registros
  candidatos se reconsultan por `Lead.Id` en Salesforce vivo y la comprobación
  final vuelve a exigir igualdad con el mismo normalizador compartido.
- Para un Lead reciente aún ausente localmente se mantiene un fallback Salesforce
  agrupado con las siete variantes exactas ya existentes. No usa `LIKE`, no
  escanea `leads_raw` y no sustituye al índice como camino principal.
- El fallback `leads_raw` se decide por email no resuelto. No existe fallback
  telefónico en esa tabla. El orden total de candidatos es
  `CreatedDate DESC, Lead.Id ASC`. Este desempate no cambia la prioridad temporal
  y elimina dependencia del orden de respuesta.

## 2026-09-07 - Lifecycle de Opportunity preservado y exclusión activa central

- Las Opportunities retiradas no se eliminan físicamente: se conservan con
  `is_deleted`, fecha Salesforce cuando está demostrada y una fuente de
  detección. `query_all_deleted` y `presence_reconciliation_missing` son estados
  distintos; una ausencia por ID no inventa causa ni timestamp.
- `SalesforceOpportunity` incorpora un scope global activo para que nuevos
  consumidores Eloquent no reintroduzcan borrados confirmados por omisión. El
  scope no excluye `presence_reconciliation_missing`: una ausencia no demuestra
  lifecycle. Solo `SalesforceOpportunitySyncService`, mediante su SELECT y
  mapper completos, puede limpiar el borrado y reactivar la misma fila; el sync
  parcial de Stock preserva esa metadata.
- La reconciliación histórica es un comando separado, read-only en Salesforce,
  con lotes de 100, cursor por PK, mutex apply y auditoría por ejecución. El sync
  diario solo consulta borrados modificados en su ventana; no infiere ausencias
  desde el universo funcional parcial de una consulta por fechas.
- El delta de borrados usa el API Name canónico `SystemModstamp`: Salesforce lo
  mantiene para cambios de usuario y sistema y lo indexa para replicación. El
  valor se conserva como
  timestamp técnico del evento detectado únicamente en `salesforce_deleted_at`;
  nunca sobrescribe `salesforce_last_modified_at`, cuya semántica continúa siendo
  `Opportunity.LastModifiedDate`. La prueba sintética prevista en sandbox no
  pudo ejecutarse; la reconciliación productiva sí confirmó que `queryAll`
  devuelve borrados reales con `SystemModstamp`, que el timestamp se persiste y
  que la repetición converge a cero cambios. No se presenta como una fecha de
  negocio ni como el instante contractual exacto de borrado.
- Campañas no filtra la fila de atribución tras el `LEFT JOIN`: mantiene Lead,
  campaña y procedencia, y hace cero únicamente las métricas dependientes de una
  Opportunity confirmada como eliminada. Stock invalida snapshots conservados
  solo por borrado confirmado; una ausencia local se contabiliza como
  `unchecked` y no altera la validez previa. Tras un apply que confirma borrados,
  el comando de presencia orquesta inmediatamente el reconciliador local de
  validez, sin acoplar Stock al servicio lifecycle ni ejecutar sincronizadores.
  `stock:reconcile-sale-validity` expone la misma operación local para recuperar
  de forma segura un fallo posterior a la persistencia de lifecycle.
- No se crea índice sobre el booleano `salesforce_opportunities.is_deleted`: con
  aproximadamente 40.557 filas y baja selectividad no existe un `EXPLAIN` que
  justifique el coste adicional de escritura/DDL. No se añade un índice
  alternativo sin evidencia.
