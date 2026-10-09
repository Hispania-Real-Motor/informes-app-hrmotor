# Informe de Reservas / Ventas

Actualizado: 2026-10-09.

## Fuente y datos locales

- Fuente principal: Salesforce `Opportunity`, que continúa siendo el hecho de
  reserva/venta, con relaciones a `Owner`, `RecordType` y `Product2`.
- Procedencia ROT-3: `Opportunity.Portal__c`; después `Interest.source` solo si
  coinciden exactamente la referencia directa Opportunity→Interest del último
  snapshot 4B y una única referencia inversa Interest→Opportunity del F2
  vigente; después Fuente de origen Opportunity y fallbacks propios.
- No participan Lead, Account, email, teléfono, owner, vehículo ni proximidad
  temporal en Opportunity↔Interest. La fotografía legacy `portal_resolved`
  permanece por compatibilidad con Campañas, pero no es autoridad del informe.
- Tabla principal: `salesforce_opportunities`.
- Evidencia CRM local: `salesforce_opportunity_interest_direct_runs`,
  `salesforce_opportunity_interest_directs` y `salesforce_interests`.
- Fechas sincronizadas: `CreatedDate`, `OPO_FEC_Fecha_de_reserva__c` y
  `Fecha_firma_contrato__c`.
- Vehículo: `OPP_BUS_Vehiculo_de_interes__c` y matrícula de la relación ya
  sincronizada.

## Resumen Dirección: producción, cohorte y estado actual

Resumen Dirección separa tres conceptos que no deben sumarse ni interpretarse
como un único embudo:

- **Producción del período:** Reservas por `reservation_date` y Ventas por
  `cv_signed_date`, ambas con límites `[inicio incluido, fin excluido)`.
- **Cohorte de oportunidades creadas en el período:** pertenencia siempre por
  `created_date`; las reservas, caídas y firmas mostradas son el estado o
  resultado actual de esas oportunidades y pueden haber ocurrido después.
- **Estado actual:** Reservas vivas actuales de todas las fechas, como contexto
  independiente de Producción y Cohorte.

El selector legacy de criterio de fecha se oculta únicamente en Resumen porque
no redefine ninguna de esas tres lentes. Continúa visible y operativo en
Comerciales/delegaciones/zonas y Portales/procedencia. Mientras Resumen está
activo, la petición usa `created_date` sin modificar el valor conservado en el
selector para las pestañas legacy. Las claves históricas
`kpis`, `comparativa`, `universe_date_criterion`, `universe_date_label` y
`periodo_actual`/`periodo_comparado` mantienen su significado;
`produccion_periodo` y `cohorte_creacion` son contratos aditivos.

La respuesta conserva `inicio` y `fin` como fechas visibles inclusivas y añade
en cada período `technical.start_inclusive`, `technical.end_exclusive`,
`technical.semantics = "[start,end)"` y `technical.timezone`. Así los presets,
incluidos mes actual y mes anterior, se comparan sin aparentar solapamiento ni
usar `23:59:59` como frontera técnica. En mes actual, el tramo equivalente del
mes anterior se limita siempre al comienzo del mes actual para que un mes largo
no desborde ni solape períodos.

El catálogo de Delegación, Zona y Comercial se forma con la unión relevante de
producción y cohorte de creación bajo el ámbito resuelto en servidor. Cambiar
un filtro conserva las reglas temporales anteriores y no amplía permisos.

Resumen conserva la timezone global `UTC`; Rendimiento comercial usa
`Europe/Madrid`. Ambos imputan los hitos por su fecha propia, pero no deben
compararse sin respetar esa diferencia y el roster/atribución mensual de
Rendimiento. Comisiones también usa `cv_signed = true`, `cv_signed_date` y
excluye `Cerrada Perdida`, aunque puede reducir su universo por owner activo,
gestión de venta, elegibilidad, tipo y fórmulas económicas. RV-2 no importa
esas reglas económicas ni exige igualdad ciega de totales.

## Cohorte temporal legacy

En las pestañas legacy, el selector de fecha continúa definiendo una única
cohorte:

| Criterio | Campo local | Campo Salesforce |
|---|---|---|
| Fecha de creación | `created_date` | `CreatedDate` |
| Fecha de reserva | `reservation_date` | `OPO_FEC_Fecha_de_reserva__c` |
| Fecha de firma | `cv_signed_date` | `Fecha_firma_contrato__c` |

Después de fijar la cohorte, los KPI legacy, porcentajes, tablas y auditorías se
calculan sobre esas mismas oportunidades. Una oportunidad creada en julio y
firmada en agosto cuenta como firmada dentro de la cohorte de julio cuando el
criterio es Fecha de creación. Una oportunidad creada en junio y firmada en
julio queda fuera de esa cohorte. Las excepciones aditivas son las métricas de
Producción: Reservas se acota por `reservation_date` y Ventas por
`cv_signed_date`, sin alterar el universo ni el significado de los KPI legacy.

Los KPI anclados a la cohorte y su auditoría CSV resuelven el mismo dataset base
con idénticos filtros y ámbitos de servidor. La exportación decora ese conjunto
sin aplicar exclusiones funcionales adicionales: para `Oportunidades totales`,
el conjunto de Opportunity IDs del KPI y el del CSV debe ser idéntico. Para
Reservas y Ventas de Producción, KPI y auditoría comparten en su lugar el mismo
conjunto de eventos acotado por su fecha propia.

La pantalla muestra criterio, período, período comparado, actualización y corte
de la fotografía local. Al cambiar filtros se cancela la petición anterior y se
ocultan los resultados obsoletos.

## Reglas de KPI

