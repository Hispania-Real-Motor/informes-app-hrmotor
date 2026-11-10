# Auditoría técnica — recuperación de informes de octubre de 2026

Fecha de auditoría: 2026-11-10.
Base revisada: `4977df8ee7ebbc2485a8454e64e701908c20c56a` (`origin/main`).
Alcance: inspección estática de código, migraciones, pruebas y documentación.

## Límite de esta auditoría

No se consultó Salesforce, no se conectó a producción y no se ejecutaron
sincronizaciones, recuperaciones, migraciones ni cambios de datos. Por ello,
las referencias a tablas locales describen **capacidad de evidencia del
esquema y los contratos de lectura**; no afirman que una fila, run o cierre de
octubre exista o sea completo en producción. Cada bloque marcado como
«contraste local requerido» necesita comprobarse por un operador autorizado,
en lectura, antes de hacerse visible.

La recuperación mensual F2/F2M/F5 de Interests sigue aislada. No es una
generación publicada y no puede convertirse en fuente oficial por este
documento ni por una adaptación de UI.

## Respuesta ejecutiva

| Informe / bloque | Qué permite mostrar el contrato si existe evidencia validada | Qué no debe mostrarse | Siguiente decisión |
| --- | --- | --- | --- |
| Intereses | Octubre provisional: sólo el universo F2M activo con contenido coincidente que conste en la evidencia validada; total, estado, tipo, fuente, medio, canal, procedencia/delegación/grupo/zona y porcentajes de conversión/descarte, con filtros combinados sobre proyección versionada. | Comerciales, delegación/zona comercial, owner/no asignados, gestión, actividad, llamadas, formularios y comparativas. | Diseñar evidencia histórica separada de Users y actividades antes de cualquier KPI comercial. |
| Reservas / Ventas | Sus métricas nativas de Opportunity son técnicamente independientes del journal mensual, **si** hay `report_sync_runs` completos y filas locales de octubre. | Atribución a Interest, portal derivado de Interest y cualquier indicador que declare relación Interest certificada. | Contrastar cobertura Opportunity y snapshot directo Opportunity–Interest; ofrecer primero bloque nativo separado si supera el contraste. |
| Llamadas | Métricas de `salesforce_calls` por su fecha pivote, **si** existe cobertura local completa y clasificación aplicable. | Portal resuelto a partir de un Interest sin generación publicada; cualquier conclusión de actividad comercial de Interests. | Verificar cobertura/clasificación de octubre y separar portal `interest` de portal propio/preservado. |
| Campañas | Métricas nativas de Meta/Google con cierre o cobertura de runs válida; el servicio ya prevé respuesta `platformOnly` cuando falta Interest certificado. | Leads/ventas/ROI atribuidos, auditoría y exportación dependientes de Interests. | Comprobar cobertura de cada plataforma y snapshots de cierre; no reutilizar F2M mensual como entrada de atribución. |
| Comisiones | Sólo un cierre o las fuentes exigidas por cada pestaña para octubre; ningún dato puede inferirse desde Interests provisionales. | Cierres económicos, fórmulas, objetivos o rankings si falta una dependencia histórica. | Auditar `report_sync_runs`, roster, objetivos, ajustes y snapshots por pestaña. |
| Stock | Ventas congeladas en `salesforce_sale_snapshots` y stock diario si existen fechas de octubre. | Stock histórico reconstruido desde el estado actual, o ventas no validadas. | Verificar que hay snapshots del mes y validez de ventas; no depende de F2M salvo cruces que hoy no deben habilitarse. |

## Inventario de evidencia de Interests

### Lo que la recuperación de octubre conserva

Las migraciones mensuales delimitan la evidencia disponible:

