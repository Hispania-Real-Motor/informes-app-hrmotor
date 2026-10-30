# Informe de Comisiones

Actualizado: 2026-08-24.

La guía completa, orientada a presentar y auditar la pestaña **Financieros**, se
encuentra en `docs/informe-comisiones-financieras.md`. Incluye campos Salesforce,
SOQL de contraste, universo, tramos, fórmulas, lectura de cada columna, ejemplo y
checklist de aprobación.

## Mes compartido

`CommissionMonthResolver` fija un único mes para:

- Comerciales;
- Delegaciones;
- Call Center;
- Contact Center;
- Área Manager;
- Financieros;
- cabecera, textos, detalles y exportaciones.

El mes solicitado se conserva en URL y selector. El mes actual puede consultarse
y siempre se muestra como `Provisional`; ninguna pestaña cambia por su cuenta al
último mes cerrado.

La proyección local de Opportunity conserva `Captador_de_cita__c` y el nombre
relacionado como `appointment_setter_id` y `appointment_setter_name` para una
atribución futura. Esta materialización no activa ni altera planes, fórmulas,
cierres, filtros ni cálculos económicos de Comisiones.

## API mensual de Comerciales

`GET /api/comisiones_comercial` exige `salesforce_id` y acepta opcionalmente
`month=YYYY-MM`. La identidad se compara de forma exacta y exclusiva con el
Salesforce User ID, incluida su capitalización; no existe fallback por nombre,
email ni coincidencia parcial.

Con `month`, la respuesta contiene `commercial_id`, `month`, `month_label`,
`economic_status`, `has_data` y `row`. `row` es, sin mapeo ni redondeo adicional,
la fila completa de `summary_rows` de la pestaña Comerciales e incluye `details`.
`200`, `has_data=false` y `row=null` significa exclusivamente que el usuario
actual existe, está activo, es elegible y no tiene fila real en ese período. Un
ID inexistente, técnico, inactivo o no elegible recibe `404` genérico cuando no
hay una fila histórica canónica. Formatos, meses imposibles y meses futuros
reciben `422` y nunca se sustituyen por el mes actual.

Si el dataset vivo devuelve `ready=false`, la API responde `503` con un mensaje
genérico; no expone las incidencias internas ni convierte la indisponibilidad en
`has_data=false` o comisión cero. Sin `month`, la indisponibilidad del mes actual
o del bloque legacy del mes anterior invalida de forma segura toda la respuesta.

Sin `month`, `CommissionMonthResolver` selecciona el mismo mes que el informe. Se
añade la fila canónica mensual y se conservan temporalmente `current_month` y
`previous_closed_month` con su forma legacy para no romper un consumidor externo
que no puede identificarse en este repositorio.

La API consulta `CommercialCommissionClosureService::definitiveSnapshot()` para
el scope `commercials`. Si el bloque es definitivo, selecciona la fila del
snapshot congelado que usa la pantalla antes de consultar la elegibilidad viva.
Una fila definitiva sigue siendo válida aunque el usuario actual esté inactivo,
haya cambiado de perfil o ya no exista localmente. Los meses provisionales,
pendientes o reabiertos usan una única construcción viva
`CommercialCommissionDashboardService::build($month, true, false, true)`: no se
construyen Delegaciones ni otras pestañas y no existe una segunda fórmula.

## Readiness de fuentes y cierre económico por bloque

Para un mes natural cerrado, cada fuente exigida conserva una ejecución
`completed` verificable que cubra íntegramente `[inicio de mes, inicio del mes
siguiente)` y respete la frescura configurada. No se acepta una cobertura parcial
ni se marca como cubierta una fecha futura.

En el mes actual provisional la evidencia se evalúa de forma distinta: se unen
solo intervalos de ejecuciones `completed` desde el primer día y se conserva el
último extremo cubierto (`coveredUntil`). No se exige que `period_end_at` alcance
la hora del render: una ejecución fija ese fin antes de completar. La cola entre
`coveredUntil` y el instante actual se trata como frescura y debe ser menor que
`closure_source_max_age_hours` (48 horas por defecto). Varias ejecuciones
solapadas o contiguas pueden cubrir el mes; un hueco entre intervalos sigue
bloqueando con **Revisar** y no queda oculto por la tolerancia final. Que aún no
haya terminado el mes no es por sí mismo una incidencia.

Estados persistentes:

- `provisional`;
- `pending_approval`;
- `definitive`;
- `reopened`.

Desde julio de 2026, los seis scopes (Comerciales, Delegaciones, Área Manager,
Financieros, Call Center y Contact Center) disponen de cierre independiente. Cada
cierre tiene estado, aprobación, reapertura, eventos y versión de snapshot propios
por combinación `month + closure_scope`.

Un bloque solo puede pasar a definitivo cuando ha terminado el mes natural, están confirmados sus componentes y aprueba Dirección o Administrador/IT.

El cierre conserva mes, usuario y fecha de aprobación, corte, versión de
fórmulas, incidencias, usuario/fecha/motivo de reapertura y eventos de auditoría.
El snapshot reproducible contiene únicamente el universo del bloque: Comerciales
incluye su auditoría de reseñas; Delegaciones sus filas; Área Manager incluye las
variantes necesarias por zona. Los registros legacy existentes se conservan con
scope `legacy`; no se convierten automáticamente en cierres de los tres bloques.

Una modificación posterior de Salesforce no sobrescribe un cierre definitivo.
Por defecto se registra en el libro de ajustes del siguiente mes abierto. Para
recalcular el mes original hay que reabrirlo manualmente con motivo obligatorio.
El ajuste conserva operación, mes original, mes de aplicación, importe, motivo,
usuario, fecha y estado.

## Universos por pestaña

Las pestañas pueden tener universos diferentes. La reconciliación sigue:

```text
base común - exclusiones de la pestaña + inclusiones especiales = total mostrado
```

Los IDs y motivos permanecen en detalles y exportaciones. El cuadro interno de
conciliación del universo solo se muestra a Administrador/IT.

Resumen funcional:

- Comerciales: operaciones elegibles por Salesforce User ID y reglas económicas
  mensuales.
- Delegaciones: entregas y rentabilidad según sus reglas de tienda.
- Call Center: ventas/compras/cambios, German y Facilitea según captador y
  configuración.
- Contact Center: citas y resultados por agente con su ventana funcional vigente;
  desde septiembre de 2026 incluye además un bloque independiente de Responsable
  Contact Center basado en entregas de toda la empresa.
- Área Manager: ámbito de zona y delegaciones normalizadas.
- Financieros: ventas/cambios por fecha de firma y zona financiera. Los bloques 1
  y 3 usan todo el universo elegible; el bloque 2 usa solo operaciones con interés
  informado y no excluido. Carlos/Cristina conservan los tres bloques; desde
  2026-06 Irene/Nuria usan exclusivamente comisión neta por 0,005. La comisión
  final se recalcula sobre la réplica local.

La identidad principal de una persona es Salesforce User ID; el nombre es solo
una etiqueta y no debe usarse para unir registros.

### Roster administrativo de Contact Center

La configuración de Comisiones incorpora un roster persistente de Contact Center
para conservar qué Salesforce User IDs deben refrescarse aunque su perfil no esté
incluido en la consulta principal de Users. Administración gestiona ID, nombre
visible y tramos de vigencia inclusivos (`effective_from` y opcionalmente
`effective_until`); no se admiten tramos solapados para un mismo ID. El bootstrap
registra siete IDs a partir de `2026-09-01`.

El roster no tiene FK física a `salesforce_users`: puede configurarse antes del
primer sync de Users. El refresco mensual solicita sus IDs en un batch trazable
separado y no amplía el filtro de perfiles de la consulta principal. Es una
configuración de identidad y actualización local, no una regla económica: no
modifica fórmulas, KPI, universos, cierres, snapshots, exportaciones ni el bloque
Responsable Contact Center.

## Reseñas

La fórmula actual no se ha cambiado:

- numerador: reseñas creadas en el mes, atribuidas por `OwnerId`;
- denominador: operaciones elegibles del responsable en ese mes.

El ratio puede superar el 100 % porque los universos no son uno-a-uno y puede
haber varias reseñas por operación. La pantalla lo explica en `Cómo se calcula`
y `/informes/comisiones-comerciales/export/reviews-audit.csv` permite auditar
cada reseña y su procedencia.

Cambiar a una reseña máxima por Opportunity exigiría una nueva decisión
funcional; no se aplica implícitamente.

### Seguridad del endpoint interno de reseñas