- `Venta` = `RecordType.Name` Venta o Cambio.
- Reserva viva = reserva true, contrato CV no firmado y etapa distinta de
  `Cerrada Perdida`.
- `Reservas totales del período` = reserva true con `reservation_date` dentro
  del período. Incluye el estado actual vivo, `Cerrada Perdida` y CV firmado;
  excluye reservas sin fecha y no depende del criterio temporal de la cohorte.
  Se deduplica con la regla común vehículo + fecha de reserva y fallback a la
  Opportunity cuando no existe identidad de vehículo.
- `Ventas del período` = RecordType Venta o Cambio, CV firmado con
  `cv_signed_date` dentro del período y etapa actual distinta de
  `Cerrada Perdida`. Tasación, otros tipos y tipo ausente no cuentan como venta
  producida, aunque los KPI legacy conserven su universo previo. Se deduplica
  por vehículo + fecha de firma, con fallback a Opportunity sin identidad de
  vehículo. Un grupo con estados de clasificación incompatibles no se resuelve
  por orden técnico: se excluye del total y se publica como incidencia de
  calidad.
- Caída = etapa `Cerrada Perdida`.
- CV firmado = flag firmado true y etapa distinta de `Cerrada Perdida`.
- `Reservas vivas del universo seleccionado` es la reserva viva dentro de la
  cohorte definida por el criterio y período elegidos.
- `Reservas vivas actuales (todas las fechas)` aplica la regla de reserva viva
  sin filtro temporal, manteniendo tipo y filtros operativos.
- Los porcentajes de desglose se calculan como
  `métrica / oportunidades de la misma fila`; describen la proporción de la
  cohorte o fila, no una valoración de rendimiento.
- Reserva viva es el estado actual de una operación; no es una conversión ni
  una tasa de éxito. Una operación puede dejar de estar viva al avanzar.
- La participación se calcula como
  `métrica de la fila / total de la métrica` y se muestra por separado.
- No se usa un benchmark de conclusiones hasta que exista una definición
  funcional aprobada.

El informe es descriptivo: conserva valores, participaciones y diferencias
numéricas entre períodos, pero no emite recomendaciones, prioridades,
diagnósticos evaluativos ni llamadas externas de IA. No incorpora conversiones
7/14/30 días.

## Duplicados por vehículo y fecha

La identidad usa primero el ID Salesforce del vehículo y, si falta, la matrícula
normalizada. Sin ID ni matrícula no se deduplican oportunidades diferentes.

Si dos o más oportunidades comparten vehículo y exactamente la misma fecha de
reserva o firma:

- el evento cuenta una sola vez en el KPI;
- se genera una alerta con vehículo, fecha y Opportunity IDs;
- si propietario, tienda de entrega, delegación, zona o portal discrepan, el
  evento se asigna a `Incidencia de datos` en el desglose afectado;
- no se usa `LastModifiedDate` ni el orden de consulta para elegir un comercial
  o una tienda;
- todas las filas permanecen en la auditoría.

`counted_in_kpi` identifica técnicamente la fila que reconstruye el recuento
global, pero no la convierte en atribución funcional cuando hay campos
contradictorios.

La auditoría JSON y CSV acepta `metric=reservas_totales` y
`metric=cv_firmados_periodo`. Cada métrica consulta el período actual por su
fecha propia, conserva todas las filas relevantes de cada grupo duplicado y
utiliza `counted_in_kpi` para reconstruir exactamente el total deduplicado. No
se añaden campos personales al contrato de auditoría.

## Portal y agrupaciones

El portal se resuelve con la regla centralizada de Opportunity y Lead
relacionado. Un `Opportunity.Portal__c` concluyente gana siempre. En caso
contrario, el Lead relacionado prioriza `Fuente_origen__c`; solo si está vacío
aplica `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`.
Placeholders no vacíos son autoritativos y pueden normalizar a `Sin clasificar`
sin reactivar fallbacks. Se conservan portal original, portal final, fuente de
resolución, Lead utilizado y traza de nuevo/legacy. Delegación y zona proceden
del owner y usan la misma normalización territorial que los informes
comerciales.

## Auditoría y permisos

- JSON: `/informes/reservas-ventas/data/kpi-audit`.
- CSV: `/informes/reservas-ventas/export/kpi-audit.csv`.

El CSV incluye Opportunity ID, vehículo, matrícula, fechas, RecordType, etapa,
propietario, tienda, delegación, zona, cuenta, portal, grupo duplicado, tamaño,
fila contabilizada, Opportunity IDs afectados, campos contradictorios y estado
del desglose.

Las tablas de zonas, delegaciones, comerciales y portales incluyen siempre el
número absoluto de oportunidades de la fila. Ese valor constituye el contexto
de muestra de los porcentajes mostrados; no se ocultan filas ni se aplican
umbrales de suficiencia.

La exportación estándar no selecciona ni publica nombre de Opportunity, nombre
de Account, teléfono, móvil ni correos de cliente. `Opportunity.Name` se ha
retirado porque puede contener identidad del cliente y no es necesario para la
conciliación; se conservan `Opportunity ID` y los identificadores operativos no
personales necesarios. Los nombres de responsables internos se mantienen para
explicar la atribución y sus conflictos.

Todas las Opportunity IDs de un grupo duplicado permanecen como filas
auditables. `counted_in_kpi` solo identifica la fila técnica que suma el evento;
el resto del grupo no se elimina del CSV.

## Operación