| Origen | Identidad / fecha | Campos útiles | Límite material |
| --- | --- | --- | --- |
| F2, `salesforce_interest_monthly_recovery_rows` | `salesforce_id`, `functional_created_at`, hash e indicador de borrado observable. | Pertenencia del inventario y contenido canónico. | No contiene campos funcionales ni histórico previo a la observación; hard deletes no acreditados. |
| F2M, `salesforce_interest_monthly_materialized_rows` | `salesforce_id`, referencia a F2 y hashes; fecha funcional. | Tipo, estado, origen, medio, canal, delegación de procedencia, UTM, relaciones Lead/Account/persona, vehículos, oportunidad inversa, `owner_salesforce_id` y `owner_name`. | La materialización es una lectura posterior y no registra vigencia histórica de owner, perfil, User o delegación comercial; no guarda payload. |
| F5 mensual, `salesforce_interest_monthly_activity_rows` | `(activity_kind, activity_salesforce_id)`, `interest_salesforce_id`, `who_salesforce_id`, `activity_date`/`start_datetime`, hashes y borrado observable. | Existencia y relación Task/Event→Interest dentro de la recuperación selectiva. | No conserva `OwnerId` ni el actor responsable de actividad, resultado funcional completo, ni una dimensión histórica de usuario/comercial. |
| Proyección HTTP, `salesforce_interest_october_provisional_projection_rows` | Clave y revisión de evidencia. | Una fila categórica sin ID de Interest: tipo, estado, procedencia, fuente/medio/canal y agregados filtrables. | Está minimizada deliberadamente: no permite atribuir resultados a Interest, comercial ni actividad. |

`October2026ProvisionalInterestReader` invalida la lectura si cambia la
revisión de integridad. `October2026ProvisionalInterestDashboardDatasetService`
consulta únicamente esa proyección en HTTP; por tanto no escanea F2/F2M/F5 ni
puede responder filtros comerciales con ella.

### Diagnóstico de atribución comercial

No existe una reconstrucción fiable de los desgloses por comercial para
octubre a partir de los journals actuales:

1. F2M tiene el owner observado al materializar, pero no una relación
   `User`/delegación con intervalo de vigencia al corte de octubre. Un owner
   actual, una delegación actual o un perfil actual no prueba la asignación
   histórica.
2. `Reports\\MonthlyCommercial\\Sync\\SalesforceMonthlyUsersSyncService`
   materializa Users actuales y su estado actual; no es una tabla as-of. Las
   tablas de snapshots de delegación deben acreditarse para octubre antes de
   usarlas.
3. F5 identifica Interest, `WhoId` y fechas, pero no conserva `OwnerId` ni el
   usuario responsable de la actividad, y tampoco acredita su elegibilidad
   histórica. No permite equiparar existencia de actividad con «gestionado» si
   la fórmula oficial exige condiciones adicionales.
4. La cobertura y las discrepancias sólo pueden tomarse de la evidencia
   versionada que contraste el operador autorizado; esta auditoría estática no
   las mide. Ninguna ausencia o discrepancia puede rellenarse por owner,
   Opportunity o estado actual.

**Resultado:** no habilitar comerciales, zonas/delegaciones comerciales,
no asignados, potenciales sin trabajar, gestionados, llamadas o formularios
como KPI de Interests. El único camino admisible es una fase que cree una
evidencia de identidad y vigencia independiente, versionada y vinculada a la
misma revisión F2M/F5. Debe fallar cerrada ante huecos, reasignaciones,
usuarios inactivos o cambios de delegación dentro del mes.

## Matriz de disponibilidad y bloqueo