`INTERNAL_REVIEWS_ENDPOINT`, `INTERNAL_REVIEWS_USER` e
`INTERNAL_REVIEWS_PASSWORD` se proporcionan exclusivamente mediante variables
de entorno y no tienen valores fallback. Si falta cualquiera de ellas, el
servicio queda `not_configured`, no construye una cabecera Basic Auth y mantiene
el cero previsto por la lógica funcional vigente. Los fallos de transporte o
remotos conservan un estado técnico separado sin incluir credenciales, cabeceras
ni cuerpos de respuesta en logs o excepciones.

Una incidencia `not_configured`, de transporte o remota deja reseñas en cero y
rating en nulo donde procede, sin romper el informe ni bloquear por sí sola el
cierre de un bloque. Debe revisarse mediante los avisos administrativos del
informe y los logs técnicos sanitizados.

## Regla financiera especial por responsable

El responsable financiero se resuelve mediante una clave técnica estable de
`zona_financiera__c`, con fallback por delegación cuando la zona está vacía.
`Opportunity.OwnerId` identifica al comercial propietario y no selecciona esta
regla. Desde `2026-06`, `zona_nuria` y `zona_irene` reciben el 0,50 % de la
comisión neta, sustituyendo completamente los bloques 1, 2 y 3. El nombre sigue
siendo únicamente una etiqueta visual.

## Dirección Comercial derivada de Área Manager

El payload global de Área Manager incluye un bloque separado
`commercial_director` para Oscar Ortega, identificado exclusivamente por el
Salesforce User ID `0057R00000B2SGg`. Su nombre es solo una etiqueta visible.
La base es la suma de `final_total` de todos los Area Managers globales y su
comisión es esa base por el 40 %, redondeada a dos decimales.

No se añade como un quinto Area Manager ni recibe delegaciones, objetivos o KPI.
Los builds restringidos por zona devuelven este bloque en nulo para evitar tanto
la exposición global como un cálculo parcial. El snapshot global del scope
`area_manager` lo congela junto con el resto del payload; front y XLSX consumen
ese mismo dato. Los snapshots definitivos anteriores no se modifican.

Financieros es un bloque operativo, por lo que no participa en los snapshots de
cierre de Comerciales, Delegaciones o Área Manager.

## Roles y datos sensibles

- Dirección y Administrador/IT: acceso completo al informe; ambos gestionan
  cierres.
- Área Manager: únicamente su zona.
- Responsable de delegación: únicamente su delegación; la delegación es
  obligatoria al asignar el rol.
- Financiero: pestaña Financieros y datos permitidos.
- Comercial: únicamente su Salesforce User ID.
- Auditor de comisiones: proyección de solo lectura con entidad, comisión final,
  estado, aprobador, fecha y alertas permitidas de jefe de tienda.

El Auditor de comisiones no recibe administración de usuarios, configuración de
fórmulas ni cierres. Márgenes, penalizaciones y comisiones globales se filtran en
servidor; ocultar una pestaña en Blade no sustituye la autorización de endpoint,
exportación, fila y columna.

## Penalizaciones financieras

Ruta: `/informes/penalizaciones-financiacion`.

Las cargas XLSX se conservan como historial. Una carga nueva sustituye las filas
activas de los meses incluidos sin volver a sumar las versiones anteriores. Los
registros sin match con Salesforce se muestran para revisión y no se aplican de
forma silenciosa.

## Operación y auditoría

El sincronizador compartido del snapshot directo Opportunity→Interest añade
`reference_fingerprint` a las estadísticas de su ejecución completada para
detectar cambios relevantes en la publicación de Campañas. No cambia las
relaciones persistidas, el universo, los filtros, las fórmulas, los cierres ni
las exportaciones de las pestañas de Comisiones; la huella no sustituye la
evidencia propia de sus cierres económicos.

- Dashboard: `/informes/comisiones-comerciales`.
- XLSX: `/informes/comisiones-comerciales/export/comisiones.xlsx`.
- CSV entregas: `/informes/comisiones-comerciales/export/delegation-deliveries.csv`.
- CSV sin captador: `/informes/comisiones-comerciales/export/call-center-missing-captador.csv`.
- CSV reseñas: `/informes/comisiones-comerciales/export/reviews-audit.csv`.
- Configuración: `/informes/configuracion-comisiones`.
- Penalizaciones: `/informes/penalizaciones-financiacion`.

```bash
php artisan salesforce:sync-opportunities --all-history
php artisan salesforce:sync-commercial-reviews --all
php artisan salesforce:sync-tasaciones --all
```