```bash
php artisan salesforce:sync-opportunities --days=60
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01
php artisan reports:debug-reservas-ventas
php artisan reports:debug-reservas-ventas --reconcile-cohort --from=2026-07-01 --to=2026-07-31 --date-criterion=created_date --opportunity-type=all
php artisan reports:reprocess-opportunity-portals --from=2026-05-01 --to=2026-06-01 --dry-run
php artisan reports:reprocess-opportunity-portals --from=2026-05-01 --to=2026-06-01 --apply --reason="Motivo operativo aprobado"
php artisan salesforce:repair-opportunity-lifecycle-dates --dry-run
php artisan salesforce:repair-opportunity-lifecycle-dates --apply --reason="Incidencia aprobada y conciliada"
```

La reparación de fechas es una herramienta manual previa a cualquier reproceso
histórico que dependa de `created_date`. Parte solo de Opportunities locales con
`created_date IS NULL`, consulta Salesforce por lotes de hasta 100 IDs y puede
escribir exclusivamente `created_date` y `salesforce_last_modified_at`. Dry-run
no persiste; apply requiere motivo, admite `--limit`/`--after-id` y queda
auditado por ejecución. No usa el sincronizador completo, no modifica
`raw_payload`, portales, hitos ni datos económicos y nunca inserta Opportunities.
Debe conciliarse y completarse antes de cualquier reproceso histórico de
portales de Opportunities que seleccione filas mediante `created_date`.

La conciliación muestra únicamente cantidades y diferencias `A - B` / `B - A`
por Opportunity ID; no muestra datos de contacto. Debe ejecutarse en el entorno
que contenga la fotografía a investigar para identificar discrepancias reales.

La deduplicación se calcula al construir el dataset; no necesita migración ni
backfill. El reproceso de portales exige rango local `[from, to)`, modo explícito
y motivo en escritura. Trabaja por lotes sobre Opportunities ya existentes,
permite reanudar con `--after-id`, registra before/after y solo consulta Leads en
Salesforce para el matching existente. No consulta ni escribe Opportunities en
Salesforce. La herramienta está preparada, pero no se ha ejecutado el histórico
ni un dry-run productivo.

El dataset de Resumen usa el namespace de caché
`reservas-ventas-dashboard-v7`; V6 queda intacto hasta expirar. Este cambio no
afecta a la caché V4 independiente de Rendimiento comercial. La identidad V7
usa fechas visibles/canónicas estables y excluye timestamps técnicos variables;
el payload cacheado conserva los límites exactos del cálculo realmente servido.

Archivos principales:

- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`;
- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`;
- `app/Services/Reports/ReservasVentas/OpportunityPortalNormalizer.php`.

## Pestaña Rendimiento comercial

### Universo evaluable mensual

El roster histórico se conserva para explicar cobertura y exclusiones, pero no
concede por sí mismo objetivo, ranking ni presencia en la tabla. Un comercial
solo es evaluable si tiene identidad válida, al menos un Lead elegible,
Opportunity elegible, reserva total, venta válida o venta caída imputados al
mes, y una asignación mensual `observed` o `bootstrap_approved`. Las
cancelaciones aisladas, margen y métricas derivadas no activan actividad real.

Las filas con actividad y un histórico no certificable se muestran como **No
evaluable**, sin objetivo, cumplimiento, estado, ranking ni referencia de
delegación. Las filas certificadas sin actividad se excluyen de tabla y filtro
Comercial, y se contabilizan en la metadata del universo. `Incidencia de datos`
se publica en un bloque separado de calidad, fuera del universo evaluable.

El ranking y las comparativas se calculan tras Zona/Delegación y antes de
Comercial. Cada referencia de delegación incluye solo comerciales evaluables de la
misma delegación; Comercial solo limita filas visibles y conserva el puesto y
las referencias calculadas sobre el universo anterior. Sin filtro Comercial, el
KPI superior muestra el cumplimiento global. La presentación explicita
`X reservas computables / Y de objetivo = Z %` usando directamente las claves
publicadas, sin recalcular el porcentaje. Con un comercial seleccionado usa
`summary.reservations_valid_for_objective`, `summary.objective` y
`summary.fulfillment_pct`; un comercial no evaluable permanece como `N/D`. En
global usa `universe.global_reservations_valid_for_objective`,
`universe.global_target` y `universe.global_fulfillment_pct`. El cumplimiento
global sigue publicándose sin cambios en `universe` como
`SUM(reservations_valid_for_objective) / SUM(objective)` del universo evaluable
y devuelve `null` cuando no existe objetivo global.

Esta pestaña es independiente de la cohorte de las tres pestañas legacy. La
política se separa por capacidad: Administrador puede ver todas las zonas,
consultar auditoría y modificar el objetivo; Director/Dirección puede ver todas
las zonas y consultar auditoría, pero el objetivo es de solo lectura; Area
Manager puede ver el dashboard agregado y el objetivo en modo lectura, limitado
en servidor a `ReportUserAccess::areaZoneLabel()` y sin acceso a la auditoría.
Un Area Manager sin zona configurada recibe 403 y los parámetros HTTP nunca
amplían su ámbito. No se concede acceso a Delegation Manager, Viewer ni otros
roles. `PUT /informes/reservas-ventas/data/commercial-performance/target` queda
restringido a Administrador, conserva CSRF y valida mes `Y-m` y objetivo entero
mayor que cero.

El aislamiento incluye `data_quality`. Los recuentos de asignaciones observadas
y con bootstrap se recalculan desde las filas certificadas de la zona. Los
contadores globales sin dimensión zonal fiable —atribución no resuelta,
conflictos, histórico no certificable, cronología, cambios organizativos y
dependencias de cancelación— conservan su clave con `null`, nunca con el total
corporativo ni con un cero inventado. La interfaz omite el aviso cuantitativo en
ese caso. Esta ocultación se aplica también a `unresolved_dependencies` dentro
de cada entrada de `cancellation_coverage_by_month`; estados, rangos, cortes y
fechas certificadas siguen siendo globales y conservadores.