| Informe / pestaña o KPI | Fuente y fecha pivote actuales | Estado de octubre | Bloqueo / riesgo de integridad | Solución mínima propuesta | Coste y prueba necesaria |
| --- | --- | --- | --- | --- | --- |
| Intereses / resumen y procedencia | Proyección categórica versionada; `functional_created_at` civil Madrid. | Disponible, provisional y sólo administración. | No es generación oficial; cobertura limitada al universo F2M activo/coincidente. | Mantener contrato actual y aviso discreto. | Ya evita escaneo HTTP; probar revisión cambiante y totales por filtros combinados. |
| Intereses / comerciales y asignación | F2M owner observado; Users y delegaciones locales. | No disponible. | Falta snapshot histórico User/delegación y vigencia; riesgo de atribuir reasignaciones posteriores. | Inventario administrativo de identidades con intervalos y hash de fuente, sin tocar F2M. | Consulta local indexada por owner/fecha; pruebas de reasignación, baja y cambio intra-mes. |
| Intereses / actividad, gestionados y potencial sin trabajar | F5 mensual por `WhatId`, fechas de actividad. | No disponible. | F5 no conserva actor/estado completo y no certifica reglas de actividad. | Especificar primero fórmula y atributos mínimos; recuperar sólo lectura autorizada si falta evidencia local. | Bloques de IDs, checkpoints y conciliación de cardinalidad; no SOQL por fila. |
| Intereses / comparativa | Requiere segundo período equivalente. | No disponible. | No hay base mensual comparable validada. | Recuperación independiente de otro mes con mismo contrato o mantener vacío. | Pruebas de no mezclar cortes/revisiones. |
| Reservas/Ventas / totales, reservas, caídas, CV | `salesforce_opportunities`; `created_date`, `reservation_date`, `close_date`, `cv_signed_date`. | Contraste local requerido; potencialmente independiente. | No se ha probado cobertura de octubre ni inmutabilidad de valores históricos. | Validar `report_sync_runs` que cubra [2026-10-01, 2026-11-01), filas y deduplicación Opportunity. | Agregación SQL por fecha, reconciliación de IDs y pruebas de timezone/DST. |
| Reservas/Ventas / portal y atribución Interest | Snapshot directo Opportunity–Interest + `InterestGenerationReader`. | Bloqueado. | Servicio exige Interest publicado; F2M no satisface su contrato ni prueba relación histórica. | Crear contrato de atribución mensual independiente sólo tras conciliación explícita. | Pruebas de direct/inverse mismatch, borrado y cutoff; no cambiar el lector oficial. |
| Rendimiento comercial | Opportunities, transiciones, roster mensual, Interests publicados. | Bloqueado parcialmente. | Métricas agregan Interests y requieren roster/cobertura de historial. | Separar oportunidades nativas de métricas Interest; no reutilizar owner F2M. | Pruebas de roster/huecos y eventos duplicados. |
| Llamadas / resumen, agentes y delegaciones | `salesforce_calls`; fecha en `created_date` y reglas de clasificación. | Contraste local requerido; parcialmente independiente. | Falta probar run completo/clasificación de octubre; portal puede depender de Interest. | Validar cobertura y exponer sólo métricas cuyo portal provenga de llamada o histórico preservado. | Índices por fecha/filtros, pruebas de llamadas sin `WhatId`, soft delete y portal no Interest. |
| Campañas / inversión, impresiones, clics, CTR, CPC | `campaign_platform_daily_metrics`; fecha diaria civil Madrid. | Contraste local requerido; potencialmente disponible en modo plataforma. | Cada plataforma requiere cierre válido o run que cubra el intervalo; no certifica resultados CRM. | Usar el contrato `platformOnly` existente tras validar Meta y Google separadamente. | Pruebas de huecos, día parcial, DST y cero válido. |
| Campañas / leads, conversiones, ventas, ROI y auditoría | Atribuciones y Opportunities/Interest certificados. | Bloqueado. | `CampaignPublicationReadiness` exige runs completos, snapshot Opportunity–Interest y generación Interest publicada. | Nuevo contrato mensual de atribución sólo si hay evidencia de relaciones y cutoffs coherentes. | Pruebas de fingerprint, cambios de inputs y rechazo de fuente provisional. |
| Comisiones / seis pestañas | Opportunities, evidence, roster, objetivos, ajustes y cierres por pestaña. | Contraste local requerido; no dependiente automáticamente de F2M. | Cada cierre exige cobertura distinta; usar un histórico viejo no supera la política de snapshot/cierre. | Auditar antes las fuentes de octubre con `CommercialCommissionSourceReadinessService`; mantener 409/no disponible por bloque. | Pruebas de cobertura mensual, snapshots cerrados, nulos y permisos. |
| Stock / ventas y rotación | `salesforce_sale_snapshots.signed_date`, `stock_daily_snapshots.snapshot_date`. | Contraste local requerido; potencialmente disponible. | Un snapshot actual no es stock de octubre; falta confirmar series y `is_valid`. | Leer snapshots de octubre ya existentes; no recalcular desde `salesforce_vehicles` actuales. | Reconciliar una fila vehículo/día y una venta/Oportunidad; pruebas de snapshot ausente. |

## Procedimiento de contraste autorizado (sólo lectura)

Antes de habilitar cada bloque, un operador con acceso autorizado debe producir
un acta sin IDs ni datos personales que incluya:

1. Rango civil `Europe/Madrid`: inicio `2026-10-01 00:00:00`, fin exclusivo
   `2026-11-01 00:00:00`, y su conversión UTC cuando la fuente sea `DATETIME`.