Los reprocesados de datos operativos no modifican snapshots definitivos salvo
reapertura autorizada.

### Evidencia manual de citas y entregas de Contact Center

Esta evidencia es auxiliar de auditoría y no una fuente del cálculo vigente.
Cada sync terminado reemplaza únicamente la evidencia de su intervalo tras
completar toda la lectura Salesforce; un fallo parcial conserva la evidencia
anterior. Un Product2 enlazado por más de una Opportunity se marca como
`DUPLICATE_PRODUCT2_LINK`, con prioridad sobre fecha y estado.
La migración `2026_10_30_150000_create_commercial_commission_delivery_evidence_tables.php`
crea tres réplicas locales separadas de los hechos del informe:

- `commercial_commission_appointment_evidences` conserva, por `Interes__c.Id`,
  el captador de cita, su nombre, `IN_Fecha_Captador_de_Cita__c`, la última
  modificación Salesforce, payload y corte de sincronización. Su consulta se
  acota por la fecha de captación con intervalo `[from, to)`.
- `commercial_commission_product_delivery_evidences` conserva, por `Product2.Id`,
  estado, `Fecha_entregado__c`, matrícula, última modificación, payload y corte.
  La carga incluye tanto Product2 con fecha de entrega en `[from, to)` como los
  IDs referidos por Opportunities locales con CV firmada en ese intervalo; no
  modifica `salesforce_vehicles`.
- `commercial_commission_delivery_reconciliations` guarda por período la
  comparación de esas evidencias contra Opportunities CV firmadas, con IDs,
  fechas, estado y clasificación `MATCH`, `OPPORTUNITY_ONLY`, `PRODUCT2_ONLY`,
  `DATE_MISMATCH`, `STATE_MISMATCH` o `DUPLICATE_PRODUCT2_LINK`. Esta última
  prevalece cuando más de una Opportunity CV firmada del período referencia el
  mismo Product2, antes de evaluar fecha o estado.

La migración
`2026_10_30_151000_add_sync_token_to_commercial_commission_evidence_tables.php`
añade un UUID `sync_token` por ejecución a las dos tablas de evidencia. Tras
leer y persistir íntegramente el intervalo, cada sincronización elimina sólo las
filas de ese intervalo no vistas por su token; si una lectura o persistencia
falla parcialmente, no se ejecuta esa limpieza. En Product2, la limpieza
conserva además los IDs aún requeridos por Opportunities CV firmadas del
período, aunque su fecha de entrega quede fuera del intervalo.

Las ejecuciones son manuales y requieren `--from=YYYY-MM-DD` y
`--to=YYYY-MM-DD` (fin exclusivo):

```bash
php artisan salesforce:sync-commercial-commission-appointments --from=YYYY-MM-DD --to=YYYY-MM-DD
php artisan salesforce:sync-commercial-commission-product-deliveries --from=YYYY-MM-DD --to=YYYY-MM-DD
```

Ambas registran su ejecución en `report_sync_runs`; no hay scheduler, escritura
en Salesforce, publicación/recuperación de generaciones de Interests ni cambio
en fórmulas, KPIs, filtros, universos, cierres, snapshots o XLSX. Las
conciliaciones son evidencia de revisión y no certifican ni recalculan una
comisión por sí solas.

Para poder preparar o cerrar el Responsable Contact Center desde `2026-09`, debe
existir una ejecución `completed` de `salesforce_opportunities` que cubra por
completo el mismo mes natural del año anterior. Por ejemplo, septiembre de 2026
requiere `[2025-09-01, 2025-10-01)`. Si falta, se debe generar la evidencia con
un rango explícito, sin ejecutar el comando desde el dashboard:

```bash
php artisan salesforce:sync-opportunities --from=2025-09-01 --to=2025-10-01
```

Una referencia con cero entregas elegibles tampoco certifica la comisión ni
permite el cierre definitivo. Este procedimiento no reabre ni altera snapshots
ya definitivos.

Archivos principales:

- `app/Services/Reports/CommercialCommissions/CommissionMonthResolver.php`;
- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`;
- `app/Services/Reports/CommercialCommissions/CommercialCommissionClosureService.php`;
- `app/Services/Reports/CommercialCommissions/AreaRestrictedCommissionScope.php`;
- servicios de Call Center, Contact Center, Área Manager y Financieros.
# Cambios efectivos desde julio de 2026

- La meta de entregas de Delegaciones es la misma configurada para la delegación en Area Manager. Solo se comparte el objetivo; las entregas reales mantienen sus reglas independientes.
- Los seis bloques tienen cierres y snapshots independientes.
- Call Center y Contact Center detallados son exclusivos de Administrador. Dirección puede aprobar o reabrir ambos mediante un resumen sin detalle.
- El jefe de tienda procede de `Delegacion__c.DEL_BUS_Jefe_Tienda__c` (lookup a `User`). La comisión mensual completa se atribuye al último responsable verificable del mes, sin prorrateo. Las rotaciones generan alertas no bloqueantes.
- Si Salesforce no aporta evidencia histórica suficiente, el informe muestra el histórico como no verificable y no inventa una asignación pasada.
- Un mes es verificable únicamente cuando existe cobertura continua. Para julio, una confirmación real puede registrarse con `php artisan commissions:record-delegation-manager-evidence <delegation-id> "<delegación>" <manager-id> "<jefe>" 2026-07 --source=<fuente> --reference=<ticket-o-documento> --recorded-by=<responsable>`.
- Financieros, Call Center y Contact Center disponen de cierre únicamente desde julio de 2026. Los filtros contractuales de Call Center son visuales y nunca alteran el snapshot mensual canónico.
- La tabla `salesforce_delegation_manager_history` se evoluciona mediante la migración incremental `2026_08_28_100000_add_coverage_metadata_to_salesforce_delegation_manager_history_table`. Hasta ejecutarla, el informe degrada el responsable a histórico no verificable sin impedir la carga.
- Dirección aprueba desde una superficie consolidada de scopes. Los importes definitivos proceden del snapshot; para scopes navegables pendientes se abre primero su pestaña canónica y Call/Contact se proyectan sin detalle.

## Preparación y evidencia de responsables

- Administración prepara cada bloque después de validaciones automáticas en servidor. No existen confirmaciones manuales de fuentes en el formulario. Dirección aprueba exactamente el snapshot candidato preparado; la aprobación no recalcula el mes.
- `Resena__c` es la fuente de reseñas comerciales. Las reseñas de Delegaciones proceden del endpoint interno de Google Reviews y su caché; disponibilidad y freshness se comprueban de forma independiente.
- El jefe al cierre puede estar confirmado aunque el historial de rotaciones del mes no sea verificable. En ese caso se muestra el jefe, se le atribuye inicialmente el 100 % y se conserva una alerta. Solo evidencia continua del mes permite informar el número de responsables.
- Backfill unitario en validación: `php artisan commissions:record-delegation-manager-evidence <delegation-id> "<delegación>" <manager-id> "<jefe>" 2026-07 --source=it_confirmation --reference=<referencia> --recorded-by=<usuario> --evidence-type=month_end --dry-run`.
- Backfill por lote: `php artisan commissions:import-delegation-manager-evidence <csv> --dry-run`. El CSV requiere `delegation_id,delegation_name,manager_id,manager_name,month,source,reference,recorded_by,evidence_type`. No usar `full_month` salvo evidencia real de cobertura completa.

## Evidencia operacional de reseñas (2026-10-15)

OPS-1 programa salesforce:sync-commercial-reviews --months=2 a las 02:20
Europe/Madrid, sin fresh y con withoutOverlapping(120), auditoría y reserva común.
El dataset commercial_reviews conserva su contrato. La readiness sigue exigiendo
cobertura del mes, completed/completed_at y frescura de 48 h por defecto; una
ejecución operacional correcta no sustituye esas comprobaciones.

La preparación automática del mes anterior el día 15 continúa pendiente en
COMM-CLOSE-SCHEDULE. Preparará candidatos; no aprobará cierres automáticamente.
No cambian fórmulas, permisos de aprobación ni UX de Comisiones en OPS-1.

## Aprobadores económicos por scope (2026-10-19)

Administración → Permisos por informe permite elegir un ReportUser activo para cada uno de los seis scopes: Comerciales, Delegaciones, Area Manager, Financieros, Call Center y Contact Center. La selección usa el ID interno; no crea usuarios ni depende de nombres o correos. El mismo usuario puede aprobar varios scopes.

La migración aditiva 2026_10_19_120000_create_commercial_commission_approvers crea seis filas únicas en commercial_commission_approvers y un registro separado commercial_commission_approver_events. configured_at nulo significa que el scope nunca fue asignado: conserva inicialmente aprobación por Administrador/Dirección. Tras la primera asignación solo aprueba el usuario asignado y activo, incluso frente a Administrador/Dirección. Si se elimina el usuario, la FK queda a NULL y configured_at se conserva: el scope queda bloqueado. No se permite vaciar una asignación para recuperar legacy; Administración debe seleccionar otro usuario activo.

Cada cambio se guarda en una transacción con scope, IDs anterior/nuevo, actor y fecha. Los IDs de auditoría también se conservan en contexto JSON sin PII aunque posteriormente se elimine un usuario. La asignación actual usa FK con nullOnDelete. Las actualizaciones y aprobaciones bloquean la fila del scope; la aprobación verifica al actor activo dentro de la transacción.

La autorización central ReportUserAccess::canApproveCommissionClosure valida el scope en backend; la configuración se carga una vez por request. El resumen de aprobación consulta únicamente snapshots de scopes autorizados y no construye dashboards adicionales. El rol Financiero mantiene exclusivamente la pestaña Financieros. La asignación no concede acceso al informe, detalles, preparación, reapertura, ajustes, fórmulas, penalizaciones ni administración: esos accesos conservan sus reglas anteriores. El auditor puede aprobar mediante el resumen solo los scopes que tenga asignados.

La aprobación sigue exigiendo pending_approval, mes natural terminado, scope disponible para ese mes y snapshot candidato existente. Conserva snapshot_version, approved_by real y evento approved; no recalcula importes. No se modifican fórmulas, universos ni cierres históricos.

Operación tras despliegue autorizado: ejecutar php artisan migrate en el entorno correspondiente antes de servir el código y seleccionar el usuario existente activo en Administración. No se requiere variable de entorno ni backfill. Esta tarea no ejecuta migraciones ni cambios de datos en producción.

### Hardening de aprobadores (2026-10-19)

Administración utiliza el mismo resumen de aprobación del snapshot preparado que los demás aprobadores. Solo aparece una acción de aprobación por scope pendiente autorizado. La tabla de preparación conserva preparación y reapertura, sin duplicar la acción de aprobación. Un administrador no puede aprobar scopes asignados a otro usuario; conserva su política original de reapertura, también para esos scopes.

El formulario marca expresamente los scopes bloqueados por aprobador inactivo/eliminado o configuración inválida, muestra el error junto a su selector e identifica la pestaña que requiere intervención. Es necesario seleccionar un usuario activo para sustituir la asignación; no hay sustituciones automáticas. Guardar una asignación vigente sin cambios conserva su fecha y no crea auditoría adicional. Los seis scopes se validan y guardan como una unidad: cualquier error revierte todos los cambios y sus eventos de asignación.

La ausencia de una fila de configuración o una fila incoherente no habilita legacy: se deniega la aprobación. Solo una fila inicial válida, sin usuario y sin configured_at, permite la política original. Una configuración incompleta impide guardar el conjunto hasta corregir su integridad; el formulario no recrea silenciosamente filas faltantes. Asignar el mismo usuario activo a una fila incoherente con ID pero sin configured_at formaliza la asignación explícita y deja auditoría.

La asignación tampoco concede acceso al informe si el rol no lo permite. Financiero conserva su pestaña Financieros y los controles administrativos siguen separados. La autorización del POST se revalida dentro de la transacción.

Orden de bloqueos: fila(s) de configuración por ID, usuarios implicados por ID y, para aprobación, cierre económico. El bloqueo de configuración se mantiene hasta el commit del cierre. Los controladores pueden repetir hasta tres veces la transacción completa ante deadlocks detectados por Laravel; los intentos abortados no conservan eventos ni cambios parciales. No se modifican algoritmos de preparación ni cálculos.

## PR #92 — alcance compartido de reconciliación Interest

`SalesforceOpportunityInterestReconciliationService` lee presencia Interest y
F2 desde la generación publicada; deja de depender del último intento F2 y la
tabla viva. El cambio afecta la evidencia compartida de relaciones, sin cambiar
las fórmulas, coeficientes, fechas pivote, pestañas ni cierres de Comisiones.
No certifica retroactivamente snapshots económicos. El alcance conservador del
mapa documental incluye este informe por la fuente Opportunity compartida;
no se ha detectado en el diff una nueva fórmula KPI de Comisiones.
[Contrato y pendientes de regresión](salesforce-interest-generations.md).