El informe mantiene un único bloque físico de filtros. Las tres pestañas legacy
presentan período, criterio de fecha, tipo de oportunidad y los controles
compartidos de delegación, zona y comercial. Al activar Rendimiento comercial,
ese mismo bloque oculta los controles de cohorte y muestra mes natural y el
objetivo mensual por comercial; solo Administrador dispone de edición y botón
Guardar. Es un único objetivo individual para todos los comerciales evaluables
del mes: no pertenece a una zona, delegación o comercial concreto. Los filtros
organizativos no cambian el valor almacenado y cada mes mantiene su propia fila;
zona, delegación y comercial conservan los mismos IDs DOM.
Cada cambio despacha únicamente el dataset del modo activo. Si una opción no
existe al cambiar de universo, se limpia y se repite una sola carga consistente.
Limpiar Rendimiento restablece mes y filtros organizativos, pero nunca modifica
el objetivo persistido.

### Último mes cerrado y mes en curso

El selector de Rendimiento comercial abre en el mes natural anterior de
`Europe/Madrid`, calculado en servidor; no representa una ventana de 30 días.
Por tanto, el primer día de enero selecciona diciembre del año anterior. El mes
actual continúa disponible para selección manual.

Al consultar el mes actual se muestra **Mes en curso · Resultado provisional**.
La actividad es la acumulada hasta el momento, pero el objetivo mensual completo
no se prorratea: cumplimiento, estado, ranking y comparativas mantienen las
fórmulas aprobadas y se interpretan como provisionales. Los meses anteriores no
muestran ese aviso. Durante loading o error el aviso previo se oculta junto con
los datos de la carga anterior.

### Resiliencia de filtros y auditoría

La base de Rendimiento comercial se cachea con el namespace
`reservas-ventas-commercial-performance-base-v4`. El valor cacheado contiene
solo arrays y escalares; las `Collection` se crean únicamente al presentar las
filas recuperadas. Esto mantiene `cache.serializable_classes=false` y permite
usar stores persistentes sin deserializar clases PHP.

Los contadores de `data_quality` se conservan por mes de hito antes de formar el
payload. El bloque público contiene exclusivamente el mes seleccionado:
atribución no resuelta, histórico no certificable, conflictos de grupo/margen,
cronología de cancelación y estados de roster. `data_incident` sigue siendo el
agregado reconciliable del mes seleccionado y no recibe objetivo.

Los filtros de la pestaña son dependientes: Zona limita Delegación y ambas
limitan Comercial. Comercial solo limita las filas visibles y no recalcula
universo, ranking ni comparativas. La tabla incluye un buscador local sobre las
filas ya renderizadas, sin peticiones ni recálculos; el Salesforce User ID no se
muestra en la vista normal y permanece disponible únicamente en los atributos
técnicos necesarios y en la auditoría autorizada. La preferencia independiente
`reservationsSalesCommercialPerformanceColumnsV5` estrena **Resumen** como vista
compacta por defecto sin leer, modificar ni eliminar V4. **Personalizar
columnas** conserva la selección individual y ofrece los presets **Resumen**,
**Actividad** y **Rentabilidad**; aplicarlos solo cambia y persiste visibilidad
local. Ranking, Estado y Comercial son obligatorias y permanecen fijas, en ese
orden, durante el desplazamiento horizontal. Al recargar se retiran los datos
anteriores;
si falla la petición se informa el error sin exponer detalles internos y se
ofrece `Reintentar` con los filtros actuales. La auditoría acepta los mismos
filtros de Zona, Delegación y Comercial, los aplica tras resolver la atribución
y antes de ordenar y paginar. Sus incidencias sin comercial se mantienen cuando
no hay filtro organizativo que las excluya naturalmente.

La dimensión técnica mantiene `Zona Mediterraneo` en filtros, payload, caché y
agrupaciones; la interfaz la presenta como **Zona Mediterráneo**. Los estados
`covered`, `partial` y `uncovered` permanecen en el contrato JSON, pero se
traducen respectivamente como **Histórico del período certificado**,
**Histórico del período parcialmente certificado** y **Sin histórico
certificado para todo el período**; cualquier valor desconocido se presenta
como **Cobertura no determinada**. `covered` certifica la continuidad de las
transiciones dentro del período evaluado, no congela sus KPI frente a cambios
futuros. Una modificación posterior incorporada por la sincronización
incremental puede reclasificar retroactivamente una reserva o venta.

La interfaz distingue dos relojes: **Datos de Salesforce sincronizados** procede
del máximo `updated_at` local de Opportunities y no se presenta como cutoff
contractual de Salesforce; **Informe generado con la fotografía local** procede
de `dataset_generated_at`. El corte certificado de OpportunityHistory se muestra
aparte dentro de la cobertura de cancelaciones.

La metodología, el universo, la fecha de la fotografía local, la cobertura de
cancelaciones y las incidencias de calidad o atribución se conservan en la
interfaz dentro del panel secundario plegable **Información y calidad de
datos**, cerrado inicialmente. Su resumen muestra únicamente el estado de
cobertura y, cuando está disponible, la fecha de la fotografía. Fuera del panel
permanecen el error de carga con su reintento, el aviso compacto de mes en curso
y un aviso compacto de limitación cuando `cancellations_available === false`.
Los registros fuera del universo y los contadores de auditoría no activan por sí
solos ese aviso exterior si los KPI siguen siendo evaluables. Una recarga limpia
el contenido dinámico anterior sin forzar el estado abierto o cerrado elegido
por el usuario.