2. `report_sync_runs` completados que cubran sin huecos el rango de cada fuente
   (`salesforce_opportunities`, llamadas, plataformas, Users/evidencia de
   comisiones), con modo, corte, inicio, fin y hash/versión disponibles.
3. Cardinalidad, clave única y máximas fechas de las tablas fuente; para
   snapshots, contadores por fecha y ausencia de duplicados. Los conteos no
   bastan: añadir muestra hash/manifest o fingerprint de columnas funcionales.
4. Para Interest comercial: evidencia con `interest_salesforce_id`, User ID,
   delegación, inicio y fin de vigencia; conciliación uno-a-uno contra el
   universo elegible de la evidencia validada, sin conservar IDs en la UI ni
   en logs.
5. Para cruces Opportunity–Interest: snapshots directos/inversos, estado de
   referencia, cutoff y diferencias. Rechazar contradicción, relación múltiple
   o fuente posterior sin as-of.
6. Para actividades: número de Tasks/Events por Interest, relacionados nulos,
   borrados observables, actor y fecha funcional; validar la fórmula antes de
   presentar «gestionado».
7. Ejecutar esas verificaciones como comando administrativo versionado y
   persistir sólo resumen, hashes, revisión y referencias de run. HTTP debe
   consumir una proyección/snapshot validado y volver a fallar cerrado si su
   revisión cambia.

No se debe usar `updated_at` como única prueba de integridad, ni `getDeleted`
como sustituto de historial. Una consulta Salesforce adicional requeriría
autorización explícita, sólo lectura, un manifiesto nuevo y una evidencia
separada; no debe actualizar journals históricos.

## Diagnóstico del frontend desplegado

En la versión de código auditada, `renderSummary()` llama a
`renderKpis(data.kpis || {}, data.provisional === true)`. La rama provisional
usa `provisionalKpiCards()`, que mantiene `No disponible` para métricas sin
evidencia; la rama oficial usa `officialKpiCards()`. Por tanto, una captura de
octubre que muestre ayuda oficial puede deberse a una de estas causas, que no
pueden distinguirse sin observación del despliegue:

1. El JSON de summary no incluye `provisional: true`.
2. El HTML/asset Vite servido no contiene la lógica de PR #126.
3. Navegador, CDN o servidor entrega un `manifest`/bundle anterior.
4. La ruta no es el modo `period=october_2026` autorizado o la evidencia no
   corresponde a la revisión esperada.

Contraste posterior al despliegue autorizado: registrar versión de `main`, URL
del asset del manifest Vite, cabeceras `Cache-Control`/ETag, SHA-256 del asset
servido y una respuesta summary sin datos personales. Confirmar
`provisional === true`, clave/revisión coincidentes y que el DOM contiene la
ayuda provisional. No atribuirlo a caché sin comparar esos valores.

## UX: cambios exclusivamente visuales propuestos

1. Etiqueta de período: «Octubre 2026 · datos reconstruidos»; tooltip breve:
   «No sustituye el informe certificado». Mantener el estado material en la
   respuesta, no sólo en el texto.
2. Mantener tarjetas disponibles y presentar las no disponibles como `—` con
   causa corta («requiere histórico de comercial»), nunca como cero.
3. Ocultar la pestaña/comparativa cuando no haya un período equivalente, en
   lugar de una tabla de ceros.
4. Separar «filtros del universo reconstruido» de «búsqueda visual» y no
   ofrecer filtros comerciales hasta que exista una proyección multidimensional
   validada.
5. Reutilizar estados de carga/error: mensaje comprensible para evidencia
   invalidada, sin revelar run IDs, SQL ni detalles de fuente.

Estos cambios no habilitan KPI, no modifican fórmulas ni sustituyen lectores.
Se implementan después de cada contrato de datos y con pruebas JS de permisos,
fallback, navegación y accesibilidad.

## Plan de implementación en PR pequeñas