La presentación numérica de Rendimiento comercial utiliza `es-ES`: enteros con
separador de millares, porcentajes con un decimal y espacio antes de `%`, puntos
porcentuales con coma decimal y moneda con dos decimales. Los importes de margen
no se extrapolan. Si `margin_coverage_pct` es inferior a 100 %, cada fila lo
muestra junto a sus valores de margen aunque la columna específica no esté
visible; el resumen calcula únicamente la cobertura de presentación mediante
`sales_with_margin / sales` cuando existen `sales_without_margin`, sin alterar
`margin_total` ni `average_margin_per_sale`.

### Actividad mensual y fórmulas

Cada hito pertenece a su propio mes natural `Europe/Madrid`:

| KPI | Universo/fecha local | Fórmula |
|---|---|---|
| Leads | Venta, Venta con cambio, Lead y Ayvens; `fecha_asignacion` | recuento por comercial efectivo consolidado |
| Oportunidades | `record_type_name IN (Venta, Cambio)`; `created_date` | recuento de Opportunity |
| Reservas totales | reserva true; `reservation_date` | evento deduplicado por vehículo+fecha |
| Reservas vivas | reserva del mes, CV false y no Cerrada Perdida | evento deduplicado por vehículo+fecha |
| Reservas caídas | reserva del mes, CV false y Cerrada Perdida | evento deduplicado por vehículo+fecha; se imputa a `reservation_date` |
| Ventas válidas | CV true, no Cerrada Perdida; `cv_signed_date` | evento deduplicado por vehículo+fecha |
| Ventas caídas | CV true y Cerrada Perdida | evento deduplicado por vehículo+fecha; se imputa a `reservation_date` o, si falta, a `cv_signed_date` |
| Cancelaciones | reserva previa y transición demostrable a Cerrada Perdida | `salesforce_opportunity_stage_transitions.transitioned_at` |
| Margen | ventas del mes; `informe_rentabilidad` | suma solo valores informados |

- Lead → Reserva = reservas totales / leads asignados × 100.
- Oportunidad → Reserva = reservas totales / oportunidades creadas × 100.
- Reserva → Venta = ventas firmadas / reservas totales × 100.
- % Reserva caída = reservas caídas / reservas totales × 100.
- % Venta caída = ventas caídas / Opportunities con CV firmado, usando para ambos
  `reservation_date` o, si falta, `cv_signed_date`.
- Cancelación = transiciones verificadas / reservas totales × 100.
- Cumplimiento = reservas válidas para objetivo / objetivo mensual × 100. Una
  reserva válida para objetivo es una reserva del mes que no está actualmente
  en `Cerrada Perdida`; se clasifica sobre la Opportunity original, no sumando
  reservas vivas y ventas de meses distintos. Si Salesforce marca en septiembre
  como `Cerrada Perdida` una reserva fechada en agosto, la fotografía actual
  reclasifica retrospectivamente agosto como reserva caída y deja de aportarla
  al cumplimiento; no cambia `reservation_date` ni duplica la Opportunity.
- Margen medio = margen informado / ventas con margen informado.

Una Venta caída con reserva no demostrada se imputa a la fecha de CV si existe,
no cuenta como reserva ni cumplimiento y queda identificada así en la auditoría.
Si no existe ninguna de las fechas de anclaje, no se inventa un mes. Las
cancelaciones continúan siendo transiciones históricas y no sustituyen ninguna
de las métricas de caída: la reserva del ejemplo anterior cae en agosto por su
estado actual, mientras la cancelación pertenece a septiembre por
`transitioned_at`. Backend: `null`. En Evolución mensual el frontend conserva
`N/D` y añade la explicación del estado real de
`data_quality.cancellation_coverage_by_month` —incluido `certified_until` cuando
existe—; una cobertura `covered` sin eventos continúa mostrando `0`. Un
denominador cero nunca produce infinito ni 0 % ficticio. La ayuda visible los
identifica como **Ratios de actividad mensual**: cada hito usa su propia fecha,
no son ratios de cohorte y pueden superar el 100 %. El objetivo
default inicial es 18; cada mes consultado se materializa inmediatamente en
`commercial_performance_monthly_targets`, aunque no haya sido editado, para que
un cambio futuro de la constante no reescriba el histórico. Cambiar un mes no
reescribe los demás. El **Estado** visible conserva la clave JSON
`traffic_light` y sus umbrales: verde ≥100,
amarillo ≥80, naranja ≥60 y rojo por debajo de 60, exclusivamente sobre
cumplimiento.

La migración usa nombres MySQL explícitos para los dos identificadores que
excederían el límite de 64 caracteres con la convención automática:
`commercial_perf_target_updated_user_fk` y `sf_opp_stage_history_uq`. La FK
conserva `ON DELETE SET NULL` y el ID de historial continúa siendo único.

El cumplimiento global se publica en `universe` y suma exclusivamente reservas
válidas para objetivo y objetivos de comerciales evaluables dentro del scope de
Zona/Delegación. El filtro Comercial no modifica ese universo. Una incidencia
conserva eventos operativos en su bloque separado, pero no recibe objetivo,
cumplimiento, estado ni ranking y no amplía el denominador global.

Existe una sola fila y un solo objetivo por `commercial_id` y mes. La delegación
solo habilita comparaciones: cualquier hueco o cambio de delegación dentro del
mes mantiene la actividad individual agregada, pero la marca como `Histórico no
certificable`, sin escoger equipo arbitrariamente. El roster inicial incluye a
todo comercial cuyo único intervalo certificado cubre el mes completo —o el
corte transcurrido del mes actual—, pero las filas sin actividad real se
excluyen del universo final.

El filtro Comercial incluye identidades comerciales válidas aunque su delegación
mensual no sea certificable. Zona y Delegación solo ofrecen asignaciones
observadas o con bootstrap aprobado. El ranking se construye antes de aplicar el
filtro Comercial; los empates exactos comparten posición y no usa margen,
cancelación ni scoring compuesto.

Las comparativas de delegación usan exactamente la misma población que el ranking:
filas evaluables con actividad real tras aplicar Zona y Delegación, pero antes
de aplicar Comercial. Cada comercial se compara solo con los evaluables de su
misma delegación, incluso si la zona contiene varias delegaciones. La media de
reservas y los ratios Lead → Reserva, Oportunidad → Reserva y Reserva → Venta se
calculan agregando sus contadores. Cada celda comparativa presenta primero el
ratio del comercial, después la referencia de su delegación y finalmente una
flecha con el valor absoluto y el texto por encima, por debajo o igual. Si falta
un denominador o referencia se conserva `N/D` y no se inventa una flecha. Las
cabeceras visibles emplean **Ratio**, no **Conversión**, porque no son cohortes.
Las claves técnicas `team_*` y `*_vs_team` se mantienen para no romper el contrato
JSON ni las preferencias de columnas guardadas. Las filas no evaluables publican
estos campos como `null`.

### Cancelaciones verificadas

La investigación de solo lectura del 25/08/2026 confirmó:

- `OpportunityFieldHistory` es consultable pero devolvió cero filas;
- `OpportunityHistory` devolvió historial y, en cinco operaciones reales
  reservadas, secuencias explícitas hacia `Cerrada perdida`;
- una operación mostró `Reserva → Cerrada perdida → Presupuesto → Reserva →
  Cerrada perdida`, por lo que se preservan transiciones distintas;
- toda transición demostrable conserva estado de calidad; solo cuenta como
  cancelación si existe reserva y `reservation_date <= transitioned_at`.

Cada consulta correcta a `OpportunityHistory` materializa su intervalo en
`salesforce_opportunity_history_sync_intervals`. El mes actual se evalúa desde
su inicio hasta el último cutoff realmente consultado y certificado, que se
muestra en la interfaz. El sync captura un `observationCutoff` al comenzar,
recorta el rango solicitado a ese instante y no persiste intervalos futuros; el
cutoff nunca avanza por el reloj del dashboard. Los meses cerrados sí exigen el mes natural completo.
Solo la unión continua de intervalos aptos para KPI permite mostrar cancelaciones. Sin
cobertura o con cobertura parcial, recuento, porcentaje y comparación devuelven
N/A; cero significa cobertura certificada sin transiciones aplicables.

Una transición cuya Opportunity no existe en la réplica activa una recuperación
directa por Salesforce ID, en lotes de 100, reutilizando el SELECT y la
persistencia canónica de `SalesforceOpportunitySyncService`; después se
reclasifica en la misma ejecución. Si Salesforce no devuelve el ID, se conserva
`opportunity_not_local`. El intervalo guarda el número de dependencias y queda
`is_kpi_certified=false`: nunca puede producir cero cancelaciones. `reservation_date` permanece NULL
cuando no se conoce; no se fabrica una fecha. Una candidata Cerrada Perdida sin
etapa inmediatamente anterior demostrable se conserva como
`previous_stage_not_demonstrated` y también bloquea la certificación. Una fila
posterior cuya etapa previa ya era Cerrada Perdida no representa una transición
nueva y no bloquea por sí sola.

`CloseDate` y `LastModifiedDate` no se guardan ni consultan como fecha de
cancelación. `LastModifiedDate` se usa exclusivamente en la sincronización
incremental para descubrir Opportunities antiguas modificadas.

#### Validación productiva y procedimiento reproducible de solo lectura

**Validación realizada.** En producción se comprobó satisfactoriamente un caso
real, sin registrar PII: existe una única fila local, `reservation_date`
conserva el mes original y el estado actual Cerrada Perdida reclasifica ese mes
como reserva caída y la excluye del cumplimiento. La transición capturada por
OpportunityHistory continúa contabilizándose en el mes de `transitioned_at`.
La cobertura diagnosticada fue coherente con los intervalos almacenados. Esta
evidencia valida la mecánica retroactiva, pero no implica que se hayan
inspeccionado o certificado todos los casos históricos.

**Procedimiento reproducible.** Para futuras comprobaciones, localizar sin
exponer datos de cliente una Opportunity con `reservation_date` en agosto,
`stage_name = Cerrada Perdida` y `salesforce_last_modified_at >= 2026-09-01`.
Sobre su `salesforce_id`, verificar en modo de solo lectura que existe una única
fila local, que la reserva conserva agosto y el estado actual es Cerrada
Perdida. Después comprobar que agosto la clasifica como caída y no la suma al
cumplimiento; si OpportunityHistory capturó la transición, confirmar que su
`transitioned_at` está en septiembre y que la cancelación se contabiliza en ese
mes. Nunca se debe inventar evidencia ni incluir PII en el registro de
comprobación.

Si producción mantiene un hueco real de OpportunityHistory en septiembre, el
intervalo debe continuar como `partial`. La corrección operativa se estudiará
con el comando existente y su modo `--modified`, previa aprobación y revisión
del rango; esta documentación no autoriza ni ejecuta sincronización o backfill,
ni permite marcar el período como `covered` sin intervalos certificados.

### Delegación histórica