| Orden | PR propuesta | Dependencia y entrega | Riesgo / condición de salida |
| --- | --- | --- | --- |
| 0 | Auditoría (esta rama) | Documento, checklist y casos de prueba; sin cambios funcionales. | Revisión técnica de los contratos y acta de cobertura local. |
| 1 | Intereses — evidencia comercial | Comando administrativo de contraste y proyección versionada de identidad/vigencia, enlazada a F2M/F5. | No publicar si falta as-of, hay reasignación ambigua o revisión cambia. |
| 2 | Intereses — KPI comerciales | Adaptador provisional que lee sólo proyección de PR 1; paginación y filtros combinados. | Reconciliación con el universo elegible validado, sin readers oficiales ni KPI de actividad no demostrados. |
| 3 | Reservas/Ventas nativas de octubre | Snapshot administrativo de Opportunities cuando cobertura local sea completa. | Separar métricas/portales Interest; no cambiar `OpportunityInterestAttributionService`. |
| 4 | Llamadas nativas de octubre | Snapshot de clasificación por versión y fecha; exclusión de fallback Interest no certificado. | Cobertura completa, DST, acceso por rol y no exponer debug. |
| 5 | Campañas plataforma-only | Reutilizar cierre/runs validados y mostrar sólo inversión/entrega nativa. | No ROI, conversiones ni exportación dependiente de Interest. |
| 6 | Comisiones y Stock, separadas | Un PR por contrato de cierre/snapshot realmente cubierto. | Cierres económicos/versiones inmutables; no ampliar permisos ni recalcular con estado actual. |
| 7 | UX transversal | Sólo después de contratos anteriores; componentes de disponibilidad consistentes. | Ningún texto puede convertir provisional en certificado. |

## Casos de prueba requeridos por fase

- Identidad comercial: reasignación antes/después de octubre, cambio de
  delegación intra-mes, User inactivo, owner nulo, identidad no elegible,
  duplicado y revisión que cambia durante validación.
- Actividad: Task/Event posterior a la fecha del Interest, relación nula o
  inesperada, soft delete, reanudación, interrupción entre bloques y sin
  duplicar contadores.
- Opportunity y Campañas: relación directa/inversa conflictiva, cutoff
  desalineado, oportunidad sin Interest, reserva/venta duplicada, cierre
  ausente y plataforma con hueco o día parcial.
- Llamadas: `WhatId` nulo, portal propio, portal preservado, Interest no
  certificado, reglas de clasificación versionadas y `Europe/Madrid` en DST.
- Comisiones/Stock: mes sin snapshot, cero válido, snapshot invalidado,
  cierre reabierto, permisos y aislamiento por ámbito.
- Rendimiento y seguridad: una consulta/proyección por endpoint, sin N+1 ni
  lectura de journals en HTTP; caché con clave de revisión; 403/404/503
  fail-closed; logs sin identificadores personales ni payloads.

## Archivos principales revisados

- `app/Services/Reports/Leads/October2026ProvisionalInterestReader.php`
- `app/Services/Reports/Leads/October2026ProvisionalInterestDashboardDatasetService.php`
- `app/Services/Reports/Leads/SalesforceInterestDashboardDatasetService.php`
- `app/Services/Salesforce/InterestMonthlyMaterializationService.php`
- `app/Services/Salesforce/MonthlyInterestActivityRecoveryService.php`
- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`
- `app/Services/Reports/ReservationsSales/OpportunityInterestAttributionService.php`
- `app/Services/Reports/Calls/CallDashboardDatasetService.php`
- `app/Services/Reports/Calls/CallInterestPortalResolver.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- `app/Services/Campaigns/CampaignPublicationReadiness.php`
- `app/Services/Reports/CommercialCommissions/CommercialCommissionSourceReadinessService.php`
- `app/Services/Reports/Stock/StockDashboardDatasetService.php`
- Migraciones de F2/F2M/F5 mensuales, Opportunities, Calls y Stock; y la
  documentación funcional enumerada en el encargo.

## Riesgos pendientes

- La cobertura real de octubre de Opportunities, Calls, plataformas,
  snapshots, Users y cierres no se puede deducir del código: requiere el acta
  de contraste autorizada.
- Las fuentes actuales de Salesforce no ofrecen automáticamente una fotografía
  histórica as-of; no sustituir ese límite por valores actuales.
- Cualquier contrato compartido que adopte fuente mensual debe ser nuevo y
  explícito. Cambiar `InterestGenerationReader` o el puntero de publicación
  mezclaría evidencia provisional con la fuente certificada y queda excluido.