La metadata Salesforce acreditó que `Delegacion_del_propietario__c` es una
fórmula calculada desde `Owner.USR_SEL_Delegacion__c`; no es histórica.
`UserFieldHistory` no está disponible y el tracking consultado de delegación no
devolvió cambios. Por ello:

- `commercial_delegation_snapshots` crea intervalos observados desde la
  implantación y los cierra al cambiar delegación, desactivarse el usuario o
  abandonar los perfiles comerciales. Para detectar este último caso, el sync
  refresca por ID solo usuarios ya conocidos aunque hayan salido del filtro
  comercial; conserva el `IsActive` real para no alterar consumidores compartidos;
- por decisión de negocio, la primera asignación observada fiable y sin
  contradicciones puede extenderse en un intervalo cerrado desde
  `2026-04-01 00:00 Europe/Madrid`. El source
  `business_bootstrap_2026_04` evita presentarlo como observación Salesforce;
  no se modifica ninguna fecha real y una segunda ejecución explícita no duplica filas.
  Esta materialización solo se solicita manualmente con
  `--bootstrap-performance-history`; la captura normal nunca la ejecuta ni
  retroatribuye a un comercial observado después. Además, una reejecución
  explícita solo acepta usuarios cuyo primer snapshot observado coincide con el
  mínimo `observed_from` de la fotografía inicial. Los posteriores se informan
  como `not_initial_cohort` y quedan excluidos permanentemente del bootstrap;
- un cambio posterior de delegación/zona genera una alerta operacional `low`
  durable y deduplicada, además de cerrar/abrir los intervalos. Si ocurre dentro
  del mes, se muestra una nota ámbar y no se escoge una delegación mensual;
- la identidad de un usuario cuyo perfil actual ya no es comercial se sigue
  cargando cuando sus snapshots solapan el período consultado. El snapshot, no
  el perfil presente, acredita su pertenencia histórica; después del cierre no
  se incorpora como miembro cero de meses futuros;
- la pertenencia mensual exige cobertura continua y una sola delegación;
- los eventos de meses con huecos o cambios se muestran como `Histórico no certificable`, sin
  media de delegación ni ranking y sin aplicar la delegación actual hacia atrás;
- los usuarios inactivos permanecen en el dataset si tienen actividad e
  intervalos históricos.

El perfil `Comerciales Partner Community` forma parte expresamente del universo
comercial: con actividad real y snapshot histórico válido sigue siendo
evaluable, recibe el objetivo mensual y participa en ranking aunque el usuario
esté actualmente inactivo. No se filtra por `is_active` ni por el perfil actual
cuando el snapshot acredita el período.

Todos los hitos usan el mismo universo de responsables. Un owner de API,
Administración, Marketing, Area Manager u otro perfil no comercial sin snapshot
histórico aplicable se atribuye a `Incidencia de datos`: permanece auditable como
`non_commercial_responsible`, pero no participa en objetivos, medias ni ranking.
Un usuario actualmente no comercial sí conserva los meses acreditados por sus
snapshots históricos.

### Sincronización y despliegue

El scheduler ejecuta diariamente a las 07:10 (`Europe/Madrid`):

```bash
php artisan salesforce:sync-opportunities --days=2 --modified
```

La réplica conserva las Opportunities retiradas de Salesforce para auditoría,
pero las marca con `is_deleted`, `salesforce_deleted_at` y
`deletion_detection_source`. El sync incremental consulta con `queryAll` los
borrados confirmados por `IsDeleted=true` en la misma ventana de
`SystemModstamp`. Solo `is_deleted=true` con fuente `query_all_deleted` sale del
scope: `presence_reconciliation_missing` conserva el registro reportable porque
una ausencia no acredita borrado, fusión, purga ni pérdida de permisos. Las
cancelaciones materializadas de una Opportunity confirmada como eliminada
tampoco participan en rendimiento.

Para el histórico existe una conciliación manual por IDs locales, sin descubrir
ni insertar Opportunities:

```bash
php artisan salesforce:reconcile-opportunity-presence --dry-run --limit=1000
php artisan salesforce:reconcile-opportunity-presence --apply --reason="Motivo operativo aprobado" --limit=1000
```

El cursor `--after-id` permite reanudar. `query_all_deleted` acredita un borrado
devuelto por Salesforce; `presence_reconciliation_missing` solo documenta que
el ID no fue recuperable durante una conciliación explícita, sin atribuir una
causa, inventar fecha de borrado ni excluirlo. Una Opportunity confirmada como
eliminada que reaparece activa queda pendiente de refresh: solo el mapper
completo de `SalesforceOpportunitySyncService` puede limpiar el lifecycle y
publicarla de nuevo. Apply usa mutex, lotes de 100, bloqueo local y auditoría por
ejecución. `rows_pending_canonical_refresh` y su muestra acotada de Salesforce
IDs permiten localizar reapariciones pendientes sin exponer PII. Si el apply
confirma al menos un borrado, el propio comando ejecuta inmediatamente y solo
`StockSaleValidityService::reconcile()`: no sincroniza Product2, logística,
Opportunities ni crea snapshots. Si esa etapa local falla después de persistir
lifecycle, puede reintentarse de forma aislada con
`php artisan stock:reconcile-sale-validity`. No borra filas ni escribe
Salesforce.

`SystemModstamp` es el cursor técnico de delta porque también refleja cambios de
procesos automáticos y es el campo indexado para replicación. Se conserva como
evidencia técnica de la detección, no como afirmación contractual de la hora
exacta del borrado, exclusivamente en `salesforce_deleted_at`;
`salesforce_last_modified_at` sigue reservado a `Opportunity.LastModifiedDate`.

La prueba sintética prevista en sandbox no pudo ejecutarse. El lifecycle sí fue
desplegado y conciliado en producción el 8 de septiembre de 2026: `queryAll`
recuperó tres Opportunities reales con `IsDeleted=true` y `SystemModstamp`, tres
IDs quedaron como ausencias diagnósticas y 40.718 como activos. Tras corregir el
casing del payload en PR #40, el apply materializó el timestamp técnico de los
tres borrados y el dry-run final examinó 40.724 filas con cero cambios, errores,
concurrencias omitidas o reactivaciones pendientes. Esta evidencia valida el
flujo operativo sin convertir `SystemModstamp` en una fecha comercial de
borrado.

La franja queda fuera de atribución de Campañas (02:15), refresco (03:15), Stock
(03:30, también escribe Opportunities) y el bloque SEO (05:15–06:30). La
captura de delegaciones pertenece exclusivamente al sync mensual cada 15
minutos y el scheduler usa `--days=2` sin la opción de bootstrap. La ventana incremental consulta `LastModifiedDate`, actualiza la
fotografía local y sincroniza `OpportunityHistory` del mismo intervalo.

Procedimiento de despliegue, sin ejecución automática:

```bash
php artisan migrate:status
php artisan salesforce:sync-monthly-commercial --days=2 --bootstrap-performance-history
php artisan salesforce:sync-opportunities --from=2026-04-01 --to=2026-05-01
php artisan salesforce:sync-opportunities --from=2026-05-01 --to=2026-06-01
php artisan salesforce:sync-opportunities --from=2026-06-01 --to=2026-07-01
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01
php artisan salesforce:sync-opportunities --from=2026-08-01 --to=<día-posterior-al-cutoff>
```

Primero se enumeran y aprueban todas las migraciones pendientes; no se ejecuta
`migrate --force` hasta aprobar el lote. Tras migrar, el comando mensual con
`--bootstrap-performance-history` se ejecuta una sola vez: se concilian
snapshots observados, bootstrap creados/existentes, omitidos y conflictos. A
partir de ahí actúa el scheduler normal sin la opción. Cada backfill mensual se ejecuta y valida por separado,
registrando filas, duración, llamadas Salesforce, transiciones válidas,
`opportunity_not_local`, `previous_stage_not_demonstrated`, cobertura e
`is_kpi_certified`. No es necesario ampliar el inicio para buscar reservas
anteriores: las Opportunities candidatas ausentes se resuelven por ID. Si quedan
dependencias, se documenta la causa y se repite únicamente el intervalo afectado
después de resolverla. `--to` continúa siendo un límite exclusivo.

La disponibilidad retrospectiva de `OpportunityHistory` depende de la retención
real de Salesforce. Las candidatas sin etapa previa demostrable quedan fuera de
cancelaciones, se persisten como incidencia y mantienen el intervalo no
certificado. Las dependencias se calculan desde el estado actual de las
transiciones: cuando una reejecución resuelve la calidad y una cobertura
certificada solapada completa el rango, la deuda antigua deja de mostrarse. El
render, los filtros, medias, ranking y evolución leen únicamente BD
local y no incluyen PII de clientes. La auditoría específica muestra IDs de
Lead/Opportunity/historial, hitos, responsable, delegación/cobertura, clave de
deduplicación e incidencias/exclusiones, con paginación limitada a 200 filas.
La procedencia de cada atribución se expone como `delegation_status`:
`observed` (Observada), `bootstrap_approved` (Bootstrap aprobado) o
`not_certifiable` (No certificable), con `delegation_issue` cuando corresponde.
Se conserva `delegation_certified` por compatibilidad, pero la interfaz no llama
certificación Salesforce al bootstrap. Calidad publica
`delegation_history_evaluable_from`, `delegation_history_observed_from` y
`delegation_history_bootstrap_from`; el campo histórico
`delegation_history_certified_from` permanece solo por compatibilidad.

## Matching Opportunity → Lead independiente del lote

La búsqueda agrupada deriva cada teléfono con una implementación compartida:
solo dígitos y retirada del prefijo español `34` cuando precede a nueve
dígitos. `salesforce_leads.phone_normalized` y
`mobile_phone_normalized`, ambos indexados, se usan exclusivamente para
descubrir Salesforce Lead IDs activos. Los candidatos se reconsultan después
en Salesforce vivo mediante `Lead.Id IN (...)`; solo la respuesta viva aporta
fecha, contacto y procedencia para la resolución final. Los correos mantienen
su política vigente de trim y minúsculas.

El fallback local de `leads_raw` se decide por cada email sin resultado
Salesforce, no por el resultado global del lote. No se usa como fallback de
teléfono. Para Leads recientes aún ausentes del índice local se conserva como
fallback una consulta Salesforce agrupada, exacta y sin `LIKE`, limitada a las
siete representaciones seguras ya existentes; no es el descubrimiento
principal. La comparación final vuelve a aplicar el mismo normalizador a los
datos vivos. Los candidatos se ordenan por
`CreatedDate` descendente y, en empate, por `Lead.Id` ascendente. Así, posición,
composición y límites de chunk no alteran `portal_resolved`, fuente, Lead
seleccionado ni debug. Se mantienen la precedencia funcional existente y el
universo de Opportunities.

La estructura se despliega separada de los datos. Después de aplicar la
migración, el histórico se prepara primero en dry-run y solo se materializa con
autorización operativa explícita:

```bash
php artisan salesforce:backfill-lead-phone-normalization --dry-run
php artisan salesforce:backfill-lead-phone-normalization --apply
```

El comando es local, reanudable con `--after-id`, admite `--limit`, usa mutex en
apply, realiza UPDATE masivo por lotes y nunca consulta ni escribe Salesforce.
