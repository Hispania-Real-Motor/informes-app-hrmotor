# Handoff para agentes

## Campañas — comparativa Google Venta y agregación por contexto (2026-10-27)

- Rama/worktree de esta iteración: base `aa23c2d3acc0a1f9e380ceb4fb967e28bbee1cfd`, commit funcional `78536fe2bcb1c35b37a09e5080b808f1c9484fae` (PR #91). Cambian `CampaignDashboardDatasetService::attributionRows()` y sus tests: el contexto se aplica a cada `campaign_lead_attributions` antes de agrupar por plataforma/Campaign ID/nombre. En una campaña sintética mixta Venta/Tasación, el `MIN(campaign_type)` agregado podía excluir del detalle y de la comparativa Venta todas las atribuciones, aunque quedara inversión Google y el KPI deduplicado contara la venta. Otro test conserva un cero Google real sin Interests y Meta con venta. No se cambian first touch, matching, atribuciones persistidas, métricas históricas, fórmulas, snapshots ni cierres.
- El payload económico pasa de caché `campaign-dashboard-v7` a `v8` y la auditoría KPI de `campaign-dashboard-kpi-audit-v1` a `v2`, evitando servir respuestas anteriores durante su TTL de diez minutos. El esquema y permisos del CSV de campañas no cambian, pero sus recuentos en un contexto mixto pueden corregirse porque `exportRows()` usa el mismo payload que el detalle. No afirmar invariancia de los valores presentados. No hay migración, variable, dependencia, proveedor ni llamada adicional durante HTTP; la consulta SQL agrega el subconjunto filtrado.
- El diagnóstico de octubre sigue **pendiente**: no hubo acceso a datos productivos ni evidencia de que el caso sintético explique el cero Google comunicado. El [procedimiento de diagnóstico](../operaciones-produccion.md#diagnóstico-de-google-ads-venta-con-inversión-y-cero-resultados) fija el rango civil Madrid 1–27 octubre como UTC `[2026-09-30 22:00:00, 2026-10-27 23:00:00)`, con consultas parametrizadas y agregadas de `campaign_lead_attributions` y `campaign_attributions` por plataforma/tipo, campañas mixtas, filtros efectivos y linaje de sincronización. No devuelve PII ni identifica campañas en el resultado compartible. Distinguir cero legítimo, filtro/contexto y fuente no certificada antes de proponer otro cambio.
- Documentación modificada por el agente documental: `docs/informe-campanas.md`, `docs/operaciones-produccion.md`, `docs/matriz-trazabilidad-kpi.md` y este HANDOFF. No cambian módulos o convenciones (`PROJECT_CONTEXT.md`), ni se adopta una decisión arquitectónica nueva (`DECISIONS.md`). La suite PHP completa pasó con 1507/1507 pruebas y JavaScript con 25/25 antes de versionar las claves de caché; después pasaron 73/73 pruebas PHP focales, Pint y `git diff --check`. El validador local pasó con una descripción temporal que incluía `Docs-Impact: updated` y `Docs-Matrix-Impact: updated`, pero el PR #91 se abrió sin descripción: el job `Validate PR documentation` falló exclusivamente por ausencia de `Docs-Impact`. Esta corrección solo actualiza este HANDOFF y la descripción del PR: no cambia base de datos, configuración, permisos, seguridad ni rendimiento. Sus 38 pruebas documentales pasaron con una omisión por symlinks en Windows; `git diff --check` pasó. Quedan el validador integral sobre el commit definitivo y los checks remotos tras publicar. Sin consultas productivas, sincronización, despliegue ni limpieza de caché global desde esta tarea. Riesgo pendiente: comparar los recuentos autorizados de octubre y el CSV antes/después bajo filtros idénticos; rollback de código restaura la agregación anterior y puede volver a mostrar ceros incorrectos en campañas mixtas.

## Campañas — reconciliación horaria y contexto publicado (2026-10-27)

- Rama `fix/campaign-automatic-update-closures`, base de esta iteración `eb9ef9e69079433587f397345b109b7884b0470a`; diff sin commit al iniciar la revisión. Cambios de aplicación en `ReconcileCampaignPublicationCommand`, `CampaignPublicationReadiness`, comando de refresco, metadata del dashboard, catálogo y salida operacional. El agente documental modificó únicamente `docs/informe-campanas.md`, `docs/operaciones-produccion.md`, `docs/matriz-trazabilidad-kpi.md`, `docs/ai/DECISIONS.md` y este HANDOFF. `PROJECT_CONTEXT.md` permanece porque no cambian módulos ni convenciones generales.
- La nueva tarea del catálogo se programa cada hora de 09:20 a 23:20 en `Europe/Madrid`, sin disparo manual desde la interfaz; cada pasada reintenta una sola dependencia mediante su reserva operacional existente. No modifica los horarios ordinarios de las fuentes, la atribución ni el refresco. El límite de tres runs no omitidos por tarea y día civil, con origen `scheduler`, incluye la pasada ordinaria. Un fallo, una reserva ocupada o el límite pueden dejar la publicación pendiente después de las 23:20. Mantener el scheduler detenido durante la revisión previa al despliegue, autorizar por separado su reactivación y comprobar entonces que la tarea nueva esté habilitada y reserve a lo sumo una hija por pasada.
- El refresco omite snapshots duplicados si la publicación predeterminada ya es vigente. Guarda `filters_hash` SHA-256 de filtros normalizados sin fechas; `published(start,end,filters)` exige misma huella y cobertura del snapshot y atribución para acreditar `dataset_cutoff_at`. Un subperíodo cubierto con igual contexto puede usar la marca; filtros distintos, rango externo, huella histórica distinta y período totalmente cerrado quedan neutrales. No se modifican fórmulas, universo, atribuciones, cierres, CSV ni datos económicos; no hay migración, nueva variable ni petición externa durante HTTP. Los snapshots antiguos no se reescriben.
- Seguridad y rendimiento: una tarea hija por pasada y límites de reintentos acotan carga de proveedores; se conservan exclusión persistente, auditoría y permisos existentes. El código no garantiza recuperación de toda la cadena el mismo día ni calidad de datos productivos. El cero Google Ads Venta sigue pendiente de diagnóstico autorizado. Pruebas focales 155/155 y suite PHP completa 1505/1505 (13.890 aserciones); JS 25/25, Pint focalizado y `git diff --check` correctos. La suite detectó una expectativa de 21 alertas en `OperationalTaskReviewTest`; se actualizó a 22 para incluir el nuevo task y se repitió íntegramente. Validador de PR pendiente del HEAD y cuerpo definitivos. Sin sincronización real, base de datos productiva ni despliegue ejecutados.

## Revisión sénior de Campañas — linaje de fuentes y publicación (2026-10-27)

- Rama `fix/campaign-automatic-update-closures`, base `493e6052ef499c74e56d563b937365f4b06f29a0`, commit previo `0e007785aad3c53568b72c30c59a3bc1114baa80`. El diff nuevo incorpora `CampaignPublicationReadiness`, comandos de atribución/refresco, catálogo y salida operacional, contadores de Opportunity, huella del snapshot directo, metadata del dashboard, pruebas de publicación y prueba aislada MariaDB. El agente documental modificó exclusivamente `docs/informe-campanas.md`, `docs/informe-reservas-ventas.md`, `docs/informe-comisiones.md`, `docs/informe-stock.md`, `docs/matriz-trazabilidad-kpi.md`, `docs/operaciones-produccion.md`, `docs/guia-mantenimiento-documental.md`, `docs/ai/PROJECT_CONTEXT.md`, `docs/ai/DECISIONS.md` y este HANDOFF; el agente principal debe revisar el diff final. Los tres documentos funcionales adicionales explican que los metadatos compartidos de sincronización no cambian sus universos, fórmulas ni exportaciones. La guía registra el mapeo explícito del comando compartido `SalesforceSyncOpportunitiesCommand.php` a los cuatro informes: su nombre plural no coincidía con el prefijo reconocido por el validador. El agente principal añade el mapa y su regresión; la validación del PR debe repetirse con el HEAD definitivo.
- Decisión: `report_sync_runs` aporta linaje persistente para fuentes Meta, Google, F2 y Opportunities; el snapshot directo aporta ID/cutoff/huella. El builder automático exige fuentes completas mediante `--require-ready`, conserva en su run IDs de fuentes y rango y omite reconstrucción cuando F2/Opportunity no cambiaron materialmente, la huella directa sigue igual y el rango ya está cubierto. Un build manual sin evidencia completa se marca `unverified`, nunca certifica publicación. La tarea programada no se ejecuta si la evidencia falta.
- `reports:refresh-campaigns --store` requiere runs Meta/Google que cubran el tramo abierto y atribución vigente que cubra el rango; revalida linaje antes de insertar el snapshot y lo registra con su run de publicación en la misma transacción. `dataset_cutoff_at` depende de una publicación persistida que cubra el período consultado, con `dataset_freshness_reason` genérico en ausencia de evidencia. Un período totalmente cerrado conserva lectura del snapshot aprobado, pero no acredita frescura operativa en la marca. Los últimos runs de fuente pueden proceder de operación manual válida; el éxito operacional viejo no basta frente a un run fuente más reciente fallido. La caché versiona estados de runs para no conservar una fecha acreditada después de un fallo.
- Cierres no consecutivos: el rango ordinario comienza después del último mes cerrado; una reapertura anterior solo permite un rango aislado que acabe antes del cierre siguiente y requiere autorización económica. La prueba MariaDB aislada usa dos procesos para la carrera cierre/escritura; no se extrapola a carga productiva. Sin nueva migración, configuración, proveedor HTTP, permiso ni fórmula económica. El diagnóstico Google Ads Venta sigue pendiente de datos autorizados; no se cambió matching ni se certificaron KPI.
- Seguridad y rendimiento: errores del refresco no imprimen excepciones sensibles, consultas de linaje son locales y por dataset/source; los runs intermedios se recorren por lotes. No se añaden llamadas remotas al render ni consultas por campaña. La retención transversal de runs completados (~un mes) y fallidos/no verificados (14 días) permanece; la pérdida de evidencia requerida degrada frescura o fuerza reconstrucción, sin prolongar retención en este correctivo. El agente principal ejecutó la suite PHP completa: 1.493/1.493 pruebas y 13.680 aserciones correctas; tras añadir una regresión adicional, el archivo focal `CampaignPublicationReadinessTest` pasó 9/9. JavaScript: 25/25; Pint focalizado: correcto; sintaxis PHP: 16/16; MariaDB temporal: carrera de dos procesos superada (`entered`, `metric_written`, `closed`, espera de cierre 1,71 s y conexión de escritura fijada). La instancia MariaDB de prueba se detuvo. El agente documental ejecutó 37 pruebas documentales: 36 correctas y una omitida por restricción local de symlinks Windows. Queda la validación documental con HEAD y cuerpo de PR tras el commit. No declarar éxito productivo, reactivación del scheduler ni actualización de octubre.

## Campañas — guarda de cierres y evidencia de actualización (2026-10-27)

- Base comprobada por el agente principal: `origin/main` `493e6052ef499c74e56d563b937365f4b06f29a0`; rama aislada `fix/campaign-automatic-update-closures`. El agente documental recibió el diff de trabajo, incluidos los archivos nuevos, y solo modificó `docs/informe-campanas.md`, `docs/operaciones-produccion.md`, `docs/matriz-trazabilidad-kpi.md`, este HANDOFF y `docs/ai/DECISIONS.md`. Commit y validación final pendientes del agente principal.
- Problema y decisión: la lectura del informe respetaba snapshots cerrados, pero Meta/Google y el builder podían reemplazar el rango histórico solicitado por `--days=120`. El nuevo `CampaignOpenInvestmentWindow` consulta la última fila `closed` de `campaign_investment_closures` en la conexión de escritura, acota rangos mixtos y bloquea los totalmente cerrados. Sincronizadores, builder, cierre y reapertura comparten bloqueo asesor MariaDB/MySQL; se revalida la ventana antes de cada reemplazo transaccional. `--dry-run` de atribución permanece de solo lectura. No se cambian reglas de matching, KPIs, inversión cerrada ni snapshots.
- Observabilidad: Meta/Google declaran intervalo efectivo en CLI y guardan estadísticas sanitizadas en `report_sync_runs`; un rango totalmente cerrado queda `skipped` con motivo `closed_period`, y una cuenta/inventario fallido no constituye run completo. Meta rechaza paginación incompleta, y ambos validan fecha diaria antes de persistir. La ventana programada del catálogo sigue en 120 días: el servicio aplica el límite también en CLI manual. Los comandos calculan fecha civil por defecto en Europe/Madrid.
- Dashboard: el aviso de F2 posterior a atribución requiere evidencia persistida de Interest modificado dentro del período o previamente atribuido a él; una sincronización horaria sin cambios no lo dispara ni oculta cambios anteriores pendientes. La caché versiona run F2 y estado operacional Meta/Google. `dataset_cutoff_at` queda nulo y la UI dice «Actualización del informe no verificada» cuando falta alguna de las dependencias que el método verifica. **Límite:** esto todavía no certifica la última sincronización Opportunities/directa ni el último refresco del snapshot; no afirmar actualidad integral a partir de la etiqueta.
- Google Ads Venta: no hay acceso a registros productivos y la causa del cero comercial no está verificada. No se cambiaron atribución, first touch, denominadores ni clasificación. El procedimiento agregado de solo lectura en `docs/operaciones-produccion.md` sigue campaña/cuenta, UTM Interest, matching/ambigüedad, Opportunity, Venta y filtros sin exponer PII. Solo una pérdida reproducible con datos autorizados justificaría otro cambio funcional.
- Base de datos y configuración: ninguna migración ni variable nueva; no se modifican tablas históricas con datos reales en esta tarea. Las pruebas de aplicación usan datos sintéticos. Seguridad: sin credenciales, tokens, respuestas completas de proveedor, acceso remoto ni consultas productivas; errores sanitizados, guardas también para comandos manuales y sin permiso HTTP nuevo. Rendimiento: una consulta indexable de cierre por operación y procesamiento por cuenta existente; el bloqueo puede agotar su espera de 30 segundos bajo contención y aborta de forma segura. No se añaden consultas por campaña durante HTTP.
- Pruebas ejecutadas: suite PHP completa, 1.484 pruebas y 13.651 aserciones correctas; 51 pruebas focales de cierres y operaciones correctas tras el último ajuste; 25 pruebas JS de Campañas correctas; build Vite correcto con bundle y manifest comprobados; Pint focalizado, sintaxis PHP y `git diff --check` correctos. Las 37 pruebas documentales finalizaron con 36 correctas y una omitida por la limitación de symlinks de Windows. La comparación sintética conserva filas de inversión, atribuciones y snapshots cerrados. El validador integral de PR requiere HEAD del commit y descripción definitiva. No declarar validación MariaDB concurrente, recuperación de octubre ni causa productiva Google sin evidencia adicional.
- Operación pendiente: verificar inventario de cierres y procesos antiguos antes de reactivar el scheduler; publicar release con bundle/manifest; autorizar por separado cualquier recuperación del mes abierto; comparar huellas y KPIs cerrados antes/después. Rollback de código/assets únicamente, con escritores económicos desactivados si se vuelve a un release sin guarda. No se despliega, sincroniza ni altera producción aquí.

## Google Ads v25 — fechas de la sincronización económica (2026-10-27)

- Base `origin/main` `67e43f7cf9cc0b175d0c1ac4637095c8402b9267`; rama `fix/google-ads-v25-economic-dates`. El agente documental recibió el diff completo de los dos servicios y las pruebas; modificó únicamente `docs/informe-campanas.md`, `docs/matriz-trazabilidad-kpi.md`, `docs/operaciones-produccion.md` y este HANDOFF. Commit final pendiente del agente principal.
- Causa: según la evidencia productiva comunicada, Google v25 acepta una consulta económica mínima pero rechaza la completa con `INVALID_ARGUMENT / UNRECOGNIZED_FIELD` por `campaign.start_date`/`campaign.end_date`. La documentación oficial v25 define `campaign.start_date_time`/`campaign.end_date_time` en hora civil de la cuenta. El código pide esos campos y valida `startDateTime`/`endDateTime` como `YYYY-MM-DD HH:MM:SS`, fecha calendario y hora válidas; extrae la fecha sin convertir zona. Ausencia o vacío quedan `NULL` en los DATE existentes; una fecha inválida aborta el reemplazo transaccional por cuenta.
- Las respuestas `searchStream` HTTP 200 estructuralmente incompletas producen error genérico antes de sustituir métricas previas; una respuesta válida vacía conserva el contrato existente. No cambian `segments.date`, micros, impresiones, clics, conversiones, Campaign ID textual, atribuciones, fórmulas, API/CSV, snapshots ni cierres. No hay migración, dependencia, configuración nueva ni assets. El estado operativo actual conserva su inventario y sincronización separados.
- Seguridad/rendimiento: excepciones genéricas y advertencias existentes sin tokens ni respuestas completas; sin consulta adicional por campaña, llamadas HTTP del dashboard o cambios de permisos. Las pruebas usan HTTP simulado y SQLite; no se consultaron Google, Salesforce ni bases de datos productivas.
- Pruebas comunicadas por el agente principal antes de esta revisión: focal Google 8/8, focal campañas 161/161, suite PHP completa 1473/1473, Pint focal, sintaxis y `git diff --check` correctos. El agente documental ejecuta por separado las validaciones documentales. Ninguna prueba local certifica aún la recuperación productiva ni una comparación de métricas reales antes/después.
- Despliegue pendiente de autorización: publicar el código tras revisión, confirmar configuración efectiva v25 sin imprimir secretos, acordar intervalo abierto y supervisar la siguiente ejecución económica autorizada, cotejando run, advertencias y métricas por cuenta/día. No sincronizar ni reconstruir meses cerrados. Rollback de código restaura el release anterior sin tocar datos, pero reintroduce los campos GAQL rechazados; las escrituras productivas posteriores requieren evaluación separada. No hubo despliegue, merge, push, sincronización real ni operación destructiva en esta tarea.
- Riesgo pendiente: el comportamiento del proveedor y la integridad económica productiva tras la siguiente ejecución solo se podrán confirmar con evidencia operacional autorizada. La auditoría integral de KPIs de Campañas continúa pendiente. `PROJECT_CONTEXT.md` y `DECISIONS.md` no cambian: se corrigen campos y validación del cliente existente, sin arquitectura o decisión nueva difícil de revertir.

## Correctivo Meta Ads / Google Ads para estados actuales (2026-10-27)

- Base de trabajo comunicada: `main` en `6f80e06aa8ddf5e5510789ec1f1a11e6a7e9dbc2`; rama `fix/campaign-provider-current-status-v25`. Alcance de este registro: `MetaAdsClient`, `GoogleAdsClient`, `config/services.php` y pruebas focales de estado, dashboard y operación; documentación en `docs/informe-campanas.md`, `docs/matriz-trazabilidad-kpi.md`, `docs/operaciones-produccion.md` y este HANDOFF. El agente documental solo editó documentación. El SHA de commit resultante lo aporta el agente principal.
- Causa: Meta rechaza el parámetro de filtrado `effective_status` del inventario aunque permite devolver ese campo. Google `v22` devuelve `UNSUPPORTED_VERSION` pese a OAuth correcto; una GAQL de atributos en `v25` respondió HTTP 200 según evidencia productiva comunicada. La consulta Meta sin el parámetro devolvió 189 campañas, 10 ACTIVE y 179 PAUSED, en una página. Esa observación no demuestra todavía que el sincronizador completo funcione para todas las cuentas.
- Decisión: Meta mantiene `fields=id,account_id,name,status,effective_status` y quita solo el parámetro rechazado; conserva validación de estados, normalización `act_`, paginación y escritura atómica por cuenta. Google cambia el predeterminado `GOOGLE_ADS_API_VERSION` y los fallbacks del cliente a `v25` para inventario `search` y métricas/identificadores `searchStream`; una configuración explícita sigue prevaleciendo. No se modificaron fórmulas, normalizadores económicos, filtros históricos, API/CSV, atribuciones, snapshots o cierres. No hay migraciones ni nuevas dependencias.
- Seguridad y rendimiento: pruebas con HTTP simulado; no se ejecutaron APIs reales, sincronizaciones ni activación del scheduler. Las advertencias de integración son genéricas; el error simulado no expone tokens ni escribe métricas. No se añaden llamadas durante HTTP, nuevas consultas por campaña ni logs de payload. `OperationalTaskOutput` ya marca como fallida una ejecución `campaigns-sync-google` que comunica fallo aunque su comando salga con código 0; se añadió regresión, sin cambiar su contrato.
- Pruebas comunicadas por el agente principal antes de la revisión documental: suite PHP 1469/1469, JavaScript 25/25 y Pint focal correctos. El agente documental ejecuta por separado las pruebas y validaciones de documentación; la validación integral del PR requiere commit HEAD y descripción final. Ninguna prueba simulada acredita compatibilidad de `searchStream` económico con cuentas Google reales ni integridad productiva posterior a una sincronización autorizada.
- Acción operacional posterior, no realizada: revisar el valor efectivo de `services.google_ads.api_version`. Si producción define `GOOGLE_ADS_API_VERSION=v22`, el nuevo predeterminado no surte efecto; un responsable debe aprobar su cambio a `v25`, refrescar caché de configuración y reiniciar procesos persistentes. Mantener `CAMPAIGN_CURRENT_STATUS_ENABLED=false` al desplegar. La primera ejecución de estados requiere autorización separada y revisión por cuenta de `operational_task_runs`/`report_sync_runs`; la ejecución anterior ID 4728 falló y no dejó estados verificados, según contexto comunicado. Antes de cualquier nueva sincronización económica revisar rangos y cierres; no se autoriza reprocesar períodos cerrados. Rollback coordinado de código y configuración, sin borrado de tablas ni regeneración histórica.
- Riesgos pendientes: cobertura real de cuentas Meta, compatibilidad completa de Google `v25` para `searchStream` económico y recuperación del estado vigente en el dashboard requieren verificación operacional autorizada. La auditoría integral de KPIs de Campañas sigue pendiente; esta corrección no la certifica. `PROJECT_CONTEXT.md` y `DECISIONS.md` no cambian: se corrige el cliente existente y su versión predeterminada, sin arquitectura ni convención nueva.

## Cierre de seguridad del validador documental (2026-10-23)

- Estado inicial: rama `chore/report-docs-maintainer`, HEAD `0113146fe88750e5eaf76dbd679746856c2f571b`. Se conservaron las modificaciones previas en `.gitignore`, `AGENTS.md`, este HANDOFF y los seis archivos nuevos de infraestructura; sin limpieza, cambio de rama, commit ni push.
- Problema: el estado Git `T` pasaba; un symlink nuevo o modificado podía presentarse como `A` o `M` y pasar; `.gitignore` y la CI principal podían declararse `Docs-Impact: none` sin revisión de controles.
- Archivos ajustados ahora: `scripts/check_documentation_integrity.py`, `tests/documentation/test_check_documentation_integrity.py`, `.github/workflows/docs-integrity.yml`, `docs/guia-mantenimiento-documental.md` y este HANDOFF. El workflow conserva `contents: read`, checkout sin credenciales y `pull_request`; no se modificaron PHP de aplicación, reglas de negocio, documentos funcionales ni configuración de producción.
- Solución: `D`, `R`, `T` y estados inesperados fallan con ruta y causa. Para cada ruta no eliminada del diff NUL, `git ls-tree -z` consulta en lotes el modo de HEAD con pathspec literal; solo se aceptan archivos regulares `100644`/`100755`. Symlinks `120000`, gitlinks y modos no verificables fallan. Los documentos obligatorios y los enlaces locales de la matriz se comprueban sin seguir componentes symlink fuera del repositorio. `.gitignore` y `.github/workflows/ci.yml` se añaden a los controles exactos que exigen `Docs-Impact: updated`, guía y pruebas documentales en el diff; no se generaliza a otros workflows.
- Decisión HANDOFF: `AGENTS.md` mantiene la obligación de actualizarlo en tareas del proyecto. No se convierte en puerta automática para cada PR funcional: la comprobación por rutas no puede certificar el contenido ni distinguir de forma fiable todas las ediciones editoriales; el agente principal y el revisor humano deben verificarlo. La primera PR documental con `Docs-Impact: updated` sigue admitida por prueba específica.
- Base de datos/configuración: sin migraciones, cambios de datos, variables de entorno ni cachés. Seguridad: no se leen destinos de symlinks ni secretos; antes de Python, el workflow comprueba modos Git regulares del script y pruebas documentales y modo árbol de sus directorios. No se introducen permisos de escritura, `pull_request_target`, autoaprobación, commits, push o despliegues. Rendimiento: una consulta `ls-tree` por lote de hasta 64 rutas cambiadas; el preflight solo consulta los entry points y directorios de control, sin recorrido global del repositorio ni ejecución Laravel/Salesforce.
- Pruebas: antes de corregir, 34 pruebas con 9 fallos esperados en `T`, symlinks `A`/`M`, controles y estado inesperado. El control previo del workflow también falló en prueba focal antes de añadirse. Después, 35 pruebas descubiertas: 34 correctas y una omitida localmente porque Windows deniega crear un symlink real (WinError 1314). Las tres integraciones con repositorio Git temporal y entradas `120000` sí se ejecutaron y pasan, incluido `T` real. Las 27 pruebas anteriores siguen pasando. Pendiente verificar la prueba de symlink del sistema de archivos y el preflight Bash en Linux CI; la CI remota aún no se ha ejecutado.
- Acciones manuales: revisar el diff completo, incluidos archivos aún no rastreados, y configurar `docs-integrity` como required status check tras la primera PR. La carga efectiva del TOML del subagente sigue **NO VERIFICADA**. No se realizó la auditoría inicial de informes.

## Hardening de la infraestructura documental (2026-10-23)

- Estado inicial verificado antes de editar: rama `chore/report-docs-maintainer`, HEAD `0113146fe88750e5eaf76dbd679746856c2f571b`. Existían cambios sin confirmar en `.gitignore`, `AGENTS.md`, `docs/ai/HANDOFF.md` y seis archivos nuevos de infraestructura documental. Se conservaron. La referencia local `origin/main` es `72d7fb968bb61c168dcd38c9c624882380ab1b72`, distinta del SHA remoto comunicado `01d3aa8ad428ad413f184741d9a4f2314696f1fe`; no se hizo fetch y no se afirma el estado remoto actual.
- Problema: faltaba el evento `pull_request.edited`; un documento funcional actualizado bastaba sin matriz en el diff; rutas PHP/Blade/JS sin palabras clave podían escapar; el servicio financiero solo exigía la guía genérica de Comisiones. Los controles también podían alterarse desde sus pruebas o instrucciones sin revisión conjunta.
- Archivos ajustados dentro de la infraestructura: `.github/workflows/docs-integrity.yml`, `scripts/check_documentation_integrity.py`, `tests/documentation/test_check_documentation_integrity.py`, `docs/guia-mantenimiento-documental.md`, `docs/matriz-trazabilidad-kpi.md` y este HANDOFF. Se mantuvieron `.codex/agents/report_docs_maintainer.toml`, `.gitignore` y `AGENTS.md` tal como estaban al inicio de esta tarea. No hay archivos eliminados ni renombrados, ni cambios de PHP de aplicación.
- Decisiones: el workflow escucha `opened`, `synchronize`, `reopened`, `edited` y `ready_for_review` hacia `main`, con Python 3.12 mediante `actions/setup-python@v5`. Para código funcional mapeado con `Docs-Impact: updated`, `Docs-Matrix-Impact: updated` exige la matriz en el diff; `unchanged` exige razón técnica y evidencia en una ruta funcional modificada. La declaración no prueba la semántica y requiere revisión humana. Las rutas ejecutables sin mapa se detectan por directorio/extensión; `Docs-Outside-Paths` debe enumerarlas exactamente y justificar su pertenencia ajena a los seis informes. Las fuentes financieras requieren la guía financiera; rutas de fórmula comercial requieren el cálculo comercial, con excepciones `unchanged` justificadas. Workflow, script, pruebas, guía, TOML y `AGENTS.md` son controles que exigen revisar guía y pruebas conjuntamente.
- Base de datos, configuración y compatibilidad: ninguna migración, variable de entorno, dato o configuración productiva. Cambia el contrato de descripción de PR: las PR funcionales futuras necesitan los nuevos campos de matriz y, según la ruta, los campos especializados o de excepción. La guía contiene ejemplos. La política no crea fichas KPI inventadas; la auditoría inicial continúa pendiente. `PROJECT_CONTEXT.md` y `DECISIONS.md` no cambian porque no se altera la arquitectura de la aplicación ni se adopta una decisión arquitectónica irreversible.
- Seguridad: se conservan `permissions: contents: read`, `persist-credentials: false`, `fetch-depth: 0` y `pull_request` sin `pull_request_target`. No se introducen tokens de escritura, secretos, producción, commits, push, despliegues ni acceso a Salesforce o base de datos. El código de la PR se ejecutará sin privilegios elevados. La validez material de las excepciones sigue a cargo de revisión humana.
- Rendimiento: el validador procesa solo las rutas del diff NUL desde `merge-base` y lee los documentos exigidos; no recorre todo el repositorio ni ejecuta Laravel o sincronizaciones. Las pruebas documentales terminaron en segundos.
- Pruebas: primero se añadieron regresiones y se ejecutaron contra las reglas defectuosas: 24 tests, 9 fallos esperados que reprodujeron evento, matriz, ruta desconocida, documento especializado y protección de controles. Tras corregir: 27 tests documentales, todos correctos con Python 3.12.14; `git diff --check` correcto. Se revisaron `git status --short --untracked-files=all`, `git diff --name-status` y los seis archivos nuevos; comprobación de espacios en no rastreados sin incidencias. No se ejecutaron tests Laravel porque no cambió código de aplicación.
- Acciones manuales y límites: revisar el diff completo, incluidas rutas nuevas no rastreadas; redactar el bloque `Docs-Impact` de la futura PR según la guía; ejecutar el workflow en GitHub y configurar manualmente `docs-integrity` como required status check después de su primera aparición. La carga efectiva del TOML personalizado sigue **NO VERIFICADA** pese a la delegación previa. La CI remota y el SHA real de `main` no se comprobaron en esta tarea. No se creó PR, commit ni push.

## Infraestructura de mantenimiento documental (2026-10-22)

- Base verificada: rama `fix/campaign-historical-status-visibility`, HEAD inicial `0113146fe88750e5eaf76dbd679746856c2f571b`, árbol limpio. El commit orientativo de la tarea no era HEAD. Sin commits, push ni PR.
- Tarea: se creó el agente de proyecto `report_docs_maintainer`, el contrato y la estructura pendiente de la matriz de KPIs, guía de uso, validador determinista con pruebas y Action independiente `docs-integrity`. No se hizo la auditoría inicial ni se modificaron reglas de negocio.
- Archivos modificados: `AGENTS.md`, `.gitignore`, `docs/ai/HANDOFF.md`. Archivos nuevos: `.codex/agents/report_docs_maintainer.toml`, `.github/workflows/docs-integrity.yml`, `docs/matriz-trazabilidad-kpi.md`, `docs/guia-mantenimiento-documental.md`, `scripts/check_documentation_integrity.py`, `tests/documentation/test_check_documentation_integrity.py`. Sin eliminaciones ni renombrados.
- Decisiones: no se añade `.codex/config.toml` porque la configuración oficial actual habilita subagentes por defecto. `.gitignore` permite solo el TOML versionado y mantiene ignorado el entorno personal. El validador usa `merge-base` hasta HEAD, rutas terminadas en NUL y un contrato de PR `Docs-Impact`; las rutas funcionales no mapeadas fallan. La excepción `none` requiere alcance, razón técnica y evidencia. Los cambios en los controles requieren revisar guía y pruebas.
- Base de datos y configuración: ninguna migración ni variable de entorno. La protección de `main` requiere marcar manualmente `docs-integrity` como required status check.
- Seguridad: workflow `pull_request` con `contents: read`, checkout sin credenciales persistidas y sin disparadores privilegiados; no hay secretos ni servicios externos en el validador. El agente documental tiene límites por instrucciones, no por sandbox independiente; el principal revisa el diff. No se añadieron cifras internas nuevas.
- Rendimiento: análisis acotado a `git diff` y documentos concretos; sin Laravel, bases de datos, Salesforce ni APIs durante validación.
- Verificaciones: 17 tests Python del validador correctos; TOML parseado con `tomllib`; tests revisan permisos y triggers del workflow. Prueba de delegación de solo lectura ejecutada sin cambios, pero el agente resultante **no recibió** las instrucciones TOML como instrucciones efectivas; reconocimiento nativo **NO VERIFICADO**. `codex exec` de solo lectura no pudo conectarse por la restricción de red del entorno. `git diff --check`, estado y diff final se revisaron; no se ejecutó suite PHP porque no hay código de aplicación modificado.
- Pendiente: comprobar en una nueva sesión Codex con conectividad que el agente personalizado carga sus instrucciones, e incorporar el required status check en GitHub. La auditoría inicial de seis informes queda para otra tarea. Un revisor humano debe validar contenido y justificaciones `Docs-Impact: none`; la CI no prueba semántica.

## Endurecimiento de estados históricos reconocidos (2026-10-22)

- Inicio limpio en fix/campaign-historical-status-visibility, HEAD 9bbff3e40551958cd5c1150293bc02ecbf8a8d2d. Commit adicional en la misma rama, sin merge, push ni despliegue.
- Problema: excluir solo vacíos/UNKNOWN/UNSPECIFIED admitía cadenas arbitrarias y normalizaba tipos/formato inválidos, pudiendo mostrar Inactiva (histórico) sin evidencia válida.
- Integraciones inspeccionadas: MetaAdsClient solicita effective_status y MetaCampaignSyncService lo copia sin traducción; GoogleAdsClient solicita campaign.status y GoogleCampaignSyncService lo copia sin traducción. No hay enum local exhaustivo. Contraste con Campaign.EffectiveStatus del SDK oficial de Meta y referencia oficial Google CampaignStatus, enlazadas en docs/informe-campanas.md. Ninguna llamada a cuentas o sincronización ejecutada.
- Solución: sets privados creados una vez por módulo. Meta ACTIVE/PAUSED/DELETED/ARCHIVED/IN_PROCESS/WITH_ISSUES; Google ENABLED/PAUSED/REMOVED. Solo cadenas canónicas exactas permiten presentar la etiqueta del backend; otros códigos, campos de otra plataforma, tipos, espacios o minúsculas quedan neutrales. Salesforce sigue neutral. Sin duplicación de reglas de clasificación económica.
- Archivos: resources/js/reports/campaign-presentation.js; tests/js/campaign-presentation.test.mjs; docs/informe-campanas.md; docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md; docs/ai/HANDOFF.md; public/build/manifest.json; bundle de campañas sustituido por public/build/assets/campaigns-dashboard-DTHx9DKs.js.
- Pruebas: node --test tests/js/campaign-presentation.test.mjs => 10/10 correctos; PHP Herd 8.4 -d max_execution_time=0 vendor/bin/pest --filter=CampaignDashboardTest --compact => 49/49, 512 aserciones, 6,951 s. Incluye integridad económica y contrato API/CSV existentes. node --check resources/js/reports/campaign-presentation.js y git diff --check correctos. No PHP modificado; no se requiere Pint focalizado. No se repite suite global.
- Build npm run build correcto (Vite 8.3.4); aviso de deprecación module.register() de Node. Bundle/manifest actualizados. Verificación automática: solo cambia la entrada de campañas en el manifest, todos sus archivos existen y el bundle incluye los estados de la lista.
- Integridad/seguridad: git diff 9bbff3e --exit-code -- app database routes sin diferencias; sin cambios económicos, API, CSV, filtros, atribuciones, snapshots, cierres, sincronizaciones, migraciones, autenticación, permisos o CSP. Escape HTML probado también con texto arbitrario malicioso. Sin secretos o datos reales en fixtures/docs.
- Rendimiento: Set.has en memoria O(1) por fila; sin consultas ni llamadas remotas. Sin arquitectura o convención general nueva: PROJECT_CONTEXT.md y DECISIONS.md no cambian.
- Acciones manuales: publicar assets y manifest del mismo release conjuntamente durante despliegue autorizado; comprobar caché y texto servido. No se ejecuta despliegue. Sin migraciones, variables, dependencias o invalidación de caché económica. Rollback de código y assets juntos.
- Riesgo deliberado: códigos nuevos/no contemplados quedarán neutrales hasta revisión explícita. No se afirma estado remoto vigente. Sigue pendiente QA visual real; no se alteran las limitaciones legacy de etiquetas API/CSV ya documentadas.


## Visibilidad del estado histórico de campañas (2026-10-22)

- Base verificada tras git fetch origin: 72d7fb968bb61c168dcd38c9c624882380ab1b72. Árbol inicial limpio; rama nueva fix/campaign-historical-status-visibility desde origin/main. Sin merge, push ni despliegue.
- Causa: frontend sustituía incondicionalmente toda etiqueta por Estado no disponible. Además mergeRows asigna campaign_status_label antes de añadir intereses: una fila sin estados puede tener etiqueta Inactiva/null y ser activa para campaignIsActive final por fallback histórico. Confirmado con prueba sintética de métricas+atribución y atribución sin métricas.
- Corrección únicamente visual: historicalStatusPresentation traduce Activa/Inactiva recibidas a Activa (histórico)/Inactiva (histórico) cuando existe evidencia local del estado relevante de Meta/Google. No reproduce la regla activa/inactiva de PHP ni infiere estados por intereses. Sin evidencia, estados UNKNOWN/UNSPECIFIED, etiqueta ausente o procedencia Salesforce => Estado no disponible. Tooltip niega consulta actual a plataformas. Encabezado de columna Estado histórico; selector y filtros sin cambios.
- Parte detenida por restricción de contrato: recalcular la etiqueta después de atribuciones cambiaría campos de API/CSV. No se hace; las filas afectadas se presentan neutrales. Sigue pendiente cualquier corrección de esa semántica legacy bajo aprobación separada. No se identifica estado remoto vigente.
- Archivos fuente/pruebas/docs: resources/js/reports/campaign-presentation.js; resources/js/reports/campaigns-dashboard.js; tests/js/campaign-presentation.test.mjs; tests/Feature/CampaignDashboardTest.php; docs/informe-campanas.md; docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md; docs/ai/HANDOFF.md. Assets: public/build/manifest.json, bundle anterior sustituido por public/build/assets/campaigns-dashboard-AsPQb6SX.js.
- Integridad: app/, database/ y routes/ idénticos a la base; sin fórmulas, importes, universos, atribuciones, cierres, snapshots, sincronizaciones, migraciones ni configuración modificados. API/exportRows se comparan completos; CSV real mantiene Activa/Inactiva sin sufijo. Tests conservan comprobaciones de tablas económicas intactas. No se audita producción.
- Seguridad/rendimiento: escape HTML conservado y probado. Sin cambios de permisos, autenticación o CSP. Sin datos reales/secretos en fixtures/docs. Helper O(1) por fila; ninguna consulta, red o procesamiento dependiente de base de datos adicional.
- Pruebas: PHP Herd 8.4 -d max_execution_time=0 vendor/bin/pest --filter=CampaignDashboardTest --compact: 49/49 correctos, 512 aserciones, 39,073 s. node --test tests/js/campaign-presentation.test.mjs: 9/9 correctos. Pint --test del PHP modificado, node --check y git diff --check correctos. No se repite suite global ni Pint global al ser cambio focalizado; límites previos documentados en entradas anteriores.
- Build: npm run build correcto con Vite 8.3.4; aviso de deprecación module.register() de Node. Se incluye el bundle recién generado y su manifest, sin cambios de assets ajenos. Verificación automática del manifest: todos sus archivos existen, solo cambia la entrada de campañas respecto a la base, y el bundle contiene ambas etiquetas históricas. Ningún bundle obsoleto permanece referenciado para campañas.
- Despliegue manual: publicar bundle y manifest del mismo release conjuntamente/atómicamente; ejecutar el build habitual y comprobar la URL resultante, texto servido y caché del navegador. No desplegar solo fuentes ni solo manifest. Sin migraciones, .env, sincronizaciones o invalidación de caché económica. Rollback conjunto de código y assets del release anterior, sin operaciones de datos. No se ejecuta despliegue.
- Pendientes: revisión visual de escritorio/móvil en navegador; estado remoto vigente no consultado. Fallback histórico y etiqueta temprana API/CSV permanecen como estaban. La distinción histórica fiable se recupera; no se afirma actividad publicitaria actual. Sin cambios arquitectónicos: PROJECT_CONTEXT.md y DECISIONS.md no requieren actualización.


## Segunda revisión de presentación de campañas (2026-10-21)

- Inicio limpio en fix/campaign-status-empty-rows-cost-display, HEAD 681bfea30344ddb988702d87bf2c25dc572afa6e; segundo commit en la misma rama, sin merge, push ni despliegue.
- Problemas verificados: salesforce_origin no estaba contemplado por el helper y podía mostrar cero sin inversión verificable; eliminar puntos null de una sola polyline conectaba períodos separados; tooltip genérico no diferenciaba causas; opciones Activas/Inactivas podían sugerir vigencia remota.
- Corrección: costPresentation centraliza texto, motivo y valor visual; salesforce_campaign_without_spend => No aplica; salesforce_origin => Sin datos aunque el payload contenga cero. Cero confirmado exige inversión conocida y denominador disponible distinto de cero. Ninguna fórmula ni valor JSON se reescribe. Tooltips de tabla/gráfico distinguen inversión desconocida, divisor cero, denominador ausente y métrica no aplicable.
- Gráficos: tramos contiguos separados por null; no se dibujan costes sin evidencia ni ceros artificiales. Serie totalmente nula sin líneas/puntos; final null conserva ausencia; cero y negativos válidos permanecen. Render lineal, sin consultas ni llamadas remotas.
- Estado: etiqueta y opciones del selector indican explícitamente criterio histórico. Conserva valores active/inactive, lógica, universo y columna Estado no disponible. NO se implementa identificación real de estados remotos vigentes.
- Archivos: resources/js/reports/campaign-presentation.js; resources/js/reports/campaigns-dashboard.js; resources/views/reports/campaigns/index.blade.php; tests/js/campaign-presentation.test.mjs; docs/informe-campanas.md; docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md; docs/ai/HANDOFF.md.
- Base de datos/arquitectura: sin migraciones, configuración nueva, sincronizaciones, cierres ni datos históricos tocados. app/, database/ y routes/ idénticos a 681bfea. No cambian convenciones generales; PROJECT_CONTEXT.md y DECISIONS.md no requieren actualización.
- Seguridad: escape HTML conservado, autenticación/autorización/CSP intactas. Documentación y fixtures sin datos reales ni secretos.
- Pruebas ejecutadas: node --test tests/js/campaign-presentation.test.mjs => 9/9 correctos; PHP Herd 8.4 -d max_execution_time=0 vendor/bin/pest --filter=CampaignDashboardTest --compact => 47/47, 473 aserciones, 21,569 s; incluye las pruebas existentes de integridad de cierres/snapshots/atribución/exportación y fórmulas. No se modifica ningún test PHP.
- Verificaciones: node --check resources/js/reports/campaigns-dashboard.js y git diff --check correctos; git diff 681bfea --exit-code -- app database routes sin diferencias. npm run build final correcto; avisos deprecación module.register() y tiempo de plugins. Artefactos de QA restaurados/retirados para excluir cambios de compilación de otros informes.
- Alcance de validación: no se repite la suite completa ni Pint global en esta revisión solo frontend; sus limitaciones anteriores siguen documentadas en la entrada de 681bfea. No hay ESLint ni otro análisis estático JS configurado en package.json. Pruebas nuevas y existentes focalizadas pasan.
- Acciones manuales: compilar assets con npm run build durante el despliegue habitual; verificar visualmente tooltip y discontinuidad de series en escritorio/móvil. Rollback de este segundo commit y recompilación, sin operaciones de datos. No requiere variables, dependencias, migraciones ni invalidación de caché económica.
- Riesgos pendientes: no hay verificación de estado remoto vigente ni acceso a datos productivos de las dos campañas. La semántica histórica del filtro/API sigue vigente por aprobación. No se declara resuelto el estado real de campañas ni una auditoría productiva.


## Presentación conservadora de campañas y autenticación (2026-10-21)

- Base verificada tras git fetch origin: d459e9f68970e683b78bd552457089807cc9ab37. Árbol inicial limpio; rama fix/campaign-status-empty-rows-cost-display creada desde origin/main. Sin push, merge ni despliegue.
- Resumen: detalle oculta únicamente filas sin actividad cuantitativa; costes diferencian cero, ausencia, divisor cero y no aplicable; gráficos conservan null; login usa gradientes sin URL inexistente y sin autofocus automático.
- Decisión aprobada expresamente por el responsable: conservar filtros históricos. No existe fuente local independiente que garantice estado vigente para campañas sin métricas recientes. La UI muestra Estado no disponible y explica que el filtro conserva semántica histórica. MIN, fallback Salesforce, etiquetas legacy API/CSV y alertas permanecen intactos para no cambiar universos económicos. No se implementa un inventario de estados vigentes.
- Archivos: resources/js/reports/campaign-presentation.js; resources/js/reports/campaigns-dashboard.js; resources/css/app.css; resources/views/auth/informes-login.blade.php; resources/views/auth/informes-password-forgot.blade.php; resources/views/reports/campaigns/index.blade.php; tests/js/campaign-presentation.test.mjs; tests/Feature/CampaignDashboardTest.php; tests/Feature/ReportsAuthMiddlewareTest.php; docs/informe-campanas.md; docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md; docs/ai/HANDOFF.md.
- Base de datos: ninguna migración, escritura real, sincronización ni cambio de datos/atribución/cierres. app, database y routes idénticos a la base. Tests usan SQLite :memory:; crean únicamente fixtures sintéticos.
- Integridad: fixture cerrada inversión 125, interés 1, ventas 0 y coste por venta null; igualdad completa de cierres, snapshots, eventos, atribuciones y métricas antes/después de consultas. API y exportación conservan las mismas filas, incluida la vacía. Denominadores por tipo 2/3/4/5/5 y costes para gasto 120: 60/40/30/24/24. No equivale a una comparación de registros productivos, inaccesibles en esta tarea.
- Seguridad: escape HTML existente conservado y probado; autorización/CSRF/CSP/cabeceras sin cambios. Sin PII, credenciales ni tokens en documentación. Mensajes de extensiones externos salvo evidencia contraria.
- Rendimiento: predicado de actividad O(n) solo en render del detalle; ninguna consulta o llamada remota adicional. Sin invalidación de caché económica necesaria al no cambiar el dataset.
- Pruebas ejecutadas: CampaignDashboardTest inicial 45 tests/433 aserciones correctos; nuevas pruebas de integridad y fórmulas 2/40 correctas; ejecución conjunta CampaignDashboardTest|ReportsAuthMiddlewareTest|ReportUserPasswordResetTest 72/613 correcta (incluye cierre, permisos, login, credenciales incorrectas, recuperación y reset). node --test tests/js/campaign-presentation.test.mjs: 5/5 correctos. node --check y git diff --check correctos.
- Estilo: Pint --test de ambos PHP modificados correcto tras formato; Pint global falla por 61 archivos preexistentes fuera del diff. No se formatean archivos ajenos. Sin analizador estático adicional configurado en composer/package.
- Build final npm run build correcto, sin referencia a login-bg.jpg. Avisos de deprecación module.register() y tiempo de plugins del build. Artefactos generados restaurados/retirados después de verificar: se requiere compilar assets durante despliegue.
- Suite completa: php artisan test --compact terminó por FatalException de max_execution_time=120 en el proceso PHP, sin resumen válido de tests. Reejecución directa php -d max_execution_time=0 vendor/bin/pest --compact: 1.429 tests, 1.428 correctos, 13.085 aserciones, 694,884 s; un fallo en StockRecommendationCandidatePaginationTest::test_calcula_un_volumen_equivalente_a_produccion_sin_agotar_el_tiempo_de_peticion (43,0695531 s frente al límite <20 s). Archivo y servicios de stock sin cambios respecto a la base. No se cambia un módulo ajeno para hacer pasar el umbral. Repetición aislada php -d max_execution_time=0 vendor/bin/pest --filter=StockRecommendationCandidatePaginationTest --compact: 2/2 tests, 13 aserciones, 13,570 s, correctos. Compatible con sensibilidad a carga, causa no demostrada; la suite completa NO se declara completamente superada. Se inició una segunda ejecución por artisan y se canceló antes de terminar para evitar duplicar carga; no se cuenta como prueba superada.
- Acciones manuales: consultar las dos campañas mediante el procedimiento de solo lectura del diagnóstico; validar escritorio/móvil, teclado e iframe real, repetir sin extensiones. Sin migraciones, .env, nuevas dependencias, backfills ni caché económica que regenerar. Desplegar solo tras revisión con assets compilados; rollback de código y assets, sin rollback de datos. No se ejecuta despliegue.
- Riesgos pendientes: no se verifica estado remoto vigente ni datos productivos de las dos campañas; los filtros legacy y etiquetas API/CSV permanecen históricos por aprobación. QA HTTP/Node no verifica geometría ni foco real del navegador. Cifras productivas antes/después requieren acceso autorizado con idéntico corte; cualquier diferencia bloquea despliegue.
- Documentación de arquitectura/decisiones generales no cambia: corrección de presentación sin nueva convención o módulo de negocio. Diagnóstico completo y comandos en docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md.


## Hardening final de aprobadores por scope (2026-10-19)

- Base comprobada: feat/commission-approvers-by-scope, HEAD ec3cf5e132ea3f2a8cadd0f6482583706fac55ed, árbol limpio. No se cambia rama ni main. Commit independiente local; sin push, PR ni despliegue.
- Problemas confirmados: test de usuario inactivo sin comprobación de efectos; formulario sin aviso explícito del scope bloqueado ni error junto al selector; Administración aprobaba únicamente desde una acción excepcional sin resumen del candidato; una fila ausente/incoherente podía resolverse como legacy en la UI; resolución sin comprobar acceso al informe; lecturas repetidas de usuarios en edición y actor administrativo sin revalidación bajo bloqueo.
- Correcciones: comprobaciones económicas completas, resumen de aprobación también para Administración, una única acción por scope autorizado, preparación/reapertura conservadas en su tabla. Formulario con aviso persistente de bloqueo y errores por pestaña; solo acepta sustitutos activos, no borra la marca explícita. Guardado sin cambios no altera fecha ni auditoría. Configuración incompleta bloquea todo el guardado. Filas ausentes/incoherentes y roles sin acceso al informe reciben denegación.
- Seguridad: autorización central por scope y acceso al informe; POST valida scope, bloquea configuración y actor activo antes del cierre. Edición bloquea scopes primero, después todos los usuarios implicados ordenados por ID y revalida rol/actividad administrativa. Sin elevación de canManageEconomicClosures ni cambios de política en preparación/reapertura/ajustes.
- Concurrencia: edición y aprobación comparten orden configuración -> usuarios -> cierre; mantienen bloqueo de asignación durante la aprobación del snapshot. Tres intentos de la transacción completa ante deadlocks de motor (por ejemplo borrado de usuario con FK); rollback incluye auditoría. El servicio de cierre no cambia. Pruebas en SQLite en memoria no reproducen contención multiconexión de MySQL: no se afirma una prueba de carga/concurrencia real.
- Base de datos: ninguna migración nueva o modificada. Pruebas verifican seis filas iniciales, unicidad, FK de usuario inexistente, nullOnDelete conservando configured_at y rollback que solo elimina las dos tablas de la feature. Las migraciones solo se ejecutan mediante pruebas en SQLite :memory:.
- Rendimiento: resolución cacheada una vez por request y sin dashboards adicionales; test verifica una sola consulta de configuración tras múltiples comprobaciones. Edición carga usuarios bloqueados en una consulta compacta. Prueba con mock verifica que aprobar no invoca build del dashboard financiero.
- Integridad: pruebas comparan el estado completo de cierres, snapshots y eventos tras denegaciones e invalidaciones. Inactivos/eliminados redirigen a login; Administrador/Dirección reciben 403 para esos scopes. No cambian approved_by, approved_at ni snapshot_version y no aparecen eventos approved ni snapshots definitivos. Reasignación conserva exactamente todo el historial económico y verifica IDs anterior/nuevo/actor, scope y fecha del evento administrativo.
- Pruebas focalizadas ejecutadas mediante php artisan test --filter=...: CommissionScopeApproverTest 23/23, 223 aserciones; CommissionMonthClosureTest 16/16, 120; CommissionVisibilitySecurityTest 13/13, 101; ReportAccessManagementTest 9/9, 58. Total 61 tests y 502 aserciones correctos.
- Suite completa: php artisan test correcto: 1.426/1.426 tests, 13.037 aserciones, 295,531 segundos; SQLite en memoria.
- Pint: vendor/bin/pint --test global ejecutado y fallido por 61 archivos ajenos sin cambios respecto a ec3cf5e (incluye migraciones históricas, seeders y servicios externos a la feature). La comparación de la lista de fallos con git diff --name-only ec3cf5e confirma cero archivos propios con fallos. Pint --test de los seis PHP modificados pasa. No se formatean esos 61 archivos porque el alcance prohíbe cambios ajenos y modificar migraciones históricas.
- Build: npm run build correcto; avisos existentes de deprecación module.register() de Node y /images/login-bg.jpg sin resolver al compilar. Assets generados restaurados a HEAD y archivos nuevos del build retirados; no se incluyen cambios frontend compilados ajenos al hardening.
- git diff --check: correcto (revalidar al cerrar documentación).
- Configuración: no hay nuevas variables, dependencias, permisos sobre usuarios reales ni cambios de fórmulas/importes/universos/algoritmos/Salesforce/scheduler. No se migran bases reales ni se modifican datos históricos.

Archivos modificados:

- app/Http/Controllers/Reports/CommercialCommissions/CommercialCommissionClosureController.php
- app/Http/Controllers/Reports/CommercialCommissions/CommercialCommissionDashboardController.php
- app/Http/Controllers/Reports/Settings/ReportAccessManagementController.php
- app/Models/CommercialCommissionApprover.php
- app/Support/ReportUserAccess.php
- resources/views/reports/commercial-commissions/index.blade.php
- resources/views/reports/commercial-commissions/partials/closure-approval.blade.php
- resources/views/reports/settings/access.blade.php
- tests/Feature/CommissionScopeApproverTest.php
- docs/informe-comisiones.md
- docs/ai/DECISIONS.md
- docs/ai/HANDOFF.md

Validación manual antes del despliegue (exclusivamente entorno local desechable con usuarios y economía sintéticos):

1. Confirmar las seis filas iniciales sin asignación; comprobar selección vacía y aprobación legacy por Administrador/Dirección sobre un mes terminado preparado.
2. Asignar Financieros a un usuario financiero activo; acceder con ese usuario, ver su candidato y aprobar; confirmar approved_by, approved_at y un único evento approved sin cambio del snapshot. Manipular POST a otro scope pendiente: 403, sin efectos.
3. Confirmar que el financiero no puede preparar/reabrir/ajustar ni gestionar fórmulas/usuarios/asignaciones o abrir detalles de otras pestañas. Asignar otro scope solo si su acceso por rol lo permite; no se amplían pestañas al asignarlo.
4. Verificar que Administración ve una única acción de aprobación para un scope legacy o asignado a sí misma y ninguna para uno asignado a otra persona; preparación y reapertura conservan sus reglas.
5. Desactivar y después eliminar un aprobador sintético: aviso de bloqueo en su selector; intentos con su sesión antigua redirigen a login y con Administración/Dirección son 403. Comprobar que cierre, fechas, eventos y snapshots siguen intactos.
6. Enviar un formulario con cambios en un scope válido y otro configurado vacío, inactivo o inexistente; comprobar error por pestaña y ausencia de cambios parciales/auditoría. Elegir un sustituto activo para recuperar el scope. Guardar sin cambios: sin nueva auditoría ni cambio de fecha.
7. Reasignar después de un cierre definitivo: comprobar trazabilidad administrativa e historial económico idéntico. En un MySQL local desechable, comprobar con dos conexiones que reasignación/aprobación serializan por fila de scope y que un deadlock no deja efectos parciales.

Riesgos residuales / revisión pendiente: Pint global sigue fallando por deuda de estilo preexistente (61 archivos); debe tratarse fuera de este commit conforme a la prohibición de cambios ajenos. No se valida contención real del motor productivo con SQLite ni se despliega nada. No hay migraciones adicionales ni acciones sobre datos reales necesarias para este hardening.


## Aprobadores de comisiones por scope (2026-10-19)

- Rama exclusiva: feat/commission-approvers-by-scope. Inicio limpio en d8cd17820523889fdcb84f87c57dd6cee3cb4b16. main local en 3154bd033671b4ad3bc5e5e06f93b39f51331c29 es antecesor de la base; no se modifica main ni se incorpora otro desarrollo.
- Resumen: configuración administrativa de seis aprobadores independientes por ID de ReportUser, validación de usuario activo, autorización server-side por scope y resumen de aprobación sobre el candidato preparado.
- Decisiones: persistencia separada, configured_at como marca de asignación explícita irreversible desde la UI, fallback legacy exclusivamente antes de primera asignación y bloqueo al desaparecer/desactivarse el usuario. Una asignación explícita prevalece sobre Administrador/Dirección. Resolver central con caché por request. Auditoría siguiendo patrón de eventos con contexto y actor.
- Base de datos: migración aditiva 2026_10_19_120000_create_commercial_commission_approvers.php. Crea commercial_commission_approvers (unicidad por scope, FK nullable con nullOnDelete, seis filas inicialmente legacy) y commercial_commission_approver_events (actor FK, IDs anterior/nuevo/actor en contexto JSON, fecha). No modifica tablas de cierres ni snapshots existentes.
- Seguridad: POST valida el scope antes de autorizarlo, bloquea fila de asignación y actor activo durante aprobación, CSRF intacto. Edición solo administrativa y transaccional, con revalidación bajo bloqueo. No usa correo/nombre para autorizar. El resumen compartido filtra scopes antes de consultar snapshots; Financiero solo conserva pestaña Financieros y auditor mantiene su proyección sin detalles. No concede permisos adicionales de preparación, reapertura, ajustes, fórmulas, usuarios ni penalizaciones. Las reglas de acceso al informe por rol siguen vigentes.
- Rendimiento: configuración cargada una vez por request con usuarios precargados; no se construyen dashboards para decidir permisos ni aprobar. No hay consultas de autorización por fila del dashboard.
- Integridad económica: no se cambian fórmulas, universos, importes, Salesforce, snapshots definitivos, approved_by histórico ni eventos existentes. Aprobar utiliza el snapshot candidato y registra actor real en approved_by y evento approved.
- Validación focalizada: 49 tests y 364 aserciones correctas con Pest (CommissionMonthClosureTest, CommissionVisibilitySecurityTest, ReportAccessManagementTest y CommissionScopeApproverTest). Pint --test correcto. git diff --check correcto. Build npm run build correcto; avisos de deprecación Node e imagen /images/login-bg.jpg preexistente sin resolver al compilar. Assets generados restaurados para evitar churn ajeno a esta feature.
- Suite completa: 1.414 tests correctos, 12.899 aserciones; duración 337,698 segundos (Pest --compact, SQLite en memoria).
- Acciones manuales: tras revisar y autorizar un despliegue, ejecutar php artisan migrate antes de servir este código. En Administración → Permisos por informe seleccionar los usuarios activos existentes por cada scope. No requiere variables de entorno, backfills ni creación automática de usuarios. No se ha desplegado ni migrado producción.
- Riesgos/pendientes: comprobar operativamente que el usuario deseado existe y está activo; si no existe o se desactiva, Administración debe resolver su configuración. El rollback de la migración elimina únicamente las dos tablas nuevas y su historial de asignación, por lo que debe preservarse dicho historial si se requiere rollback.

Archivos modificados:

- app/Http/Controllers/Reports/CommercialCommissions/CommercialCommissionClosureController.php
- app/Http/Controllers/Reports/CommercialCommissions/CommercialCommissionDashboardController.php
- app/Http/Controllers/Reports/Settings/ReportAccessManagementController.php
- app/Services/Reports/CommercialCommissions/CommercialCommissionClosureService.php
- app/Support/ReportUserAccess.php
- resources/views/reports/commercial-commissions/index.blade.php
- resources/views/reports/commercial-commissions/audit-summary.blade.php
- resources/views/reports/settings/access.blade.php
- routes/web.php
- tests/Feature/CommissionMonthClosureTest.php
- docs/informe-comisiones.md
- docs/ai/HANDOFF.md
- docs/ai/PROJECT_CONTEXT.md
- docs/ai/DECISIONS.md

Archivos creados:

- app/Models/CommercialCommissionApprover.php
- database/migrations/2026_10_19_120000_create_commercial_commission_approvers.php
- resources/views/reports/commercial-commissions/partials/closure-approval.blade.php
- tests/Feature/CommissionScopeApproverTest.php

Comandos de validación (PHP 8.4 de Herd; Pest directo evita depender del PATH):

- php vendor/bin/pest --filter='CommissionMonthClosureTest|CommissionVisibilitySecurityTest|ReportAccessManagementTest|CommissionScopeApproverTest' --compact
- php vendor/bin/pest --compact
- php vendor/bin/pint --test [nueve archivos PHP de esta feature]
- npm run build
- npm run build -- --outDir storage/app/commission-scope-build (revalidación final correcta sin cambios en assets versionados)
- git diff --check


## Alineación de pruebas Executive con Interest (2026-10-14)

- No cambia runtime: `ExecutiveDailyDatasetService` ya inyectaba
  `SalesforceInterestDashboardDatasetService`. La métrica técnica `leads`
  continúa contando únicamente Interests activos por `functional_created_at`,
  bajo el último F2 canónico `salesforce_interests/salesforce` completed y
  estable; no consulta ni exige F5.
- `ExecutiveSummaryPageTest` reemplaza el fixture funcional Lead y el run
  `leads_dashboard` por `SalesforceInterest` y el F2 canónico. Una regresión
  prueba que un Interest del día produce `leads.current=1` y que insertar
  después un Lead legacy equivalente no altera el valor. Los fixtures base de
  Executive diario dejan de crear snapshots F5 accesorios y se denominan según
  la fuente Interest real; únicamente el test que invoca además el dashboard
  operativo crea explícitamente el F5 que ese contrato sí exige.
- Se mantienen sin cambios `metric_key=leads`, la etiqueta visible «Leads»,
  reglas, thresholds, baseline, D-7/D-14/D-21/D-28/D-364, MTD, rutas, UI, API,
  scheduler y autorización.
- Seguridad y rendimiento: no se añaden queries runtime, Salesforce, PII ni IDs
  al HTML. Las regresiones existentes siguen comprobando que la salida no expone
  emails, teléfonos, nombres sensibles, Salesforce IDs o IDs de sync runs.
- Cambios limitados a dos tests y documentación técnica; sin migraciones,
  configuración, dependencias ni acciones manuales.
- Validación: `ExecutiveSummaryPageTest` 4/50; `ExecutiveDailyDatasetTest`
  26/116; `ExecutiveSummaryServiceTest` 7/34;
  `SalesforceInterestDashboardDatasetTest` 16/80; suite Executive completa
  101/516. Pint sobre ambos PHP modificados y `git diff --check` correctos.

## ROT-5 — SEO Salesforce → Interests (2026-10-09)

### Hardening de igualdad literal e índice (2026-10-13)

- El predicado SQL `medium = 'Orgánico'` se conserva para reducir candidatos,
  pero el conteo exige además una comparación PHP estricta con el literal
  `Orgánico`. No se normaliza, recorta, cambia case ni elimina tildes; UTM,
  source y channel siguen fuera de la decisión. Así la igualdad funcional no
  depende de la collation `utf8mb4_unicode_ci` de MySQL.
- La migración correctiva
  `2026_10_13_090000_add_rot5_projection_index_to_salesforce_interests` añade
  únicamente `sf_interests_rot5_medium_date_id_idx` sobre `is_deleted`,
  `medium`, `functional_created_at`, `id`, en ese orden. Igualdades preceden al
  rango temporal y `id` mantiene la cobertura de la paginación estable. El
  rollback retira solo este índice y conserva los índices FOUNDATION.
- Seguridad y rendimiento: el cambio continúa siendo local, sin Salesforce,
  HTTP, PII, payloads ni concatenación SQL; procesa por cursor/chunks y no
  altera datos. La validación SQLite certifica esquema, orden y reversibilidad,
  pero no sustituye el plan del optimizador MySQL/MariaDB.
- Archivos del correctivo: servicio de proyección, nueva migración de índice,
  pruebas de sync/esquema y documentación `HANDOFF`/`SEO_ANALYTICS`; no cambian
  configuración, credenciales, scheduler, reglas, tablas de métricas ni datos.
- Validación: tests estrictos y de migración 26/71; focal ROT-5 74/697; SEO
  152/1.279; Foundation Interest 128/730; Campañas 89/737; Reservas/Ventas
  104/1.252; Llamadas 11/73; dashboard Leads 24/78; Monthly Commercial 15/61;
  Executive 100/509; suite completa 1.312/9.381. Composer validate/audit, Pint
  sobre PHP modificado, Vite, `migrate --pretend` y `git diff --check` pasan.
  El test de esquema ejecuta `down()`/`up()` reales exclusivamente sobre SQLite
  de tests y comprueba que los índices FOUNDATION permanecen.
- Acción manual posterior al merge: aplicar la migración en el entorno
  autorizado y ejecutar en shadow un `EXPLAIN` de la consulta ROT-5 equivalente
  para comprobar el uso efectivo del índice. Ese resultado no se presume ni se
  ha ejecutado durante este correctivo.

- Rama `feat/rot-5-seo-to-interests`, creada desde `main`
  `0d0bcb6577a687023de3e07e8447386eb694f58e` (merge ROT-4, PR #74). El lote
  rota exclusivamente la fuente Salesforce del informe SEO; no cambia Search
  Console, GA4, salud técnica ni consumidores ajenos.
- `seo:sync-salesforce-organic` conserva nombre, opción `--days=1..480`, lock y
  horario 05:30 por compatibilidad, pero ya no llama Salesforce. Proyecta desde
  `salesforce_interests` únicamente registros activos con
  `medium = 'Orgánico'`, usando `functional_created_at` y límites
  Europe/Madrid→UTC. UTM, source, channel y Lead no son fallbacks.
- La nueva tabla `seo_salesforce_organic_interest_daily_metrics` conserva un
  conteo por día, timezone, ID/cutoff F2 y fecha de extracción. El run propio es
  `seo_salesforce_organic_interests/local_database`; el último F2 por ID debe
  estar completed y con cutoff, y se revalida tras leer, antes de persistir y
  antes de completar. La publicación es transaccional e incluye ceros dentro
  de la cobertura efectiva.
- La métrica vigente es `salesforce_organic_interests`, con identidad
  `salesforce-organic-interests`, label «Interés orgánico (Salesforce)» y campo
  `interest_count`. Dashboard, snapshot, evaluación, señales y correo ejecutivo
  filtran el registry activo. Los aliases `salesforce_leads`/`leads` se mantienen
  solo en el payload descriptivo para compatibilidad.
- La tabla `seo_salesforce_organic_daily_metrics`, la identidad
  `salesforce_organic_leads`, sus snapshots/evaluaciones y `seo_rules_v1`
  permanecen intactos como histórico. La migración crea vN+1 copiando las seis
  reglas y todos sus umbrales, y cambia únicamente la regla Salesforce a la
  identidad Interest. El rollback solo reactiva el predecesor si no existe una
  versión posterior.
- Diagnóstico y readiness de esta fuente pasan a comprobar exclusivamente F2 y
  la proyección locales. No requieren credenciales Salesforce, no ejecutan
  describe y no realizan red. Los errores son sanitizados y no incluyen IDs,
  payloads, PII ni secretos.
- Rendimiento: cursor por PK en chunks de 1.000, agregación acotada de hasta 480
  días y un upsert transaccional; no se carga F2 completo, no hay N+1 ni una
  consulta por día. El índice ROT-5 específico acompaña lifecycle, literal de
  medium, rango de fecha funcional y cursor PK.
- Archivos principales: nuevo modelo y servicio de proyección, dos migraciones
  ROT-5, command/registry/dataset/snapshot/evaluación/readiness/diagnóstico SEO,
  vista, scheduler, pruebas focales y documentación contractual/operativa. Se
  eliminan el sync Lead SEO y su resolver describe, ya sin consumidores.
- Seguridad: ejecución 100 % local y read-only sobre F2; sin acceso Salesforce,
  shadow o producción, sin backfill real y sin migraciones persistentes durante
  la implementación. Search Console/GA4 conservan sus integraciones existentes.
- Validación: focal ROT-5/analítica/dashboard/diagnóstico/scheduler 64 tests y
  679 aserciones; suite SEO completa 142/1.261; Foundation Interest 128/730;
  Campañas 89/737; Reservas/Ventas 104/1.252; Llamadas 11/73; dashboard Leads
  24/78; Monthly Commercial 15/61; Executive 100/509; suite completa
  1.302/9.363. Composer validate y audit correctos sin advisories; Pint sobre
  todos los PHP modificados, Vite, `migrate --pretend` y
  `git diff --check` correctos. El Pint global conserva deuda de estilo previa
  en archivos no modificados, que no se corrigió fuera del alcance.
- Acciones manuales: aplicar las migraciones en el entorno autorizado, verificar
  un F2 completed con cutoff y ejecutar primero la proyección (ampliando
  `--days` si se requiere histórico) antes de construir snapshots/evaluaciones.
  Certificar en shadow antes de cualquier despliegue productivo. ROT-5 permanece
  `en_revision` hasta ese proceso.

## ROT-4 — Campañas → Interests (2026-10-09)

### Correctivo del diagnóstico de exclusiones en dry-run (2026-10-13)

- Causa raíz: el builder incrementaba únicamente
  `excluded_by_reason[reason].count`, mientras el comando asumía que cada motivo
  incluía también `sample_ids`; una exclusión válida completaba la simulación,
  pero el render CLI terminaba con `Undefined array key "sample_ids"`.
- El productor expone ahora un contrato estable `{count, sample_ids}` por motivo
  y recoge durante el recorrido existente un máximo de 20 Salesforce Interest
  IDs. El conteo no se trunca y se mantienen sin cambios
  `excluded_campaign_tasador`, `excluded_campaign_ren2click` y
  `excluded_campaign_hrrenting`; `null_empty_none` continúa fuera del universo
  funcional.
- La CLI conserva una defensa ante diagnósticos incompletos: una clave o muestra
  ausente se representa vacía y no derriba un dry-run ya completado. No cambia
  ninguna regla de campaña, matching u Opportunity.
- Seguridad y rendimiento: no hay consultas adicionales ni PII; la muestra se
  construye en memoria con coste O(1) por candidato y tamaño máximo constante.
  El dry-run sigue sin inserts, updates, upserts, deletes o invalidación de
  caché sobre las tablas funcionales.
- Archivos modificados: builder, comando CLI, `CampaignCommandsTest`, contrato
  operativo de Campañas y este handoff. No hay cambios de esquema,
  configuración, dependencias, scheduler ni consumers.
- Validación: `CampaignCommandsTest` 21/115; batería ROT-4 completa 88/731;
  suite PHP completa 1.314/9.398. Pint sobre los tres PHP modificados y
  `git diff --check` correctos. La regresión ejecuta el comando Artisan real con
  una exclusión y confirma exit 0, motivo/muestra,
  `source_type_salesforce_origin=0`, tablas sin cambios y versión de caché
  intacta.

### Hardening de revisión sénior

- El universo funcional vuelve a exigir evidencia explícita de campaña en
  `utm_campaign`, `utm_id` o `utm_content`. `source`, `original_source`,
  `medium` y `channel` continúan auditables, pero Google Maps, Chatbot,
  Exposición, Web y otras procedencias sin esa evidencia no materializan una
  campaña ni una pseudo-campaña `salesforce_origin`.
- Se retiró el UNIQUE histórico de `campaign_attributions.opportunity_id` y se
  sustituyó por un índice no único. Así una fila Lead legacy preservada para
  rollback puede coexistir con la fila Interest ROT-4 de la misma Opportunity;
  el claiming funcional filtra `interest_id IS NOT NULL` y mantiene una única
  asignación dentro del universo Interest. El rollback elimina primero filas
  ROT-4 y restaura el UNIQUE legacy.
- El `--dry-run` no escribe ni borra en `campaign_attributions`,
  `campaign_lead_attributions` o `campaign_unresolved_attributions`, incluso
  cuando simula crear o resolver una ambigüedad. Tampoco invalida caché.
- `opportunity_relationship_status` conserva la evidencia CRM obtenida del
  resolver y `opportunity_attribution_method` registra separadamente
  `both_match`/first touch. Los estados `contradiction` e `inverse_shared`
  quedan auditables y no reciben ganador Account; los estados no conflictivos
  pueden usar first touch sin perder su status original.
- El orden diario queda Opportunity 07:10, snapshot directo 07:35, F2/F5
  horario de las 08:05, builder Campañas 08:30 y refresh 09:00. No se añade
  otro sync F2 y el sync Lead de Campañas sigue fuera del scheduler.

- Rama `feat/rot-4-campaigns-to-interests`, creada desde `main`
  `bbb435ae3536e3d4f0d139b6b57caa48a370d07e` (merge ROT-3, PR #73).
  `/informes/campanas`, su builder y auditorías rotan a Interests activos del
  último F2 estable; no leen Lead, Account/Contact para completar el Interest ni
  usan PII.
- Nueva migración aditiva para `campaign_attributions` y
  `campaign_lead_attributions`: `lead_id` pasa a nullable y se añaden columnas
  explícitas de identidad, fecha funcional, status, tipo, adquisición, owner,
  lifecycle, F2 y relación Opportunity. Las filas ROT-4 dejan las columnas Lead
  a `NULL`; los nombres de tabla y aliases `leads_*` permanecen por
  compatibilidad.
- El builder procesa `salesforce_interests` por cursor PK y límites
  Europe/Madrid→UTC. Fija/revalida run y cutoff F2 y el contexto directo/inverso
  Opportunity↔Interest antes de publicar; un cambio concurrente revierte la
  transacción. La caché incorpora IDs, status, cutoffs, disponibilidad y razón,
  incluido el cambio sobre una misma fila `running → completed`.
- Solo `both_match` autoriza relación CRM exacta. El first touch por Account
  sigue separado, compara IDs Account, conserva precedencia/ambigüedad y tiene
  menor confianza; no emplea PII ni afirma relación bidireccional. Status, tipo,
  fuente/UTM, procedencia, owner y lifecycle proceden únicamente del Interest.
- Se mantienen Google Ads, Meta Ads y resultados Opportunity. La antigua
  inferencia Meta desde campos Lead no se replica sin evidencia Interest y las
  procedencias genéricas sin campaña explícita no entran en el universo. UI y
  CSV usan terminología Interest; las auditorías no exponen PII.
- `salesforce:sync-campaign-leads`, `CampaignLeadSyncService` y
  `campaign_salesforce_leads` quedan aislados como infraestructura legacy y se
  retira únicamente su entrada programada. El orden diario queda Opportunity
  07:10, snapshot directo 07:35, F2/F5 horario 08:05, atribución Campañas
  08:30 y refresh 09:00, conservando Google/Meta y la cadencia F2/F5 existente.
- Seguridad: no hay consultas/escrituras Salesforce nuevas, payloads, secretos,
  Lead fallback ni cambios de autorización. Rendimiento: cursor/chunks, lookups
  bulk, sin N+1 y sin funciones sobre IDs Salesforce indexados.
- Validación local del hardening: suite Campañas 86 tests/714 aserciones;
  regresiones Interest/F4/ROT-3/Executive 331/2.439; suite completa
  1.287/9.314. Composer validate correcto; Composer audit conserva
  dos advisories previos de `league/commonmark` (uno medium y uno high), ajenos
  al lote y sin cambios de lock. Pint, Vite, `migrate --pretend` y
  `git diff --check` correctos.
- Acción manual futura: aplicar la migración, disponer de F2 completo/estable y
  snapshot directo coherente, reconstruir el período y certificar en shadow
  antes de habilitar ROT-4. No se ejecutó backfill, migración persistente,
  despliegue, Salesforce, shadow ni producción durante esta implementación.

## Correctivo final ROT-3 — caché y lookup indexable (2026-10-09)

- La identidad de caché del dashboard incorpora el contexto completo y estable
  de atribución: IDs, status y cutoffs direct/F2, disponibilidad, razón de
  degradación y cutoff Opportunity requerido. Una transición sobre la misma
  fila `running → completed` genera una clave distinta y no reutiliza el
  payload degradado.
- El lookup inverso vuelve a usar directamente el índice existente sobre
  `salesforce_interests.inverse_opportunity_salesforce_id`; no aplica funciones
  SQL a la columna. Los valores no canónicos no se normalizan mediante scans o
  heurísticas en runtime.
- Cuando la atribución no está disponible, la resolución devuelve `unresolved`
  en memoria y no consulta detalles directos ni Interests. Con contexto válido
  conserva exactamente dos consultas bulk por chunk. Sin migraciones, índices,
  Salesforce, Lead fallback ni cambios funcionales de precedencia.
- Validación: focal de atribución 10 tests/37 aserciones; Reservas/Ventas
  104/1.252; Foundation 4A/4B, Campañas, Executive y Commercial Performance
  210/1.985; suite completa 1.284/9.278. Pint focal y `git diff --check`
  correctos. Archivos modificados: servicio de atribución, dataset del
  dashboard, regresiones focales y este HANDOFF. No hay acciones manuales ni
  cambios de base de datos para aplicar.

## ROT-3 — Reservas/Ventas → Interests (2026-10-09)

- Rama `feat/rot-3-reservations-sales-to-interests`, base exacta de `main`
  `dd6d3e1a68d3cfbf9118f178e8781adda67221fb`. Opportunity conserva universo,
  fechas, stages, reservas, ventas, caídas, vehículo, margen, deduplicación,
  responsable y geografía.
- La atribución ROT-3 es efímera y local: fija el último snapshot directo 4B y
  el F2 actual, carga por lote direct evidence e Interests inversos y solo usa
  `Interest.source` en `both_match`. Estados unilaterales, contradicción,
  multiplicidad, referencia inválida y ausencia nunca eligen un Interest.
- La precedencia es Portal Opportunity concluyente, Interest `both_match`,
  Fuente de origen Opportunity, Exposición, Web y Sin clasificar. El dashboard
  no usa `portal_resolved` ni `portal_resolution_lead_id` como autoridad ROT-3.
  El productor legacy y sus columnas permanecen intactos por Campañas hasta
  ROT-4.
- Snapshot directo missing/stale/failed/running degrada de forma segura: el
  informe sigue disponible sin atribución Interest y publica metadata de
  calidad. Direct/F2 se revalidan antes de devolver/cachear para impedir mezcla
  de snapshots.
- Rendimiento comercial consulta `SalesforceInterest` activo, fecha
  `functional_created_at` con límites Madrid→UTC, tipo normalizado compatible y
  owner actual `owner_salesforce_id/owner_name`. No consulta SalesforceLead ni
  inventa histórico: la semántica es `current_interest_owner_at_last_sync`.
  `leads` y ratios `lead_*` sobreviven solo como aliases técnicos; UI y auditoría
  muestran Interest e `interest_id`, sin PII.
- Cache base sube a v5 e incluye ID/cutoff F2. El snapshot directo se programa
  diariamente a las 07:35 Europe/Madrid, después del sync Opportunity 07:10,
  con `withoutOverlapping` y motivo auditable. No se modifican F2/F5.
- No hay migraciones, APIs, escrituras Salesforce ni cambios de Campañas,
  Comisiones, Stock, ROT-1 o ROT-2. Queda pendiente despliegue/certificación; no
  ejecutar bootstrap, reproceso ni sincronizaciones reales desde desarrollo.
- Validación local final: focal ROT-3/Rendimiento comercial 83 tests y 1.048
  aserciones; Reservas/Ventas 99/1.235; Foundations y regresiones transversales
  270/2.006; suite completa 1.279/9.261. Pint focal y `git diff --check`
  correctos; Vite compila correctamente. El `pint --test` global sigue
  señalando deuda histórica en archivos ajenos al lote, que no se reformateó.
- Archivos funcionales ROT-3: nuevo
  `OpportunityInterestAttributionService`; dataset/auditoría/controlador de
  Reservas/Ventas; rendimiento comercial; scheduler; UI y tests asociados.
  Documentación actualizada en ROADMAP, HANDOFF, PROJECT_CONTEXT, DECISIONS e
  `informe-reservas-ventas.md`. No hay cambios de base de datos ni variables de
  entorno. Acción manual futura: desplegar y certificar antes de cerrar ROT-3.

## ROT-2 — rotación de atribución de Llamadas a Interest (2026-10-08)

- Correctivo de revisión sobre `f7c70a65518a639b9052454bd4d4a12b3da9d899`:
  `exact_interest` exige igualdad limpia entre WhatId e Interest ID; una
  incoherencia queda `interest_mismatch` y nunca usa la fuente suministrada.
  El histórico compara la huella Interest previa/nueva y solo declara
  `interest_dependency_changed` cuando esa evidencia cambió y participó en la
  clasificación. Con Task estable, otros cambios se registran neutralmente
  como `local_classification_changed`; Task modificada conserva
  `salesforce_source_modified`. No hay queries, schema ni PII adicionales.
- Correctivo final sobre `dca6e91db82b80ad9a349dd0ce5914718dffbe8e`:
  `preserved_historical` continúa auditable pero deja de demostrar causalidad.
  Solo `exact_interest` junto con `interest_source_used=true` habilita
  `interest_dependency_changed`; un cambio local sobre una clasificación
  meramente preservada queda `local_classification_changed`.
- Hardening de causalidad sobre `a140725689009d50ec2980e3293a19fbc8856069`:
  incluso con huella Interest modificada y participación exacta, provenance
  Interest exige que cambie directamente `portal_resolved` o `call_origin`.
  Overflow, su motivo y la duración ajustada son efectos derivados con otras
  causas posibles y no demuestran causalidad por sí solos; cambios de
  equipo/delegación/zona permanecen locales.
- Rama `feat/rot-2-calls-to-interests`, creada desde `main`
  `dc8e7d59d7e5bdc6ce065a4ce873fb2364493eb1` (merge ROT-1, PR #71).
- Task sigue siendo el hecho y conserva universo, fecha, CallObject, equipos,
  duraciones, ABANDONED, filtros y scopes. La relación CRM única pasa a ser
  `Task.WhatId → salesforce_interests.salesforce_id` exacta; WhoId permanece
  como evidencia bruta y nunca atribuye.
- Se sustituye `CallLeadPortalResolver` por `CallInterestPortalResolver`. El
  sync deja de consultar `FROM Lead` y el reproceso deja de usar
  `SalesforceLead`; ambos cargan en lote solo ID, source y lifecycle de los
  Interests referenciados. No se consulta Salesforce Interest ni FOUNDATION-5.
- `Portales__c` null/directo y reconocido mantienen prioridad. Solo un valor
  Task no clasificable usa `Interest.source`. Interest eliminado es evidencia
  válida. La ausencia local no infiere otra relación y una clasificación previa
  solo puede conservarse con provenance `historical_preserved` explícita.
- Auditoría JSON/CSV añade WhatId, match exacto, ID/lifecycle/source Interest y
  provenance sin PII ni payload. El histórico se crea por diferencias en la
  clasificación funcional, incluida una modificación Interest-derived con Task
  estable, y no se duplica en reejecuciones idénticas.
- No hay migraciones, cambios de scheduler, frontend, rutas, informes ajenos ni
  ejecución real contra Salesforce. La versión de reglas es `2026-10-08.1`.
- Validación local final: focal resolver/sync/reproceso 24 pruebas y 138
  aserciones; batería completa de Llamadas 102/782; Foundations Interest
  relacionadas 147/874; suite completa 1.273 pruebas y 9.233 aserciones. Pint
  y `git diff --check` correctos. Vite no aplica porque no cambió frontend; no
  hay migración ni configuración manual.

## ROT-1 — rotación Leads → Interests (2026-10-08)

- Correctivo final de revisión: Task usa `ActivityDate` como día civil
  `Europe/Madrid`; `CreatedDate` solo desempata Tasks del mismo día y nunca se
  combina con la fecha para fabricar otro instante. Task sin `ActivityDate`
  cuenta en el total pero no acredita recencia. Event conserva
  `StartDateTime` como instante funcional.
- Monthly Commercial convierte el instante de ejecución a `Europe/Madrid`
  antes de derivar calendario y `fecha_analisis`; su precheck transforma los
  límites Madrid a UTC antes de consultar `functional_created_at`. Se cubrieron
  medianoche Madrid con día UTC anterior y cambio DST sin offsets fijos.
- `docs/reglas-negocio-leads.md` se reescribió como contrato ROT-1 vigente. La
  semántica Lead queda aislada como referencia para Campañas/consumidores no
  rotados y no se presenta como fuente de `/informes/leads`.
- Correctivo de revisión sénior sobre `620ad16f8a5a0a85e6e782f40bc5f7c2bcc14f06`:
  los límites funcionales se interpretan en `Europe/Madrid` y se convierten a
  UTC antes de consultar `functional_created_at`; los cutoffs se publican en
  ISO-8601 con offset. Se cubren explícitamente los bordes de día y mes.
- La métrica ejecutiva técnica `leads` cuenta Interests activos directamente
  sobre el F2 estable y no consulta ni exige F5. El dashboard operativo y sus
  auditorías sí conservan la exigencia F2/F5 alineada. Ambos caminos revalidan
  su fuente antes de publicar el resultado.
- KPI audit expone exclusivamente evidencia Interest y actividad directa, sin
  PII ni columnas Lead/Campañas. Conciliación incluye activos y eliminados
  dentro del scope autorizado, indica `included_in_active_dataset` y motivos de
  exclusión. Los CSV consumen cursores/chunks y no materializan toda la
  exportación en memoria.
- Antes de habilitar el consumo productivo del scheduler incremental
  `salesforce:sync-interest-reporting` se requiere un bootstrap F2 completo y
  un snapshot F5 alineado con ese mismo run/cutoff. Este correctivo no ejecutó
  bootstrap, Salesforce, shadow ni producción.
- Rama `feat/rot-1-leads-to-interests`, base
  `8a9d476b19d216167c5ebc82f99947393700ac5e`.
- `SalesforceInterestDashboardDatasetService` es un dataset funcional separado.
  El dataset Lead conserva su semántica para Campañas. Solo `/informes/leads`,
  Monthly Commercial y la métrica ejecutiva técnica `leads` se rewirean.
- El período usa `functional_created_at`; solo incluye Interests activos. Estado,
  tipo, fuente, medio, canal, procedencia y owner salen del Interest, sin
  fallback Lead/Account/Contact ni PII.
- La actividad se carga en bulk desde F5 `completed` ligado al mismo run/cutoff
  F2 vigente. Solo cuenta `resolved` no eliminada. Task usa `ActivityDate` con
  `CreatedDate` como desempate y Event usa `StartDateTime`. Si las fuentes se
  desalinean o cambian durante el render, el dataset falla de forma segura.
- La auditoría es Interest-centric y elimina nombre de persona, teléfono, móvil
  y email. Ruta, permiso `leads` y claves KPI legacy se conservan; `portal` es
  únicamente alias técnico de `source`.
- `salesforce:sync-interest-reporting` serializa con lock F2 incremental → F5 y
  valida el run/cutoff. Se programa cada hora al minuto 5, `Europe/Madrid`, con
  monitor operacional y `withoutOverlapping`. El scheduler legacy permanece.
- No hay migraciones ni rotación de Campañas, Llamadas o Reservas/Ventas. El
  filtro legacy Exposición se retiró de la UI porque Interest no ofrece una
  dimensión canónica equivalente; no se inventa un fallback desde `source`.
  No se accedió a Salesforce real, shadow o producción.
- Validación local final: focal Task/Monthly/Executive 46 pruebas/207
  aserciones; bloque ampliado ROT-1, Monthly, Executive, Dashboard, Campañas y
  Foundations 147/1.024; suite completa 1.261/9.169. Pint y
  `git diff --check` correctos. Vite no aplica porque este correctivo no modifica
  frontend. No hay migración ni configuración de entorno nueva.

## SF-INTEREST-FOUNDATION-5 — evidencia directa Task/Event–Interest (2026-10-07)

- Rama cerrada `feat/sf-interest-foundation-5-activities`, creada desde `main`
  `4eb62475184b29234024c94c8084174be8020ca5`. PR #69 fusionado mediante
  `543955975a2ca43c3b6e07b71400f437e6e3321f`; FOUNDATION-5 está desplegada y
  certificada operacionalmente en shadow. Producción permanece intacta.
- Se incorporan runs auditables y detalle materializado propios para
  `Task.What → Interes__c` y `Event.What → Interes__c`. La fuente no modifica ni
  sustituye `salesforce_activities`, `salesforce_calls` o cualquier consumidor
  legacy; no existe scheduler ni endpoint nuevo.
- El servicio usa exactamente dos consultas iniciales queryAll paginadas, una
  para Task y otra para Event, con cutoff UTC fijo y
  `What.Type = 'Interes__c'`. No usa `TYPEOF`, prefijos hardcodeados ni un
  fan-out por los IDs locales. Cada página deduplica sus `WhatId`, valida IDs y
  los resuelve contra `salesforce_interests` mediante `whereIn` en lotes de 500.
- Estados auditables: `resolved`, `interest_not_in_source`,
  `invalid_activity_id` e `invalid_interest_reference`. Una referencia directa
  devuelta por Salesforce pero ausente del F2 queda observable como
  `interest_not_in_source`, con `interest_is_deleted=null`; no se busca un
  Interest alternativo.
- Task conserva por separado `ActivityDate` y `CreatedDate`; Event conserva
  `StartDateTime` y `CreatedDate`. Ambos guardan lifecycle, LastModifiedDate y
  SystemModstamp técnicos. `WhoId` es evidencia técnica nullable y nunca una
  condición para procesar la relación directa.
- El run conserva ID/cutoff del F2 canónico y vuelve a comprobar antes de
  publicar que continúa siendo el último run completed. Cualquier F2 posterior
  running, failed o completed hace fallar de forma segura el snapshot sin
  sustituir el último completed.
- Persistencia bulk en chunks de 200, cleanup por PK en chunks de 1.000,
  transacciones cortas, lock de seis horas y errores sanitizados. No se guardan
  Subject, Description, nombres, PII, payloads ni credenciales; no existen
  escrituras Salesforce.
- Comando manual:
  `salesforce:sync-interest-activities --reason="..."`. Las métricas distinguen
  volumen fuente, páginas Task/Event, filas examinadas/materializadas,
  lifecycle y cada causa de referencia no resuelta sin persistir listas de IDs.
- Validación local del correctivo: focal FOUNDATION-5 `15/108`; bloque
  FOUNDATION-1/2/3/3A/4A/4B/5 más regresiones Activities/Calls `137/850`; suite
  completa `1.243/9.082`, todas verdes. Pint y `git diff --check` correctos. La
  migración no cambió y `migrate --pretend` confirmó nuevamente SQL aditiva; no
  se aplicó persistentemente durante esa validación local previa al merge.
- Despliegue shadow verificado: migración
  `2026_10_07_090000_create_salesforce_interest_activity_foundation_tables` en
  batch 69 con estado `Ran`; existen `salesforce_interest_activity_runs` y
  `salesforce_interest_activities`.
- Fuente definitiva: F2 run 2693 completed, cutoff
  `2026-10-07T14:23:10+00:00` y 96.778 Interests locales. El incremental previo
  cubrió `2026-10-07T10:06:26+00:00` →
  `2026-10-07T14:23:10+00:00`: 759 registros, 276 insertados, 468 actualizados,
  15 sin cambios, 0 eliminados, 0 reactivados y 0 errores.
- Certificación FOUNDATION-5 run 2: fuente F2 run 2693/cutoff
  `2026-10-07T14:23:10+00:00`, cutoff F5
  `2026-10-07T14:26:51+00:00`, 96.778 Interests fuente, 2 queryAll iniciales,
  3 páginas Task y 1 Event. Examinó 4.621 Tasks y 393 Events, materializó 5.014
  filas en 26 chunks y resolvió 5.012 relaciones; las 5.014 actividades estaban
  activas y no hubo eliminadas.
- Resultado de calidad: 2 unresolved, ambos `interest_not_in_source`; 0 IDs de
  actividad inválidos, 0 referencias Interest inválidas, 0 errores y 0 errores
  de cleanup. Duración observada: `3.825 s`.
- Los dos `interest_not_in_source` fueron comprobados directamente y read-only
  en Salesforce: los Interests y sus Tasks nacieron a las 14:24:00Z y
  14:24:06Z, después del cutoff F2 14:23:10Z y antes del cutoff F5 14:26:51Z.
  Son drift temporal esperado entre snapshots secuenciales, no referencias
  huérfanas ni errores de integridad. No se documentan sus IDs Salesforce. La
  primera ejecución, separada unas cuatro horas de F2 run 2692, había observado
  286 casos; actualizar F2 los redujo a estos 2 casos temporalmente explicados.
- Retención certificada: permanecen 2 runs históricos y solo 5.014 detalles,
  todos del run 2 vigente. El detalle superseded del primer snapshot se eliminó
  y la metadata histórica se conservó.
- La certificación Salesforce fue exclusivamente read-only. No existe scheduler
  ni consumidor funcional; no se modificaron informes, tablas legacy o
  producción.
- ROT-1 es el siguiente lote funcional previsto. FOUNDATION-5 solo prepara la
  evidencia inequívoca y no rota todavía dashboards ni KPIs. ROT-1 no se inicia
  en este cierre.

## Cierre SF-INTEREST-FOUNDATION-4B (2026-10-07)

- PR #68 fusionado en `main` mediante
  `4eb62475184b29234024c94c8084174be8020ca5`; FOUNDATION-4B queda cerrada y
  certificada en shadow.
- Fuente Interest/F4A: F2 run 2692, cutoff `2026-10-07T10:11:26Z`, 96.502
  Interests y 2.564 referencias inversas únicas y activas, sin review.
- Fuente directa 4B: cutoff `2026-10-07T10:27:24Z`, 2.561 referencias, 2.560
  activas y 1 eliminada.
- Resultado reconciliado: `both_match=2558`, `direct_only=3`,
  `inverse_only=6`, `contradiction=0`, `inverse_shared=0`, `unresolved=0`,
  `invalid_references=0`, `lifecycle_mismatches=0`, `requires_review=9`,
  `errors=0` y `cleanup_errors=0`. Los casos unilaterales no se interpretan como
  errores de integridad demostrados. No se añadió scheduler ni consumidor de
  informes.

## SF-INTEREST-FOUNDATION-4B — evidencia directa y reconciliación bidireccional (2026-10-06)

- Base verificada: `main` en `1813c9bd43a1d46ab8c086fd517aafdb96364217`;
  rama `feat/sf-interest-foundation-4b-opportunity-origin`. El describe
  productivo confirmó `Opportunity.HRM_Interes_Origen__c` como reference a
  `Interes__c` y legible por la integración, por lo que queda superado el
  bloqueo documental anterior.
- La auditoría read-only del 2026-10-06 observó aproximadamente 2.489 relaciones
  directas activas, al menos una eliminada, 2.459 pares coherentes en ambos
  sentidos, 30 solo directos, 6 solo inversos y cero contradicciones. Son cifras
  de certificación de un sistema vivo, no constantes ni cardinalidad contractual.
- Se crean runs y detalles propios para el snapshot directo. El servicio
  `SalesforceOpportunityInterestDirectSyncService` usa queryAll paginado y un
  cutoff UTC fijo; selecciona solo Id, `HRM_Interes_Origen__c`, IsDeleted,
  LastModifiedDate y SystemModstamp. Persiste IDs, validez de referencia y
  lifecycle, sin payload, PII ni datos comerciales.
- `SalesforceOpportunityInterestReconciliationService` procesa por chunks la
  unión de Opportunities del snapshot directo y del último snapshot 4A. Una
  fila por Opportunity conserva el Interest directo, la lista de Interests
  inversos, multiplicidad, lifecycle y `requires_review`. Estados:
  `both_match`, `direct_only`, `inverse_only`, `contradiction`,
  `inverse_shared` y `unresolved`.
- Los runs de reconciliación registran ID/cutoff directo e ID, run F2 y cutoff
  Interest de 4A. Antes de publicar se revalidan ambas fuentes. Cutoffs distintos
  permanecen visibles; no se finge un instante común ni se resuelve una
  contradicción mediante heurísticas.
- Correctivo sénior: antes de consultar `salesforce_interests` se exige que el
  último `ReportSyncRun` canónico sea el mismo F2 completed —ID y cutoff— usado
  por 4A, y se repite la comprobación justo antes de publicar. Un F2 posterior,
  incluso running o failed, impide combinar lifecycle mutable con evidencia 4A
  anterior y deja el run 4B failed sin sustituir el último completed.
- El detalle conserva separadamente `direct_opportunity_is_deleted`,
  `inverse_opportunity_is_deleted` e `inverse_opportunity_presence_status`. Una
  diferencia de lifecycle entre cutoffs se cuantifica en
  `lifecycle_mismatches` y requiere revisión, pero no transforma `both_match` en
  `contradiction` ni establece precedencia directa/inversa.
- Correctivo final: `requires_review` propaga tanto cualquier señal diagnóstica
  ya materializada por las filas F4A como los presence inversos
  `salesforce_missing`, `invalid_reference` y `present_unresolved`. La calidad
  de evidencia no modifica `relationship_status`: un par de IDs coincidente
  continúa `both_match` aunque su presence requiera revisión. El cálculo usa la
  colección inversa ya cargada y no añade queries.
- Se admiten varias Opportunities por Interest y varias referencias inversas
  por Opportunity. No existe constraint 1:1 basada en la fotografía observada.
  Salesforce ID canónico es la única identidad.
- Publicación y retención siguen el patrón probado: solo completed es válido,
  failed no sustituye el último completed, metadata histórica permanece y el
  detalle superseded se elimina por PK en chunks de 1.000 y transacciones
  breves. Un fallo de cleanup posterior no degrada el snapshot publicado.
- Comandos manuales: `salesforce:sync-opportunity-interest-direct --reason=...`
  y `salesforce:reconcile-opportunity-interests --reason=...`. Ambos exigen
  motivo, lock de seis horas y métricas JSON. No existe scheduler ni consumidor
  funcional.
- Seguridad: cero escrituras Salesforce, cero cambios en
  `salesforce_opportunities`, FOUNDATION-4A o informes; errores sanitizados y
  ausencia de credenciales, raw payload y PII. No se ejecutaron migraciones
  persistentes ni accesos Salesforce/producción durante la implementación.
- Validación inicial local: focales 4B `14/110`; FOUNDATION-1/2/3/3A/4A/4B
  `111/652`; suite completa `1.223/8.936`, todas verdes. El correctivo sénior
  amplía focales F4B a `18/138`, el bloque FOUNDATION-1/2/3/3A/4A/4B a
  `115/680` y la suite completa a `1.227/8.964`, todas verdes. Pint focal y
  `git diff --check` correctos. `migrate --pretend` revisado: las dos migraciones
  4B generan únicamente tablas, índices y FK aditivos; el entorno local mostró
  además una migración AUTH previa todavía pendiente, ajena a este cambio. No se
  aplicó ninguna migración persistente.
- Validación del correctivo final de propagación review: focales F4B `19/148`,
  FOUNDATION-1/2/3/3A/4A/4B `116/690` y suite completa `1.228/8.974`, todas
  verdes. No se modificó el esquema ni fue necesario repetir
  `migrate --pretend`.

## EXE-3 — Resumen Ejecutivo global (2026-10-02)

- Rama de trabajo: `feat/exe-3-executive-summary`, creada desde `main`
  `206e0c7b7c55e44e1e37935d59a37aaab44067ff` tras el merge del PR #66.
- EXE-2 queda cerrado documentalmente: PR #66 fusionado con head
  `f5413d594f53ddf09af9f5bfc6e52b964dead41e`, merge
  `206e0c7b7c55e44e1e37935d59a37aaab44067ff`, rama
  `feat/exe-2-executive-daily-data` cerrada.
- Se añade `App\Services\Analytics\Executive\ExecutiveSummaryService` como
  compositor EXE-3. `build()` ejecuta EXE-2 una sola vez por request y
  `compose()` permite probar payloads controlados. El controller no contiene
  thresholds, fórmulas, sorting complejo, queries directas ni lógica de alertas.
- El compositor evalúa cada `engine_input` de EXE-2 con
  `ExecutiveMetricRulesEngine`, conserva MTD y D-364 como contexto, traduce
  status/direction/health a presentación y ordena alertas exclusivamente cuando
  `business_alert=true`.
- Orden de alertas V1: severidad `Crítico > Desviación > Atención > Correcto`;
  a igual severidad, `Desfavorable` precede a `Favorable`; después se usa
  `absolute_difference`; último desempate determinista por `metric_key`. No se
  inventa impacto económico.
- `/informes` deja de ser placeholder y renderiza `reports.summary.index` con
  `x-reports.app-shell`, `x-reports.ui.page-header`, `x-reports.ui.status`,
  `report-ui-kpi-strip`, paneles y empty-state compartidos. No se crea ruta,
  endpoint JSON, JS, scheduler, correo ni migración.
- Acceso: se mantiene `reports.auth` y `ReportUserAccess::canViewReport(...,
  'summary')`. V1 sigue limitado a Administrador y Dirección; los roles
  operacionales continúan redirigiendo a su primer módulo permitido.
- Seguridad: la vista muestra agregados y textos de presentación. No expone PII,
  Salesforce IDs, IDs de `ReportSyncRun`, raw payloads, `error_message` ni
  reason codes técnicos `exe_v1_*`.
- Causas y acciones: EXE-3 no infiere causalidad ni prescribe acciones. Si no
  existe `confirmed_cause`, la alerta muestra revisión requerida sin fabricar
  causa. `recommended_action_key` permanece sin catálogo en V1.
- Pruebas focales iniciales: `ExecutiveSummaryServiceTest` correcto, 4 pruebas /
  18 aserciones; `ExecutiveSummaryPageTest` correcto, 3 pruebas / 43
  aserciones. La validación final completa se registra en la entrega de la
  rama.

## EXE-2 — Datos ejecutivos diarios (2026-10-01)

- Rama de trabajo: `feat/exe-2-executive-daily-data`, creada desde `main`
  `99fc0ee3973919cdfaa57389872b6cd89f480352` tras el merge del PR #65.
- Se añade `App\Services\Analytics\Executive\ExecutiveDailyDatasetService` como
  punto único de entrada del dataset ejecutivo V1 global para `leads`,
  `reservas` y `ventas`. No crea rutas, UI, correo, scheduler, snapshots
  persistentes ni migraciones.
- Cutoff: último día natural cerrado en `Europe/Madrid`, calculado en servidor
  con `CarbonImmutable`. Para una ejecución el 2026-10-01 evalúa 2026-09-30; el
  2027-01-01 evalúa 2026-12-31.
- Referencias: para el día D publica D, D-7, D-14, D-21, D-28 y D-364 opcional.
  D-364 se conserva como referencia complementaria y no forma parte del baseline
  de EXE-1.
- MTD: contexto acumulado `[primer día del mes de D 00:00, D+1 00:00)` en
  `Europe/Madrid`. MTD no sustituye `current` ni las referencias semanales.
- Desde ROT-1, la métrica técnica `leads` reutiliza el universo canónico de
  `SalesforceInterestDashboardDatasetService`. `executiveLeadTotal()` cuenta
  Interests activos por `functional_created_at`, exige el F2 exacto estable y no
  consulta F5 ni ejecuta el pipeline de insights/IA del dashboard. El nombre de
  la clave y su etiqueta visible siguen siendo compatibles con EXE-1/EXE-3.
- Reservas y Ventas reutilizan `ReservationsSalesDashboardDatasetService` vía
  `executiveProduction()`, que usa el agregado canónico. Reservas procede de
  `reservas_totales` por `reservation_date`; Ventas procede de
  `production_sales` por `cv_signed_date`, con deduplicación y exclusiones
  existentes.
- Cobertura/frescura: un sample solo publica valor numérico cuando existe
  evidencia de cutoff de fuente `>= endExclusive` y no hay incidencia de calidad
  real. En Reservas/Ventas se separa cobertura base histórica de frescura
  incremental: la cobertura base exige un `ReportSyncRun`
  `salesforce_opportunities` `completed`, con modo `period` o `all_history`, que
  cubra el rango funcional. Un run `modified` nunca sustituye esa cobertura
  base; solo puede aportar frescura incremental. Los runs `period`/`all_history`
  aportan frescura únicamente para los rangos que cubren. `updated_at` local
  queda solo como metadata diagnóstica. Sin cobertura base queda `parcial`; con
  cobertura base y cutoff/frescura insuficiente queda `desactualizado`; con run
  operativo no completado o incidencia real queda `incidencia`. No se inventan
  umbrales por horas.
- La salud agregada y `day_complete` del `engine_input` se calculan solo con
  `current` y las referencias obligatorias D-7/D-14/D-21/D-28. D-364 y MTD
  conservan cobertura individual, pero no degradan la evaluación diaria.
- Ausencia y cero quedan separados: `0` es válido si la fuente está cubierta y
  el universo canónico contiene cero hechos; `null` representa ausencia de
  cobertura, desactualización o incidencia.
- La salida incluye `cutoff`, `mtd_period`, `metrics.*.current`,
  `references.d7/d14/d21/d28/d364`, `mtd`, `data_health`, `day_complete`,
  `data_incident`, `coverage`, `source_cutoff` y `engine_input` listo para
  `ExecutiveMetricRulesEngine::evaluate()` con configuración tomada de
  `ExecutiveMetricRulesEngine::defaultMetricConfigs()`. EXE-2 no duplica
  thresholds.
- Seguridad: el dataset solo devuelve agregados y metadata técnica; las pruebas
  comprueban que no aparecen emails, teléfonos, nombres sensibles ni IDs
  Salesforce. No consulta Salesforce, no usa HTTP externo, no usa IA, no envía
  correo y no lee filesystem como fuente de negocio.
- Rendimiento: el dataset consulta un conjunto fijo de muestras (D, cuatro
  referencias semanales, D-364 y MTD) mediante los servicios canónicos locales.
  La metadata de `ReportSyncRun` para `salesforce_opportunities` se resuelve
  con consultas acotadas `first()`/`LIMIT 1` y caché interna por rango, sin
  cargar el histórico completo de sincronizaciones. El máximo actual es 14
  consultas de metadata por build de EXE-2 para Reservas/Ventas: cobertura base
  y frescura sobre los 7 rangos fijos. No hay N+1 por registro ni carga de
  tablas completas añadida por EXE-2.
- Pruebas focales iniciales: `php artisan test --filter=ExecutiveDailyDatasetTest`
  correcto, 26 pruebas / 114 aserciones. La validación final completa se registra
  en la entrega de la rama.
- EXE-1 queda cerrado documentalmente: PR #65 fusionado en
  `99fc0ee3973919cdfaa57389872b6cd89f480352`, CI verde, rama
  `feat/exe-1-executive-engine` cerrada. El motor disponible es
  `ExecutiveMetricRulesEngine` con versión `executive_metric_rules_v1`.
- COMM-CLOSE-SCHEDULE queda registrado únicamente como tarea futura pendiente.
  EXE-2 no implementa comando, scheduler ni snapshots de comisiones.

## EXE-1 — Motor Ejecutivo V1 (2026-09-30)

- Rama de trabajo: `feat/exe-1-executive-engine`, creada desde `main`
  `fb88b47551c05669c8c33ee16fc101067c6b08ba`.
- Se añade `App\Services\Analytics\Executive\ExecutiveMetricRulesEngine` como
  motor determinista, puro y agnóstico de módulo para evaluar una métrica
  ejecutiva ya preparada. No tiene IO, Eloquent, queries, HTTP, Salesforce,
  Cache, filesystem, Mail, IA, UI ni dependencia de fecha actual.
- Contrato de entrada: `metric_key`, `current`, referencias `d7`, `d14`, `d21`,
  `d28`, `d364` opcional, `config.minimum_baseline`,
  `config.absolute_gates`, `data_health`, `day_complete` y bandera opcional de
  incidencia. La configuración aprobada inicial para `leads`, `reservas` y
  `ventas` vive en helpers del motor para EXE-2, pero el algoritmo opera sobre
  la configuración recibida.
- Contrato de salida: `rule_version`, `evaluable`, `baseline`, referencias
  usadas, `d364_reference`, variación porcentual con signo, magnitud porcentual,
  diferencia absoluta, `status`, `direction`, `data_health`, `reason_codes`,
  `business_alert`, y campos nulos para causa confirmada, posible causa a
  revisar y acción recomendada. EXE-1 no inventa causalidad ni recomendaciones.
- Versión de reglas: `executive_metric_rules_v1`.
- Reason codes V1: `exe_v1_normal_evaluation`,
  `exe_v1_missing_weekly_reference:<d7|d14|d21|d28>`,
  `exe_v1_insufficient_baseline`, `exe_v1_incomplete_day`,
  `exe_v1_data_incident`, `exe_v1_zero_current`,
  `exe_v1_negative_value` y `exe_v1_missing_current`.
- Decisión funcional fijada: `actual > baseline` es `favorable`, `actual ==
  baseline` es `estable`, `actual < baseline` es `desfavorable`; no existe
  tolerancia de estabilidad ni dirección `revisar`.
- La matriz unitaria cubre igualdad/subida/bajada, `actual=0`, baseline mínimo
  exacto e insuficiente, límites 15/25/40 y superior a 40, degradación por
  puertas absolutas, ausencia individual y múltiple de referencias, D-364
  ausente/presente sin impacto, salud parcial/desactualizada/incidencia, día
  incompleto, ceros reales y negativos no evaluables.
- No hubo migraciones, rutas, controllers, Blade, scheduler, correo ejecutivo,
  datasets EXE-2/EXE-3, llamadas Salesforce ni cambios de runtime de módulos.
- Seguridad: el motor no necesita ni acepta PII por contrato funcional; no
  registra payloads; `no_evaluable` e incidencias no generan alertas de negocio.
- Rendimiento: evaluación O(1), solo arrays escalares pequeños y sin caché.
- Validación focal: `php artisan test --filter=ExecutiveMetricRulesEngineTest`
  correcto, 40 pruebas / 110 aserciones.
- Cierre real: PR #65 fusionado, CI verde, merge
  `99fc0ee3973919cdfaa57389872b6cd89f480352` y rama
  `feat/exe-1-executive-engine` cerrada. EXE-2 se aborda en lote posterior
  separado y EXE-3 sigue pendiente.

## AUTH-PASSWORD-RESET — Cierre operacional (2026-09-30)

- AUTH-PASSWORD-RESET queda `cerrada`: PR #63 fusionado en
  `fb14def6952606cb45c05b9f2d95cfe5e7494552`, CI verde, despliegue producción
  completado y validación operacional completada el 2026-09-30.
- Producción usa `QUEUE_CONNECTION=database` y un worker dedicado
  `laravel-informes-queue-worker`.
- Worker observado: container `laravel-informes-queue-worker`, image
  `app-informes-app`, network `app-informes_informes-network`, bind mount
  `/srv/production/laravel/app-informes -> /var/www`, queue `default`, restart
  `unless-stopped`.
- La validación real confirmó dispatch asíncrono, consumo del Job
  `SendReportUserPasswordResetLink`, tabla `jobs` sin acumulación, `failed_jobs`
  sin fallos durante la prueba, creación del token, nueva solicitud consumiendo
  el token anterior, SMTP aceptado y recepción final tras resolver una
  incidencia externa de Dinahosting/Dinaserver.
- Deuda de infraestructura: las labels históricas de `laravel-informes-app`
  apuntan a `/srv/production/laravel/app-informes/docker-compose.production.yml`
  y `/srv/production/laravel/app-informes/.env.docker`, pero esos ficheros no
  estaban presentes durante la validación operacional. Queda tarea independiente
  para recuperar IaC reproducible del stack de Informes; EXE-1 no reconstruye ni
  versiona Compose.

## AUTH-PASSWORD-RESET — Recuperación segura de contraseña (2026-09-30)

- Implementado el flujo completo para usuarios reales `ReportUser`: enlace desde
  login, formulario de solicitud, Job de envío de correo, formulario de reset,
  actualización de password y vuelta al login. No se toca `App\Models\User`, el
  guard estándar ni la lógica de dashboards/Salesforce.
- Arquitectura: solución dedicada de dominio Informes con
  `report_user_password_reset_tokens`. Se descartó usar el broker `users`
  porque apunta al modelo estándar y no al login manual de `ReportUser`.
- Archivos principales modificados/creados: rutas públicas en `routes/web.php`;
  controlador `ReportUserPasswordResetController`; servicio
  `ReportUserPasswordResetService`; modelo `ReportUserPasswordResetToken`;
  mailable `ReportUserPasswordResetMail`; vistas Blade de solicitud/reset y
  correo; migración `2026_09_30_090000_create_report_user_password_reset_tokens_table`;
  `ReportUser`, login/middleware, CSS del login, `.env.example`; pruebas
  `ReportUserPasswordResetTest`; documentación `ROADMAP`, `PROJECT_CONTEXT`,
  `DECISIONS` y este handoff.
- Base de datos: migración aditiva que añade `report_users.password_changed_at`
  nullable y crea `report_user_password_reset_tokens` con FK, `token_hash`
  único, `email_hash`, `expires_at`, `consumed_at` e índices de consumo/expiración.
- Seguridad: respuesta pública genérica para evitar enumeración; el request HTTP
  no conecta con SMTP y encola siempre el mismo Job para emails válidos no
  limitados; el Job implementa `ShouldBeEncrypted` para proteger el email en el
  payload persistido de cola; solo usuarios activos reciben email; tokens de 64
  caracteres aleatorios; persistencia solo por hash; expiración configurable de
  60 minutos; single use; nueva solicitud invalida anteriores; CSRF en POST;
  validación `min:12|max:255|confirmed`; rate limit por IP + email/token
  hasheados; no hay contraseñas temporales ni tokens en logs.
- Sesiones y remember: el reset actualiza `password_changed_at`; sesiones
  anteriores quedan rechazadas por middleware en la siguiente petición. Las
  cookies remember previas quedan criptográficamente inválidas porque el HMAC
  existente incluye el hash nuevo de la contraseña. No se borra físicamente toda
  la tabla de sesiones para evitar depender del driver/encriptación.
- Concurrencia: `createTokenFor()` bloquea únicamente la fila del `ReportUser`
  con `lockForUpdate()` antes de consumir tokens previos y crear el nuevo token,
  de modo que solicitudes concurrentes del mismo usuario quedan serializadas sin
  lock global ni bloqueo entre usuarios distintos.
- Rendimiento: el endpoint público responde sin esperar a SMTP; búsqueda por
  email usa el índice unique existente dentro del Job; validación de token usa
  `token_hash` único; no hay scans globales ni schedulers nuevos.
- Configuración: nuevas variables opcionales
  `REPORT_PASSWORD_RESET_EXPIRE_MINUTES`,
  `REPORT_PASSWORD_RESET_REQUEST_MAX_ATTEMPTS`,
  `REPORT_PASSWORD_RESET_REQUEST_DECAY_SECONDS`,
  `REPORT_PASSWORD_RESET_MAX_ATTEMPTS` y
  `REPORT_PASSWORD_RESET_DECAY_SECONDS`. Se reutilizan `APP_URL` y `MAIL_*`.
- Pruebas ejecutadas y resultado inicial del lote:
  `php artisan test --filter=ReportUserPasswordResetTest` correcto, 11 pruebas /
  70 aserciones; `php artisan test` correcto, 1.098 pruebas / 8.314 aserciones.
- Pint: el `--test` global sigue fallando por infracciones preexistentes en
  archivos ajenos al diff. La validación aplicable sobre todos los PHP
  modificados/creados en este lote pasó con
  `php .\vendor\bin\pint --test ...` y resultado correcto.
- Build frontend: `npm run build` correcto. Vite conserva los avisos no
  bloqueantes ya conocidos de `/images/login-bg.jpg` resuelto en runtime y
  deprecación Node `module.register()`.
- Acciones manuales necesarias: desplegar código, ejecutar migraciones en la base
  objetivo, limpiar config/cache si aplica y verificar `APP_URL`, `MAIL_*`,
  trusted proxies y `SESSION_SECURE_COOKIE` en staging/producción.
- Riesgos pendientes: no se ha introducido revocación física centralizada de
  todas las sesiones persistidas; la invalidación es efectiva al siguiente
  request mediante comparación de `password_changed_at`.
- Corrección sénior posterior: se eliminó del diff el cambio de rendimiento en
  Stock introducido durante el lote inicial; AUTH no modifica Stock.
- Corrección sénior de pre-PR: el envío pasa a `SendReportUserPasswordResetLink`,
  Job Laravel cifrado con `ShouldBeEncrypted` y `ShouldQueue`. El endpoint de
  solicitud ya no consulta usuarios ni abre SMTP; para cualquier email válido no
  bloqueado por rate limit solo encola el Job y devuelve el mensaje genérico.
  El Job busca por email exacto, exige `ReportUser` activo, genera el token y
  envía el correo. Si el usuario no existe, está inactivo, se desactivó o cambió
  de email antes de ejecutar el Job, termina sin enviar.
- Una incidencia SMTP ocurre fuera del request HTTP ya respondido y queda bajo
  el comportamiento estándar de retries/failures de Laravel Queue; no revela
  existencia de cuenta en la respuesta original.
- `createTokenFor()` bloquea la fila del usuario con `lockForUpdate()` dentro
  de la transacción antes de consumir tokens previos y crear el nuevo. Esto
  serializa solicitudes concurrentes del mismo `ReportUser` sin lock global.
- Validación de la corrección: `php artisan test --filter=ReportUserPasswordResetTest`
  correcto, 16 pruebas / 89 aserciones; `php artisan test --filter=StockRecommendationCandidatePaginationTest`
  correcto, 2 pruebas / 13 aserciones; `php artisan test` correcto, 1.103
  pruebas / 8.333 aserciones; `npm run build` correcto con los avisos no
  bloqueantes conocidos; `git diff --check` correcto. Pint focal sobre PHP
  modificado correcto. Pint global sigue fallando por infracciones preexistentes
  en archivos ajenos al diff.

## SF-INTEREST-FOUNDATION-4A — dependencias Opportunity read-only (2026-09-30)

- La primera versión 4A quedó aplicada y certificada en MySQL local y shadow:
  dos runs completed reprodujeron 65 Interests, 45 sin inversa, 20 referencias
  distintas, 20 `inverse_unique`, cero shared/errores y retención correcta. Las
  20 referencias se clasificaron `not_local` al no existir en la réplica legacy.
- La auditoría posterior contra SandboxRefreshed consultó por queryAll los 20
  IDs: todos eran REST canónicos de Opportunity, todos existían activos y no
  hubo deleted ni missing. Como control, una Opportunity de la réplica shadow
  no existía en ese Salesforce. La causa exacta no se presume; sí queda probado
  que ambas fuentes no sirven como evidencia autoritativa entre sí.
- Se añaden `salesforce_interest_opportunity_dependency_runs` y
  `salesforce_interest_opportunity_dependencies`. Sus semillas distinct
  proceden solo de `inverse_opportunity_salesforce_id`; procesa hasta 100 IDs
  REST canónicos por queryAll y conserva únicamente ID, estado, lifecycle,
  LastModifiedDate y SystemModstamp. Invalid no sale a Salesforce. No se copia
  PII, datos comerciales ni payloads, y nunca se modifica
  `salesforce_opportunities`.
- El pipeline manual queda FOUNDATION-2 → dependency Opportunity → 4A. Tanto el
  dependency run como 4A exigen el mismo ID/cutoff F2 completed y current, sin
  pending ni huecos de cobertura. Un cambio concurrente invalida la publicación.
- Estados dependency finales: `active`, `deleted`, `missing`, `invalid`;
  `pending` solo durante construcción. En 4A se proyectan como
  `present_active`, `present_deleted`, `salesforce_missing` e
  `invalid_reference`. El detalle registra
  `interest_opportunity_dependency_snapshot` como evidence source.
- El servicio `SalesforceInterestOpportunityReconciliationService` procesa
  Interests por PK en chunks de 200 y multiplicidades por lote. Presence y
  lifecycle proceden exclusivamente del dependency snapshot; la metadata
  legacy nullable queda solo para preservar el histórico de runs 1 y 2 y ya no
  bloquea ni clasifica nuevas ejecuciones.
- La identidad Opportunity se normaliza con `TRIM` de extremo a extremo: seed
  dependency, cobertura, recuento distinct, agrupación de multiplicidad y
  materialización. Variantes equivalentes con whitespace producen una única
  dependencia y una única Opportunity distinta, y comparten el mismo
  `inverse_reference_count`, sin modificar físicamente las fuentes.
- Relación y presencia permanecen separadas. Relación: `no_inverse`,
  `inverse_unique`, `inverse_shared`. Presencia: `not_applicable`,
  `present_active`, `present_deleted`, `salesforce_missing`,
  `invalid_reference` y `present_unresolved` para los runs dependency-backed.
  `requires_review` sigue siendo diagnóstico para shared, missing, invalid,
  inconsistencia y un Interest activo con Opportunity deleted.
- El comando manual `salesforce:sync-interest-opportunity-dependencies
  --reason=...` exige motivo auditable, usa lock de seis horas y emite métricas
  agregadas. El reconciliador 4A conserva su comando manual independiente.
- La retención protege el completed vigente; durante fallo protege además el
  parcial failed actual. El detalle superseded se elimina por PK en chunks de
  1.000 y transacciones breves. Un error de cleanup posterior no degrada el
  completed y solo incrementa `cleanup_errors`.
- En esta fase 4A FOUNDATION-4B quedó temporalmente bloqueada porque aquel
  usuario técnico no veía un lookup Opportunity → `Interes__c`. No se añadió un
  campo supuesto ni se tocó el sincronizador legacy. El bloqueo se resolvió
  posteriormente mediante describe productivo de `HRM_Interes_Origen__c`; el
  cierre y certificación 4B vigentes constan al inicio de este documento.
- No existe scheduler ni consumidor funcional. Las nuevas migraciones y el
  pipeline dependency-backed permanecen pendientes de revisión/aplicación
  runtime; producción continúa intacta.
- Validación local: 4A focal, 20 pruebas/112 aserciones; dependency Opportunity
  + 4A, 30/185; FOUNDATION-1/2/3/3A/4A y regresiones Opportunity/Stock,
  172/1.094; suite completa final, 1.117/8.429. El único fallo observado en una
  ejecución previa fue el umbral temporal ajeno de
  `StockRecommendationCandidatePaginationTest` (41,58 s frente a 20 s): pasó
  aislado (2/13) y la repetición completa quedó verde. Pint y
  `git diff --check HEAD` correctos.
  `migrate --pretend` generó únicamente las tablas dependency, sus índices/FK,
  el enlace nullable dependency run→4A, evidence source y la conversión nullable
  de metadata legacy para preservar los runs históricos; no se aplicó ninguna
  migración persistente.
- Certificación final shadow: dependency runs 1 y 2, ambos ligados al F2 run
  2688 y cutoff 2026-09-29T11:28:13+00:00, resolvieron 20 referencias como
  active, con 0 deleted, missing, invalid, errors y cleanup_errors. La retención
  dejó únicamente las 20 dependencias del run 2.
- Reconciliación final: runs 3 y 4 dependency-backed, ambos completed. El run 4
  quedó ligado al dependency run 2 y materializó 65 filas: 20 inverse_unique /
  present_active y 45 no_inverse / not_applicable, con 0 requires_review,
  errors y cleanup_errors. Solo permanecen los 65 detalles del run 4; la
  metadata histórica de los runs 1-4 se conserva.

## SF-INTEREST-FOUNDATION-3 — métrica `lead_merged` (2026-09-29)

- Se corrigió exclusivamente la agregación de `lead_merged` para las
  resoluciones dependency-only materializadas desde FOUNDATION-3A: cada fila con
  `immediate_master_lead_id` demostrado suma una vez, igual que la rama legacy.
- No cambian relaciones, estados master, lifecycle, evidencia ni matriz de
  conflictos. El cálculo permanece O(1) durante el procesamiento, sin queries o
  scans adicionales y sin doble conteo cuando el origin también existe en
  `salesforce_leads`.
- La revalidación final en shadow quedó certificada sobre el commit
  `636d0640c9c8dae7480bc3c7d95bfc046f7b0fea`: `lead_merged=5904`,
  `master_direct=5387`, `master_chain=517`, `exact=4`, `conflicts=1` y cero
  errores. La identidad `5387 + 517 = 5904` confirma la métrica corregida.
- Validación local: FOUNDATION-3, 25 pruebas/132 aserciones; FOUNDATION-3A +
  FOUNDATION-3, 40/215; FOUNDATION-1/2/3/3A, 67/356; suite completa,
  1.087/8.244. Pint y `git diff --check HEAD` correctos.

## SF-INTEREST-FOUNDATION-3A — dependencias Lead read-only (2026-09-29)

- Se añadieron `salesforce_interest_lead_dependency_runs` y
  `salesforce_interest_lead_dependencies` como snapshot separado. Las semillas
  proceden exclusivamente de `salesforce_interests.migration_origin_lead_id`;
  nunca se inserta ni modifica `salesforce_leads`.
- El comando manual `salesforce:sync-interest-lead-dependencies --reason=...`
  exige motivo, lock de seis horas y solo publica métricas agregadas. No existe
  scheduler.
- El servicio exige que el run más reciente de FOUNDATION-2 sea `completed` y
  tenga cutoff. Persiste su ID/cutoff, siembra origins mediante `INSERT … SELECT`
  y vuelve a validar la misma fuente antes de publicar. Un run F2 nuevo,
  running o failed impide producir un snapshot 3A válido.
- La cola persistente procesa hasta 100 pending por iteración y admite únicamente
  IDs REST canónicos de exactamente 18 caracteres alfanuméricos. Un ID de 15
  caracteres queda `invalid` y no se consulta ni se convierte. Se usa solo
  queryAll con `Id`,
  `IsDeleted`, `MasterRecordId`, `LastModifiedDate` y `SystemModstamp`. Invalid no
  sale a Salesforce; masters descubiertos vuelven a la cola sin duplicarse.
- Estados: pending transitorio, active, deleted, missing observable e invalid.
  No se almacenan Name, email, teléfono, dirección, Owner ni raw payload.
- Solo `completed` es snapshot válido. Los detalles superseded se borran por PK
  en chunks de 1.000 y transacciones cortas; un cleanup posterior fallido no
  degrada el nuevo completed y se conserva metadata histórica de runs. Un
  SIGKILL puede dejar running parcial; no hay recovery automático.
- FOUNDATION-3 exige el último 3A completed, current, completo y ligado al run
  exacto de FOUNDATION-2. Para migration origins, 3A prevalece sobre lifecycle y
  MasterRecordId legacy incluso si el ID también existe en `salesforce_leads`.
  Leads legacy sin Interest mantienen su comportamiento. Las resoluciones y el
  run registran `lead_evidence_source` y `lead_dependency_run_id`.
- El lifecycle 3A conserva su semántica nullable: active=false, deleted=true y
  missing/invalid=null, incluso si el mismo ID existe activo en la réplica
  legacy. `lead_deleted` solo cuenta evidencia estrictamente true.
- F2, 3A y 3 siguen siendo procesos manuales sin scheduler. Antes de automatizar
  el pipeline debe evaluarse un lock compartido o mecanismo equivalente que
  serialice FOUNDATION-2 → FOUNDATION-3A → FOUNDATION-3 y cierre la ventana
  entre la comprobación final de la fuente y la publicación.
- Las migraciones aditivas de FOUNDATION-3A y la ampliación nullable de
  FOUNDATION-3 fueron revisadas, aplicadas y verificadas en shadow; producción
  permanece intacta.
- La fuente certificada fue el run F2 número 2688, `completed`, con cutoff
  `2026-09-29T11:28:13+00:00`: 65 Interests activos, 0 eliminados, 0 errores e
  incremental con overlap de 300 segundos.
- Certificación real: run 3A número 1, ligado al run F2 número 2688 y cutoff
  `2026-09-29T11:28:13+00:00`, terminó completed. Sembró 4 origins, descubrió 5
  dependencias —incluido 1 master— y realizó 2 llamadas queryAll para 5 IDs.
  Totales: 4 activos, 1 eliminado, 0 missing, 0 invalid, 0 pending, 0 errores y
  0 errores de cleanup. Entre los origins hubo 3 activos y 1 eliminado; el
  master descubierto estaba activo. No se amplió `salesforce_leads`.

## SF-INTEREST-FOUNDATION-3 — reconciliación local Lead–Interest (2026-09-28)

- FOUNDATION-3 está revisada sénior y certificada en shadow. El hallazgo inicial
  de cuatro migration origins ausentes de `salesforce_leads` motivó
  FOUNDATION-3A; quedó resuelto sin ampliar el universo legacy y los cuatro se
  materializan ahora como relaciones `exact` con evidencia dependency snapshot.
- `salesforce:reconcile-interests-local --reason=...` es manual, no usa
  Salesforce ni scheduler y exige motivo auditable. Un lock de seis horas
  impide ejecuciones concurrentes; siempre se libera en `finally`.
- La clave histórica exclusiva sigue siendo el Salesforce ID de
  `migration_origin_lead_id`. FOUNDATION-3A aporta lifecycle/master autoritativo
  para esos IDs sin incorporarlos al universo `salesforce_leads`.
  No se usan nombre, teléfono, email, Owner, fecha, vehículo, fuente o UTM. Las
  fuentes Lead/Interest solo se leen y no se copian PII ni `raw_payload`.
- Cada run dedicado materializa un snapshot por chunks de 200 y cursor PK, sin
  OFFSET ni carga total. Cada Lead produce una resolución; los Interests con
  origen ausente o inexistente producen una resolución propia. Las relaciones
  exactas no se duplican. Un nuevo run completado sustituye las filas del
  snapshot completado anterior, pero conserva sus métricas históricas.
- Los estados documentados son: relación `exact`, `lead_without_interest`,
  `interest_origin_missing_lead`, `interest_without_migration_origin`; master
  `none`, `direct`, `chain`, `missing`, `self_reference`, `cycle`,
  `depth_exceeded`, `not_applicable`; persona `coherent_account`,
  `coherent_lead`, `coherent_none`, `inconsistent`, `not_applicable`; vínculo
  actual `matches_origin`, `matches_master`, `no_current_lead`, `other` y
  `not_applicable`.
- Un run fallido conserva filas parciales únicamente como diagnóstico ligado al
  run `failed`; nunca se presenta como snapshot válido. Solo se conserva el
  parcial del failed más reciente. Con un fallo pueden coexistir como máximo el
  completed vigente y ese failed; tras éxito queda únicamente el completed
  actual. Los detalles superseded se borran por PK en chunks de 1.000 y
  transacciones acotadas. La ruta failed protege por ID el failed actual y el
  último completed válido, y elimina detalles de cualquier otro run finalizado,
  incluidos restos de completed superseded. Las métricas históricas permanecen.
- Construcción/publicación y cleanup están separados: al terminar la
  reconciliación el run se publica `completed`; un fallo posterior tras uno o
  más chunks de cleanup solo incrementa `cleanup_errors`, no cambia el estado,
  no elimina el snapshot nuevo y deja restos superseded reintentables por el
  siguiente run, incluso cuando esa siguiente reconciliación falla durante la
  construcción.
- La matriz central de conflicto marca persona inconsistente, master
  missing/self/cycle/depth, origin huérfano, alignment `other`, o
  `matches_origin` con master direct/chain. No marca automáticamente
  `lead_without_interest`, `interest_without_migration_origin` ni
  `no_current_lead`.
- No se valida Accounts físicamente ni se convierte `converted_account_id` en
  regla. Un Lead sin Interest es un hecho local observable, no prueba de que el
  batch histórico lo omitiera, porque no existe réplica local verificada de
  `DuplicateReviewed__c`.
- FOUNDATION-3 no tiene consumidores funcionales y no cambia informes, KPIs,
  Leads, Interests, Opportunities, Activities ni tablas legacy.
- La resolución de masters diferencia IDs cargados e IDs ya expandidos. Así una
  cadena A→B→C resuelve C por lote aunque A/B estén en el chunk y C fuera.
- Límite operacional: la cota de dos snapshots detallados se garantiza en rutas
  gestionadas cuyo cleanup concluye. Si también falla el cleanup de una ruta
  failed, pueden persistir temporalmente restos superseded, sin eliminar el
  último completed válido. Un `SIGKILL` o caída abrupta puede dejar un run
  `running` parcial; no se implementa todavía recovery automático ni scheduler.
- Certificación final: run 3, ligado al dependency run 1, terminó completed en
  `363.401 s`. Examinó 1.059.421 Leads y 65 Interests y materializó 1.059.486
  filas: exact 4, lead_without_interest 1.059.421,
  interest_origin_missing_lead 0, interest_without_migration_origin 61,
  lead_deleted 6.477 y lead_merged 5.904. Masters: none 1.053.521, direct 5.387,
  chain 517, not_applicable 61 y cero missing/self/cycle/depth. Persona: 49
  coherent_account, 16 coherent_lead y 0 inconsistent. Hubo un único conflicto,
  correspondiente a alignment `other`; es una incidencia observable de
  integridad, no una regresión del reconciliador.
- Los cuatro origins quedaron exact con evidencia
  `interest_dependency_snapshot`: 3 activos, 1 eliminado, 1 master direct y 3
  sin master; alignments matches_master 1, matches_origin 2 y other 1. Tras
  publicar, solo permanecen las 1.059.486 resoluciones del run 3; el detalle del
  run 2 fue eliminado y sus métricas históricas se conservaron.

## SF-INTEREST-FOUNDATION-2 — sincronización local read-only (2026-09-28)

- Estado formal: **completada y certificada en shadow y contra Salesforce
  SandboxRefreshed en full + incremental**.
- Implementación y revisión sénior aprobadas.
- Commit funcional:
  b69f0f88435e7509a039d30e4d62bf64468cd87b.
- Validada en MySQL local y certificada en shadow.
- En shadow se aplicaron correctamente las migraciones 61 y 62.
- Verificados lifecycle, auditoría, índices, FK cascade y UNIQUE.
- Smoke MySQL shadow:
  insert bulk, idempotencia, update por PK y conflicto de migration origin.
- El smoke fue transaccional y terminó con 0 fixtures persistidas.
- Runtime shadow correcto: rutas OK, comando registrado, scheduler no registrado
  y HTTP 302.
- La autenticación real contra Salesforce SandboxRefreshed usa OAuth
  `client_credentials`: se obtuvo un token Bearer y la Connected App ejecutó
  mediante `Run As` con un usuario técnico específico. Laravel no usa
  username/password/security token de ese usuario y no se documentan secretos.
- Permisos efectivos verificados sobre `Interes__c`: `queryable=true` y
  `createable=false`, `updateable=false`, `deletable=false`. Los 26 campos
  requeridos estaban disponibles (`missing_fields=[]`); query estándar,
  queryAll y `Owner.Name` funcionaron. No se realizó ninguna escritura. Aunque
  el cliente PHP conserva métodos genéricos de escritura, los permisos del
  usuario técnico aportan la defensa efectiva de mínimo privilegio.
- El bootstrap real `salesforce:sync-interests --full` completó con cutoff
  `2026-09-28T14:31:28+00:00`: 2 páginas, 65 consultados, 65 insertados, 0
  actualizados, 0 sin cambios, 0 eliminados, 0 reactivados y 0 errores.
- Estado local posterior: 65 Interests activos, 0 eliminados y 0 errores de
  auditoría. El último run `salesforce_interests/salesforce` quedó `completed`,
  con `source_cutoff_at=2026-09-28 14:31:28 UTC` y `error_message=null`. Solo un
  run completado puede aportar el watermark; eliminados continúa usando
  queryAll.
- FOUNDATION-2 ofrece full e incremental por `SystemModstamp`, cutoff UTC fijo,
  overlap configurable, lectura paginada, chunks locales de 200, lifecycle de
  eliminados/reactivados, auditoría segura y materialización canónica previa.
  La primera ejecución incremental real completó la ventana
  `2026-09-28T14:26:28+00:00` → `2026-09-28T15:06:10+00:00`. Aplicó exactamente
  300 segundos de overlap sobre el watermark full de `14:31:28`, consultó 2
  páginas y 0 registros, con 0 insertados, actualizados, sin cambios, eliminados,
  reactivados y errores. El run terminó `completed`, `error_message=null`, avanzó
  `source_cutoff_at` a `2026-09-28 15:06:10 UTC` y mantuvo 65 Interests activos,
  0 eliminados y 0 errores de sync. La cardinalidad quedó estable y no se
  observaron duplicados.
- Quedan certificados full, incremental, watermark, overlap, query/queryAll,
  lifecycle, persistencia por chunks, auditoría y permisos Salesforce read-only.
- Producción permanece intacta.
- No se habilitó scheduler ni consumidor funcional de informes.
- FOUNDATION-3 es una fase local independiente. El hallazgo histórico de cuatro
  migration origins ausentes de la réplica legacy fue resuelto por la fuente
  FOUNDATION-3A separada; no fue un defecto del sync de Interests ni requirió
  ampliar `salesforce_leads`.
- Deuda operativa de shadow, fuera del comportamiento funcional: el contenedor
  conserva variables Salesforce antiguas inyectadas por Docker y las pruebas
  manuales requieren actualmente retirar esas variables para usar la
  configuración segura; además, el egress hacia Salesforce se añadió
  manualmente. Ambas cuestiones deben resolverse antes del despliegue
  productivo del conjunto, sin documentar credenciales ni valores de entorno.

### Contratos posteriores confirmados por Samu (no implementados)

- SandboxRefreshed usará un usuario técnico exclusivamente read-only y el batch
  histórico continúa desactivado. Su universo operativo son Leads no eliminados
  con `DuplicateReviewed__c=false`; eliminados/fusionados serán excepciones
  separadas. El batch requerirá ejecución persistente, métricas y rollback por
  ejecución.
- `IN_Lead_Origen_Migracion__c` identifica el Lead histórico concreto. Account
  mantiene prioridad canónica y distintos Leads históricos conservan distintos
  Interests. Contact se resolverá por Lead convertido o Account, sin elección
  arbitraria.
- Quote seguirá `Quote → Opportunity → HRM_Interes_Origen__c → Interes__c`.
  Task usará `ActivityDate` y `CreatedDate` como desempate del mismo día; Event,
  `StartDateTime`. Nunca se asociará automáticamente una actividad a un Interest
  posterior a ella. Contact Center continúa parcialmente bloqueado.
- Los pools productivos procederán de `HRM_Reparto_Regla__c`, sin IDs
  hardcodeados. El cutover registrará timestamps UTC y hora local. Opportunity
  fotografiará la atribución del Interest y cambios posteriores no reescribirán
  esa historia.
- Los tipos canónicos siguen siendo `Venta`, `Venta con cambio` y `Tasación`.
  El orden diario confirmado es Lead/Account/Contact → Interest →
  Opportunity/Quote → Task/Event → hitos → deletes/merges → integridad.

## SF-INTEREST-FOUNDATION-1 — persistencia local de Interest (2026-09-25)

- FOUNDATION-1 está implementada, revisada y aprobada. El commit publicado es
  `2b9ab559e1b1016e3e623fcd45462e1a0fd43541`
  (`feat: add local Salesforce interest foundation`). La fase queda validada
  en MySQL local y certificada en shadow; producción permanece intacta.
- Se añadió exclusivamente la foundation local y aditiva de `Interes__c` en
  `salesforce_interests`. No existe todavía sincronizador, SOQL, comando,
  scheduler, reconciliador, endpoint ni consumidor funcional; todas las tablas
  y funcionalidades legacy permanecen sin cambios.
- La tabla usa PK local y `salesforce_id` de 18 caracteres único. Conserva IDs
  externos de Lead, Account, Lead de migración, Owner, ambos vehículos y
  Opportunity inversa sin FK SQL. `migration_origin_lead_id` es nullable y
  único cuando existe; MySQL y SQLite permiten múltiples `NULL`.
- La persona canónica se materializa como tipo + Salesforce ID: Account tiene
  prioridad, Lead es fallback y ausencia se representa con `NULL`. No existe
  tabla Persona ni matching por teléfono, email o nombre. La fecha funcional
  se materializa con `origin_created_at ?? salesforce_created_at`; nunca usa
  timestamps locales.
- Corrección de revisión sénior: una fecha de origen `null`, vacía o compuesta
  solo por whitespace se considera ausente y cae correctamente a
  `salesforce_created_at`. `SalesforceInterestFoundationResolver::materialize()`
  es el contrato único para preparar los derivados. El evento Eloquent
  `saving` lo invoca como safety net; los futuros `insert`/`upsert` bulk no
  ejecutan eventos y deberán invocarlo explícitamente al construir cada fila.
- Estado, tipo, fuente, medio y canal son strings no restrictivos. No hay
  enum/check ni normalización silenciosa. Las seis UTMs respetan las longitudes
  confirmadas; `raw_payload` es JSON nullable y el futuro sync solo deberá
  guardar campos consultados explícitamente, sin registrarlo en logs.
- Los índices se limitan a identidades, fecha funcional, cursor incremental,
  Opportunity inversa y persona canónica + fecha. No se añadieron índices
  prematuros por Owner, estado, tipo, delegación, fuente, medio o canal.
- Los tipos de los campos de descarte continúan `PENDIENTE DE CONTRATO
  SALESFORCE`; no se inventaron columnas. También quedan pendientes rollback
  del batch, snapshot final legacy, sincronización continua y deletes/merges,
  atribución histórica mutable frente a fotografiada, fechas por actividad,
  controles de integridad, campos Contact Center, Quote, Contact, pools de
  producción y cutover.
- La migración `2026_09_25_090000_create_salesforce_interests_table` se aplicó
  correctamente únicamente sobre la base MySQL local
  `informes_intereses_local`. Se verificaron la PK, los ocho índices de
  FOUNDATION-1, las dos restricciones únicas y los índices incremental y de
  persona canónica. Dos fixtures sintéticas confirmaron Lead→Lead,
  Account→Account y el fallback de fecha funcional a Salesforce CreatedDate;
  ambas se eliminaron tras la comprobación.
- Seguridad: no se añadieron datos de contacto, secretos o credenciales. No se
  conectó a Salesforce, shadow o producción y no se ejecutaron backfills. La
  migración se certificó posteriormente en shadow y no se ha aplicado en
  producción; producción permanece intacta.
- Validación final tras las correcciones: test específico con 12 pruebas y 56
  aserciones; regresiones Salesforce con 43 pruebas y 296 aserciones; suite
  completa con 1.032 pruebas y 7.940 aserciones. Pint pasó sobre los PHP
  modificados, `git diff --check` fue correcto y `migrate --pretend` mantuvo el
  mismo `CREATE TABLE` y ocho índices. El literal `Tasación` se verificó en el
  archivo real como UTF-8 correcto. No se requirió build frontend.

## Cierre formal de RV-2 tras PR #59 (2026-09-23)

- El PR #59 se fusionó en
  `d57d460922cddbfe4e8abf4f9dceb9bffd134613`; la CI del PR y la CI posterior
  de `main` (`CI #132`) finalizaron correctamente.
- La rama `feat/rv-2-direction-summary-production-periods` fue eliminada y
  RV-2 queda formalmente `cerrada`, sin acciones funcionales pendientes. Con
  este cierre, RV-1, RV-2 y RV-3 están `cerradas`.
- RV-2 no incorporó migraciones propias ni realizó escrituras Salesforce,
  sincronizaciones o reprocesados. Tampoco se ha realizado todavía ningún
  despliegue.
- El siguiente paso operacional es el despliegue controlado del bloque
  Reservas/Ventas. La siguiente tarea de desarrollo prevista es SF-7A-OPS, que
  continúa `pendiente` y no ha sido activada.

## Aprobación sénior de RV-2 para PR (2026-09-23)

- La revisión sénior aprobó RV-2 sobre el HEAD
  `a07da5646b1ecdc5e1edee4ac6bb37bac8a17232`. Los cuatro defectos de la primera
  revisión quedaron corregidos y no existen bloqueantes funcionales conocidos.
- La suite completa pasó con 1.017/1.017 pruebas y 7.818 aserciones. La deuda
  global de Pint permanece en archivos ajenos al lote y no fue introducida por
  RV-2; todos los PHP modificados por RV-2 pasan Pint.
- RV-2 queda `aprobada`, no `cerrada`. La CI del PR está pendiente y es
  obligatoria antes de cualquier merge; SF-7A-OPS continúa `pendiente`.
- Este checkpoint no incorpora cambios runtime, datos productivos ni PII. No
  hubo despliegue, sincronizaciones, Salesforce ni otras operaciones
  productivas.

## Correcciones de revisión sénior de RV-2 (2026-09-23)

- Sobre el HEAD revisado `648751bbc260659396394b0ed1680e2a078ecde7` se
  publicó el commit funcional corregido
  `d6486ca3710c43e2a96a941338d17c46358b10aa`. RV-2 continúa `en_revision` en la
  misma rama y su punto de reanudación sigue siendo la revisión sénior previa al
  PR; no se abrió PR ni se inició SF-7A-OPS.
- `current_month` calcula los días completos incluidos y limita el fin candidato
  del tramo comparado a `currentStart`. La regresión del 31-03-2026 acredita
  actual `[2026-03-01, 2026-04-01)` y comparado
  `[2026-02-01, 2026-03-01)`, con `fin` visible 28-02-2026 y sin solapamiento.
- Ventas de Producción exige siempre RecordType Venta/Cambio además de firma,
  `cv_signed_date` y exclusión de `Cerrada Perdida`. Con filtro `all` solo esos
  dos tipos producen ventas; `Venta` conserva ambos y `Tasacion` produce cero.
  Tasación, Otro y tipo ausente siguen disponibles para el contrato legacy y no
  se redefinió `kpis.cv_firmados`.
- La identidad de `reservas-ventas-dashboard-v7` usa únicamente límites
  visibles/canónicos, no timestamps técnicos variables. El payload público
  conserva los límites exactos del cálculo cacheado. Dos peticiones
  `last_30_days` separadas cinco segundos devolvieron el mismo
  `dataset_generated_at` y la misma metadata técnica.
- En Resumen, `currentFilters()` envía explícitamente `created_date`; el valor
  DOM elegido para Comerciales/Portales no cambia ni se resetea al alternar
  pestañas. Así el filtro oculto no afecta al contrato ejecutivo ni fragmenta
  su caché.
- No se añadieron consultas, endpoints, permisos, PII, migraciones, schema,
  dependencias u operaciones productivas. Rendimiento comercial, Comisiones,
  Stock y Salesforce no fueron modificados.
- Pruebas específicas correctas: períodos/caché 4/4 (38 aserciones), tipos 2/2
  (19), Reservas totales/frontend 8/8 (85), cohorte/calidad 8/8 (102), endpoint
  3/3 (77), criterio de fecha 1/1 (8) y Rendimiento comercial 77/77 (1.020).
  `npm run build`, `node --check`, Pint sobre todos los PHP modificados y
  `git diff --check` fueron correctos.
- `./vendor/bin/pint --test` global continúa fallando por deuda de estilo
  preexistente en archivos ajenos a RV-2, incluidos módulos de Comisiones,
  Stock, migraciones y tests no modificados. No se amplió el alcance para
  reformatearlos. La suite completa sí pasó íntegra: 1.017/1.017 pruebas y
  7.818 aserciones en 359,109 s.

## RV-2 — Producción y períodos de Resumen Dirección (2026-09-23)

- RV-2 queda `en_revision` en
  `feat/rv-2-direction-summary-production-periods`, nacida de `main`
  `3d3354f6fd4a3605dd3207b9448fa5be114e8d80`. El HEAD funcional revisable es
  `ae09d4cb75bf50063cc8e78c3540ef70bc28d293`; el punto de reanudación es la
  revisión sénior previa al PR. No se abrió PR ni se inició SF-7A-OPS.
- Resumen Dirección separa visual y contractualmente **Producción del período**,
  **Cohorte de oportunidades creadas en el período** y **Reservas vivas
  actuales**. Se retiró la mezcla visual de Comparativa básica, sin eliminar ni
  redefinir `kpis`, `comparativa`, `universe_date_criterion`,
  `universe_date_label`, `periodo_actual` o `periodo_comparado`.
- El contrato aditivo publica `produccion_periodo` y `cohorte_creacion`.
  Producción reutiliza exactamente `reservas_totales` por `reservation_date` y
  calcula ventas válidas por `cv_signed_date`; Cohorte pertenece siempre a
  `created_date` y describe resultados actuales, aunque ocurran después.
- Ventas conserva `cv_signed = true`, fecha presente, etapa distinta de
  `Cerrada Perdida`, filtros/scopes existentes y deduplicación vehículo + fecha
  con fallback a Opportunity. Un grupo con clasificación contradictoria se
  excluye y se audita como incidencia; no se elige por orden técnico.
- Los períodos mantienen sus fechas visibles y añaden inicio técnico incluido,
  fin técnico excluido, semántica `[start,end)` y timezone `UTC`. Mes actual usa
  comienzo del día siguiente como fin exclusivo y compara el mismo número de
  días completos; mes anterior y custom conservan límites naturales, y últimos
  30 días mantiene su semántica previa.
- El selector `dateCriterion` se oculta solo en Resumen. Permanece intacto en
  las pestañas legacy y no se resetea al cambiar de pestaña. El catálogo de
  filtros une las dimensiones relevantes de cohorte, reservas y ventas después
  de aplicar el scope del servidor, incluida la restricción de Area Manager.
- La auditoría JSON/CSV existente admite `metric=cv_firmados_periodo`, consulta
  por `cv_signed_date`, conserva filas de grupos duplicados y reconstruye el KPI
  con `counted_in_kpi`. Las pruebas verifican que no incorpora nombres, datos de
  contacto ni otra PII nueva.
- La reconciliación fija que una operación creada en junio, reservada en julio
  y firmada en agosto pertenece a esas producciones por fecha de hito y no a la
  cohorte de agosto. Una fixture simple reconcilia con Reservas/Ventas de
  Rendimiento comercial; otra compatible con Comisiones confirma firma, mes y
  exclusión de perdida. Comisiones puede diferir por owner activo, gestión,
  elegibilidad, tipo y fórmulas económicas, que RV-2 no importa.
- Rendimiento: se reutiliza la consulta de reservas ya existente y se añaden dos
  consultas acotadas por `cv_signed_date` (actual/comparada). Solo si el criterio
  legacy no es `created_date` se añaden dos consultas acotadas para la Cohorte;
  con el valor por defecto se reutiliza el conjunto cargado. No hay N+1, nuevas
  peticiones HTTP ni lectura de todo el histórico. El namespace de Summary pasa
  a `reservas-ventas-dashboard-v7`; V6 expira por TTL y la caché V4 de
  Rendimiento comercial no cambia.
- Archivos modificados: servicio de dataset de Reservas/Ventas; Blade, JS y CSS
  de la pantalla; bundles JS/CSS y `manifest.json`; cuatro suites feature;
  `ROADMAP.md`, `HANDOFF.md`, `PROJECT_CONTEXT.md`, `DECISIONS.md` y
  `docs/informe-reservas-ventas.md`.
- Base de datos y operación: sin migraciones, schema, configuración,
  dependencias, consultas productivas, sincronizaciones, Salesforce ni
  despliegue. No se requieren acciones manuales antes de la revisión.
- Validaciones específicas: `OpportunityDateCriterionTest` 1/1 (8 aserciones),
  `ReservationsSalesTotalReservationsTest` 8/8 (83),
  `ReservationsSalesCohortAndDuplicateQualityTest` 8/8 (102),
  `OpportunitiesCustomPeriodFullMonthTest` 2/2 (25),
  `OpportunityDashboardEndpointTest` 3/3 (77) y
  `ReservationsSalesCommercialPerformanceTest` 77/77 (1.020), todas correctas.
  Pint, `npm run build`, `node --check` y `git diff --check` fueron correctos.
- Suite completa: 1.013/1.014 pruebas y 7.794 aserciones correctas; el único
  fallo fue el benchmark temporal ajeno
  `StockRecommendationCandidatePaginationTest` (44,9126338 s frente a 20 s).
  La repetición aislada pasó: 1/1, 3 aserciones, 12,829 s. No se modificó Stock.
- Riesgo residual: revisión sénior del contrato y la presentación antes del PR;
  la diferencia intencional de timezone con Rendimiento comercial permanece
  documentada y no se armonizó silenciosamente.

## Cierre formal de RV-1 tras el PR #57 (2026-09-23)

- El PR #57 se fusionó con CI verde en el merge SHA
  `f40637bfbab786f8909c2ce4e3b99435937542f6`; la rama funcional
  `feat/rv-1-commercial-performance-ux` fue eliminada después del merge.
- RV-1 queda formalmente `cerrada`, sin acciones operativas pendientes. No hubo
  cambios backend ni operaciones productivas; el detalle de implementación y
  validación permanece en las entradas siguientes de este documento.
- El siguiente lote previsto es RV-2, que continúa `pendiente`, sin rama ni SHA
  de activación. Este checkpoint no inicia su trabajo.

## Aprobación sénior de RV-1 para PR (2026-09-23)

- La revisión sénior aprobó RV-1 para abrir PR sobre el SHA revisado
  `7c4cb00d42b841ac2fe27ce3b0d52a91e077f3f7`; el estado pasa de
  `en_revision` a `aprobada`, no a `cerrada`. RV-2 continúa `pendiente`.
- No se detectaron vulnerabilidades, regresiones funcionales ni cambios
  backend. La implementación revisada, sus bundles y el manifest permanecen
  intactos en este checkpoint exclusivamente documental.
- Observación no bloqueante: los helpers compartidos de formato modificados por
  RV-1 afectan también a otras vistas de Reservas/Ventas, únicamente en su
  presentación `es-ES`; no cambian valores, fórmulas, universos ni contratos.
- La incidencia temporal ajena de Stock permanece documentada sin alterar su
  benchmark. La CI del PR debe estar completamente verde antes de cualquier
  autorización de merge.
- Punto de reanudación: abrir el PR contra `main`, esperar CI verde, realizar la
  revisión final del PR y fusionar solo tras autorización expresa.

## RV-1 — Cierre ejecutivo de Rendimiento comercial (2026-09-23)

### Resumen y decisiones

- RV-1 queda `en_revision` en `feat/rv-1-commercial-performance-ux`, nacida de
  `main` `6bebc0a1d9dc11ef9c5ebf62fcf7c16ea3c9e923`. El HEAD funcional probado es
  `521310b1730b68f0579b0f734101904f65955018`; el siguiente paso es la revisión
  sénior previa al PR. RV-2 continúa `pendiente` y no se creó PR.
- El KPI de cumplimiento muestra directamente `X reservas computables / Y de
  objetivo = Z %`. En global consume las tres claves canónicas de `universe` y,
  con filtro Comercial, las equivalentes de `summary`; no recalcula el
  porcentaje backend ni inventa valores para filas no evaluables.
- La presentación cambia **Semáforo** por **Estado** sin renombrar
  `traffic_light`, y denomina **Ratios de actividad mensual** a los ratios. La
  ayuda visible aclara que cada hito usa su fecha propia, no constituye cohorte
  y puede superar 100 %.
- `reservationsSalesCommercialPerformanceColumnsV5` inicia con una vista
  **Resumen** compacta. **Resumen**, **Actividad** y **Rentabilidad** cambian
  únicamente la visibilidad local, se persisten en V5 y conviven con la
  personalización individual. V4 no se lee, migra ni elimina.
- Ranking, Estado y Comercial son siempre visibles y sticky con anchos y offsets
  deterministas. El Salesforce User ID deja de renderizarse bajo el nombre en
  la tabla ejecutiva; continúa disponible en el payload/atributo técnico local
  y en la auditoría autorizada.
- Enteros, porcentajes, puntos porcentuales y moneda se presentan con `es-ES`.
  La cobertura de margen inferior a 100 % aparece junto al margen de cada fila
  y en el resumen agregado cuando existen ventas sin margen, sin extrapolar
  importes desconocidos. El margen medio sigue usando solo ventas informadas.
- Evolución mensual conserva `cancellations = null` como `N/D` y explica el
  estado real de `cancellation_coverage_by_month`, incluyendo la certificación
  disponible. Una cobertura completa sin eventos mantiene `0`; `sales_dropped
  = 0` también permanece como cero.
- No cambiaron backend, claves JSON, fórmulas, universos, objetivos, ranking,
  deduplicación, caché backend V4, permisos, endpoints, consultas ni peticiones.

### Archivos, seguridad, rendimiento y base de datos

- Fuentes modificadas: vista, JavaScript y CSS específicos de
  Reservas/Ventas; prueba feature; `docs/informe-reservas-ventas.md`,
  `docs/ai/ROADMAP.md` y este handoff. Vite sustituyó únicamente los bundles
  CSS/JS versionados del dashboard y sus dos referencias en el manifest; el
  bundle CSS global rehashado sin relación se restauró.
- No hay cambios de base de datos, migraciones, configuración, dependencias ni
  variables de entorno. No se ejecutaron migraciones, consultas productivas,
  sincronizaciones, conexiones Salesforce, despliegues ni escrituras de datos.
- No se añadieron IDs visibles, PII, payloads, secretos o credenciales. Los
  valores dinámicos nuevos se escapan antes de insertarse en HTML y la auditoría
  conserva su autorización existente.
- Presets, búsqueda, cobertura y formato se resuelven sobre las filas ya
  descargadas. No hay nuevos `fetch`, SQL, N+1, recálculo del dataset ni aumento
  del payload.

### Pruebas, acciones y riesgos

- `php artisan test tests/Feature/ReservationsSalesCommercialPerformanceTest.php`:
  correcto, `77` pruebas y `1.020` aserciones.
- `php artisan test tests/Unit/CommercialPerformanceKpiTest.php`: correcto, `1`
  prueba y `6` aserciones.
- `npm run build`: correcto. Se mantienen los avisos no bloqueantes conocidos
  de Node `module.register()`, `/images/login-bg.jpg` resuelto en runtime y
  tiempos de plugins de Vite.
- `php artisan test`: `1.008` pruebas correctas de `1.009`, con `7.668`
  aserciones. El único fallo fue ajeno a RV-1:
  `StockRecommendationCandidatePaginationTest` tardó `20,1037129 s` frente al
  límite de `20 s`. La repetición aislada del archivo pasó (`2` pruebas/`13`
  aserciones), por lo que se conserva como fluctuación temporal documentada y
  no se modificó Stock.
- `node --check resources/js/reports/reservations-sales-dashboard.js` y
  `git diff --check`: correctos.
- No hay acción manual de datos o configuración. Pendiente únicamente revisión
  sénior; no hacer PR, merge, despliegue ni activar RV-2 antes de la aprobación.

## Cierre formal de RV-3 tras el PR #55 (2026-09-23)

- El PR #55 se fusionó con CI verde en el merge SHA
  `8acb8e461cc675b32162f174af771a5cbc15e8e8`; la rama funcional
  `audit/rv-3-historical-validation` fue eliminada después del merge.
- RV-3 queda formalmente `cerrada`, sin acciones operativas pendientes y sin
  cambios runtime. La evidencia y conclusiones revisadas permanecen en las
  entradas siguientes de este documento.
- El siguiente lote previsto es RV-1, que continúa `pendiente`, sin rama ni SHA
  de activación. Este checkpoint no inicia su trabajo.

## Aprobación sénior de RV-3 para PR (2026-09-23)

- La revisión sénior aprobó RV-3 para abrir PR sobre el SHA revisado
  `821870a0252093dd5c0d50e044878549e8f99589`; el estado pasa de `en_revision` a
  `aprobada`, no a `cerrada`, porque todavía faltan PR, CI y merge.
- No se detectaron cambios runtime, vulnerabilidades, regresiones ni
  discrepancias funcionales. La evidencia productiva ya revisada permanece
  intacta y no se añadieron datos productivos ni PII.
- Punto de reanudación: abrir el PR contra `main`, esperar CI verde, hacer merge,
  eliminar la rama y registrar entonces RV-3 como `cerrada` antes de activar
  RV-1.

## Evidencia productiva y paso a revisión de RV-3 (2026-09-23)

### Resumen, evidencia y archivos

- La investigación histórica de RV-3 finalizó y su estado pasa a
  `en_revision`. La rama `audit/rv-3-historical-validation` queda pendiente
  únicamente de revisión sénior previa al PR; no se inicia RV-1.
- La evidencia se recogió manualmente el 2026-09-23 contra la réplica local
  productiva mediante operaciones exclusivamente de lectura. No hubo llamada a
  Salesforce, sincronización, escritura, modificación de datos ni despliegue.
- `migrate:status` confirmó que
  `2026_09_07_120000_add_lifecycle_fields_to_salesforce_opportunities_table`
  estaba aplicada en producción como lote `60` (`Ran`). El comando solo consultó
  el estado; no se ejecutó ninguna migración.
- Cobertura de julio de 2026: `status = partial`, rango
  `[2026-06-30T22:00:00Z, 2026-07-31T22:00:00Z)`,
  `source_cutoff_at = 2026-07-31T22:00:00Z`,
  `certified_until = 2026-07-15T00:00:00Z` y
  `unresolved_dependencies = 1`. Que la fuente llegue al final del mes no
  certifica el KPI: la dependencia bloqueante detiene la certificación y
  explica correctamente las cancelaciones `N/D`/`null`. No se convierten en
  cero ni se infiere el tramo no certificado.
- Cobertura de agosto de 2026: `status = covered`, rango
  `[2026-07-31T22:00:00Z, 2026-08-31T22:00:00Z)`, certificación y cutoff hasta
  `2026-08-31T22:00:00Z`, con `unresolved_dependencies = 0`. La cobertura de
  cancelaciones de agosto es completa.
- La consulta agregada previa a deduplicación de ventas caídas de agosto obtuvo
  `total_candidates = 0`, `candidates_with_reservation_date = 0` y
  `candidates_with_cv_signed_date_fallback = 0`. La normalización de posibles
  `SUM()` nulos a entero cero no altera el dato determinante: no existe ningún
  candidato bruto. Por ello, `sales_dropped = 0` está demostrado y debe
  conservarse; no hace falta una segunda conciliación de deduplicaciones o
  conflictos.
- Archivos modificados: `docs/ai/ROADMAP.md` y `docs/ai/HANDOFF.md`. No se
  modifican decisiones estables, contexto arquitectónico ni documentación
  funcional porque la evidencia confirma el contrato existente.

### Seguridad, rendimiento y operación

- Las salidas persistidas contienen únicamente estados y timestamps de
  cobertura, recuentos agregados y estado de migración. No contienen Salesforce
  IDs, Account IDs, nombres, matrículas, datos de clientes, `raw_payload`,
  secretos, credenciales ni otra PII.
- No hubo `INSERT`, `UPDATE`, `DELETE`, migración, limpieza de caché, endpoint de
  auditoría, construcción del dashboard completo ni conexión a Salesforce.
- No hay cambios de runtime, rendimiento, esquema, fórmulas, contratos JSON o
  UI. No se repitieron consultas productivas durante este checkpoint
  documental.
- No se intenta resolver la dependencia histórica de julio dentro de RV-3. La
  eventual presentación visible del motivo de `N/D` corresponde a RV-1.

### Validación y reanudación

- La evidencia es coherente con el contrato conservador implementado: solo
  `covered` habilita cancelaciones y la deduplicación no puede crear eventos de
  venta caída cuando el conjunto bruto está vacío.
- No existe una discrepancia que autorice modificar métricas. Punto exacto de
  reanudación: revisión sénior de esta rama; tras aprobación se podrá abrir el
  PR conforme al protocolo, sin marcar todavía RV-3 como `aprobada` o `cerrada`.

## Activación de RV-3 — Validación histórica (2026-09-23)

### Resumen, decisiones y archivos

- Se activó RV-3 como `en_progreso` en la rama
  `audit/rv-3-historical-validation`, nacida exactamente de `main` en
  `04367da61f15301f9d63bb480a5f907dc2caae9b`.
- Antes de crearla se verificó que la rama remota residual
  `docs/roadmap-executive-v1` seguía apuntando al commit aprobado
  `22e2f6496dc376ad6236854e56434e8a8aa0f3cc`; solo entonces se eliminó esa rama
  remota ya fusionada. El PR #54 queda registrado como cerrado y fusionado.
- `ROADMAP.md` identifica la nueva línea base, rama, fecha y punto exacto de
  reanudación. RV-3 no se marca en revisión, aprobada ni cerrada: falta obtener
  la evidencia productiva de solo lectura de julio y agosto.
- Se prepararon dos comandos explícitos: uno llama exclusivamente a
  `CommercialPerformanceDatasetService::historyCoverage()` para julio/agosto y
  otro agrega candidatos brutos a venta caída de agosto con
  `SalesforceOpportunity::query()` y el scope normal del modelo.
- Archivos modificados: `docs/ai/ROADMAP.md` y `docs/ai/HANDOFF.md`. No cambian
  `PROJECT_CONTEXT.md` ni `DECISIONS.md`, porque no se adopta una nueva decisión
  arquitectónica ni cambia la arquitectura implementada.

### Base de datos, seguridad, rendimiento y operación

- No hay migraciones, cambios de esquema, configuración, dependencias ni código
  productivo. No se ejecutaron los comandos de evidencia ni ninguna consulta
  contra datos productivos.
- Los comandos preparados contienen solo consultas `SELECT`: no llaman a los
  payloads de Rendimiento comercial o auditoría que pueden ejecutar
  `insertOrIgnore()`, ni a endpoints, sincronizadores o Salesforce.
- La salida queda limitada a metadatos de cobertura y tres recuentos agregados;
  no contiene nombres, matrículas, IDs de Salesforce o clientes, PII,
  `raw_payload`, secretos ni credenciales.
- Cobertura consulta solo los meses julio/agosto de 2026. La agregación de venta
  caída limita cada rama temporal a `[2026-08-01, 2026-09-01)`, usa los campos
  de fecha indexados y no carga Opportunities en memoria ni introduce N+1.
- No hubo despliegue, PR, merge, sincronización, escritura Salesforce, limpieza
  de caché ni operación de producción.

### Validación y reanudación

- Se revisaron el contrato actual de cobertura, el scope global de
  `SalesforceOpportunity`, la lógica de fecha de referencia y los índices
  existentes antes de redactar los comandos.
- Punto exacto de reanudación: una persona autorizada debe ejecutar ambos
  comandos contra la réplica local y devolver sus salidas agregadas. Si julio
  no está `covered`, se documentará el corte, hueco o dependencia; si el total
  bruto de agosto es mayor que cero, no se concluirá un bug sin autorizar una
  segunda conciliación exacta.
- Riesgo pendiente deliberado: todavía no existe evidencia de datos, por lo que
  no se concluye la causa de `N/D` ni la validez del cero y no se modifica
  ninguna métrica, UI o lógica de negocio.

## Roadmap controlado y decisiones del Resumen Ejecutivo V1 (2026-09-23)

### Resumen, decisiones y archivos

- Se creó `docs/ai/ROADMAP.md` como fuente única de verdad del trabajo pendiente.
  Distingue estados, dependencias, rama/SHA al activar, criterios de aceptación,
  bloqueos y punto exacto de reanudación.
- El roadmap registra como lote previo RV-3 (validación histórica), RV-1 (cierre
  ejecutivo de Rendimiento comercial) y RV-2 (producción y períodos de Resumen
  Dirección). El PR #53 permanece reconocido como fusionado y no se reabre.
- Se registró la secuencia posterior: cierres operacionales Salesforce 7A/7B,
  motor y datos ejecutivos, dashboard global, correo piloto, calibración,
  correctivos transversales, UX por módulo, simplificación SEO, ampliación
  analítica e iniciativas posteriores de IA y GEO/IA.
- `docs/ai/DECISIONS.md` incorpora únicamente el contrato funcional aprobado del
  Resumen Ejecutivo V1: acceso, referencias, evaluabilidad, umbrales,
  priorización, acciones/causas, exclusiones y piloto de correo.
- La revisión sénior precisó que el estado usa la magnitud absoluta de la
  variación y los mínimos usan la diferencia absoluta, manteniendo la dirección
  como dimensión separada y sin inventar tolerancia para `Estable`.
- La secuencia preferida quedó separada de las dependencias técnicas reales. Los
  cierres 7A/7B preceden al Resumen Ejecutivo por planificación, pero no son una
  dependencia técnica de EXE-1 ni de los datos globales de EXE-2.
- No se modificaron `PROJECT_CONTEXT.md`, `DESIGN_SYSTEM.md` ni
  `docs/informe-reservas-ventas.md`: se revisaron como fuentes, pero esta tarea no
  cambia arquitectura implementada, convenciones generales ni fórmulas actuales.
- Archivos modificados: `docs/ai/ROADMAP.md`, `docs/ai/DECISIONS.md` y
  `docs/ai/HANDOFF.md`.

### Base de datos, seguridad, rendimiento y operación

- No hay migraciones, cambios de esquema, variables de entorno, dependencias ni
  configuración.
- No se añadieron secretos, tokens, credenciales ni datos productivos sensibles.
  El único dato identificativo incorporado es el email corporativo expresamente
  aprobado como destinatario del piloto.
- No hubo conexiones a Salesforce, escrituras externas, operaciones sobre
  producción, despliegue, PR ni merge.
- No hay impacto de runtime o rendimiento: el cambio es exclusivamente
  documental y no implementa ninguna tarea del roadmap.

### Validación

- Base verificada antes de editar: `main` limpio en
  `0355dd688a352e9b5d3bc34e7ab98a308cec0498`, actualizado con
  `git pull --ff-only origin main`.
- Rama de trabajo: `docs/roadmap-executive-v1`.
- Rutas y enlaces documentales comprobados: los seis archivos referenciados
  existen. El diff contiene únicamente los tres archivos Markdown declarados y
  `git diff --check` finaliza correctamente. No existe un lint documental barato
  configurado en el repositorio.
- Después del commit y del push iniciales se comprobó que el working tree estaba
  limpio y la rama quedó sincronizada con `origin/docs/roadmap-executive-v1`.
- No se requieren acciones manuales, migraciones ni limpieza de caché. El riesgo
  pendiente está deliberadamente contenido en las fichas: ninguna rama futura,
  contrato no aprobado u operación queda activada por este documento.

## Reservas totales del período en Resumen Dirección (2026-09-22)

### Resumen, decisiones y archivos

- El dashboard legacy de Reservas / Ventas incorpora
  `kpis.reservas_totales`: reserva true con `reservation_date` dentro del rango,
  independientemente de su estado actual vivo, `Cerrada Perdida` o CV firmado.
  Las reservas sin fecha quedan fuera. La métrica siempre usa
  `reservation_date`, aunque la cohorte legacy use creación o firma.
- Se reutiliza `metricEventGroups(..., 'reservation_events')`: vehículo + fecha
  de reserva, matrícula normalizada como fallback de vehículo y Opportunity
  como fallback final. No cambian `reservas_vivas`,
  `reservas_vivas_actuales_salesforce`, oportunidades, caídas ni CV firmados.
- Resumen Dirección presenta seis KPI y distingue **Reservas totales del
  período**, **Reservas vivas del universo seleccionado** y **Reservas vivas
  actuales (todas las fechas)**. Comparativa básica añade la nueva métrica para
  período actual/comparado y diferencia absoluta, sin porcentaje, y mantiene
  las demás filas ancladas a la cohorte seleccionada.
- La auditoría JSON y CSV acepta `metric=reservas_totales`, selecciona por
  `reservation_date`, devuelve fecha métrica sin fallback y reconstruye el KPI
  mediante los metadatos de grupo y `counted_in_kpi`. No se añadió PII.
- Archivos modificados: servicio legacy del dataset, JS y Blade específicos de
  Reservas / Ventas, nueva regresión feature, documentación funcional/general
  y este handoff. Rendimiento comercial no cambia.

### Base de datos, seguridad, rendimiento y operación

- No hay migraciones, dependencias, configuración, SOQL, sincronizaciones ni
  llamadas Salesforce nuevas. El global scope de Opportunities activas se
  conserva.
- Se añaden dos consultas locales por payload no cacheado: una para el período
  actual y otra para el comparado. Ambas se acotan en SQL mediante
  `reservation_date >= start AND reservation_date < end`, aprovechan el índice
  existente, se procesan por lotes y reutilizan filtros de tipo, comercial,
  delegación, zona y scopes de acceso. No hay consultas por fila ni N+1.
- La versión de caché del payload pasa a `v6` para impedir que durante el TTL se
  sirvan respuestas anteriores sin la nueva clave. TTL y estrategia no cambian.
- Correctivo previo a PR: las filas autorizadas de eventos de reserva del
  período alimentan también las opciones de Comercial y Delegación. Así, una
  reserva realizada en el rango sigue siendo filtrable aunque su Opportunity
  quede fuera de la cohorte legacy. Las opciones se recogen después de
  `passesFilters()`, por lo que no amplían los scopes de comercial, delegación o
  zona ni revelan nombres fuera del ámbito autorizado.
- Los grupos de calidad de reserva unen por clave funcional los eventos de la
  cohorte legacy y los eventos que forman `reservas_totales`. Dentro de cada
  grupo, las filas se deduplican por Opportunity: un grupo presente en ambos
  universos genera una sola incidencia, mientras permanecen visibles los
  duplicados exclusivamente legacy y los exclusivos del período. La consulta
  acotada ya existente se reutiliza para KPI, filtros y calidad; siguen siendo
  dos consultas nuevas por payload no cacheado, una por período.
- No hay acciones manuales, variables de entorno ni riesgos de compatibilidad
  conocidos; consumidores de las claves existentes conservan sus contratos.

### Validación

- Regresión nueva tras el correctivo: 8/8 pruebas, 71 aserciones. Focales
  legacy obligatorias del correctivo: 10/10 pruebas, 99 aserciones. Suite
  completa final: 1.009/1.009 pruebas, 7.639 aserciones. El primer intento de
  suite tuvo un único timeout ajeno en el benchmark de Stock (23,19 s frente a
  20 s); el benchmark pasó aislado (2/2, 13 aserciones) y la repetición completa
  quedó verde sin relajar umbrales. Pint sobre ambos PHP modificados,
  `node --check`, Composer
  validate estricto, Composer audit, npm audit y build Vite correctos; los
  audits no informaron vulnerabilidades.
- El build mantiene los avisos no bloqueantes ya conocidos de
  `/images/login-bg.jpg` resuelto en runtime, deprecación `module.register()` y
  tiempos del plugin Tailwind. Solo sustituye el bundle JS de Reservas / Ventas
  y su entrada del manifest; el `app.css` rehashado sin cambio fuente fue
  restaurado. `git diff --check` correcto.

## Revisión final para managers de Rendimiento comercial (2026-09-22)

### Resumen, decisiones y archivos

- Rama `fix/commercial-performance-final-review`, base
  `2e305f44f2f6c6263ae274d2a897ac0ac7080b75`. Se separan tres capacidades:
  Admin ve, audita y modifica el objetivo; Director ve y audita con objetivo de
  solo lectura; Area Manager ve únicamente el agregado de su zona y el objetivo
  de solo lectura, sin auditoría. Delegation Manager, Viewer y el resto de roles
  no reciben acceso nuevo.
- El scope del Area Manager se deriva exclusivamente de
  `ReportUserAccess::areaZoneLabel()` en servidor. Se aplica antes de opciones,
  filtros, ranking, resumen y evolución; un parámetro HTTP de otra zona devuelve
  cero filas ajenas y nunca amplía el ámbito. Sin zona configurada se responde
  403. La base cacheada continúa siendo global e inmutable y el scope se aplica
  al presentar, sin crear variantes de caché ni consultas adicionales.
- Corrección de aislamiento posterior: `data_quality` ya no reutiliza para Area
  Manager los agregados corporativos cacheados. `observed_assignments` y
  `bootstrap_approved_assignments` se recalculan desde las filas ya limitadas a
  la zona; los demás contadores de negocio y
  `cancellation_unresolved_dependencies`, que no conservan una atribución zonal
  fiable, mantienen la clave con `null`. La UI no los convierte en avisos de
  cero. Cada mes de `cancellation_coverage_by_month` conserva su estructura,
  estado y fechas, pero también publica `unresolved_dependencies: null` para
  Area Manager. Estado, fuente y fechas técnicas de cobertura permanecen
  globales.
- El objetivo se presenta como **Objetivo mensual por comercial**: es un único
  valor individual para todos los comerciales evaluables del mes, independiente
  de Zona, Delegación o Comercial. Solo Admin obtiene campo editable y Guardar;
  el `PUT` se autoriza de nuevo en backend con permiso específico.
- Cobertura `covered` se presenta como histórico del período certificado, no
  como garantía sobre cambios futuros. Se explica que el sync incremental puede
  reclasificar retrospectivamente reservas/ventas. Caída sigue siendo la
  clasificación actual imputada al mes original y cancelación sigue siendo la
  transición histórica imputada a `transitioned_at`; no se fusionan métricas.
- Se distinguen **Datos de Salesforce sincronizados** (máximo `updated_at` local)
  e **Informe generado con la fotografía local** (`dataset_generated_at`). Las
  comparativas muestran ratio del comercial, referencia de delegación y flecha
  con texto por encima/por debajo/igual; `N/D` conserva null sin flecha. Las
  claves JSON `team_*`/`*_vs_team` y localStorage no cambian.
- `Comerciales Partner Community` conserva evaluación, objetivo y ranking con
  actividad y snapshot histórico válido, incluso inactivo. No se cambiaron
  fórmulas, ranking, semáforo, universo, payload, Salesforce, SOQL, scheduler ni
  sincronizaciones.
- Archivos fuente: `ReportUserAccess.php`, los requests de objetivo/auditoría,
  ambos controladores de Reservas/Ventas, `CommercialPerformanceDatasetService.php`,
  Blade/JS/CSS de Reservas/Ventas, la prueba feature y
  `docs/informe-reservas-ventas.md`; este handoff queda actualizado. Assets:
  salen `reservations-sales-dashboard-3boLO164.css` y
  `reservations-sales-dashboard-Bcj2yumC.js`; entran
  `reservations-sales-dashboard-CuCMlABp.css` y
  `reservations-sales-dashboard-DB44sp4k.js`; el manifest cambia solo esas dos
  entradas.

### Base de datos, seguridad, rendimiento y operación

- No hay migraciones, configuración, variables de entorno ni dependencias
  nuevas. No se ejecutaron sync, backfill, reproceso, escritura Salesforce ni
  cambio de datos. CSRF y escaping existentes se conservan; no se expone PII ni
  `raw_payload`.
- No se añaden fetch, consultas por fila, N+1 ni llamadas externas HTTP. La
  autorización real no depende del flag JavaScript y auditoría conserva su
  protección server-side.
- La regresión integrada mantiene la reserva de agosto descubierta por
  `LastModifiedDate` en septiembre, una única Opportunity, fecha original
  intacta, agosto total 1/válida 0/caída 1/cumplimiento 0 y septiembre una
  cancelación histórica. Las regresiones de Partner Community y cobertura
  `covered`/`partial`/`uncovered` permanecen verdes.
- Validación real completada satisfactoriamente en producción, en modo de solo
  lectura y sin registrar PII. Un caso real confirmó una única fila local, la
  fecha original de reserva intacta, la reclasificación retroactiva del mes de
  reserva como caída y su exclusión del cumplimiento. La transición histórica
  continúa imputada al mes de `transitioned_at`; la cobertura observada fue
  coherente con los intervalos almacenados. Esta evidencia valida la mecánica,
  no certifica por sí sola todo el histórico. Cualquier hueco futuro debe seguir
  el runbook y nunca marcarse `covered` sin intervalos certificados.

### Validación

- Rendimiento comercial: 77/77 pruebas, 991 aserciones. Opportunity sync: 21/21,
  151 aserciones. OpportunityHistory sync: 5/5, 39 aserciones.
- Suite completa: 1.001/1.001 pruebas, 7.568 aserciones. Pint sobre todos los PHP
  modificados, `node --check`, `composer validate --no-check-publish --strict` y
  `git diff --check`: correctos.
- `composer audit --locked --no-dev`: sin advisories. `npm audit --omit=dev`:
  0 vulnerabilidades. `npm run build`: correcto; conserva los avisos
  informativos del entorno sobre `module.register()` y `/images/login-bg.jpg`.
  La corrección de aislamiento no cambia fuentes ni assets frontend.
- No hay acciones manuales de base de datos o configuración. Mensaje de commit:
  `fix(reservations-sales): finalize performance manager review`.

## Finalización de Rendimiento comercial de Reservas/Ventas (2026-09-21)

### Resumen y archivos

- Rama `fix/commercial-performance-final`, base
  `b0596cfd7a82cdb8cbb86c865b7de12f1b4c46b3`. Se corrigió el KPI superior:
  sin Comercial muestra `universe.global_fulfillment_pct`; con Comercial muestra
  `summary.fulfillment_pct` bajo la etiqueta `Cumplimiento comercial`, incluido
  `N/D` para una fila no evaluable. El universo global, ranking y referencias de
  equipo continúan calculándose antes del filtro Comercial.
- La nueva regresión de ranking reprodujo un defecto concreto en la ordenación
  final: los puestos densos se asignaban correctamente, pero el `sortBy()` con
  callback podía intercalar un puesto 2 entre dos puestos 1. Un comparador
  explícito ordena ahora por ranking ascendente y después por el mismo nombre
  comercial ascendente; se conserva `100 %, 100 %, 80 % → 1, 1, 2` y filtrar a
  una persona no recalcula puesto ni medias.
- La UI usa `reservationsSalesCommercialPerformanceColumnsV4` y muestra Ranking
  por defecto sin migrar ni borrar V3. Añade búsqueda client-side O(n) por nombre
  o Salesforce User ID, estado vacío propio y sin `fetch`; Semáforo y Comercial
  quedan sticky solo dentro de `.performance-table`, con fondos opacos y niveles
  de apilado compatibles con el header sticky.
- `Zona Mediterraneo` permanece intacta como valor técnico y se presenta como
  `Zona Mediterráneo` en opción y tabla. Los estados técnicos de cobertura se
  traducen mediante un único formatter con fallback no afirmativo. Se muestra
  `dataset_generated_at` como generación de la fotografía local y se conserva el
  corte separado de OpportunityHistory.
- Una regresión integrada parte de una reserva válida de agosto, sincroniza en
  septiembre la misma Opportunity por `LastModifiedDate` como
  `Cerrada Perdida`, incrementa solo la versión compartida del dashboard y
  verifica: una fila local sin duplicado, `reservation_date` intacta, agosto con
  total 1/válida 0/caída 1/cumplimiento 0 y cancelaciones 0, y septiembre con una
  cancelación por `transitioned_at`. También congela que el comando no incremente
  adicionalmente `commercial_performance_cache_version`.
- Archivos fuente modificados:
  `CommercialPerformanceDatasetService.php`,
  `reservations-sales-dashboard.js`, `reservations-sales-dashboard.css`,
  `reservations-sales/index.blade.php`,
  `ReservationsSalesCommercialPerformanceTest.php` y
  `docs/informe-reservas-ventas.md`. Este handoff queda actualizado. Assets:
  entran `reservations-sales-dashboard-B1QzFSn3.js` y
  `reservations-sales-dashboard-BC2I2FYg.css`; salen
  `reservations-sales-dashboard-DxqPdbxo.js` y
  `reservations-sales-dashboard-BjVL1ck3.css`; el manifest solo cambia esas dos
  entradas.

### Base de datos, seguridad y rendimiento

- No hay migraciones, variables de entorno, dependencias, endpoints, permisos,
  cambios de Salesforce, SOQL, universos, atribución, objetivos ni umbrales. No
  se ejecutaron sincronizaciones reales, backfills, reprocesos ni escrituras
  Salesforce. `Comerciales Partner Community` conserva la regla histórica y su
  regresión permanece verde.
- Nombre, ID y resto de valores visibles se escapan antes de insertarse en HTML.
  La búsqueda opera solo sobre datos ya autorizados y renderizados. No se expone
  `raw_payload` ni nueva PII, y no se modifica CSRF/autorización.
- No se añaden consultas, N+1, llamadas HTTP al escribir, cachés ni
  invalidaciones. Se conserva `buildBase()` cacheado diez minutos y la versión
  compartida de Reservas/Ventas. El filtrado local recorre linealmente las filas
  existentes.

### Validación y operación

- `php artisan test --filter=ReservationsSalesCommercialPerformanceTest`:
  74/74, 866 aserciones.
- `php artisan test --filter=SalesforceOpportunitySyncServiceTest`: 21/21,
  151 aserciones. `SalesforceOpportunityHistorySyncServiceTest`: 5/5,
  39 aserciones.
- `php artisan test`: 998/998, 7.443 aserciones.
- `php vendor/bin/pint --test` sobre los PHP/Blade modificados, `node --check`,
  `composer validate --no-check-publish --strict`, `npm run build` y
  `git diff --check`: correctos.
- `composer audit --locked --no-dev`: sin advisories. `npm audit --omit=dev`:
  0 vulnerabilidades. Vite conserva sus avisos informativos preexistentes sobre
  `module.register()` y la resolución runtime de `/images/login-bg.jpg`; el build
  termina correctamente.
- No hay acciones manuales de base de datos o configuración. Riesgo residual:
  validar visualmente el sticky en los navegadores corporativos soportados tras
  el despliegue. Mensaje de commit: `fix(reservations-sales): finalize commercial performance`.

## Planificador de traslado dirigido de Stock (2026-09-21)

- Rama inicial `feature/stock-transfer-simulator`, HEAD inicial
  `7cdd2db178cb8a153b6aa04100db0dcfbc9b5c1a`, exactamente igual a
  `origin/main` tras `git fetch origin --prune` (0 detrás, 0 delante). Es el
  merge del PR #46 del rol `stock_only`.
- Se añade en Stock > Recomendaciones un planificador GET/read-only con origen,
  destino y unidades. El controlador valida IDs locales, delegaciones
  comerciales, exclusiones existentes, origen distinto de destino y 1–150
  unidades. No hay endpoint nuevo, sesión, persistencia, migración, orden,
  reserva, cambio de vehículo/capacidad ni escritura Salesforce.
- `StockRecommendationService::evaluateDestination()` reutiliza la única
  implementación privada de perfil/scoring. La prioridad existente también se
  centraliza en `reviewLevel()` y sigue usando edad, concentración del modelo y
  mejora clara sobre el perfil actual. No cambian pesos, horizonte de 120 días,
  normalización, penalización sin histórico ni configuración productiva.
- `StockDirectedTransferPlanner` es un componente puro, sin queries. Recibe la
  fotografía y el contexto ya cargados, filtra Disponible + `is_in_stock` +
  origen + catálogo operativo, evalúa cada candidato solo contra el destino y
  ordena por score, prioridad, días e identificador estable. La petición
  dirigida omite el cálculo y render del plan general candidato×delegaciones;
  el flujo normal conserva su plan conjunto y capacidad virtual.
- El destino dirigido mantiene las exclusiones funcionales existentes, pero no
  exige capacidad positiva. La capacidad es informativa: stock actual,
  capacidad/plazas, solicitados/propuestos/faltantes, stock y ocupación
  previstos y exceso actual/proyectado. Capacidad nula/cero, destino lleno o
  sobreocupado nunca eliminan candidatos.
- Seguridad: se conserva `report.access:stock`; `stock_only` puede simular y no
  gana Capacidades, administración ni aprobación de alias. No se aceptan
  nombres libres, `raw_payload`, mass assignment ni inputs no validados. Los
  filtros generales del informe no reducen el universo dirigido.
- Rendimiento: contexto y estadísticas se preparan una vez, relaciones se
  eager-load, no hay N+1 ni consultas por vehículo y el scoring dirigido es
  candidatos del origen × un destino (más el perfil actual necesario para la
  prioridad existente). No se añadieron cachés globales ni llamadas externas.
- Archivos productivos/documentales: controlador y dataset de Stock,
  `StockRecommendationService`, nuevo `StockDirectedTransferPlanner`,
  `config/stock.php`, índice/partial nuevo/partial de Recomendaciones, CSS de
  Stock, `docs/informe-stock.md`, este handoff y artefactos compilados propios
  de Stock (`stock-dashboard-B6I6fbqZ.css`, retirada de
  `stock-dashboard-CG-hvHJu.css` y hunk de Stock del manifest). Pruebas:
  `StockDirectedTransferPlannerTest`, `StockRecommendationServiceTest`,
  `StockRecommendationCandidatePaginationTest` y `StockDashboardTest`.
- Validación focal final: planificador 6/6 (37 aserciones), motor 2/2 (13),
  paginación/benchmark 2/2 (13) y Stock feature 14/14 (154). Suite completa:
  994/994 tests y 7.367 aserciones. `npm run build` y `git diff --check`,
  correctos. Pint pasa sobre los seis PHP sin deuda previa; el barrido de todos
  los PHP modificados conserva exclusivamente los mismos fixers preexistentes
  de `StockDashboardDatasetService` y `StockRecommendationService` ya
  registrados antes de esta rama, por lo que no se reformatearon masivamente.
- `composer audit` informa los cuatro advisories altos preexistentes de
  `league/commonmark` (DoS/XSS, corregidos según advisory en 2.9.1/2.10.0). La
  actualización de dependencias queda fuera de alcance y debe abordarse por
  separado con validación de compatibilidad.
- Correctivo semántico posterior: el plan dirigido muestra los niveles como
  `Prioritario`, `Revisión` y `Normal`, igual que el plan general, sin atribuir
  el nivel `priority` exclusivamente a 90 días. La prueba feature cubre un
  vehículo joven elevado por concentración de modelo. Es un cambio solo de
  Blade/prueba/documentación, sin alterar scoring, ranking, umbrales ni assets.
  Validación: `StockDirectedTransferPlannerTest` 6/6 (37 aserciones),
  `StockDashboardTest` 14/14 (156), Pint del test y `git diff --check`, todos
  correctos.
- Correctivo de CI posterior: Pint detectó deuda de estilo preexistente en
  `StockDashboardDatasetService` y `StockRecommendationService`, pero ambos
  archivos forman parte del PR y el workflow exige que queden completamente
  conformes. Pint normalizó únicamente formato; no cambió consultas, scoring,
  prioridad, capacidad ni comportamiento. Pasan Pint sobre los dos servicios y
  sobre los 11 PHP/Blade modificados respecto a `origin/main`, además de
  `StockRecommendationServiceTest` 2/2 (13 aserciones),
  `StockDirectedTransferPlannerTest` 6/6 (37),
  `StockRecommendationCandidatePaginationTest` 2/2 (13) y
  `StockDashboardTest` 14/14 (156).
- Cambios locales ajenos preservados y no incluidos: sustitución global
  `app-DzLCIK8P.css` → `app-CN9O6aR-.css` y su hunk en manifest, ya presentes
  antes de la tarea. Sin acciones manuales de base de datos/configuración. No se
  hizo push, PR, merge, despliegue ni operación Salesforce.

## Rol funcional de acceso exclusivo a Stock (2026-09-21)

- Rama inicial `feature/stock-only-access`, HEAD inicial
  `487eb6b85316db155b1eaf44672772d4f722c951`, coincidente con `origin/main`
  tras `git fetch` (0 commits delante y 0 detrás). El árbol ya contenía cambios
  ajenos en `public/build/manifest.json` y dos artefactos CSS; se preservaron y
  no forman parte de esta tarea.
- Se añade `ReportUser::ROLE_STOCK_ONLY` (`stock_only`) con el label
  `Stock exclusivamente`. El rol queda disponible en alta y edición mediante
  las opciones centralizadas existentes. No se añade a `ROLE_WEIGHTS` ni se
  convierte en un nivel de la jerarquía Viewer/Director/Admin.
- `ReportUserAccess` trata el rol como funcional aislado: solo autoriza `stock`.
  `accessibleReportKeys()` deja la navegación limitada a Stock y
  `defaultAccessibleRouteName()`/`defaultOperationalRouteName()` dirigen login
  y `/informes` a `/informes/stock`. El middleware existente mantiene
  redirección para HTML y 403 para endpoints JSON/data/export no autorizados.
- `reportMinimumRoleOptions()` excluye `stock_only`, igual que otros roles
  funcionales aislados. La validación del controlador rechaza también un valor
  manipulado. No cambian los mínimos existentes ni la configuración almacenada.
- Stock conserva sus reglas: lectura y pestañas operativas disponibles;
  Capacidades sigue siendo exclusiva de Admin tanto en UI como en POST/PUT; la
  aprobación de alias conserva su permiso independiente. Usuarios, permisos,
  coeficientes, penalizaciones y alertas siguen protegidos en servidor.
- Archivos modificados por la tarea: `app/Models/ReportUser.php`,
  `app/Support/ReportUserAccess.php`,
  `tests/Feature/ReportUserManagementTest.php`,
  `tests/Feature/ReportAccessManagementTest.php`,
  `tests/Feature/StrategicReportNavigationTest.php`,
  `tests/Feature/StockDashboardTest.php` y este handoff.
- Base de datos/configuración: sin migraciones, cambios de entorno, usuarios
  reales ni datos productivos. `report_users.role` ya es `VARCHAR(32)`. Al
  crear o convertir un usuario a `stock_only`, zona, delegación y Salesforce
  User ID quedan a `null` mediante el flujo existente.
- Seguridad y rendimiento: autorización exclusivamente server-side y sin reglas
  por email; no se añaden consultas, cargas de datasets, N+1, llamadas externas
  ni accesos Salesforce. Admin, Director, Viewer y los demás roles funcionales
  conservan la matriz previa.
- Validación: `ReportUserManagementTest` 6/6 (46 aserciones),
  `ReportAccessManagementTest` 9/9 (58), `StrategicReportNavigationTest` 7/7
  (119), `StockDashboardTest` 12/12 (118) y suite completa 985/985 (7.288),
  todas correctas. Pint focal sobre los seis PHP modificados y
  `git diff --check`, correctos. El Pint global falla por infracciones
  preexistentes en archivos ajenos al diff. `composer audit` informa cuatro
  avisos de severidad alta ya presentes en `league/commonmark` (DoS/XSS;
  corregidos en 2.9.1/2.10.0); no se actualizaron dependencias fuera de alcance.
  No se ejecutó `npm run build` porque no cambió ninguna fuente frontend.
- Acciones manuales: ninguna aparte del despliegue normal del código cuando sea
  aprobado. Riesgo pendiente: planificar por separado la actualización segura
  de `league/commonmark` y validar compatibilidad. No se hizo merge, push, PR,
  despliegue, creación de usuario ni escritura Salesforce.

## UX-1B - Autonomía CSS de Reservas / Ventas (2026-09-21)

### Resumen y alcance

- Reservas / Ventas deja de cargar
  `resources/css/reports/leads-dashboard.css`. La vista queda sustentada por el
  Design System, el Application Shell y
  `resources/css/reports/reservations-sales-dashboard.css`.
- No se modificó `leads-dashboard.css`, ningún controlador, servicio, modelo,
  ruta, middleware, permiso, payload, endpoint, fórmula, filtro funcional,
  caché ni integración Salesforce.
- Archivos fuente modificados:
  `resources/views/reports/reservations-sales/index.blade.php`,
  `resources/js/reports/reservations-sales-dashboard.js`,
  `resources/css/reports/reservations-sales-dashboard.css` y
  `tests/Feature/ReservationsSalesCommercialPerformanceTest.php`. También se
  actualizaron los dos bundles versionados de Reservas/Ventas,
  `public/build/manifest.json` y este handoff.

### Inventario legacy previo y decisión

| Selector / grupo | Clasificación | Decisión UX-1B |
|---|---|---|
| `.wrap` | Utility compartida del Application Shell | Se conserva: `app-shell.css` la consume para el layout principal; no depende de la regla homónima de Leads. |
| `.card` | Puramente visual | Retirada; `report-ui-card` o `report-ui-data-panel` cubren cada uso. |
| `.filters`, `.compact-filters` | Puramente visual / layout | Retiradas; se consumen `report-ui-filter-bar` y una variante local semántica para la búsqueda de comerciales. |
| `.filter-group` | Puramente visual | Retirada; `report-ui-field` cubre estructura y espaciado. |
| `.filter-actions` | Puramente visual | Retirada; sustituida por `report-ui-filter-bar__actions`. |
| `.filter-reset` | Puramente visual | Retirada; los botones usan únicamente las primitives `report-ui-button`. |
| `.tabs-main` | Puramente visual | Retirada; `report-ui-tabs` cubre el patrón. |
| `.main-tab` | Visual y hook JavaScript | Retirada; la presentación usa `report-ui-tab` y el hook es `data-report-tab`. |
| `.tab-panel` | Comportamiento estructural y hook JavaScript | Retirada; `data-report-panel` controla el conjunto y el CSS local explícita ocultación/activación. |
| `.kpis`, `.dashboard-kpis` | Puramente visual | Retiradas; `report-ui-kpi-strip` cubre el contenedor. |
| `.kpi`, `.kpi-copy`, `.kpi-label`, `.kpi-value`, `.kpi-hint` | Puramente visual, incluidas plantillas JS | Retiradas; el HTML dinámico usa directamente las subclases de `report-ui-kpi-strip`. |
| `.panel`, `.panel-title` | Puramente visual / estructura | Retiradas; `report-ui-data-panel` y sus subclases cubren el patrón. |
| `.table-wrap` | Comportamiento estructural de scroll | Retirada; `report-ui-data-panel__scroll` conserva overflow, altura máxima y foco, con límites locales para las tablas de Rendimiento. |
| `.num` | Utility visual | Retirada; sustituida en Blade y HTML dinámico por `report-ui-table__numeric`. |
| `.notice` | Puramente visual | Retirada; loading/empty consumen `report-ui-card` y `report-ui-empty-state`, manteniendo `reservations-message` para espaciado propio. |
| `.columns-menu` | Estructura y hook de click fuera | Renombrada a `.reservations-columns-menu`; el comportamiento usa `data-columns-menu`. |
| `.columns-popover` | Estructura específica | Renombrada a `.reservations-columns-popover` y definida íntegramente en el CSS del informe. |
| `.is-hidden` | Estado funcional compartido dentro del módulo | Se conserva y se define explícitamente bajo `.reservations-sales-report`, sin `!important`; se declara al final para vencer de forma determinista displays de variantes locales. |
| `.active`, `.is-active` | Estado funcional / visual de tabs | Se conservan como exige el contrato aprobado; no se usan para localizar nodos. |
| `.legacy-filter-control`, `.performance-filter-control` | Hooks JavaScript puros | Retirados; sustituidos por `data-filter-scope="standard"` y `data-filter-scope="performance"`. |
| `.period-strip`, `.period-card`, `.universe-definition-card` | Presentación específica de Reservas/Ventas | Se conservan, pero todas sus reglas necesarias están ahora en el CSS local; ya no heredan de Leads. |
| calidad, métricas, sorting y opciones de columnas | Presentación específica antes heredada parcialmente | Renombradas con prefijo `reservations-` y definidas localmente; sorting elimina su indicador mediante `data-sort-indicator`. |

No se encontró ninguna dependencia legacy que impida retirar completamente el
CSS de Leads. Las únicas coincidencias nominales restantes con selectores del
archivo legacy son estados con consumidor real, las tres clases específicas de
periodos y `.wrap`; todas tienen ahora un proveedor explícito en el Design
System, Application Shell o CSS propio del informe.

### Hooks y comportamiento preservado

- Hooks migrados: `.main-tab` → `data-report-tab`, `data-panel` →
  `data-report-panel-target`, `.tab-panel` → `data-report-panel`, controles de
  modo → `data-filter-scope`, `data-mode` → `data-filter-mode`,
  `.columns-menu` → `data-columns-menu` y `.sort-indicator` →
  `data-sort-indicator`.
- Las tabs siguen alternando `active` e `is-active`, conservan
  `aria-controls` y no introducen roles de tablist, `aria-current` ni un widget
  ARIA incompleto.
- Se conservaron los 60 IDs del HTML. El diff JavaScript no toca `fetchJson`,
  URLs, `URLSearchParams` ni `setParam`; no cambia el número ni contrato de las
  requests.
- Se mantienen scroll horizontal, altura máxima, cabeceras sticky mediante el
  modificador oficial, scroll superior sincronizado, sorting, selector de
  columnas, loading, empty, error, reintento y auditoría.

### CSS específico que permanece

- Layout raíz y panel activo del informe; mensajes, periodos y definición de
  universo; calidad de datos; acciones de auditoría KPI; altura/overflow de
  tablas; filtro compacto de comerciales; popovers de columnas; presentación
  de valor/porcentaje y sorting; orden del modo Rendimiento; objetivo;
  mensajes informativos/calidad/error; anchos de tablas, scroll superior,
  semáforos y ajustes móviles.
- Se eliminaron los overrides transitorios de UX-1A que neutralizaban inputs,
  selects, botones, tabs, KPI, paneles y tablas de Leads. No se añadió
  `!important`, selector global ni primitive duplicada.
- Correctivo final: se retiró el `max-width: 1600px` inefectivo de la raíz del
  informe. No se sustituyó por otra anchura; `.app-shell .wrap` continúa
  determinando `max-width: none`, por lo que la anchura efectiva no cambia.

### Seguridad, rendimiento y datos

- Sin cambios en autenticación, autorización, CSRF, sesiones, cookies,
  validación backend, permisos o exportaciones. La rama de permiso para
  Rendimiento comercial permanece intacta.
- El HTML dinámico existente mantiene `escapeHtml()`; no se añadió `innerHTML`
  nuevo con datos sin escapar, dependencia, listener duplicado,
  `MutationObserver`, polling, request, endpoint, consulta o llamada Salesforce.
- La hoja específica compilada baja respecto a UX-1A y el informe deja de
  solicitar el CSS legacy; no aumenta materialmente el coste frontend.
- Sin migraciones, variables de entorno ni cambios de base de datos.

### Validación ejecutada

- Precondiciones Git: rama
  `feature/ux-reservations-sales-remove-legacy`, worktree inicialmente limpio y
  UX-1A local `c3b6c3d` como `HEAD`; no se trabajó desde `main`.
- Baseline previo: el primer intento de la prueba focal no arrancó porque este
  worktree carecía de `vendor/autoload.php`. Se regeneró únicamente el autoload
  sobre el `vendor` ya presente con `composer dump-autoload --no-scripts`, sin
  descargar ni instalar dependencias. El baseline aprobado de UX-1A documentado
  en la sección anterior era 71 pruebas / 808 aserciones.
- `php artisan test tests/Feature/ReservationsSalesCommercialPerformanceTest.php`:
  71/71 pruebas y 814 aserciones, correcto.
- `php artisan test`: 982/982 pruebas y 7.237 aserciones, correcto; 281.051 ms.
- `vendor/bin/pint --test tests/Feature/ReservationsSalesCommercialPerformanceTest.php`:
  correcto mediante el mismo binario invocado con PHP de Herd.
- `composer validate --no-check-publish --strict`: correcto.
- `composer audit --locked --no-dev`: correcto, sin advisories.
- `node --check resources/js/reports/reservations-sales-dashboard.js`: correcto.
- `npm run build`: correcto con Vite 8.0.12, 15 módulos transformados y build
  final en 3,82 s. Se usó temporalmente un junction a las dependencias ya instaladas
  del checkout principal y se retiró después; no se instalaron paquetes.
- El build conserva los avisos ambientales conocidos de deprecación
  `module.register()` e imagen `/images/login-bg.jpg` resuelta en runtime.
- Bundles finales del módulo:
  `reservations-sales-dashboard-BjVL1ck3.css` y
  `reservations-sales-dashboard-DxqPdbxo.js`. Se retiraron sus dos versiones
  anteriores. El churn global `app-DzLCIK8P.css` → `app-CN9O6aR-.css` se
  descartó de forma específica y su manifest volvió a `HEAD`.
- `git diff --check`: correcto. Comprobaciones estáticas: ningún
  `!important`, selector global nuevo, clase legacy genérica en Blade/JS o
  cambio de IDs/requests.

### Acciones manuales y riesgos pendientes

- No hay migraciones, configuración ni limpieza de caché obligatoria. En el
  despliegue normal deben publicarse el manifest y los dos bundles nuevos.
- Riesgo residual exclusivamente visual: falta una pasada manual en navegador
  con datos representativos para usuario con/sin Rendimiento, cuatro tabs,
  custom period, filtros vacíos/con valores, loading/empty/error/reintento,
  objetivo disabled/habilitado/guardado, columnas, sorting, auditoría, mes
  provisional/cerrado, textos largos, portátil y móvil. Tests, contrato DOM,
  CSS compilado y scrolls están validados, pero no sustituyen esa inspección.
- `PROJECT_CONTEXT.md` y `DECISIONS.md` no cambian: no hay arquitectura,
  módulos, convenciones globales ni decisiones irreversibles nuevas.

## UX-1A - Migración visual de Reservas / Ventas al Design System (2026-09-18)

### Resumen y archivos

- Se migró presentacionalmente Reservas / Ventas al lenguaje visual compartido
  del Application Shell y SEO/Analytics. La vista adopta page header, tabs,
  filter bars, fields, controles, botones, KPI strips, data panels, section
  headers, tablas y empty/loading states del Design System existente.
- Archivos modificados: `resources/views/reports/reservations-sales/index.blade.php`,
  `resources/css/reports/reservations-sales-dashboard.css`,
  `resources/js/reports/reservations-sales-dashboard.js`,
  `tests/Feature/ReservationsSalesCommercialPerformanceTest.php`, los dos
  bundles de Reservas/Ventas y `public/build/manifest.json`, además de este
  handoff.
- `resources/css/reports/leads-dashboard.css` continúa en el `@vite` del informe.
  No se modificaron controladores, servicios, rutas, modelos, middleware,
  permisos, contratos API, fórmulas ni sincronizadores.

### Decisiones y contratos conservados

- Las primitives adoptadas son `report-ui-page-header`, `report-ui-tabs`,
  `report-ui-tab`, `report-ui-filter-bar`, `report-ui-field`, `report-ui-label`,
  `report-ui-input`, `report-ui-select`, `report-ui-button`,
  `report-ui-kpi-strip`, `report-ui-data-panel`, `report-ui-table`,
  `report-ui-table__numeric`, `report-ui-card` y `report-ui-badge`; los títulos
  de panel usan `<x-reports.ui.section-header>` y la cabecera usa
  `<x-reports.ui.page-header>`.
- Se conservaron sin renombrar todos los IDs. La comparación automatizada con
  `487eb6b` obtuvo cero IDs añadidos o retirados. También se conservan los hooks
  funcionales `.main-tab`, `.tab-panel`, `.legacy-filter-control`,
  `.performance-filter-control` e `.is-hidden`, con el mismo número de
  apariciones que en la base. La pestaña activa sincroniza ahora las clases
  legacy `active` y DS `is-active`, sin usar `aria-current` ni introducir roles
  o navegación de teclado nuevos. `aria-controls` conserva la asociación
  descriptiva con cada panel, sin cambiar qué carga cada pestaña.
- Los avisos de Rendimiento comercial conservan sus clases y significado. No se
  reutiliza `<x-reports.ui.status>` como decoración porque sus semáforos y notas
  no corresponden de forma directa a los cinco estados analíticos oficiales.
  Las notas informativa, de calidad y de error mantienen paletas locales y no
  consumen tokens `--report-ui-status-*`. Loading usa `report-ui-card`, mientras
  que `report-ui-empty-state` queda reservado al estado realmente vacío.
- Todas las cabeceras de las tablas que adoptan `report-ui-table`, incluida la
  tabla de auditoría construida dinámicamente, declaran `scope="col"` sin
  cambiar columnas, orden, textos, IDs o `data-column`.
- Se añadió una regresión focal pequeña para el contrato de primitives y hooks;
  no se introdujo un snapshot HTML.
- `PROJECT_CONTEXT.md` y `DECISIONS.md` no cambian: no hay arquitectura, módulo,
  convención transversal ni decisión irreversible nueva.

### Seguridad, datos y rendimiento

- No cambia autenticación, autorización, CSRF, sesiones, cookies, endpoints ni
  el control de acceso de Rendimiento comercial. La rama de Blade que oculta la
  pestaña y controles a usuarios sin permiso permanece intacta.
- El HTML dinámico existente conserva `escapeHtml()` y no se añadieron nuevas
  interpolaciones de datos sin escape. No se añadieron secretos, PII, llamadas
  HTTP, consultas, listeners globales, dependencias, CDN ni scripts externos.
- El diff JavaScript no modifica `fetchJson`, `URLSearchParams`, `setParam` ni
  los listeners de filtros. Solo añade clases de presentación/ARIA al cambio de
  pestaña y primitives al markup ya renderizado. No cambia caché, número de
  peticiones ni renderizados de datos.
- Las tablas anchas de Rendimiento, evolución y auditoría conservan sus anchos
  mínimos, alturas máximas, scroll horizontal y sincronización de scroll
  superior. Los contenedores de scroll reciben foco y nombre accesible.

### Validación ejecutada

- Verificación base previa: árbol limpio, rama
  `feature/ux-reservations-sales-design-system`, `HEAD` en `487eb6b`.
- `php artisan test tests/Feature/ReservationsSalesCommercialPerformanceTest.php`:
  71/71 pruebas y 808 aserciones, correcto.
- `php artisan test`: 982/982 pruebas y 7.231 aserciones, correcto; duración
  reportada de 532.308 ms.
- `php vendor/bin/pint --test tests/Feature/ReservationsSalesCommercialPerformanceTest.php`:
  correcto. Se usó la invocación explícita de PHP porque el wrapper directo no
  encontraba `php` en su `PATH`; el binario y modo de Pint son los mismos.
- `composer validate --no-check-publish --strict`: correcto.
- `composer audit --locked --no-dev`: correcto, sin advisories.
- `npm run build`: correcto con Vite 8.0.12, 15 módulos transformados y build en
  5,17 s. Conserva los avisos ambientales de deprecación `module.register()` e
  imagen `/images/login-bg.jpg` resuelta en runtime.
- Bundles finales: `reservations-sales-dashboard-D07LQtal.css` y
  `reservations-sales-dashboard-kuug831T.js`. Los bundles anteriores
  `reservations-sales-dashboard-DX5Bsl6G.css` y
  `reservations-sales-dashboard-4W-i5lqI.js` se retiraron porque el manifest ya
  no los referencia.
- El churn ajeno `app-DzLCIK8P.css` → `app-CN9O6aR-.css` se descartó de forma
  específica: el asset global y su entrada de manifest coinciden con `HEAD`.
  No cambió ningún asset de otro módulo.
- `node --check resources/js/reports/reservations-sales-dashboard.js`: correcto.
- Comprobación estática CSS: 65 llaves de apertura/cierre, ningún `!important`,
  token inexistente o selector global prohibido; correcto.
- Comparación de IDs/hooks y revisión de URLs/parámetros de `fetch`: correcto.
- Comprobación de cabeceras: ningún `<th>` de tablas DS sin `scope="col"`.
- `git diff --check`: correcto tras el build y saneamiento de assets.

### Acciones manuales y riesgos pendientes

- Antes de integrar, completar la validación visual en navegador con usuario
  sin/con permiso, cuatro pestañas,
  mes cerrado/actual provisional, loading/error/reintento/empty, columnas,
  filtros, objetivo disabled, viewport estrecho, scroll horizontal y consola
  limpia. Las pruebas y el build están completos, pero esta revisión final no
  sustituye esa inspección visual con datos representativos.
- Riesgo visual residual: `leads-dashboard.css` conserva selectores legacy
  genéricos. UX-1A los neutraliza de forma acotada bajo
  `.reservations-sales-report`, pero la validación visual debe confirmar la
  cascada real tras compilar assets.
- Propuesta UX-1B basada en el inventario real: sustituir primero los hooks DOM
  de `.main-tab`, `.tab-panel`, `.legacy-filter-control` y
  `.performance-filter-control` por atributos `data-*` explícitos; después
  retirar de este informe las clases visuales legacy `.wrap`, `.filters`,
  `.card`, `.filter-group`, `.filter-actions`, `.filter-reset`, `.tabs-main`,
  `.kpis`, `.dashboard-kpis`, `.kpi`, `.kpi-copy`, `.kpi-label`, `.kpi-value`,
  `.kpi-hint`, `.panel`, `.panel-title`, `.table-wrap`, `.num`, `.notice` y
  `.compact-filters`. Mantener `.is-hidden` hasta acordar una utility compartida
  y conservar las clases específicas `performance-*`, `data-quality-*` y de
  scroll/columnas mientras sigan aportando comportamiento o layout propio.
  Solo tras esa caracterización y validación se debe retirar
  `leads-dashboard.css` del `@vite` de Reservas/Ventas.

## Tarea 4 - Último mes cerrado y resultado provisional (2026-09-14)

- Rendimiento comercial calcula una sola vez en Blade el mes actual y el último
  mes natural cerrado de `Europe/Madrid`. El segundo es valor inicial y de
  reset; el primero se entrega como metadata descriptiva al JavaScript, sin
  consultar el reloj del navegador ni añadir endpoints.
- El mes actual sigue seleccionable. Tras una respuesta correcta para ese mes,
  la UI muestra `Mes en curso · Resultado provisional` y explica que actividad
  acumulada, objetivo mensual completo, cumplimiento y semáforo no se
  prorratean. El aviso se limpia durante loading/error y no aparece para meses
  cerrados. No cambian cache V4, filtros, auditoría, funnel, universo, calidad,
  ranking, equipo, objetivos ni umbrales.
- Pruebas temporales congelan septiembre de 2026, el primer día de octubre y el
  cambio de año de enero de 2027; además prueban 9/18 = 50 % y semáforo rojo en
  mes actual sin prorrateo. Validación final real: suite focal 72/72 pruebas,
  798 aserciones; Pint del test, `node --check`, `npm run build` y `composer
  audit --locked --no-dev` correctos/sin advisories. El churn de `app.css` del
  build se descartó específicamente tras comprobar que su fuente no cambió.

## Tarea 3 - Resiliencia de filtros y auditoría de Rendimiento comercial (2026-09-14)

- La base de `CommercialPerformanceDatasetService` pasa a
  `reservas-ventas-commercial-performance-base-v4`. Solo persiste arrays y
  escalares; `rowsByMonth` ya no contiene Collections y presentación las crea
  después del cache hit. Se conserva `cache.serializable_classes=false`, TTL de
  10 minutos, lock y versionadores. Las entradas V3 no se borran: expiran de
  forma natural.
- `data_quality` se acumula por mes: atribución no resuelta, conflictos
  duplicados y de margen, histórico no certificable, cronología inválida de
  cancelación, cambios organizativos y conteos observed/bootstrap. El payload
  publica solo el mes seleccionado; `data_incident` permanece separado y
  reconciliable.
- Auditoría acepta Zona, Delegación y Comercial, filtra la atribución resuelta
  en ese orden antes de sort/paginación, y conserva incidencias sin comercial
  cuando no hay filtro organizativo. La UI envía los tres filtros. Durante la
  carga borra la presentación anterior; ante fallo muestra un mensaje seguro y
  permite reintentar con los filtros actuales. El texto de auditoría ya no
  afirma erróneamente que los nombres de responsables no sean PII.
- Validación real: `ReservationsSalesCommercialPerformanceTest` y
  `CommercialPerformanceMonthlyRosterServiceTest`, 68/68 pruebas, 766
  aserciones; Pint focal de dataset, auditoría, request de auditoría y test,
  correcto; `node --check resources/js/reports/reservations-sales-dashboard.js`,
  correcto; `npm run build`, correcto; `composer audit --locked --no-dev`, sin
  advisories. Vite volvió a generar el hash global de `app.css` a causa del
  entorno local; se restauró exclusivamente `app-DzLCIK8P.css` y solo se
  conserva el bundle nuevo de Rendimiento comercial. `git diff --check`,
  correcto.
- Las cifras productivas de Incidencia de datos (por ejemplo Leads/Opportunities
  no atribuibles) no se verificaron ni se reasignaron desde tests locales.
- Correctivo posterior de integridad: `performanceTarget` y Guardar arrancan y
  permanecen deshabilitados durante carga/error; el valor se vacía y solo se
  habilitan tras una respuesta válida con objetivo entero positivo. El guard de
  guardado bloquea además controles deshabilitados o valores inválidos, evitando
  que un objetivo visualmente antiguo se persista para otro mes. Validación:
  69/69 pruebas focales, 774 aserciones; Pint del test, `node --check` y build
  Vite correctos. El churn global de `app.css` del build se descartó de forma
  específica.

## Universo evaluable de Rendimiento comercial (2026-09-11)

- Tarea 2: la evaluabilidad final se calcula tras agregar contadores. Requiere
  identidad, actividad real (Lead, Opportunity, reserva total, venta válida o
  caída) y snapshot mensual `observed`/`bootstrap_approved`. Cancelación aislada,
  margen y roster no activan objetivo ni ranking.
- Los comerciales certificados sin actividad quedan fuera de tabla, filtro,
  objetivo, ranking, equipo y cumplimiento global; las filas con actividad e
  histórico no certificable siguen visibles como `No evaluable`. Incidencia de
  datos se expone separada de la tabla y conserva sus métricas reconciliables.
- Equipo y ranking se calculan en memoria después de Zona/Delegación y antes de
  Comercial. El equipo se agrupa por delegación y contiene solo evaluables. El
  bloque `universe` incluye tamaño, exclusiones, objetivo global y cumplimiento
  global; Comercial no lo recalcula.
- Auditoría: `monthly_evaluable`, estado y objetivo se derivan una vez del
  comercial mensual, no de `counted_in_metric` de la fila. Por tanto, un evento
  deduplicado puede conservar `counted_in_metric=false` y seguir siendo
  evaluable; `data_incident` publica objetivo y cumplimiento como `null`.
- Se versionó la base cacheada a
  `reservas-ventas-commercial-performance-base-v3`; TTL, lock y versionadores
  O(1) se mantienen. No hay migraciones, dependencias, llamadas Salesforce ni
  cambios de rutas/controladores.
- Archivos modificados: dataset, roster, auditoría, JS/Blade de Reservas/Ventas,
  tests focales y documentación de Reservas/Ventas, decisiones, contexto y este
  handoff. Validación final real: suite focal (`ReservationsSalesCommercialPerformanceTest`
  y `CommercialPerformanceMonthlyRosterServiceTest`) 65/65 pruebas, 554
  aserciones y 0 fallos; Pint focal sobre los cinco PHP modificado, correcto;
  `git diff --check`, correcto y staging vacío antes del checkpoint. `node --check`
  y el build Vite se ejecutaron correctamente durante el cierre. El audit de
  Composer previo (`composer audit --locked --no-dev`) no informó advisories y
  `composer.json`/`composer.lock` siguen sin cambios. El hash global de
  `resources/css/app.css` generado por las vistas compiladas locales se descartó
  específicamente: se restauró `app-DzLCIK8P.css` y el manifest conserva esa
  referencia; solo cambia el bundle de Reservas/Ventas.

## Semántica de funnel en Rendimiento comercial (2026-09-10)

- Se distinguen Reservas totales, Reservas vivas, Ventas válidas, Reservas
  caídas y Ventas caídas. La caída depende del snapshot actual de Opportunity,
  es excluyente (Venta caída prevalece) y conserva deduplicación por
  vehículo+fecha y la exclusión lifecycle de Opportunities eliminadas.
- El cumplimiento y semáforo usan `reservations_valid_for_objective`: reservas
  con fecha en el mes cuya Opportunity no está actualmente perdida. Las ventas
  válidas siguen imputándose a su fecha de firma; por ello no se suman para
  reconstruir cumplimiento.
- Venta caída usa `reservation_date` como ancla y, si no existe, `cv_signed_date`.
  La auditoría expone la clasificación, contribución a cumplimiento y el caso
  `reservation_not_demonstrated`, sin consultar Salesforce ni crear tablas.
- Validado finalmente: `ReservationsSalesCommercialPerformanceTest`, 57 passed
  y 509 assertions; regresiones de rendimiento, presencia/reconciliación
  lifecycle y CampaignCommands, 98 passed y 739 assertions. Pint focal sobre
  `CommercialPerformanceDatasetService` y
  `CommercialPerformanceAuditService`, correcto. `composer audit --locked
  --no-dev` correcto y sin advisories. El build Vite final fue correcto. La suite completa sigue pendiente
  de CI por el límite PHP local de 120 s dentro de Laravel/Composer antes de
  emitir resultados; no se atribuye a una regresión funcional de esta rama.
- Política final de duplicados: si un grupo lógico tiene clasificación de funnel
  contradictoria se atribuye a Incidencia de datos; solo conserva la métrica
  demostrable y no aplica precedencia arbitraria. Para firmas, el conflicto se
  identifica por vehículo + `cv_signed_date`, mientras `sales_dropped` conserva
  su imputación por `reservation_date` cuando existe. Las métricas derivadas,
  como `sales_signed_reference`, no vuelven a incrementar incidencias ni conflictos.
  Un conflicto firmado válido/perdido invalida también la reserva demostrada
  para cumplimiento, incluso si `cv_signed_date` cae fuera del mes consultado.
  La auditoría propaga esa misma identidad de firma a las filas emitidas por
  reserva o por Venta caída, para no contar una caída cross-month que el dataset
  ya excluyó por conflicto.
- La clave de firma solo propaga el conflicto: no sustituye la deduplicación por
  `event_type` y fecha. Las Opportunities conservan su propia contabilización,
  las Reservas mantienen su representante demostrable y las categorías de venta
  conflictivas quedan excluidas.

## Rendimiento de Rendimiento comercial (2026-09-08)

- La base pesada de `CommercialPerformanceDatasetService` se cachea por mes y
  por tres versiones O(1): Leads, Reservas/Ventas y Rendimiento comercial. Los
  filtros Zona, Delegación y Comercial se aplican después en memoria; Comercial
  conserva su condición de filtro de display y no altera el ranking/equipo.
- En producción el store de caché por defecto es `file` (`FileStore`),
  compatible con `Cache::lock` sin Redis ni dependencias nuevas. El miss
  verifica caché antes y después de un lock de 30 segundos, con espera máxima
  de 10 segundos; solo entonces construye y hace `Cache::put`. `block()` libera
  el lock ante retorno o excepción. FileStore coordina procesos que comparten
  filesystem; si se escala a contenedores con almacenamiento no compartido,
  caché y lock deberán migrar conjuntamente a un backend distribuido.
- Se eliminó el versionado por `count`/`max`/`sum` de tablas. La actualización
  del objetivo incrementa la versión específica, mientras que los syncs y la
  reconciliación de Opportunities ya incrementan los versionadores reutilizados.
- Leads materializados se agregan en SQL por mes/responsable efectivo; los
  registros legacy con `record_type_normalized` nulo mantienen el fallback PHP
  anterior para no cambiar el universo. No se añadieron índices ni llamadas
  Salesforce desde HTTP.
- Producción, ventana junio--septiembre: 56.207 Leads por
  `fecha_asignacion`; 41.848 son elegibles materializados. Hay 11 filas con
  `record_type_normalized` nulo, todas Tasación, por lo que no pertenecen al
  universo del informe. El `EXPLAIN` del agregado SQL usa
  `type=range`, `key=sf_leads_asign_idx`, 30.720 filas estimadas,
  `filtered` aproximado del 26 % y
  `Extra=Using index condition; Using where; Using temporary`. No se añade un
  índice nuevo: la evidencia no lo justifica.
- Tiempos medidos del agregado SQL de Leads: junio 444,57 ms; julio 346,59 ms;
  agosto 235,42 ms; septiembre 88,91 ms; total aproximado 1.115,49 ms. No se
  implementa warming en esta PR; tras el despliegue se medirán el cold path y
  los cache hits del endpoint completo antes de decidirlo.
- Corrección posterior de calidad: el agregado SQL de Leads pasa el tamaño del
  grupo a `attribution`, para que `unresolved_attribution_events` y
  `uncertified_historical_events` sigan contando Leads/eventos y no grupos.
  Opportunities, cancelaciones y el fallback legacy conservan el peso por
  defecto de un evento.
- Validación final: `ReservationsSalesCommercialPerformanceTest` 40 passed,
  367 assertions; `CampaignCommandsTest` 19 passed, 92 assertions; y
  reconciliación de presencia de Opportunities 11 passed, 51 assertions. La
  suite completa obtuvo 944 passed; un benchmark de Stock falló una vez por
  22,56 s frente al límite de 20 s, pero pasó aislado después en 18,70 s. Se
  documenta como variabilidad ambiental conocida, no como regresión de esta
  rama.
- Pint limitado sobre
  `CommercialPerformanceDatasetService.php` y
  `ReservationsSalesCommercialPerformanceTest.php`, correcto. `composer audit
  --locked --no-dev` no detectó advisories y `git diff --check` fue correcto.

## Comparativa de equipo en Rendimiento comercial (2026-09-07)

- Tarea: se sustituyeron los cinco campos de comparación `delegation_*` sin
  consumidor real por nueve campos `team_*`/`*_vs_team_pp`. La referencia usa
  una sola población: `ranking_eligible=true` después de Zona/Delegación y
  antes de Comercial. Los ratios se agregan por contadores y las diferencias se
  calculan sin usar ratios redondeados; las filas no evaluables devuelven `null`.
- Archivos modificados: `CommercialPerformanceDatasetService`, el test feature
  de Rendimiento comercial, Blade, JavaScript y esta documentación.
- UI: se añadieron cinco columnas opcionales, no visibles por defecto, al
  selector persistente `reservationsSalesCommercialPerformanceColumnsV1`:
  Media equipo, Desviación reservas y los tres ratios frente al equipo. Usan un
  decimal español, `pp` y `N/D`; no alteran el semáforo.
- Correctivo posterior: el signo de las diferencias se decide después de
  redondear a una decimal, por lo que valores entre `-0,05` y `0,05` se
  presentan como `0,0`, sin signo. El helper de fixtures usa bucles `for`
  seguros cuando Leads u Opportunities valen cero.
- Base de datos/configuración: sin migraciones, backfill, endpoint, permisos,
  consultas Salesforce ni variables de entorno. El objetivo por defecto sigue
  siendo 18 y permanece editable.
- Seguridad/rendimiento: conserva la autorización Administrador/Dirección y no
  expone datos nuevos; los agregados se calculan una vez en memoria sobre el
  mismo Collection del ranking, sin N+1.
- Pruebas: se añadieron comprobaciones Ana/Bea para la agregación global,
  Zona, Delegación, intersección Zona+Delegación y Comercial, además del
  contrato de no evaluación y de `DEFAULT_RESERVATIONS_TARGET === 18`. El
  análisis sintáctico JavaScript con Node fue correcto. 
- Validación final:
  - suite completa: 926 tests, 6.663 aserciones, correcta;
  - composer audit --locked --no-dev: sin vulnerabilidades;
  - Pint limitado sobre PHP modificado: correcto;
  - build Vite: correcto;
  - git diff --check: correcto;
  - CI del PR: correcto.
- No quedan validaciones de runtime pendientes para esta implementación.

## Segunda iteración de Rendimiento comercial (2026-08-27)

- Bootstrap: `CommercialDelegationSnapshotService` materializa, de forma
  idempotente, un intervalo cerrado desde `2026-04-01 00:00 Europe/Madrid` hasta
  la primera observación fiable. Usa `business_bootstrap_2026_04`, no modifica
  observaciones reales y excluye IDs sin dimensiones o con historia previa
  contradictoria; este último caso abre `commercial_bootstrap_conflict` para
  conservar la incidencia. La réplica local inspeccionada tenía 0 snapshots, por lo que
  no existían usuarios locales bootstrapables ni IDs que clasificar sin ejecutar
  el sync Salesforce (prohibido en esta tarea). `captureCurrentUsers()` ya no
  invoca esta operación: el comando solo la ejecuta tras la captura observada
  cuando recibe `--bootstrap-performance-history`. El scheduler cada 15 minutos
  conserva `--days=2` sin la opción, por lo que altas posteriores no se
  retroatribuyen a abril. La reejecución explícita también está protegida: la
  cohorte inicial exige que el primer snapshot observado del usuario coincida
  exactamente con el mínimo `observed_from` global. Los usuarios posteriores
  se devuelven en `not_initial_cohort`; una observación inicial incompleta no se
  rellena usando otra posterior.
- Organización: bootstrap y observación son asignaciones evaluables y quedan
  diferenciados en `delegation_status`; los huecos/cambios son
  `not_certifiable`. Un cambio observado cierra/abre intervalos y genera una
  alerta `commercial_organisation_change` de severidad `low`, deduplicada por
  ID técnico, instante y dimensiones. Un cambio dentro del mes conserva la fila
  individual, excluye ranking y muestra una nota ámbar.
- Auditoría/calidad: cada evento conserva `delegation_certified` por
  compatibilidad y añade `delegation_status` (`observed`,
  `bootstrap_approved`, `not_certifiable`) y `delegation_issue`. La UI muestra
  Observada, Bootstrap aprobado o No certificable. El payload separa las fechas
  `delegation_history_evaluable_from`, `delegation_history_observed_from` y
  `delegation_history_bootstrap_from`; el nombre legacy se conserva sin usarlo
  para presentar el bootstrap como certificación Salesforce.
- Filtros: Comercial parte de todas las identidades comerciales válidas del mes,
  incluso sin delegación evaluable. Zona/Delegación solo parten de observación o
  bootstrap. No cambian los controles compartidos ni los datasets legacy.
- Cancelaciones: la inspección local contó 382 transiciones (291 `valid`, 80
  `opportunity_not_local`, 7 `reservation_not_demonstrated`, 3
  `reservation_after_transition` y 1 `previous_stage_not_demonstrated`). Había
  cuatro intervalos no certificados con 81 dependencias y uno certificado. La
  UI muestra `N/D` para null y reserva 0 a períodos cubiertos.
- Resolución por ID: History solicita solo Opportunities ausentes en chunks de
  100 mediante `SalesforceOpportunitySyncService::syncBySalesforceIds()`, que
  comparte SELECT, retry del email opcional, resolución de Lead y persistencia
  canónica. Recarga y reclasifica en el mismo run; los IDs no devueltos siguen
  como dependencia auditable.
- UI: se retiraron todas las medias de delegación; 11 columnas son visibles por
  defecto y Semáforo/Comercial son obligatorias. Las preferencias opcionales
  usan `reservationsSalesCommercialPerformanceColumnsV1`. Margen incluye ayuda,
  Evolución localiza el mes al español, los avisos separan información/calidad/
  error, la auditoría crea su tabla solo tras carga manual y se invalida al
  cambiar filtros. Las tres tablas anchas sincronizan scroll superior e inferior.
- Base de datos/configuración: sin migraciones, backfills, Salesforce ni cambios
  de entorno. No se alteró ningún snapshot local.
- Seguridad/rendimiento: no hay PII de cliente en alertas, IDs SOQL escapados,
  lotes acotados, ninguna red desde HTTP y ninguna petición nueva al cambiar
  columnas. Se mantienen autorización, CSRF y contratos existentes.
- Validación final de cohorte: snapshots/roster/comando 10/10 (74 aserciones);
  Rendimiento/CampaignCommands/Opportunity/History 61/61 (470); consumidores
  transversales de Leads, Comisiones, Area Manager y Llamadas 66/66 (474);
  suite completa 700/700 (5.267). Pint correcto y limitado a los cuatro PHP
  modificados en este correctivo. `composer audit --locked --no-dev` sin
  advisories. No se repitió Vite porque no cambió frontend: el último build
  aprobado permanece vigente y el manifest referencia únicamente los bundles
  `reservations-sales-dashboard-DX5Bsl6G.css` y
  `reservations-sales-dashboard-BXx1OBp9.js`. Los dos anteriores del módulo se
  eliminaron y los bundles de módulos ajenos generados por Vite se restauraron.
  La validación local en navegador confirmó la carga lazy de 8.011 eventos, el
  texto visible `No certificable · Cobertura incompleta` y cero errores de consola;
  las etiquetas Observada y Bootstrap aprobado están cubiertas por pruebas con
  snapshots controlados, sin modificar la BD local.
- Despliegue: revisar/aprobar todas las migraciones pendientes; ejecutar una vez
  `salesforce:sync-monthly-commercial --days=2 --bootstrap-performance-history`,
  conciliar observados/creados/omitidos/conflictos y dejar después el scheduler
  sin opción. Ejecutar rangos mensuales contiguos desde abril con `--to` exclusivo, medir filas,
  duración y llamadas, y validar por mes transiciones/calidad/cobertura antes de
  continuar. La recuperación por ID evita ampliar fechas a ciegas.
- Riesgos: la retención de OpportunityHistory y primeras filas sin etapa previa
  pueden impedir certificar meses; un usuario solo puede bootstrapearse después
  de existir una primera observación normalizada y no contradictoria.

## Aislamiento CI de salesforce:sync-opportunities (2026-08-27)

- Causa: `CampaignCommandsTest` mockeaba usuarios y Opportunities, pero no el
  nuevo `SalesforceOpportunityHistorySyncService`; el container resolvía el
  servicio real y el comando terminaba en FAILURE al intentar Salesforce.
- Corrección exclusivamente de test: History espera una llamada con
  `2026-01-01` / `2026-02-01`, devuelve el contrato neutro completo y se valida
  su salida. Opportunity espera además el tercer argumento `modified=false`.
- Seguridad/producción: sin credenciales, conexiones externas, migraciones,
  backfills ni cambios en comandos, servicios, workflows o lógica funcional.
- Validación: caso focal 1/1 (9 aserciones), `CampaignCommandsTest` 18/18 (81),
  Rendimiento 29/29 (246), HistorySync 4/4 (32), OpportunitySync 4/4 (48) y
  suite CI completa 687/687 (5.154). Pint se limitó al test modificado.

## Comisión de Dirección Comercial en Area Manager (2026-08-26)

- Tarea: se restaura la comisión de Oscar Ortega como el 40 % de la suma de los
  `final_total` de todos los Area Managers globales, redondeada a dos decimales.
- Identidad: Salesforce User ID funcional confirmado `0057R00000B2SGg`; el nombre
  `Oscar Ortega` es solo presentación. El usuario no estaba presente en la réplica
  local durante la comprobación de solo lectura, por lo que no se inventó otra
  identidad ni se bloqueó el cálculo.
- Diseño: `AreaManagerCommissionDashboardService` publica `commercial_director`
  solo en el dataset global. Front, XLSX y snapshot definitivo consumen ese dato;
  los builds por zona lo dejan en nulo y nunca calculan un Oscar parcial.
- Base de datos/configuración: sin migraciones, esquema, variables de entorno,
  rutas, permisos ni configuración administrativa nueva. No se modifican
  snapshots definitivos existentes.
- Seguridad/rendimiento: identidad por ID estable, sin exposición en vistas por
  zona, consultas adicionales, llamadas externas ni segundo build.
- Pruebas: cálculo 1.000 + 2.000 + 3.000 + 4.000 = 10.000 EUR y comisión de 4.000
  EUR; identidad, front global, restricción por zona, XLSX, snapshot y reapertura.
  Focalizadas: `area_manager` 19/19 (111 aserciones),
  `CommercialCommissionDashboardTest` 50/50 (398 aserciones) y
  `CommissionMonthClosureTest` 9/9 (72 aserciones). Suite completa: 687/687
  (5.152 aserciones). Pint y compilación Blade correctos.
- Acciones manuales: ninguna. No requiere migración, backfill ni sincronización.

## Rendimiento comercial en Reservas / Ventas (2026-08-25)

- Correctivo MySQL/UI 26/08: la migración evita los nombres automáticos de 72 y
  69 caracteres mediante `commercial_perf_target_updated_user_fk` y
  `sf_opp_stage_history_uq`. En `informes_local` estaba Pending y solo existía
  `commercial_performance_monthly_targets` parcial, sin índices/FK y con una fila
  local; se eliminó exclusivamente esa tabla y `php artisan migrate` terminó en
  batch 3. `SHOW CREATE TABLE` acredita FK `SET NULL`, UNIQUE e índices finales.
- Filtros: se retiró el bloque interno de Rendimiento. `zone`,
  `commercialDelegation` y `commercial` son controles físicos compartidos; el
  modo activo muestra controles legacy o mes/objetivo y despacha una sola carga.
  Cambios de universo limpian opciones inválidas y realizan una única recarga
  correctiva. Limpiar Rendimiento no altera el objetivo mensual.

- Revisión de integridad 26/08: el mes actual usa el último cutoff diario
  capturado al comenzar el sync, no `now()`; consultas e intervalos se recortan
  a ese instante y el dashboard nunca lo hace avanzar. El payload y la UI
  muestran corte fuente y continuidad certificada. Meses cerrados siguen
  exigiendo el mes completo y cualquier hueco anterior al cutoff deja N/A.
- Dependencias históricas: `reservation_date` NULL se preserva. Una candidata
  cuya Opportunity no está local queda persistida como `opportunity_not_local`;
  su intervalo guarda `unresolved_dependencies`, queda
  `is_kpi_certified=false` y nunca puede producir cancelaciones cero. El
  backfill debe abarcar también la cohorte de reservas anterior y repetir los
  intervalos pendientes tras resolver dependencias.
- Una candidata Cerrada Perdida sin etapa previa se persiste como
  `previous_stage_not_demonstrated` y bloquea el KPI. Si la etapa previa ya era
  Cerrada Perdida se clasifica como permanencia, no como transición ni bloqueo.
  La deuda actual sale de transiciones aún pendientes: intervalos antiguos no
  mantienen deuda después de una reejecución certificada que resuelve la calidad.
- Elegibilidad: el sync de usuarios refresca por ID solo usuarios locales
  relevantes/snapshots abiertos que hayan salido del filtro comercial. Mantiene
  `IsActive` real y cierra el snapshot por inactividad o pérdida de perfil, sin
  cambiar reglas de Leads, Comisiones, Llamadas ni Area Manager.
- El roster histórico carga la identidad desde `salesforce_users` cuando un
  snapshot solapa el período, aunque el perfil actual sea Marketing u otro no
  comercial. El usuario conserva actividad certificada pasada y no reaparece
  como miembro cero después del cierre del snapshot.
- Cumplimiento agregado: resumen y evolución suman los objetivos individuales
  de las filas comerciales incluidas por filtros. `Incidencia de datos` conserva
  eventos operativos cuando corresponde, pero objetivo, cumplimiento, semáforo
  y ranking son NULL. Owners API/Administrador/Marketing/Area Manager sin
  pertenencia demostrable usan esa incidencia y quedan auditados como
  `non_commercial_responsible` en todos los tipos de hito.
- Benchmark Stock comparado en condiciones equivalentes contra el worktree
  detached `5359646`: caso exacto aislado 6,311 s en working tree y 6,348 s en
  baseline, ambos por debajo de 20 s. Los fallos anteriores de 25–40 s se
  atribuyen a variabilidad ambiental, sin cambios en Stock ni en su umbral.
- Encoding real: no aparecen los tokens sospechosos indicados por revisión ni
  mojibake añadido en el diff de
  `app/`, `resources/`, `routes/`, `docs/` o `tests/`. Secuencias antiguas en
  documentación/tests ajenos no se modificaron. Los próximos patches deben
  exportarse preservando UTF-8.
- Auditoría y UI: todas las ventanas usan `>= start` y `< end`; el límite exacto
  del mes siguiente queda excluido. La UI explicita disponibilidad y cutoff de
  cancelaciones, y el asset versionado/manifest corresponde al JS actual.

- Tarea: pestaña funcional mensual solo para Administrador/Dirección, con
  Leads, Opportunities, reservas totales/activas, ventas, cancelaciones,
  objetivos, semáforo, medias de delegación, margen, ranking y cuatro meses de
  evolución. Las pestañas legacy conservan su cohorte y UI.
- Investigación Salesforce read-only: `OpportunityFieldHistory` devolvió cero;
  `OpportunityHistory` devolvió 500 filas recientes y diez cancelaciones
  reservadas en la muestra. Cinco secuencias completas acreditaron transiciones
  reales, incluida una reapertura con dos cancelaciones. La delegación de
  Opportunity es fórmula de `Owner.USR_SEL_Delegacion__c`; `UserFieldHistory` no
  está disponible y no hubo tracking útil de delegación.
- Integridad sénior: una fila por Salesforce User ID/mes. Huecos o cambios de
  delegación no duplican objetivo; conservan actividad individual y deshabilitan
  media/ranking. El roster certificado incluye ceros y participa en equipo.
- Cancelación solo desde transición persistida, con
  `reservation_date <= transitioned_at`; cronologías inválidas quedan auditadas.
  Cobertura continua se acredita en intervalos de sync: uncovered/partial es N/A.
- Persistencia: migración aditiva `2026_08_25_120000` crea objetivos congelados,
  snapshots con único abierto, transiciones de Stage e intervalos de cobertura; añade
  `salesforce_last_modified_at` indexado a Opportunities.
- Seguridad: dataset, auditoría GET y objetivo PUT autorizan servidor a
  Admin/Director; PUT valida CSRF, mes e integer >0. La auditoría paginada
  excluye PII de cliente y no se consulta Salesforce en HTTP.
- Rendimiento: ingesta incremental diaria por `LastModifiedDate`, agregación en
  batch de cuatro meses, sin query por comercial/mes, medias calculadas una vez
  por delegación y caché versionada por todas las fuentes/configuración.
- Scheduler: 07:10 Europe/Madrid, fuera de Campañas/Stock/SEO, comando
  `salesforce:sync-opportunities --days=2 --modified`.
- Verificación final del correctivo MySQL/UI 26/08: específicas de Rendimiento y
  filtros legacy 48/434 y transversales 59/428, correctas. La suite ejecutó 685
  pruebas y 5.123 aserciones: 684 pasaron y falló solo el benchmark Stock por
  latencia ambiental (63,02 s global; 29,51 s y 21,34 s aislado), sin modificar
  Stock ni su umbral; el comparativo controlado previo sigue siendo 6,311 s
  actual y 6,348 s base. Pint se limitó a la migración y al test PHP modificados.
  `composer audit --locked --no-dev` quedó limpio y el build Vite temporal pasó;
  solo se publicaron CSS/JS de Reservas/Ventas y sus entradas del manifest. Se
  ejecutó únicamente la migración local autorizada; no hubo Salesforce ni
  backfills.
- Despliegue: revisar `migrate:status`, enumerar/aprobar todas las pendientes y
  no ejecutar `migrate --force` antes. Backfill corto por `--from/--to`, con
  inicio suficientemente anterior para incluir las reservas de cancelaciones
  posteriores; medir filas/duración/llamadas, revisar dependencias no resueltas,
  repetir intervalos afectados y ampliar por lotes. No ejecutar `--fresh`.
- Riesgo: solo pueden certificarse delegaciones desde el primer snapshot y
  cancelaciones dentro de la retención disponible de OpportunityHistory.

## Presentacion financiera de Irene y Nuria (2026-08-24)

- Tarea: ajuste exclusivamente visual del cuadro `Irene y Nuria` en la pestaña
  Financieros. `Comision final` pasa a la segunda columna y se recuperan como
  contexto importe total, importe financiado, porcentaje financiado,
  rentabilidad, garantia y porcentaje de garantias.
- Decision: se reutilizan directamente las metricas existentes de `summary_rows`;
  no se modifican servicios, formulas, conciliacion, sincronizacion ni mappings.
  Los bloques 1, 2 y 3 siguen sin mostrarse ni aplicarse a Irene/Nuria, cuya regla
  exclusiva permanece en comision neta por 0,50%.
- Archivos: Blade financiero, prueba feature de Comisiones y este handoff.
- Base de datos/configuracion: sin migraciones, variables de entorno ni cambios de
  configuracion.
- Seguridad/rendimiento: sin cambios de autorizacion, consultas o volumen del
  payload; solo se presentan campos agregados ya calculados.
- Pruebas: la prueba focalizada valida el contrato y orden de las 13 columnas, los
  valores informativos formateados y que las tres comisiones estandar permanecen
  en cero. Las verificaciones completas de esta tarea se detallan en la entrega.
- Acciones manuales: ninguna. No requiere sincronizacion Salesforce.

## Auditoria y correccion de Financieros (2026-08-24)

- Tarea: se concilio julio de 2026 por `Opportunity.Id` contra Salesforce y se
  corrigieron la frescura de la replica, la resolucion del responsable financiero,
  la regla exclusiva de Irene/Nuria y la presentacion auditable por delegacion.
- Causa raiz: Salesforce rechazaba `Account.AC_C_EMA_email__c`, un campo opcional;
  el error sanitizado impedia activar el fallback y dejaba la replica incompleta.
  Tras reintentar una vez sin ese campo, el sync acotado guardo julio completo.
  Ademas, Irene/Nuria estaban asociadas al OwnerId comercial en lugar de a su
  zona financiera.
- Conciliacion: la SOQL de contraste Salesforce y la replica local contienen los
  mismos 680 IDs, con 0 ausentes, 0 extras y 0 diferencias en
  comision/descuento. Totales: 12.085.921,06 EUR de importe total,
  5.064.691,00 EUR financiado, 718.638,40 EUR de comision financiera,
  27.086,00 EUR de descuento y 243.080,00 EUR de garantia. Los 29 Sin Zona/General
  aportan cero a comision y descuento y quedan fuera de responsables.
- Resultado: Carlos 4.791,84 EUR; Cristina 1.350,77 EUR; Irene Simon 352,68 EUR
  sobre Alicante/Paterna; Nuria Moracho 149,03 EUR sobre Castellon/Sedavi. La
  suma final de responsables y delegaciones coincide en 6.644,32 EUR.
- Revision previa al commit: una zona financiera explicita desconocida queda
  excluida y visible en diagnostico con sus IDs. Si contiene comision o descuento,
  marca `ready=false` y bloquea el XLSX con HTTP 409. General/Sin Zona mantienen
  su exclusion permitida. Los ajustes de centimos se muestran por delegacion y
  se cuantifican en diagnostico.
- Delegacion de detalle: prioridad actual
  `Owner.USR_SEL_Delegacion__c`; `Delegacion_del_propietario__c` solo es fallback.
  No se certifica igualdad con la agrupacion del report porque no se obtuvo su
  metadata ni exportacion interna de IDs.
- Archivos: servicios financiero/configuracion/sync, controlador, Blade financiero,
  config de comisiones, pruebas de dashboard/sync y documentacion financiera,
  general, contexto, decisiones y este handoff.
- Base de datos: sin migraciones ni datos manuales. Config cambia de excepcion por
  Salesforce User ID a reglas por clave estable de responsable. No hay variables
  de entorno nuevas.
- Seguridad: Salesforce se uso solo en lectura; no se registraron payloads,
  credenciales ni IDs en documentos. Diagnosticos siguen limitados a Admin/IT y
  las autorizaciones de Comisiones no se ampliaron.
- Rendimiento: una consulta local mensual, sin N+1 ni Salesforce en render; los
  tres niveles del payload se derivan de la misma coleccion.
- Pruebas finales: focalizadas de Comisiones/sync/permisos 78/537 y suite completa
  640/4.710, correctas. Un primer pase completo tuvo un timeout del benchmark de
  Stock; paso aislado (2/13) y en la repeticion completa. Pint, Blade y
  `git diff --check` finales tambien son correctos.
- Acciones de despliegue: `php artisan optimize:clear` y sync acotado con
  `php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01 -vvv`.
- Riesgo: no se preservo el dataset incompleto anterior de produccion, por lo que
  el delta aproximado de 105.128,40 EUR no puede atribuirse honestamente a una
  lista historica exacta de IDs. La metadata/lista interna del report tampoco
  estuvo accesible; la SOQL produjo 680 IDs y los mismos sumatorios del report,
  pero no se afirma que sus IDs internos fueran exportados.

## Documentación de comisiones financieras (2026-08-24)

- Tarea: se auditó el flujo completo de la pestaña Financieros desde la SOQL de
  `Opportunity`, su materialización en `salesforce_opportunities`, la configuración
  mensual, el servicio de cálculo, el controlador, el front, la exportación XLSX y
  las pruebas. Se creó una guía de presentación/revisión con universo, diccionario
  Salesforce-local, query de contraste, zonas, tramos, fórmulas, ejemplo,
  explicación de cada dato visible y checklist de aprobación.
- Archivos modificados por esta tarea:
  `docs/informe-comisiones-financieras.md`, `docs/informe-comisiones.md`,
  `docs/Calculo_comisiones_comerciales.txt`,
  `docs/Documentacion_general_informes_y_contraste_salesforce.md` y este handoff.
- Decisiones de aquella revisión documental: el bloque 2
  usa comisión financiera válida menos descuento financiero; los intereses vacíos
  o excluidos solo retiran la operación del bloque 2. La resolución anterior por
  Salesforce User ID fue reemplazada por la auditoría descrita arriba. También se
  corrigió la descripción de cierres: Financieros sigue operativo/provisional y no
  dispone de snapshot definitivo propio.
- Base de datos/configuración: sin migraciones, cambios de esquema, `.env`, datos
  ni configuración. Los tramos documentados son defaults; una revisión debe usar
  siempre `commercial_commission_month_settings` del mes seleccionado.
- Seguridad: no se añadieron credenciales, IDs personales ni datos de producción.
  Se documentó que el detalle contiene Opportunity ID/nombre y que el rol Financiero
  ve actualmente todas las zonas; debe validarse ese alcance con mínimo privilegio.
- Rendimiento: sin cambios de ejecución. Quedan documentados el cálculo mensual en
  memoria, el detalle sin paginación y la necesidad de vigilar el plan de la consulta
  por fecha si aumenta el volumen.
- Pruebas: `php artisan test --filter=financieros` correcto (7/41);
  `php artisan test tests/Feature/CommercialCommissionDashboardTest.php` correcto
  (45/294); `php artisan test tests/Feature/CommercialCommissionFormulaSettingsTest.php`
  correcto (10/51); `git diff --check` final correcto.
- Acciones manuales: ninguna para desplegar documentación. Para presentar un mes
  real, sincronizar el rango explícito de Opportunities, confirmar la configuración
  efectiva y conservar fecha/hora del corte y evidencias de conciliación.
- Riesgos/pendientes de aquella revisión: Sin Zona/General no aparecía en el detalle; el detalle no
  expone bases unitarias completas de bloques 1/3; una resincronización puede cambiar
  meses históricos. No se modificaron `PROJECT_CONTEXT.md` ni `DECISIONS.md` porque
  no cambió arquitectura, módulos, convenciones ni decisiones funcionales.

## SEO/Analytics Lote 7 - Correo ejecutivo diario (2026-08-21)

- Se separó el resultado del transporte de la confirmación local. Solo una
  excepción de SMTP permite `sending -> failed`; después de que el sender
  retorna, la transición `sending -> sent` comprueba filas afectadas y, si no
  puede demostrarse `sent`, conserva/restaura `sending`, incrementa
  `confirmation_pending_count` y hace fallar el comando. Así un posible correo
  aceptado no entra de nuevo en el circuito automático de retry.
- Se añadieron settings de 1–10 destinatarios en BD, gestionables únicamente
  por Administrador/Director desde la configuración SEO. La lista se normaliza
  por trim/lowercase y deduplicación; no usa DNS ni contiene secretos SMTP.
- El dataset ejecutivo consume las seis comparativas/evaluaciones actuales, la
  frescura compartida de Search Console/Salesforce/GA4 y un resumen factual de
  Salud técnica. Siempre representa seis filas, respeta fingerprints stale y no
  llama APIs, builders, evaluadores, IA ni SISTRIX.
- Un report diario congelado conserva payload y hash SHA-256. El ledger por
  fecha+recipient hash hace claim atómico antes del envío individual, evita
  duplicados y reintenta solo `failed`; `sending` exige revisión manual.
- `SeoExecutiveDailyReportMail` ofrece HTML y texto plano sin tracking, imágenes
  remotas, JavaScript ni contenido sin escapar. El transporte es síncrono y
  exige mailer distinto de `log`/`array` y remitente distinto del fallback.
- `seo:send-executive-daily-email` registra `ReportSyncRun` sin emails/secretos
  y se agenda a las 08:00 Europe/Madrid con `$monitor` y lock de 30 minutos. No
  crea alertas de negocio por métricas SEO.
- Migración aditiva `2026_08_21_120000`: settings, reports diarios y deliveries.
  No se ha migrado producción ni enviado correo real.
- Validación final: `SeoExecutive` 17 pruebas/183 aserciones,
  `AnalyticalEvaluation` 30/267, `SeoAnalytical` 21/295, SEO 123/1.179,
  patrones 5/46, Design System 5/54 y navegación 6/84, correctos. Suite
  completa: 635/4.682. Pint, Composer audit
  runtime (cero advisories), Vite y `git diff --check` correctos; el build no
  modificó assets versionados.

## SEO/Analytics Lote 6 - Evaluación analítica versionada (2026-08-21)

- Se mantiene `analytical_metric_snapshots` como capa factual separada. Es una
  proyección rolling mutable por identidad/fecha, no append-only. El
  core `AnalyticalEvaluationEngine`, sin DB/SEO/HTTP, aplica una regla resuelta y
  devuelve estado, dirección, banda y reason code cerrados.
- La migración aditiva `2026_08_21_090000` crea rule sets, seis reglas por
  versión y evaluaciones por snapshot/versión. Inicializa `seo_rules_v1` con
  10/20/35 % y materialidad para volúmenes, más 0,5/1/2 pp para CTR y
  0,5/1/2 posiciones. No modifica tablas del Lote 5.
- Baseline bajo no escala por encima de Observación; baseline cero no inventa
  infinito. Una mejora fuerte conserva su banda, pero aparece como Observación
  favorable «Oportunidad / posible anomalía». D-364 no interviene.
- `/informes/seo-analytics/configuracion` es server-side y solo permite
  Administrador/Director. Solo acepta los valores numéricos whitelisteados y un
  motivo; cada save crea vN, audita actor/fecha, bloquea ediciones obsoletas y
  reevalúa únicamente los seis snapshots actuales.
- Un conflicto concurrente redirige sin `withInput`: se descartan thresholds
  stale y la pantalla carga íntegramente la versión activa. Tras una validación
  ordinaria fallida, los hidden conservan con `old()` la identidad y versión de
  origen junto con los campos editables; un retry stale vuelve así a entrar en
  el control transaccional y no puede adoptar silenciosamente la versión nueva.
  Cada evaluación captura current/baseline/cambios/evaluabilidad/motivo y un
  SHA-256 canónico. El dashboard compara el hash con los hechos actuales,
  muestra pendiente si no coincide y las señales históricas usan exclusivamente
  valores capturados.
- El dashboard conserva la tabla factual, añade Estado/Lectura y hasta 50
  señales no `ok` de los últimos 30 días, siempre aisladas por la property
  configurada y escogiendo una sola evaluación reciente por snapshot.
- `seo:evaluate-analytical-snapshots` registra `ReportSyncRun`, no usa red y se
  agenda a las 06:30 Madrid con lock 120. Backfill 1–90 solo mientras v1 siga
  activa; versiones posteriores no reinterpretan automáticamente el histórico.
- No se generan `OperationalAlert` por señales de negocio, email, SISTRIX,
  scoring o recomendaciones. El monitor del scheduler conserva únicamente su
  alerta técnica habitual.
- Producción: migrar, comprobar `seo_rules_v1`/seis reglas, ejecutar
  opcionalmente `--days=30` antes de crear v2 y verificar scheduler/dashboard.
  No se desplegó ni se ejecutó ningún backfill real.
- Validación final: retry `ValidationException`/conflicto 1 prueba/21 aserciones,
  `AnalyticalEvaluation` 30/267, `SeoAnalytical` 21/295, SEO 106/996,
  patrones 5/46, Design System 5/54, navegación estratégica 6/84 y suite
  completa 618/4.499, correctos. Pint `--dirty --test`, Composer audit runtime
  (cero advisories) y `git diff --check` también correctos. El build Vite previo
  del mismo Lote 6 fue correcto y no modificó assets; este cierre no toca
  fuentes frontend compilables.

## API mensual canónica de Comisiones Comerciales (2026-08-20)

- `GET /api/comisiones_comercial` mantiene `salesforce_id` obligatorio y añade
  `month=YYYY-MM` opcional. El mes explícito se valida estrictamente: formato,
  mes real y no futuro; los errores son `422` sin fallback silencioso. Sin mes se
  usa `CommissionMonthResolver`.
- La fuente y la fila se resuelven antes que la elegibilidad actual. Una fila de
  snapshot definitivo se devuelve aunque el usuario haya quedado inactivo,
  cambiado a perfil no comercial o desaparecido de `salesforce_users`. Reopened
  continúa ignorando el snapshot anterior y usa el cálculo vivo.
- Si no existe fila canónica, la elegibilidad fallback exige coincidencia PHP
  exacta —también en mayúsculas/minúsculas—, `is_active=true` y las reglas ya
  usadas por `CommercialCommissionDashboardService`. No hay búsqueda por
  nombre/email, coincidencia parcial ni consulta Salesforce en vivo.
- La respuesta mensual expone metadatos no económicos, `has_data` y la `row`
  exacta de `summary_rows`, incluidos `details`. `200`/`row=null` significa solo
  usuario activo/elegible sin fila; IDs inexistentes, técnicos, inactivos o no
  elegibles reciben un `404` genérico si no existe fila congelada. Nunca se
  devuelven las demás filas del dataset.
- Los definitivos leen `definitiveSnapshot(month, commercials)` antes de
  construir el cálculo vivo. Provisional, pendiente y reabierto ejecutan
  `build(month, true, false, true)` una sola vez: sin Delegaciones ni las otras
  pestañas. No se copiaron ni modificaron fórmulas económicas.
- Un payload vivo con `ready !== true` devuelve `503` genérico, sin `issues`,
  filas ni importes. La compatibilidad sin `month` también falla completa si el
  mes actual o el mes anterior requerido no están disponibles; no fabrica cero.
- Las peticiones sin `month` conservan temporalmente `current_month` y
  `previous_closed_month`; además reciben el contrato mensual canónico. Las
  peticiones con `month` no calculan el mes anterior.
- Archivos modificados: controlador API, servicio de dashboard (consulta pública
  de elegibilidad exacta), test feature de API, documentación funcional,
  operativa, general, README y este handoff. Sin migraciones, dependencias,
  variables de entorno ni cambios en middleware, cierres, snapshots o SEO.
- Seguridad: se conservan `internal.api.audit`, `commissions.api.auth` e
  `internal.api.throttle`, `X-Request-ID` y el logging sin query/body/details ni
  secretos. Las pruebas usan exclusivamente credenciales y datos sintéticos.
- Rendimiento: un mes explícito realiza una sola construcción económica y una
  sola carga de sus `summary_rows`; un definitivo no ejecuta cálculo vivo. La
  compatibilidad sin mes mantiene dos meses por contrato legacy.
- Validación final de esta corrección: sintaxis PHP correcta; API 22 pruebas /
  138 aserciones (`8187 ms`); dashboard 45 / 294 (`12199 ms`); cierres 9 / 72
  (`5689 ms`); suite completa 586 / 4.206 (`261287 ms`), todo correcto.
  `vendor/bin/pint --dirty --test`, `git diff --check` y la ruta con
  `internal.api.audit`, `commissions.api.auth` e `internal.api.throttle`
  también correctos.
- Acciones manuales: ninguna migración ni configuración nueva. Antes de retirar
  los campos legacy debe identificarse y migrarse el consumidor externo.
- Riesgo residual: el consumidor externo no está localizado en el repositorio;
  por ello no se eliminó el contrato anterior. No se desplegó, sincronizó ni
  modificó información real.

## SEO/Analytics Lote 5 - Comparativa diaria (2026-08-19, histórico)

La identidad Salesforce Lead descrita aquí corresponde al contrato previo a
ROT-5. Los snapshots históricos permanecen válidos; los nuevos usan la identidad
Interest documentada al inicio.

- Se añadió el core transversal `SameWeekdayComparisonEngine`, sin queries ni
  conceptos SEO. `same_weekday_v1` usa D-7/D-14/D-21/D-28 exactos, mínimo 3/4,
  D-364 opcional, ausencia distinta de cero y variación relativa nula cuando el
  baseline es cero. No existen thresholds, scoring ni severidad.
- La migración aditiva `2026_08_19_090000` crea
  `analytical_metric_snapshots` con DECIMAL, unique por módulo/métrica/scope/
  hash de fuente/fecha e índices de lectura. No hay pruning aprobado.
- `SeoAnalyticalSnapshotService` carga una serie local por fuente, construye
  exactamente seis KPI y hace upsert transaccional por chunks. Search Console y
  GA4 se aíslan por property; Salesforce usa `salesforce-organic-leads` y scope
  `all`. Cada fuente conserva su cutoff propio.
- `seo:build-analytical-snapshots --days=30` admite 1–90, registra
  `ReportSyncRun`, no hace HTTP y se agenda a las 06:15 Madrid con lock 120. Un
  fallo interno se sanitiza; una fuente ausente no bloquea las disponibles.
- Resumen lee solo el último snapshot compatible mediante un dataset separado.
  La tabla factual es independiente de range 7/28/90, accesible, sin colores ni
  estados de severidad. Salud técnica queda fuera.
- Corrección de revisión: actual y D-364 conservan formato entero para métricas
  de conteo, pero baseline y diferencia muestran dos decimales (`9,50` y
  `+0,50`). Las constantes de `SameWeekdayComparisonEngine` son la única fuente
  del contrato y `config/seo_analytics.php` las referencia. El default del
  builder consume `snapshot_refresh_days=30` y el scheduler invoca ese default
  sin duplicarlo; ingestas admiten 1–480 y builder 1–90.
- Corrección CI: `SeoAnalyticalSnapshotsTest` configura credenciales sintéticas
  y properties locales para que los guards `configured()` no dependan del
  entorno, y bloquea cualquier request HTTP no simulada. Los casos que validan
  ausencia de fuentes limpian explícitamente toda esa configuración. No se
  añadieron secretos ni se modificó el builder productivo o el workflow.
- Seguridad/rendimiento: cero credenciales/PII, cero endpoints de escritura,
  cero requests, una query de serie por fuente, sin N+1 y transacción corta solo
  para persistencia. No se tocaron ingestas Search Console/Salesforce/GA4,
  normalizador científico, Salud técnica, CSS ni JavaScript.
- Operación pendiente: migrar; opcionalmente sincronizar 400 días para D-364;
  construir 30 días; verificar scheduler, run local y conteos. Producción no
  requiere Node y no debe borrar snapshots.
- Validación final: motor 7 pruebas/42 aserciones, SEO analítico 10/82, suite SEO
  95/771 y suite completa 569/4.081, correctos. Pint `--dirty --test`, Composer
  audit runtime (cero advisories), Vite 8.0.12 y `git diff --check`, correctos.
  El build regeneró únicamente el bundle CSS general: retiró el hash anterior,
  creó el nuevo y actualizó `public/build/manifest.json`; no cambió CSS o
  JavaScript fuente en esta corrección.

## Dependencias runtime sin advisories (2026-08-19)

- Actualización focalizada dentro de majors: Laravel 13.8.0 -> 13.12.0,
  CommonMark 2.8.2 -> 2.9.0, cinco componentes Symfony afectados -> 8.0.15 e
  `symfony/polyfill-intl-idn` 1.37.0 -> 1.38.1. No cambió ningún otro paquete
  del lock final.
- Guzzle permanece 7.15.3 y PSR-7 2.13.0. `composer.json` bloquea tanto sus
  rangos vulnerables como Guzzle 8/PSR-7 3 para conservar los majors aprobados.
- GitHub Actions ejecuta `composer audit --locked --no-dev` después de instalar
  dependencias, sin ignores ni tolerancia de fallo. Operación instala el lock y
  repite el audit; nunca ejecuta update en producción.
- Seguridad: `composer audit --locked --no-dev` y el audit completo devuelven
  cero advisories. No se añadieron dependencias, supresiones, secretos ni
  permisos de CI.
- Rendimiento/funcionalidad: no cambian aplicación, consultas, scheduler,
  sincronizadores, frontend, assets ni esquemas. Suite SEO 82/628,
  autenticación 8/43, navegación estratégica 6/84 y suite completa 545/3.836
  correctas (315,45 s). Pint, Vite 8.0.12 y `git diff --check`, correctos.
- Archivos: `composer.json`, `composer.lock`, `.github/workflows/ci.yml` y
  documentación operativa/AI. No hay migraciones ni configuración manual nueva;
  producción deberá desplegar el lock aprobado mediante
  `composer install --no-dev` y exigir audit runtime limpio.

## SEO/Analytics Lote 4 - Salud técnica acotada (hardening 2026-08-19)

- Selección cerrada: Home, URLs estratégicas configuradas y hasta 150 páginas
  del ranking local Search Console ESP/90; default 200 URLs y hard cap 500. No
  existe crawler, navegación de enlaces ni fuente Stock directa.
- El checker obtiene robots/sitemaps y realiza un GET por candidato más redirects
  válidos. Guard SSRF único: HTTP/S y puertos estándar, hosts exactos, DNS
  íntegramente público, IP fijada al transporte y redirects manuales. Bodies,
  cookies, Authorization y headers completos no se persisten.
- Dos migraciones aditivas crean el registro activo/inactivo y un check único por
  URL/día. Sitemap membership diferencia `true`, `false` y `null`; la red se
  completa antes de la transacción corta de upsert.
- `seo:sync-technical-health` publica `ReportSyncRun` con host y contadores no
  sensibles. Scheduler: 06:00 Madrid, lock 120 y monitor técnico. Los findings
  URL no generan alertas operativas ni estados analíticos.
- Salud técnica renderiza solo BD/config, fuera del common cutoff y sin selector
  7/28/90. Muestra métricas descriptivas, infraestructura y hasta 100 URLs.
- Config manual pendiente: `SEO_TECHNICAL_SITE_URL`, hosts adicionales, URLs
  estratégicas y sitemaps explícitos. No se inventaron valores. Pendiente de
  negocio: mapping verificable Stock -> URL pública y política de retención.
- Seguridad/rendimiento: límites de bodies/sitemaps, XML `LIBXML_NONET`, gzip
  acotado, cero dependencias nuevas, cero HTTP desde GET y ninguna transacción
  abierta durante red.
- Hardening final: Guzzle pasa de 7.10.0 a 7.15.3 y `composer.json` impide
  resolver `<7.15.2`. El guard exige hosts DNS ASCII canónicos y que todas las
  A/AAAA sean globales. El checker ignora proxies de entorno (`proxy=''`),
  mantiene TLS y `CURLOPT_RESOLVE`, y añade `read_timeout`.
- El streaming lee iterativamente hasta EOF/límite+1 y cierra en `finally`;
  `body_read_error` queda como finding aislado. Sobre HTML truncado solo se
  conservan señales positivas: noindex y canonical múltiple; las conclusiones
  negativas o de unicidad/coincidencia quedan no evaluables.
- Integridad de sitemap: el flag completo combina descubrimiento concluyente de
  robots y procesamiento íntegro de documentos. Un error/truncamiento de robots
  o una directiva `Sitemap:` bloqueada mantiene el scan parcial; los positivos
  hallados siguen siendo `true` y las ausencias quedan `null`.
- Hardening runtime final: Laravel 13.12.0, CommonMark 2.9.0, Symfony afectado
  8.0.15 e IDN polyfill 1.38.1 eliminan los 17 advisories restantes. Guzzle
  7.15.3 y PSR-7 2.13.0 se conservan; sus majors superiores quedan bloqueados
  por `composer.json`. CI ejecuta `composer audit --locked --no-dev` sin
  supresiones y producción debe instalar el lock, nunca actualizarlo.
- Archivos: configuración/env; comando, dos modelos y servicios
  `SeoTechnical*`; dos migraciones `2026_08_18_090000/090100`; integración
  acotada en dataset, vista y scheduler; cuatro tests SEO técnicos; documentación
  SEO/contexto/decisiones/operación. No se modificaron Stock, Search Console,
  GA4, Salesforce, CSS, JavaScript ni assets.
- Validación local de este cierre: sitemap 12 pruebas/40 aserciones,
  SeoTechnical 35/199, SEO 82/628 y suite completa 545/3.836, correctos. El
  presupuesto temporal ajeno de Stock falló en la primera ejecución, pero pasó
  aisladamente y en la repetición completa sin modificar Stock. Pint
  `--dirty --test`, Vite 8.0.12 y `git diff --check`, correctos. Vite no cambió
  `public/build`.
- Cambios de BD: migraciones aditivas pendientes de producción. El histórico no
  tiene todavía una retención aprobada y no debe purgarse por analogía. Acción
  manual: configurar/validar el origen, hosts, estratégicas y sitemaps; migrar,
  regenerar config cache y ejecutar la primera comprobación explícitamente.

Actualizado: 2026-08-19.

## SEO/Analytics Lote 3 - Conversiones web orgánicas GA4 (2026-08-17)

- Fix productivo de métricas: `Ga4MetricDecimalNormalizer` acepta decimal y
  notación científica `TYPE_FLOAT` mediante manipulación textual exacta. Los
  valores reales `2.6e-05` y `4e-06` persisten como `0.000026` y `0.000004`.
  No usa float; precisión real superior a seis decimales u overflow de
  `DECIMAL(18,6)` falla antes de la transacción y conserva datos anteriores.
- GA4 sincroniza exclusivamente `keyEvents` atribuidos a
  `defaultChannelGroup = Organic Search`, `platform = web`; España usa
  `countryId = ES`. Salesforce y GA4 siguen siendo métricas separadas y nunca se
  suman.
- Dos tablas nuevas conservan totales diarios ALL/ESP y detalle España por
  evento. `key_events` es `DECIMAL(18,6)`; los agregados cubiertos rellenan cero,
  pero el detalle no fabrica filas. El reemplazo del detalle ocurre dentro de la
  misma transacción que los upserts, después de completar las llamadas remotas.
- `seo:sync-ga4-organic --days=120` valida property, timezone, web streams y Key
  Events. Usa tres `runReport` habituales, paginación limit/offset y cutoff
  operativo con lag 2–7 días (default 3) en timezone de la property. Scheduler:
  05:45 Madrid, lock 120 y alerta técnica existente.
- `ReportSyncRun` guarda `stats.property_id` antes del primer HTTP y conserva
  estados por property. El dashboard incorpora GA4 al common cutoff, KPI, serie
  diaria, source status y breakdown limitado a 50 eventos; el GET continúa sin
  red.
- Seguridad: OAuth `analytics.readonly`, property y filtros solo desde config y
  código; no se consultan Measurement Protocol secrets ni se persisten tokens,
  credenciales o payloads raw. No hay dependencias nuevas ni cambios CSS/JS.
- Quality gate: cada página `runReport` se rechaza si GA4 declara thresholding,
  pérdida por fila `other` o sampling. La validación sucede antes de acumular
  filas y del zero filling; cualquier incidencia conserva todos los datos
  anteriores y usa el flujo de fallo técnico existente.
- Cambios de base de datos: dos migraciones aditivas crean exclusivamente los
  agregados diarios GA4 y su detalle diario por Key Event. No se ejecutaron
  migraciones ni sincronizaciones sobre producción.
- Acciones manuales: configurar `SEO_GA4_REPORTING_LAG_DAYS` (2–7, default 3),
  desplegar código y migraciones, refrescar la config cache, validar primero con
  `seo:diagnose-integrations --live` y ejecutar la primera
  `seo:sync-ga4-organic --days=120` solo tras aprobar la property. El riesgo
  residual principal es validar con datos reales que la taxonomía de Key Events
  y los valores `defaultChannelGroup`/`platform` coinciden con la property.
- Validación local tras el normalizador científico: normalizador 2 pruebas/34
  aserciones; sync GA4 11/119; GA4 20/178; SEO 85/679; suite completa
  550/3.921 (272,30 s). Pint `--dirty --test`, Vite 8.0.12,
  `composer audit --locked --no-dev` y `git diff --check`, correctos. El build no
  cambió `public/build` porque no existen cambios CSS/JS.

## SEO/Analytics Lote 2 - Search Console y Lead orgánico (2026-08-17, histórico)

Esta sección documenta el contrato anterior a ROT-5. La fuente Salesforce SEO
vigente es la proyección Interest local descrita al inicio de este HANDOFF; no
debe usarse esta sección para operar el pipeline actual.

- Tres tablas nuevas separan agregados diarios exactos Search Console,
  rankings top reemplazables y proyección diaria Salesforce. Las sync son
  idempotentes y los rankings solo se reemplazan después de completar todas las
  lecturas remotas.
- `seo:sync-search-console` determina cutoff final en timezone Los Ángeles,
  persiste ALL/ESP y brand/non-brand, y conserva rankings 7/28/90. La regex de
  marca procede únicamente de configuración.
- `seo:sync-salesforce-organic` consulta `Lead.Medio_origen__c = 'Orgánico'`,
  cuenta registros y agrupa `CreatedDate` en Madrid. No toca el mapping legacy
  `LEA_SEL_Medio_Origen__c -> salesforce_leads.medio_origen`.
- Scheduler: Search Console 05:15 y Salesforce 05:30, 120 días, zona Madrid,
  locks de 120 minutos y monitorización técnica existente.
- El dashboard usa solo BD local/config y acepta exclusivamente los rangos
  textuales 7/28/90. Cada KPI exige cobertura diaria completa acreditada por el
  último `ReportSyncRun` completado; runs fallidos/en curso posteriores conservan
  el último cutoff válido. Resumen/Tráfico usan periodo común y rankings el
  periodo propio de Search Console/property configurada.
- `ALL / all` y `ESP / all` son totales exactos. Marca/no marca son subconjuntos
  filtrados no exhaustivos: pueden sumar menos que España porque Search Console
  omite consultas anonimizadas al filtrar por query. No se fuerza conciliación ni
  se inventa un tercer segmento.
- El comando Search Console persiste `stats.property` al crear el run, antes de
  cualquier HTTP. Así los estados `running`/`failed` se atribuyen a su property
  sin guardar credenciales; completion sustituye esos stats por el resumen
  completo. Los contenedores de tablas SEO focalizables tienen nombre accesible
  y sus encabezados de columna declaran `scope="col"`.
- Deploy requiere las tres migraciones, config cache y primera sync manual por
  fuente solo después de validar configuración. No hay dependencias nuevas.
- Seguridad: credenciales solo en entorno, OAuth/SOQL read-only, errores
  sanitizados, inputs web whitelisteados y escaping Blade. Rendimiento: 14
  consultas Search Analytics acotadas por sync, una consulta Salesforce
  paginada por ventana y render exclusivamente sobre índices/BD local.
- Validación final: SEO 29 pruebas/268 aserciones; regresión del mapping
  Salesforce 2/17; suite completa 492/3.476. Pint
  `--dirty --test`, Vite 8.0.12 y `git diff --check`, correctos. El build no
  rotó assets porque no cambiaron fuentes CSS/JS.

## SEO/Analytics Lote 1 - fundamento de integraciones (2026-08-17)

- Configuración independiente para Search Console, GA4 y SISTRIX; Salesforce
  reutiliza `SalesforceClient::describe()`. No se modifica Google Ads.
- Clientes nuevos son exclusivamente read-only y usan Laravel Http, timeouts y
  `IntegrationErrorSanitizer`. Search Console lista sites; GA4 verifica property,
  metadata, timezone y Key Events paginados; SISTRIX solo llama `credits`.
- `seo:diagnose-integrations` es config-only por defecto. `--live` realiza las
  lecturas externas y aísla fallos por fuente sin imprimir credenciales, tokens,
  respuestas OAuth completas ni saldo SISTRIX.
- La pantalla muestra cuatro estados neutrales desde configuración local. No
  ejecuta HTTP externo, no afirma acceso verificado y conserva el mensaje de que
  todavía no existen métricas.
- No hay migraciones, tablas, scheduler, jobs, KPI, snapshots o alertas SEO. El
  contrato operativo y futuro está en `docs/ai/SEO_ANALYTICS.md`.
- Diagnóstico real read-only del 2026-08-17: Salesforce verificó
  `Medio_origen__c` (`Medio de origen`, picklist) como único candidato con el
  valor exacto `Orgánico`; `LEA_SEL_Medio_Origen__c` ofrece `Organic`. Search
  Console, GA4 y SISTRIX estaban sin configurar y no recibieron llamadas.
- Acciones manuales: completar variables de entorno cuando existan credenciales,
  refrescar config cache y ejecutar el diagnóstico live desde CLI. Nunca usar el
  resultado config-only como acreditación de acceso.
- Validación local final: filtro `Seo` (12 tests, 113 assertions), navegación
  estratégica (6 tests, 84 assertions), suite completa (475 tests, 3.321
  assertions), Pint `--dirty`, Vite 8.0.12 y `git diff --check`, correctos.

## Lote 1 Fase 1 - Application shell y navegacion estrategica (2026-08-13)

- Se incorpora `x-reports.app-shell` a Leads, Reservas/Ventas, Llamadas,
  Campanas, Stock, Comisiones y las paginas administrativas existentes. No se
  modifican datasets, endpoints, filtros, KPIs, reglas, exports ni integraciones.
- La sidebar agrupa Resumen; Comercial; Marketing; Operaciones; Comisiones; y
  Administracion. Solo renderiza rutas autorizadas. En escritorio puede
  ocultarse por completo para liberar ancho; en movil funciona como drawer con
  overlay, Escape y gestion de foco. El estado de escritorio usa la key local
  `hrmotor-report-sidebar` bajo `try/catch`, sin BD ni API.
- Resumen (`reports.index`) y SEO y Analytics
  (`reports.seo-analytics.index`) son pantallas estructurales sin consultas,
  metricas ficticias ni llamadas externas. Solo Administrador y Director
  acceden. La politica vive fuera de las definiciones editables y no puede
  rebajarse mediante `report_access_settings`.
- Para perfiles no estrategicos, `/informes` y el login siguen resolviendo el
  primer informe operativo permitido. Director no hereda paginas exclusivas de
  Administrador. Marketing no recibe SEO y Analytics.
- `ReportUserAccess` memoiza los minimos y las claves visibles en atributos del
  request, evitando consultar la configuracion una vez por enlace. No se usa
  estado estatico mutable ni se cargan datos de dashboards desde la navegacion.
- Assets nuevos: `resources/css/reports/app-shell.css` y
  `resources/js/reports/app-shell.js`, compilados por Vite. No hay dependencias,
  migraciones ni variables de entorno nuevas. Los artefactos de `public/build`
  deben desplegarse junto al codigo porque produccion no dispone de Node.
- El shell define `border-box` de forma acotada para su arbol y
  pseudo-elementos. Su geometria no depende del reset global de ningun
  dashboard, por lo que Resumen y SEO conservan la misma densidad de sidebar.
- Validacion focal previa al cierre: 24 pruebas de shell/acceso/login (189
  aserciones) y 99 pruebas de dashboards complejos (857 aserciones), todas
  correctas. Consultar la entrega de la tarea para suite completa, Pint, build
  y diff-check finales.

## Lote 2 Fase 1 - Design System compartido (2026-08-14)

- `resources/css/reports/design-system.css` introduce tokens
  `--report-ui-*` y primitives `report-ui-*` para cabeceras, superficies,
  botones, badges, formularios, empty states, skeletons y tablas. No contiene
  selectores globales legacy, `!important`, JavaScript ni dependencias nuevas.
- Controles y superficies nuevos usan radio discreto de 8 px. El radio pill se
  reserva a badges, estados y futuros chips; no se aplica globalmente al CSS
  legacy. El logout del shell consume el radio de control.
- El shell carga Design System antes de su CSS y conserva apariencia y
  comportamiento mediante aliases. `--app-sidebar-width` y
  `--app-topbar-height` siguen perteneciendo al shell. `updatedBadge` mantiene
  ID y clase `badge` junto al nuevo primitive.
- Componentes Blade nuevos: `x-reports.ui.page-header`,
  `x-reports.ui.empty-state` y `x-reports.ui.status`. No consultan datos y usan
  el escape estándar. Un estado desconocido cae en `not-evaluable`.
- Solo Resumen y SEO/Analytics adoptan los primitives; mantienen sus mensajes
  estructurales sin KPI, estados analíticos ni integraciones ficticias. Los seis
  dashboards y sus vistas internas no se migran en este lote.
- Contrato operativo: `docs/ai/DESIGN_SYSTEM.md`. No hay migraciones, cambios de
  autorización, endpoints, consultas ni configuración sensible.
- Verificación focal: Design System 5 pruebas/53 aserciones y navegación
  estratégica 6/84. Suite completa 458 pruebas/3.205 aserciones, Pint sobre PHP
  modificado y build Vite correctos. Consultar la entrega del lote para hashes
  finales.

## Lote 3 Fase 1 - Analytical UI Patterns (2026-08-14)

- El bundle existente `design-system.css` incorpora KPI strip continuo, data
  panels, section headers, tablas densas, tabs lineales, filter bar, row
  highlighting neutral y source status. No hay bundle, dependencia o JavaScript
  adicional.
- Componentes Blade nuevos: `x-reports.ui.section-header` y
  `x-reports.ui.source-status`; son presentacionales, escapan props y no leen
  request, sesión, BD o servicios. Source status no define estados funcionales.
- KPI items no son cards; tabs no son pills; sticky de tabla es opt-in; highlight
  significa énfasis y no estado. La identidad continúa siendo HR Motor y la
  referencia externa se usa solo para estructura y densidad.
- El cierre de revisión usa `--report-ui-text-muted: #5f6b7d` y
  `--report-ui-control-border: #8793a5` para cumplir contraste WCAG AA de texto
  secundario y límites de controles sin crear overrides por componente.
- Resumen/SEO no reciben datos, KPI, tablas, fuentes, tabs o estados ficticios.
  Los seis dashboards y CSS legacy permanecen sin migrar.
- Contratos y accesibilidad quedan documentados en `docs/ai/DESIGN_SYSTEM.md`.
  No hay cambios funcionales, rutas, autorización, queries o migraciones.
- Validación local: `ReportAnalyticalPatternsTest` (5 tests, 46 assertions),
  `ReportDesignSystemTest` (5 tests, 53 assertions),
  `StrategicReportNavigationTest` (6 tests, 84 assertions), suite completa
  (463 tests, 3.251 assertions), Pint `--dirty` y build Vite 8.0.12, correctos.
  El build sustituye únicamente el asset compilado del Design System y actualiza
  su referencia en `manifest.json`; no requiere acciones manuales distintas del
  despliegue habitual de assets versionados.

## Lote de cold-path: AI Leads y caches de Llamadas (2026-08-13)

- Leads devuelve inmediatamente el fallback de insights cuando no existe un
  resultado AI válido en caché. El refresco se difiere hasta después de enviar
  la respuesta (`defer()`), por lo que no requiere un worker permanente; si no
  llega a ejecutarse, el dashboard sigue devolviendo el mismo fallback. Un
  resultado AI cacheado mantiene `source=ai` sin red. Fallos de conexión,
  timeout, HTTP no exitoso o respuesta inválida abren un cooldown cacheado y
  evitan reintentos lentos durante `REPORT_AI_COOLDOWN_SECONDS` (60 por
  defecto). No se registran prompts, payloads ni secretos.
- La sincronización real de Llamadas (`SalesforceCallSyncService::sync()` vía
  `salesforce:sync-calls`) incrementa la versión de datos al finalizar. El
  comando intenta precalentar entonces, y de forma no crítica, los filtros
  `calls-dashboard:filters:<md5(version)>`; un error de precarga solo deja el
  fallback existente de construcción bajo demanda y se registra sin payload.
- `summary` y `/agents` comparten `calls-dashboard:shared:agents:<hash>`.
  El hash contiene filtros (incluyendo scope), periodo actual y data version;
  usa lock Laravel con espera acotada y fallback compatible si el store no
  soporta locks. TTL 10 minutos y versión aseguran invalidación en sync. No se
  comparte el agregado de delegaciones: rankings excluye etiquetas operativas
  inválidas y usa métricas diferentes de `/delegations`.
- Server-Timing permanece: distingue AI cache/fallback/refresh, filters hit/miss
  y agents shared hit/miss. No hay migraciones, índices, cambios de SQL, TTL
  del payload principal, frontend ni reglas funcionales.

## Diagnóstico temporal Server-Timing de Leads y Llamadas (2026-08-12)

- Se añade `REPORT_SERVER_TIMING=false` por defecto. Cuando se habilita, solo
  un administrador puede recibir la cabecera estándar `Server-Timing` en
  Leads summary y en Llamadas summary, agents y delegations. Se reutiliza
  `ReportUserAccess::canSeeSyncDiagnostics()`: no se amplían permisos de
  auditoría ni se expone el diagnóstico a roles funcionales.
- `App\Support\ReportServerTiming` es efímero por request y solo mide nombres
  técnicos fijos y milisegundos con `hrtime(true)`. No usa consultas extra,
  listeners, query log, logs, sesión adicional ni persistencia. El callback de
  `Cache::remember()` marca el miss sin una lectura extra de caché; un hit solo
  informa del hit y del total, nunca de bloques internos no ejecutados.
- Métricas: Leads separa current, previous, groups, finalize, filters e
  insights. Calls summary separa current, previous, agents/teams, portals,
  daily, ranking, reconciliation, metadata y filters; agents y delegations
  separan query y finalize. La respuesta JSON y sus claves de caché no cambian.
- No hay migraciones ni cambios de SQL, índices, TTL, permisos de informe ni
  reglas funcionales. Para activarlo temporalmente en producción: establecer
  `REPORT_SERVER_TIMING=true`, ejecutar `php artisan config:clear` y
  `php artisan config:cache`; después repetir con `false` y los mismos
  comandos. No requiere `APP_DEBUG=true`.
- Prueba focalizada: `ReportServerTimingTest` cubre flag apagado, administrador,
  usuario no administrador, miss/hit de los cuatro endpoints, sintaxis de
  cabecera, equivalencia de JSON, aislamiento de instancia y excepción medida
  sin alterar su propagación.

## Lote 2: plan temporal de Leads y Llamadas (2026-08-12)

- Se restaura en Leads la lectura independiente de periodo actual y comparado.
  El plan productivo elegÃ­a el Ã­ndice booleano de baja cardinalidad para ambas
  variantes y la consulta conjunta con `OR` no mejorÃ³ latencia; no se aÃ±ade
  Ã­ndice de Leads sin un plan adicional que justifique el orden de columnas.
- Se aÃ±ade `sf_calls_dashboard_created_idx` sobre
  `salesforce_calls(included_in_dashboard, created_date)`. Atiende el predicado
  compartido por los agregados del dashboard; no cubre ni intenta optimizar
  expresiones de equipo, zona, delegaciÃ³n o portal.
- La migraciÃ³n es aditiva. Su `down()` elimina solo ese Ã­ndice. En producciÃ³n,
  programar la creaciÃ³n en una ventana de bajo trÃ¡fico y verificar espacio libre
  e impacto sobre sincronizaciones antes de ejecutarla.
- Las pruebas cubren frontera de periodos de Leads, consistencia cache miss/hit,
  conciliaciÃ³n existente de Llamadas y creaciÃ³n/eliminaciÃ³n aislada del Ã­ndice
  en SQLite. El plan SQLite no sustituye `EXPLAIN` de MySQL 8.0 de producciÃ³n.

## Lote 1: cache miss de Leads y Llamadas (2026-08-12)

- Leads reutiliza una sola lectura por lotes para los periodos actual y comparado
  del payload compartido. Conserva la decoraciÃ³n con la fecha de referencia de
  cada periodo y la misma construcciÃ³n de KPIs, filtros, agrupaciones e
  insights.
- Llamadas consolida las cuatro consultas de conciliaciÃ³n del resumen en un
  agregado equivalente. TambiÃ©n reutiliza la agregaciÃ³n de agentes para los
  equipos y obtiene zonas/delegaciones desde un Ãºnico agrupado por pareja.
- No se modifican TTL, scopes, rutas, contratos HTTP, Ã­ndices, migraciones ni
  reglas de clasificaciÃ³n. No se aÃ±ade instrumentaciÃ³n persistente.
- Se aÃ±aden pruebas de equivalencia cache miss/cache hit para Leads y para los
  endpoints summary, agents y delegations de Llamadas. Las pruebas existentes
  cubren filtros, Ã¡mbitos, comparativas y reconciliaciÃ³n.
- Riesgo pendiente: validar en producciÃ³n el impacto real con las mismas
  combinaciones de periodo; el baseline local no representa cardinalidad ni
  planes de MySQL 8.0 de producciÃ³n.

## CI: aislamiento de Google Ads en CampaignDashboardTest (2026-08-12)

- `test_google_ads_inventario_auditable_consulta_anuncio_y_ad_group` ya declara
  el `customer_ids` sintÃ©tico que exige `GoogleAdsClient::configured()`, junto
  con sus otros valores sintÃ©ticos y `Http::fake`.
- El test no realiza red ni usa credenciales reales. `GoogleAdsClient` conserva
  el fallo seguro `Google Ads no configurado.` fuera de ese contexto de prueba.
- Focalizado: 41 tests, 406 aserciones. Suite completa: 436 tests, 2.951
  aserciones. Build, Pint del test y `git diff --check`: correctos.
- Sin migraciones, configuraciÃ³n productiva ni cambios funcionales de CampaÃ±as.

## Hardening transversal final

### Resumen

- Se añadió CI real en `.github/workflows/ci.yml`, sin secretos ni permisos de
  escritura: PHP 8.4, Composer, migraciones SQLite, tests, Pint, Node 22/build y
  whitespace check.
- Login: throttling configurable, mensaje uniforme, coste de hash también para
  usuario inexistente, regeneración de sesión y cookie remember segura según
  configuración. Producción activa Secure y cifrado de sesión por defecto.
- API Comisiones: Basic compatible con credenciales múltiples/versionadas en
  entorno, revocación, comparación sobre hashes de longitud fija, rate limits de
  auth y tráfico por integración, `429` y auditoría diaria con request ID.
- Logs: daily por defecto, 90 días y procesador central de redacción.
- Retención: comando chunked/dry-run para ocho payloads crudos sin consumidores,
  sync runs, colas y alertas resueltas. Cinco payloads con lecturas funcionales
  quedan bloqueados hasta materializar dependencias; no se fuerzan borrados.
- Alertas: tabla/modelo/servicio deduplicado, panel paginado solo admin y
  callbacks de scheduler. Stock conserva su criterio, pero deja de enviar email
  y de escribir Tasks Salesforce.
- Autorización sensible de usuarios, permisos, fórmulas y penalizaciones devuelve
  `403` en acceso directo no autorizado.
- `DatabaseSeeder` ya no crea una identidad corporativa ni datos demo fuera de
  `local/testing`; se retiró el email fallback legacy de alertas de Stock.
- Despliegue documentado sin Node y sin rollback genérico destructivo.

### Archivos principales modificados

- `bootstrap/app.php`, `routes/api.php`, `routes/web.php`, `routes/console.php`.
- Middlewares de API, login/sesión y controladores administrativos.
- `config/auth.php`, `logging.php`, `services.php`, `session.php`, `.env.example`.
- `OperationalAlert`, `OperationalAlertService`, panel administrativo y servicio
  de alertas de Stock.
- `PruneTransversalDataCommand` y migración
  `2026_08_11_130000_create_operational_alerts_and_retention_indexes.php`.
- Tests focalizados de API, auth, permisos, logs, alertas y retención.
- README, runbook de producción, contexto y decisiones AI.

### Decisiones y base de datos

- Secrets en environment, no en BD; legacy se retira solo tras identificar al
  consumidor y completar rotación.
- La migración es aditiva: crea `operational_alerts` y 11 índices de retención
  sobre tablas existentes. No contiene data migration ni ejecuta pruning.
- El `down()` de la tabla de alertas es destructivo una vez utilizada; preferir
  rollback de código/forward fix. Los índices sobre tablas grandes pueden
  consumir tiempo y bloquear DDL en MariaDB 10.6: medir sobre copia.

### Seguridad y rendimiento

- Auditoría API no registra headers, body, IP ni query completa.
- Alertas y errores persistidos se sanitizan y truncan.
- Panel con filtros servidor y 25 filas; alertas deduplicadas por unique
  fingerprint. Pruning usa índices, lotes y transacción por lote.
- Cachés revisadas: TTL 10 minutos e inclusión de ámbitos/filtros donde aplica.
  La evaluación de Stock pasó de consultar alertas abiertas por delegación a
  una carga única; no se añadieron cambios funcionales ni N+1.

### Pruebas ejecutadas

- Baseline previo: la suite completa agotó 300 segundos sin resumen; no llegó a
  comunicar fallos de aserción. El historial anterior registraba 423 tests.
- Sintaxis de PHP modificado: correcta.
- `migrate:fresh --env=testing --force`: correcto, incluida la nueva migración.
- Rutas API y `schedule:list`: correctos.
- Focalizadas iniciales: 29 tests, 166 aserciones, correctas tras actualizar una
  expectativa histórica de redirect a `403`.
- Focalizadas ampliadas: 40 tests, 213 aserciones, correctas.
- Suite completa final: 436 tests, 2.951 aserciones, correcta en 390,3 s con
  `max_execution_time=900`. El intento inmediatamente anterior con el límite
  local de 120 s terminó por timeout del runner en Symfony Finder, sin fallo de
  aserción; no es un error funcional del lote.
- `npm run build`: correcto; no cambió artefactos versionados. Conserva avisos
  no bloqueantes sobre `login-bg.jpg` resuelto en runtime y una API Node obsoleta.
- Composer `validate --strict --no-check-publish`: correcto.
- Workflow `.github/workflows/ci.yml`: sintaxis correcta con `yaml-lint`.
- Pint global: no correcto por deuda histórica ajena al lote. Pint sobre todos
  los PHP modificados/nuevos: correcto.
- `git diff --check`: correcto en la revisión final.

### Acciones manuales y riesgos

- Seguir `docs/operaciones-produccion.md`; no ejecutar npm en producción.
- Verificar backup restaurable, cuota de logs, locking/tiempo de índices y dueño
  de cada consumidor API.
- Configurar en cPanel límites de proceso/monitorización de cron; los locks de
  Laravel no terminan procesos y solo deben limpiarse tras confirmar que no hay
  una ejecución activa.
- Configurar/rotar credenciales fuera del repo; Codex no lo ha hecho.
- No se ejecutaron deploy, push, migración/pruning/job real ni llamadas externas.

## Cierres independientes de Comisiones Comerciales

- Los cierres pasan a ser independientes por `month + closure_scope` para
  Comerciales, Delegaciones y Área Manager. Cada scope conserva snapshots y
  eventos propios; Contact Center, Call Center y Financieros no se congelan.
- La migración mantiene los cierres existentes como `legacy` y no presupone que
  representen aprobación funcional de los nuevos bloques.
- No se verificaron en el repositorio los Salesforce User IDs de las excepciones
  Oscar, Nuria e Irene. No se mantienen reglas económicas por nombre.
- La lectura definitiva de Área Manager reutiliza `area_manager` o
  `area_manager_by_zone` del snapshot según el rol. Está cubierta para Dirección
  y para Área Manager restringido; los cambios posteriores de fuente no alteran
  el bloque congelado.

## Correctivo Meta Direct Form sin `campaign_acquired`

- `CampaignAttributionBuilderService` conserva como candidatos los Leads Meta Direct Form identificados por portal/origen aunque `campaign_acquired` sea nulo, vacío o inválido. No incrementan `discarded_invalid_values`.
- Los IDs originales mantienen su precedencia: un match publicitario inequívoco gana antes del fallback Meta. Un Lead no Meta sin campaña válida sigue descartándose.
- No hay cambios de esquema, migraciones ni escrituras de reproceso en este correctivo.

## Resumen de la tarea

Correctivo focalizado de las exportaciones de auditoría de Llamadas y
Reservas/Ventas. Se corrigió la cardinalidad y serialización del CSV de
Llamadas, la conciliación visible de atendidas por equipo, la unicidad de la
cohorte de Reservas/Ventas y la minimización de datos personales.

## Archivos modificados

- `app/Support/CsvValueSerializer.php`.
- Controladores y datasets de Llamadas y Reservas/Ventas.
- `app/Console/Commands/DebugReservasVentasReportCommand.php`.
- Pruebas feature de ambos informes y de ámbitos de exportación.
- Documentación específica y handoff del proyecto.

## Decisiones adoptadas

- El CSV de Llamadas usa columnas fijas, cursor y serialización explícita de
  valores estructurados.
- El KPI y la auditoría de Reservas/Ventas consumen una única función de
  resolución de cohorte.
- `Opportunity.Name` y los datos de contacto de Account no forman parte de la
  exportación estándar de auditoría.
- Se conserva la matriz vigente de acceso; los ámbitos se aplican en servidor.

## Cambios de base de datos

Ninguno. No hay migraciones ni backfill.

## Seguridad

Se retiraron del flujo estándar de auditoría el nombre potencialmente personal
de Opportunity, nombre de Account, teléfonos y correos del cliente. El comando
de conciliación solo muestra IDs técnicos y cantidades.

## Rendimiento

Llamadas se exporta mediante cursor y sin consultas por Task. Reservas/Ventas
selecciona únicamente las columnas necesarias y resuelve cada Opportunity una
vez por construcción del dataset, en lotes de 1.000 y sin consultas por fila.

## Pruebas ejecutadas

- Lint PHP: correcto.
- Pruebas nuevas/focalizadas: 11 tests, 83 aserciones, correctas.
- `--filter=CallDashboard`: 5 tests, 40 aserciones, correcto.
- `--filter=ReservationsSales`: 3 tests, 38 aserciones, correcto.
- `--filter=OpportunityDashboard`: 3 tests, 35 aserciones, correcto.
- `--filter=ReportAccess`: 8 tests, 55 aserciones, correcto.
- Regresión focalizada final: 23 tests, 166 aserciones, correcta.
- Suite completa, primer intento: recorrió 405 tests; 403 pasaron y detectó dos
  regresiones de compatibilidad en agentes, corregidas y verificadas después.
- Suite completa, reintentos finales: bloqueados por timeout de 120 segundos en
  un handler HTTP de Guzzle antes de emitir resumen; no fue un fallo de
  aserción del lote.
- Laravel Pint: correcto sobre todos los PHP modificados.
- `git diff --check`: correcto.
- `npm run build`: correcto; Vite finalizó en 12,06 segundos con advertencias no
  bloqueantes indicadas por el propio build.

## Acciones manuales necesarias

- Desplegar código y limpiar cachés de Laravel.
- Ejecutar en el entorno con la fotografía observada el diagnóstico de cohorte
  documentado, si se necesitan conocer los IDs de producción.

## Riesgos o pendientes

- Los cuatro Opportunity IDs observados no están disponibles en los datos
  locales; no se han inventado ni documentado.
- No se ha implementado ningún cambio de reglas de negocio ni reproceso.

## P1 Leads y Reservas/Ventas (2026-08-07)

- `LeadRecordTypeNormalizer` normaliza Lead y Ayvens como Venta. El comando
  `reports:reprocess-lead-record-types` permite alinear el histórico
  materializado con `--dry-run`, por lotes e idempotencia.
- Campañas usa una fotografía propia de tipos normalizados; no se reconstruye
  automáticamente desde este lote.
- El campo funcional Salesforce “Delegación” no tiene API Name verificable en
  el repositorio. No se utiliza ni se inventa; este es un bloqueo documentado.
- Reservas/Ventas deja de depender de `ReservationsSalesAiInsightsService`.
  El servicio se conserva sin cambios para evitar afectar consumidores ajenos.
- Verificación P1: `--filter=Lead` correcto (121 tests, 617 aserciones),
  pruebas nuevas focalizadas correctas (17 tests, 124 aserciones), suite completa
  correcta (409 tests, 2.777 aserciones), Pint y build frontend correctos.

## Corrección de fallback Exposición (2026-08-07)

- El fallback de delegación por owner/persona trabajadora usa exclusivamente el
  tipo funcional normalizado `exposicion`; el portal no participa en esa decisión.
- Se conservan las prioridades de campos y el fallback histórico persistido.
- Verificación: delegación 4 tests/16 aserciones, Leads 121/622, suite completa
  409/2.782, build y Pint correctos.

## P1 Llamadas (2026-08-07)

- Se reutilizan `included_in_dashboard` y `dashboard_exclusion_reason` para
  excluir el perfil Salesforce exacto de pruebas sin retirar Tasks de auditoría.
- `CallClassificationRules` centraliza ajuste 5/10, exclusión de perfil y el
  valor canónico `unassigned`. No hay cambios de esquema.
- Sync y reproceso resuelven el perfil desde `SalesforceUser` por el ID operativo
  cuando está disponible; `missing_call_object` prevalece.
## P1 Campañas: cierre de inversión (2026-08-07)

- Se añadieron cierres, snapshots y eventos de Campañas. Solo Administrador/IT
  puede cerrar/reabrir; snapshots no se borran en cascada.
- El dashboard usa inversión congelada para meses cerrados y deja abiertos los
  resultados comerciales. Ambigüedades y exclusiones permanecen auditables.
## Correctivo Salesforce-only de Campañas (2026-08-07)

- El dataset representa como `null` las métricas comerciales/económicas de
  Salesforce-only y evita que entren en los totales.
- La auditoría de exclusiones incorpora motivo, mecanismo `exact_name` y valor.
- No hay migración adicional ni reproceso ejecutado.
## Simulación histórica de Campañas (2026-08-07)

- `salesforce:sync-campaign-leads --dry-run` lee Salesforce sin borrar,
  upsertear ni invalidar caché.
- `campaigns:build-attribution --dry-run` ejecuta el mismo builder en memoria y
  compara la simulación con `campaign_attributions`, sin escritura.
- La reconstrucción histórica en escritura mediante `--from` requiere
  `--reason`. No se ejecutó ningún reproceso.
## Correctivo de dry-run de tipos nulos (2026-08-07)

- La simulación de atribución usa la etiqueta técnica `null` para estadísticas
  cuando el normalizador no devuelve tipo; el Lead sigue en el universo.
- El comando de reproceso desglosa cambios no Lead/Ayvens por raw, valor
  materializado y valor calculado, con muestras de IDs no personales.
## Diagnóstico de cambios de atribución (2026-08-07)

- El dry-run separa identidad de campaña (`platform + campaign_id`, o nombre
  normalizado sin ID) de cambio de método y muestra transiciones agregadas.
- Valores inválidos y campañas excluidas se contabilizan por separado; las
  exclusiones exactas informan motivo y muestra de IDs.

## Regla anterior de Financieros, sustituida (2026-08-11)

- Esta sección conserva la decisión histórica para explicar el cambio. La
  auditoría de 2026-08-24 demostró que `owner_id` pertenece al comercial y
  sustituyó la selección por las claves `zona_irene` y `zona_nuria`.
- El resultado especial continúa sustituyendo los tres bloques normales y no
  usa nombre ni email.
- La regla histórica del 40 % atribuida a Oscar no está en la especificación
  vigente y sigue desactivada; no se agregó ninguna identidad ni fila sintética.
- No hay migraciones, llamadas Salesforce ni cambios de universos. Financieros
  continúa siendo operativo y no se congela mediante cierres de comisiones.
- Verificación: `CommercialCommissionDashboardTest` (44/291), `CommercialCommission`
  (56/345), `financieros` (6/38), ámbito Área Manager (1/4), cierres (9/72) y
  suite completa (423/2.883) correctos. Build correcto; Pint correcto para los
  PHP modificados. Pint global conserva deuda preexistente fuera de este lote.

## Auditoría y correctivos de Stock (2026-08-11)

- Se validó el universo Product2 y el scheduler: Disponible, Reservado y
  Bloqueado ocupan capacidad; solo Disponible entra en recomendaciones. El
  plan usa capacidad virtual y no escribe movimientos.
- Se añadió `missing_signed_date` a la validez de ventas y se mantiene la
  resolución existente de duplicados por última firma sin desempatar con
  `LastModifiedDate`.
- Se incorporó la fecha de matriculación verificada por describe de Salesforce:
  `PRO_DAT_Fecha_de_matriculacion__c` → `salesforce_vehicles.registration_date`.
  No se modificaron pesos de ranking, al no existir fórmula aprobada.
- Los aliases de catálogo requieren permiso independiente
  `stock.catalog_aliases.approve`, target Product2 activo y auditoría de
  aprobador/fecha/motivo. Los aliases históricos quedan sin efecto como
  `legacy_unverified` hasta revisión; no constituyen catálogo alternativo.
- `StockDailySnapshotService` ahora reemplaza transaccionalmente la fotografía
  de la fecha para evitar filas obsoletas en reintentos.
- Archivos principales: modelos `ReportUser`, `StockCatalogAlias`,
  `SalesforceVehicle`; servicios de Stock; controlador/route de aprobación;
  migraciones `2026_08_11_120000` a `122000`; pruebas de Stock y documentación.
- No hubo llamadas Salesforce de escritura ni operaciones de stock. Bloqueo:
  falta una definición verificable de los estados que identifican logística
  activa; no se añadió una inferencia que pueda excluir o puntuar vehículos.

## Cierre de revisión del Application Shell (2026-08-13)

- El drawer móvil aplica `inert` únicamente al workspace mientras está abierto,
  ofrece cierre dentro de la navegación y restaura el foco al control de apertura
  al cerrar mediante Escape, overlay o botón. El cambio de breakpoint siempre
  retira `inert`; la persistencia desktop en `localStorage` se conserva.
- La topbar continúa siendo sticky. Los selectores sticky de Comerciales, Call
  Center y Contact Center usan un offset basado en `--app-topbar-height`.
- El fondo neutral prevalece en páginas `body.campaigns-report`. El enlace activo
  usa el rojo accesible `#a50f23` y el foco visible el anillo `#8f1020`; no se ha
  añadido `!important`. Penalizaciones dispone de icono propio.
- Se verificó expresamente que una fila histórica `summary=viewer` no concede el
  Resumen ni altera el landing operativo de viewer. La protección fija de
  SEO/Analytics permanece cubierta.
- No hay migraciones, dependencias, requests ni cambios funcionales en informes.
  Verificación: `StrategicReportNavigationTest` 6 pruebas/83 aserciones, suite
  completa 453/3.151, Pint sobre PHP modificado, build Vite y
  `git diff --check` correctos.

## Reducción de amplificación de escrituras del sync mensual (2026-08-25)

- Se auditó `salesforce:sync-monthly-commercial --days=2`. La causa era el
  `upsert` incondicional de Leads y Activities, el `synced_at` nuevo en cada
  Lead, el `updated_at = now()` de summaries y la persistencia individual de
  Users con `raw_payload`. Una repetición idéntica generaba `UPDATE` aunque el
  estado funcional no cambiara.
- Se añadió `ChangedRowUpsert`, que por chunk carga en una consulta las filas
  locales, normaliza casts, fechas UTC, booleanos y JSON (incluyendo orden de
  claves y equivalencia `null`/cadena vacía), y separa `inserted`, `updated` y
  `unchanged` antes de escribir. La comparación usa todos los atributos de la
  proyección recibida; no depende únicamente de `LastModifiedDate`.
- Leads excluye `synced_at` de la decisión de cambio, pero lo actualiza junto
  con `updated_at` cuando cambia un atributo persistible. La reconciliación de
  ausentes ya no usa `synced_at` como marca de presencia: compara por chunks
  contra los Salesforce IDs activos observados en el run. Deleted/merged
  repetidos se clasifican sin volver a escribir.
- Tasks, Events y Users usan la misma clasificación por chunks. Summaries se
  siguen recalculando para todo el período, conservando las reglas matemáticas,
  pero solo se persisten si cambia el resultado; el recálculo incremental queda
  como optimización posterior para no introducir riesgo funcional en este lote.
- `saved` conserva compatibilidad y en Leads significa registros activos válidos
  procesados, no escrituras físicas. `active_inserted`/`active_updated`/
  `active_unchanged` desglosan ese universo; `persisted_inserted`/
  `persisted_updated`/`persisted_unchanged` incluyen también deleted/merged. Las
  claves compatibles `inserted`/`updated`/`unchanged` son aliases del total
  `persisted_*`. Leads añade `deleted_merged_changed`/
  `deleted_merged_unchanged`, y summaries añade `summaries_changed`/
  `summaries_unchanged`. `ReportSyncRun` conserva `summaries` como entero y
  publica el desglose adicional sin payloads ni datos personales.
- La metadata separa el último run completado del último run que cubre todo el
  período del dashboard. `salesforce_leads_synced_at`, `activities_synced_at`,
  `sync_run_id` y `sync_run_status` proceden del último `ReportSyncRun`
  completado aunque el scheduler solo haya sincronizado dos días;
  `dataset_cutoff_at` conserva el run con cobertura completa o el mínimo cutoff
  local como fallback. Los `synced_at` por fila quedan como fecha del último
  cambio real.
  El uso de `MAX(salesforce_leads.synced_at)` en alertas de atribución de
  Campañas se mantiene como señal de cambio de datos, evitando reconstrucciones
  tras consultas idénticas. La invalidación de caché por run se conserva para
  publicar el nuevo cutoff.
- Archivos modificados: comando mensual; servicios de Leads, Activities, Users
  y summaries; nuevo comparador `ChangedRowUpsert`; metadata del dashboard de
  Leads; pruebas unitarias de los cuatro syncs/summaries y prueba funcional del
  comando.
- Base de datos/configuración: sin migraciones, backfills, cambios de `.env` ni
  cambios MySQL. No se modificaron `binlog_format`, `binlog_row_image` ni
  compresión. No hay acciones manuales previas al despliegue.
- Seguridad: no se registran payloads, IDs, emails ni datos personales en las
  nuevas estadísticas. Se conserva el alcance SOQL y la autorización existente.
- Rendimiento: una lectura local por chunk (200 Leads; 500 Activities/Users/
  summaries) sustituye escrituras redundantes. El conjunto de IDs observados es
  proporcional a la ventana consultada; con la ventana operativa de dos días es
  acotado. `withoutOverlapping(30)` permanece activo.
- Pruebas: regresión específica final 21 tests/179 aserciones, incluyendo
  dashboard de 30 días con run incremental de dos días sin cambios locales;
  grupos Monthly Commercial, Leads, Campañas, Comisiones y auditorías
  190/1.370; suite completa 646/4.818. Pint correcto.
  `composer audit --locked --no-dev`: sin advisories.
  `git diff --check`: correcto. No hubo requests reales a Salesforce ni otros
  servicios externos.
- Medición recomendada tras despliegue autorizado: capturar posición de binlog
  y contadores Performance Schema inmediatamente antes y después de dos runs
  consecutivos con la misma ventana; usar el segundo run como AFTER estable.
  Correlacionar del `ReportSyncRun` los contadores `inserted`/`updated` con los
  deltas de `salesforce_leads`, `salesforce_activities`, `salesforce_users` y
  summaries. Persistirán las escrituras pequeñas del propio run/caché y cualquier
  cambio real ocurrido en Salesforce.
- Riesgo residual: el cálculo completo de summaries conserva su coste de CPU y
  lectura aunque ya no amplifique escrituras. La clasificación es previa al
  `upsert`; el lock programado evita solapamiento normal, pero ejecuciones
  manuales concurrentes siguen dependiendo de los índices únicos existentes.
# Evolución de cierres y responsables de comisiones (2026-08-28)

- Se unificó desde 2026-07 la meta editable de entregas: Delegaciones consume `area_manager.assignments.*.objectives.deliveries`; junio y meses anteriores conservan `delegations.goals`. Los contadores reales de ambos informes no se modificaron ni reconciliaron entre sí.
- Los cierres económicos admiten seis ámbitos independientes: comerciales, delegaciones, Area Manager, financieros, Call Center y Contact Center. Administrador prepara; Administrador o Dirección aprueban/reabren; Auditor no escribe.
- Call Center y Contact Center detallados quedan restringidos a Administrador tanto por pestaña como por resolución server-side y export detallado. Dirección recibe una superficie mínima de aprobación.
- El Auditor recibe una proyección final-only por entidad, estado, aprobador, fecha y alertas de jefe de tienda. No puede gestionar penalizaciones ni exportar datasets detallados.
- Salesforce Describe confirmó `Delegacion__c.DEL_BUS_Jefe_Tienda__c` como lookup a `User`. `Delegacion__History` existe, pero las consultas de solo lectura no devolvieron eventos de ese campo ni desde 2026-07-01 ni históricamente; por ello julio no puede afirmarse como reconstruido y se representa como histórico no verificable.
- Se añadió persistencia temporal local y el comando `salesforce:sync-delegation-managers --from=2026-07-01`. Requiere ejecutar migración y después el comando en cada entorno; el scheduler lo refresca diariamente a las 04:15. No se ejecutó sincronización ni migración en esta tarea.
- La comisión no se prorratea: el 100 % corresponde al último responsable verificable del periodo; varias identidades generan alerta no bloqueante que queda dentro de la fila canónica y, por tanto, del snapshot de Delegaciones.
- La verificabilidad exige cobertura temporal continua del mes. Los eventos de Field History generan intervalos; las observaciones diarias solo cubren cada día observado. Un evento antiguo o una lectura actual aislada no validan julio retroactivamente.
- Backfill controlado de julio: IT/Dirección debe confirmar cada delegación y ejecutar `commissions:record-delegation-manager-evidence` indicando Salesforce Delegation ID, Salesforce User ID, mes, fuente, referencia documental y quién registra. Las delegaciones no confirmadas permanecen como histórico no verificable. No se ejecutó ningún backfill.
- Los scopes `financials`, `call_center` y `contact_center` solo pueden cerrarse desde 2026-07; junio y meses anteriores mantienen los tres scopes históricos.
- El Auditor usa snapshot para scopes definitivos y dashboard canónico vivo con `includeDetails=false` para scopes abiertos, proyectando inmediatamente solo entidad y comisión final.
- Si Call Center se visualiza con fechas contractuales personalizadas, el panel de cierre informa el rango mensual y total canónico que se congelará; el snapshot nunca consume esos query parameters.
- Seguridad: no se sincronizan permisos `ReportUser`, no se consulta Salesforce durante el render y las identidades económicas usan Salesforce User ID.
- Corrección sénior final: los tres scopes nuevos se rechazan en backend antes de 2026-07; el Auditor usa datos vivos final-only en provisional/pending/reopened y snapshot en definitivo; tanto la vista como el endpoint JSON reducen el estado a situación, aprobador y fecha. Call Center informa el total mensual canónico cuando existen filtros contractuales personalizados y sus snapshots nunca consumen esos filtros.
- Cobertura histórica: un jefe solo es verificable si intervalos con Salesforce User ID cubren el mes completo sin huecos. Se cubren cero cambios con evidencia mensual, seguimiento diario completo, rotaciones de dos o tres responsables, huecos posteriores y observaciones puntuales insuficientes. Una cobertura sin responsable no se considera verificable.
- Backfill de julio: no se ejecutó. Tras confirmación real de IT/Dirección se registra por delegación con `commissions:record-delegation-manager-evidence`, Salesforce Delegation ID y User ID, mes, fuente permitida, referencia documental y registrador. La ausencia de confirmación conserva el warning explícito.
- Validación ejecutada: `CommissionGovernanceEvolutionTest` 9/28; `CommercialCommissionAuditProjectionServiceTest` 2/17; `CommissionVisibilitySecurityTest` 7/47; `CommissionMonthClosureTest` 16/120; `CommercialCommissionDashboardTest` 50/398; `ReportAccessManagementTest` 8/53; configuración/penalizaciones/Call Center/Contact Center 22/135; suite completa 725 pruebas/5.401 aserciones. Lint PHP de 26 archivos, compilación Blade y `git diff --check` correctos. Por restricción expresa no se ejecutó Laravel Pint.

## Correctivos de despliegue y UX de cierres de Comisiones (2026-08-28)

- Causa del `SQLSTATE 42S22`: la primera versión ya ejecutada de `2026_08_28_090000_create_salesforce_delegation_manager_history_table` no contenía metadata de cobertura, mientras el código posterior consultaba `coverage_from`. Se añadió la migración incremental `2026_08_28_100000_add_coverage_metadata_to_salesforce_delegation_manager_history_table`, segura tanto sobre el esquema legacy como sobre instalaciones nuevas.
- La migración añade solo cuando faltan `coverage_from`, `coverage_to`, `evidence_reference` y `recorded_by`, además del índice `sf_deleg_mgr_coverage_idx`. En filas legacy, `coverage_from` se deriva de `effective_at`; `coverage_to` permanece nulo y `history_verified` no se modifica. No se borran ni promocionan observaciones.
- Mientras la migración esté pendiente, `DelegationManagerHistoryResolver` inspecciona el esquema una vez por build, no consulta columnas ausentes y devuelve responsables no verificables con warning administrativo saneado. Delegaciones y la proyección del Auditor continúan cargando sin inventar responsables.
- La causa adicional del 500 al emitir warnings era la firma de `SanitizeLogRecords`: Laravel 13 entrega `Illuminate\Log\Logger`, no directamente `Monolog\Logger`. El tap admite ambos tipos y conserva el mismo procesador de redacción de secretos.
- Contact Center no tenía una regresión de fórmula. La traza llegaba al histórico de Delegaciones durante la construcción de superficies de Comisiones y fallaba antes del render. Tras el guard de esquema y el logger compatible, el tab de Administrador responde correctamente tanto vivo como desde snapshot definitivo.
- El subtab Operaciones usaba el trigger `deliveries` mientras su panel mensual se llama `operations`; la inicialización JavaScript ocultaba todos los paneles. Trigger y panel vuelven a compartir clave. Validación manual: Operaciones visible por defecto, cambio a Tasaciones y vuelta correctos, y cambio entre 59 comerciales sin panel vacío.
- Dirección dispone de una superficie consolidada con los scopes disponibles del mes. Los no preparados no ofrecen aprobación; los pendientes muestran una acción explícita por scope; los definitivos muestran aprobador, fecha, importe congelado y reapertura con motivo. Los primeros cuatro scopes reutilizan el dashboard activo para evitar builds duplicados; Call/Contact se calculan sin detalle y nunca se exponen operativamente.
- Seguridad: no se relajaron permisos. Solo Administrador abre detalle Call/Contact; Auditor conserva final-only y no puede preparar, aprobar, reabrir, exportar detalle ni gestionar penalizaciones. No hubo escrituras ni consultas a Salesforce.
- Rendimiento: el chequeo de esquema es por resolución batch. La superficie de Dirección no recalcula los cuatro dashboards navegables fuera del bloque activo; para revisarlos enlaza a su pestaña antes de aprobar. Los snapshots definitivos se reutilizan.
- Pruebas focalizadas correctas: histórico/migración 13/47, visibilidad y aprobaciones 11 pruebas, cierres 16/120, proyección Auditor 2/17, dashboard 50/404, Contact Center 2/27 y logging 3/11. La suite global ejecutó 734 pruebas/5.455 aserciones: 733 pasaron; el único fallo fue el benchmark ajeno `StockRecommendationCandidatePaginationTest` por 34,62 s frente a su umbral de 20 s, repetido aisladamente en 34,21 s. No se modificó Stock.
- Acciones manuales tras despliegue autorizado: ejecutar `php artisan migrate`, limpiar caché de aplicación/vistas según el procedimiento de despliegue y verificar Delegaciones/Auditor. No se ejecutó la migración sobre la base local o producción en esta tarea. Tampoco se ejecutó backfill de julio.
- Restricciones respetadas: no se ejecutó Laravel Pint ni formatters globales; no hubo commit, push, PR ni deploy.

## Preparación automática, Auditor por scope y evidencia de cierre (2026-08-28)

- Se separaron dos hechos independientes de Jefes de tienda. El responsable al cierre se obtiene de evidencia que cubre el último instante del mes y recibe inicialmente el 100 % de la comisión. La cobertura de rotaciones solo se declara verificada cuando intervalos confiables cubren todo el mes. Un jefe de cierre confirmado puede mostrarse con alerta de rotaciones no verificables; no se prorratea ni se afirma que fuera el único responsable.
- El backfill no copia observaciones actuales hacia julio. `commissions:record-delegation-manager-evidence` admite `--evidence-type=month_end|full_month` y `--dry-run`. Para lotes se añadió `commissions:import-delegation-manager-evidence <csv> --dry-run`; valida IDs Salesforce, catálogo local, fuente, referencia y registrador antes de una transacción única. No se ejecutó ningún backfill.
- Ejemplo de validación sin escritura: `php artisan commissions:import-delegation-manager-evidence storage/app/private/jefes-julio.csv --dry-run`. Cabeceras: `delegation_id,delegation_name,manager_id,manager_name,month,source,reference,recorded_by,evidence_type`. Solo una confirmación documental que cubra todo julio puede usar `full_month`; para confirmar exclusivamente quién estaba al cierre se usa `month_end`.
- La causa del 500 del Auditor era agotamiento de 128 MB al construir seis dashboards vivos en una petición. La proyección ahora obtiene metadata de seis estados pero construye únicamente `audit_scope`. Definitivo consume snapshot; provisional, pendiente o reabierto calcula solo ese scope con `includeDetails=false` y proyecta inmediatamente entidad/comisión final. Un fallo queda aislado como bloque no disponible, se registra sin datos sensibles y no se convierte en cero.
- Preparar ya no acepta checkboxes del navegador. `CommercialCommissionSourceReadinessService` comprueba metadata `report_sync_runs`, cobertura temporal, estado y freshness; un run completado con cero filas es válido. Los comandos de Opportunities, `Resena__c` comerciales y Tasaciones registran sus ejecuciones. Fuentes locales se validan por esquema, no por recuento.
- Las reseñas de Delegaciones se verifican mediante el estado técnico y `fetched_at` de `CommercialCommissionDelegationReviewsService`, que consume el endpoint interno y su caché. No se usa `salesforce_reviews` como indicador. Fallo, respuesta incompleta o caché desactualizada bloquean Preparar sin exponer URL ni credenciales.
- Al preparar se guarda el snapshot candidato y su `source_state`. Aprobar transforma exactamente esa versión en definitiva sin reconstruir el dashboard. Una reapertura conserva snapshots anteriores y una preparación posterior crea una versión nueva. Dirección lee el importe candidato; no recalcula los seis dashboards.
- La UI de Administrador es una tabla compacta de fuentes/estado/acción y deja approve/reopen únicamente bajo `Acciones excepcionales`. Dirección dispone de una tabla compacta con seis scopes, importe preparado, aprobador, fecha y acciones bajo demanda. Un rango contractual personalizado de Call Center no ofrece Preparar hasta volver al mes canónico.
- Archivos nuevos de esta iteración: servicios de readiness y evidencia, importador CSV y prueba de readiness. No hay migraciones adicionales a las ya descritas para el histórico.
- Validación actual: sintaxis correcta en los 33 PHP modificados y compilación Blade correcta. Focalizados: gobierno 16/60; proyección Auditor 3/22; readiness 3/9; visibilidad/seguridad 11/78; cierres 16/120; Call Center 6/45; permisos 8/53; regresión específica de Oscar/snapshot 1/10. Suite completa: 741 pruebas y 5.483 aserciones; 740 pasaron. El único fallo fue el benchmark preexistente `StockRecommendationCandidatePaginationTest`: 43,47 s frente a su umbral de 20 s. Stock no se modificó. `git diff --check` correcto. No se ejecutó Pint, migración, sincronización ni servicio externo.

## Selector mensual del Auditor y Jefes fuera del catálogo comercial (2026-08-31)

- El Auditor puede seleccionar cualquier mes entre julio de 2026 y el mes actual. El selector conserva `audit_scope`, y los enlaces de scope conservan `month`; el backend limita meses anteriores a julio y meses futuros sin construir dashboards adicionales. Las etiquetas se generan con Carbon en español, sin hardcodear nombres de meses.
- Se mantiene la restricción final-only y un único dashboard económico por request: el selector se construye localmente y no consulta Salesforce ni activa los otros cinco scopes.
- La validez de un Jefe de tienda ya no depende de `salesforce_users`. La identidad canónica sigue siendo el Salesforce User ID del lookup `Delegacion__c.DEL_BUS_Jefe_Tienda__c`; el histórico persiste ID y nombre sin foreign key ni creación de `ReportUser`/`SalesforceUser`.
- El import CSV conserva validación estricta, dry-run y transacción atómica. `manager_id` debe ser un Salesforce User ID de 15/18 caracteres con prefijo `005`, pero su ausencia en `salesforce_users` ya no bloquea el backfill. El catálogo local de delegaciones, fuente, referencia, registrador, mes y tipo de evidencia siguen siendo obligatorios.
- La sincronización obtiene el nombre actual mediante `DEL_BUS_Jefe_Tienda__r.Name`. Solo los IDs históricos o relaciones sin nombre se resuelven con una única consulta batch a `User`; no existe consulta por delegación ni se amplía el universo de la sincronización comercial.
- No hay migraciones, cambios de esquema, variables de entorno ni acciones manuales nuevas. No se ejecutó el CSV de julio, ninguna sincronización real ni escritura en Salesforce.
- Pruebas nuevas: import dry-run con manager externo, persistencia sin crear usuarios/permisos, manager ya presente, rollback atómico; sincronización de un Area Manager como Jefe, lookup nulo y resolución histórica batch; selección julio/agosto, locale español, límites temporales, conservación mutua de mes/scope y un único scope vivo.
- Validación focalizada final de esta iteración: `23/152` en import, sync de Jefes, proyección Auditor y seguridad/selector; además cierres `16/120`, permisos `8/53` y sync de usuarios existente `4/19`. Suite completa: 750 pruebas/5.497 aserciones, 743 correctas. Permanecen siete fallos del working tree previo: cuatro cálculos de Delegaciones, una penalización financiera, la meta histórica junio/julio y una auditoría de calidad de Leads; no se modificaron porque esta tarea no autoriza cambios económicos ni de Leads. Compilación Blade y lint PHP correctos. No se ejecutó Laravel Pint.

## Alertas de Jefes y presentación final-only del Auditor (2026-08-31)

- La alerta funcional de Jefes de tienda ya no depende de cobertura histórica completa. Cero, uno o dos Salesforce User ID distintos demostrados no generan aviso; únicamente tres o más generan una advertencia no bloqueante con la cantidad real y el mes localizado en español. Se mantienen internamente los estados de responsable al cierre y cobertura de rotaciones, sin borrar evidencia ni prorratear la comisión.
- Las superficies de Administrador, Dirección y Auditor filtran las alertas mediante `store_manager_distinct_count > 2`, no mediante búsquedas en mensajes humanos. Esto también evita presentar como incidencias las advertencias antiguas congeladas en snapshots legacy que no contienen un contador superior al umbral.
- La proyección reducida del Auditor para Delegaciones incorpora solo `manager_name`, además de delegación y comisión final. No expone Salesforce User ID, referencias, evidencias ni histórico. Los demás scopes mantienen su contrato anterior.
- Los estados visibles del Auditor se proyectan como `Provisional`, `Pendiente de aprobación`, `Definitivo` y `Reabierto`. `Definitivo` reutiliza la variante existente `type-pill group` (verde); pendiente y reabierto usan `type-pill pending`.
- Inspección de solo lectura del snapshot definitivo local de Delegaciones de julio: versión 2, 34 filas; las 34 contienen `store_manager_name` y el ID interno del responsable. Ninguna contiene un contador de responsables utilizable y las 34 conservan el antiguo texto de cobertura incompleta. No se modificó el snapshot: el Auditor puede mostrar directamente el nombre congelado y la proyección ignora los avisos legacy sin contador. Un snapshot aún más antiguo sin `manager_name` muestra `No verificable` sin recalcular ni inventar el responsable.
- No hubo cambios de fórmulas, sincronizaciones, readiness, preparación/aprobación, CSV/backfill, base de datos ni configuración. No se ejecutaron sincronizaciones, backfills, migraciones ni reaperturas.
- Validación focalizada: proyección Auditor, visibilidad, snapshots, cierres y permisos `36/277`; resolver e histórico `14/51`. Suite completa: 754 pruebas/5.535 aserciones, 747 correctas. Persisten exactamente los siete fallos previos ya documentados: cuatro cálculos de Delegaciones, import de penalización financiera, meta histórica junio/julio y calidad de Leads. Lint PHP de los ocho archivos PHP afectados correcto. Compilación Blade y `git diff --check` correctos. No se ejecutó Laravel Pint.

## Cierre final de Comisiones y HTTPS tras proxy (2026-08-31)

- Se localizó la última superficie que mostraba avisos legacy de cobertura: `CommercialCommissionSourceReadinessService` copiaba sin filtrar `dashboard.warnings` hacia la preparación de Administrador. Ahora elimina únicamente alertas asociadas estructuralmente a filas de Jefes con contador `<=2` y conserva/reincorpora las de `store_manager_distinct_count > 2`. Los estados reales del endpoint de reseñas, oportunidades y demás fuentes permanecen intactos y pueden seguir bloqueando preparación.
- La causa del Mixed Content era que Laravel recibía HTTP desde el reverse proxy y, al no existir proxies confiables, ignoraba `X-Forwarded-Proto: https`. `request()->getScheme()` y las URLs derivadas de `fullUrlWithQuery()`/`route()` quedaban en `http`; no era un fallo específico de los tabs.
- `bootstrap/app.php` sustituye el middleware oficial por `TrustConfiguredProxies`, una extensión mínima del `TrustProxies` de Laravel que consume la lista cacheable de `config/app.php`. Se confían `X-Forwarded-For`, host, puerto, protocolo y prefijo mediante las constantes estándar. No se acepta `*`, no se hardcodea dominio, no hay parser propio ni `URL::forceScheme`; local HTTP permanece operativo y un header HTTPS desde origen no confiable se ignora.
- Producción debe configurar `APP_URL=https://informes.app.hrmotor.com`, `TRUSTED_PROXIES` con la IP/CIDR real del proxy y asegurar `X-Forwarded-Proto: https`. Después debe limpiar/recachear configuración. No se modificó `.env` ni el proxy desde esta tarea.
- Los seis fallos económicos previos de Comisiones tenían una causa común no económica: `CarbonImmutable::createFromFormat('Y-m', ...)` heredaba el día actual. Ejecutado un día 31, junio desbordaba a julio y activaba la meta compartida, además de desplazar operaciones, reseñas y una fila de penalización. El formato `!Y-m` reinicia fecha/hora antes de `startOfMonth`; el import usa también formatos parciales reiniciados. No se cambiaron fórmulas, tramos ni universos.
- Fallos corregidos: cuatro métodos de `CommercialCommissionDashboardTest` sobre meta/bonus, agrupación de rentabilidad, alias/meta y comisión de reseñas; `CommercialFinancingPenaltyImportTest::test_importa_por_email_mes_y_sustituye_el_mes_previamente_cargado`; y `CommissionGovernanceEvolutionTest::test_july_delegation_goal_is_derived_from_area_manager_while_june_keeps_legacy_goal`. Los seis focalizados pasan con 61 aserciones.
- El séptimo fallo, `LeadVentaAndQualityAuditTest::test_incidencias_de_calidad_auditan_exactamente_el_kpi_y_no_fusionan_nombres`, permanece aislado. Test y `SalesforceLeadDashboardDatasetService` no difieren de `HEAD`. La implementación vigente excluye de totales (`INCLUDE_UNCLASSIFIED_IN_TOTALS`) el lead sin comercial cuya delegación también queda sin clasificar; por ello KPI y auditoría devuelven cero mientras el fixture espera su ID. No se alteró Leads ni su expected sin una decisión funcional de ese dominio.
- Pruebas nuevas de proxy: una request desde proxy confiable genera HTTPS para los seis tabs y URLs de formularios/exports; un origen no confiable no puede falsificar protocolo; local sin forwarded headers conserva HTTP. Readiness prueba que desaparece el warning legacy, se conserva un warning legítimo de caché y una rotación de tres Jefes sigue siendo no bloqueante.
- Validación: nuevos focalizados de proxy/readiness `9/33`; seis regresiones de Comisiones `6/61`; suite completa final `760` pruebas y `5.589` aserciones, con `759` correctas y únicamente el fallo independiente de Leads descrito. No se ejecutó Laravel Pint, Salesforce, backfill, migración, reapertura, commit, push, PR ni deploy.

## Readiness conservador de reseñas de Delegaciones (2026-09-01)

- `CommercialCommissionSourceReadinessService` deja fuera de la validación del endpoint exclusivamente filas con `reviews_technical_status=not_applicable` que ya demuestran ausencia total de materialidad: objetivo y entregas no positivos, objetivo no alcanzado y rentabilidad, prima previa a reseñas, comisión de reseñas y comisión total exactamente a cero.
- La excepción no cambia `delegation_rows`, estados técnicos, contadores, fechas, fórmulas ni mapping de ubicaciones. Una fila residual permanece visible como `not_applicable`; únicamente deja de exigir una consulta que no puede alterar su resultado económico.
- Cualquier campo requerido ausente/no numérico, cualquier materialidad o cualquier estado distinto de `available`/`not_applicable` conserva el bloqueo. `not_configured`, `transport_error`, `remote_error`, `unavailable` y estados desconocidos siguen siendo errores. Las filas `available` continúan sujetas al TTL de `reviews_fetched_at`.
- Si todas las filas son `not_applicable` e inertes, reseñas queda `ready` sin falsear `fetched_at`; en una mezcla, freshness se calcula solo sobre filas realmente aplicables. No se añadieron consultas SQL/HTTP, migraciones, variables de entorno, frontend ni assets.
- Validación: readiness `22/67`, dashboard de Comisiones `50/404`, suite completa `777/5.654`, Pint focalizado correcto y `git diff --check` correcto. No se consultó Salesforce ni el endpoint real y no hubo commit, push, merge ni deploy.

# Auditoría preparatoria de campos Salesforce (2026-09-01)

## Resumen

- Se auditó el recorrido actual de procedencia, canal, medio, portal,
  delegación y adquisición desde SOQL hasta tablas, resolvers, datasets,
  informes y exports.
- Se creó `docs/auditoria-migracion-campos-salesforce.md` porque la documentación
  existente está separada por informe y no había una matriz transversal capaz
  de registrar las colisiones entre Leads, Campañas, Calls, Opportunities y
  SEO sin duplicar o mezclar sus reglas.
- Se añadieron pruebas de caracterización para `LeadPortalResolver`, el universo
  legacy exacto de `CampaignLeadSyncService`, `dryRun`, fallback SOQL, doble
  upsert y prioridades auxiliares de Calls y Opportunities.
- No se modificó PHP productivo, SOQL productivo, frontend, rutas, configuración,
  esquema, datos ni reglas de negocio.

## Línea base Git

- Rama: `main`.
- Commit: `4a4037a1036f0ce32800e003abdb571289cd10a1`.
- Estado inicial: limpio; `origin/main` coincidía con HEAD.
- El commit de referencia solicitado
  `a6b7ef768d0c261e18508e902b846abfffc12707` no existe en los objetos Git
  locales. No fue posible calcular ahead/behind o merge-base y no se hizo fetch,
  reset ni checkout para alterar la línea base.

## Archivos modificados

- Nuevo `docs/auditoria-migracion-campos-salesforce.md`: matriz, flujos,
  comportamiento real, colisiones, doble escritura, riesgos y bloqueos.
- Modificado `docs/decisiones-negocio-pendientes.md`: decisiones que bloquean la
  migración futura, sin resolverlas.
- Nuevo `tests/Unit/LeadPortalResolverCharacterizationTest.php`: canal,
  prioridades, normalización, vacíos, fallback y source actuales.
- Nuevo `tests/Feature/CampaignLeadSyncCharacterizationTest.php`: filtro y SELECT
  legacy, cinco candidatos, exclusión, dry-run, fallback y doble upsert.
- Modificado `tests/Unit/OpportunityPortalResolutionTest.php`: prioridad del
  portal en el Lead relacionado.
- Modificado `tests/Feature/SalesforceCallSyncServiceTest.php`: consulta auxiliar
  y prioridad del Lead cuando `Task.Portales__c` no clasifica.
- Modificado este handoff. No se actualizó `PROJECT_CONTEXT.md` ni
  `DECISIONS.md`: no cambió arquitectura ni se adoptó una decisión difícil de
  revertir.

## Decisiones adoptadas

- Mantener exactamente las prioridades y universos existentes.
- Tratar `salesforce_leads.fuente_origen` y `.medio_origen` como columnas legacy
  alimentadas por `LEA_SEL_*`; no reutilizarlas para API Names nuevos.
- Documentar `Medio_origen__c` de SEO como integración existente aislada, no
  como precedente para Leads/Campañas.
- Congelar mediante test la doble escritura peligrosa de Campañas sin corregirla
  en esta fase.

## Base de datos y configuración

- Sin migraciones, nuevas columnas, índices, backfill ni cambios de `.env`.
- Sin configuración manual necesaria para esta fase.

## Seguridad

- No se usaron credenciales reales ni se llamó a Salesforce.
- Los clientes Salesforce de tests son mocks/stubs y usan datos sintéticos.
- No se escribieron tokens, secretos, datos personales ni payloads productivos
  en tests o documentación.
- Los endpoints y controles de autorización no cambiaron.

## Rendimiento

- No se añadieron queries de producción ni se cambiaron ventanas, chunks,
  reintentos, upserts, índices o cachés.
- La fase futura debe evaluar crecimiento del SELECT y del OR Salesforce,
  volumen adicional, doble escritura, backfill y reatribución masiva.

## Pruebas y verificaciones

- Línea base relacionada antes de cambios: `php artisan test` sobre 8 archivos
  relacionados: 115 pruebas, 795 aserciones, todo correcto.
- Caracterización inmediata: 25 pruebas, 106 aserciones, todo correcto. De
  ellas, 18 casos son nuevos (incluidos datasets del data provider).
- Suite relacionada final de Leads, Campañas, Calls, Opportunities,
  sincronizadores y SEO: 160 pruebas, 993 aserciones, todo correcto.
- Suite completa `php artisan test`: 778 pruebas, 5.679 aserciones, todo
  correcto.
- `php vendor/bin/pint --test` sobre los cuatro PHP modificados: correcto.
- `composer validate --no-check-publish --strict`: correcto.
- `composer audit --locked --no-dev`: sin vulnerabilidades conocidas.
- `npm run build`: correcto. Vite avisó de `/images/login-bg.jpg` resuelta en
  runtime y de una deprecación Node; no son fallos de este cambio. Los hashes
  generados por el build se restauraron porque no hubo cambios frontend.
- `git diff --check`: correcto.
- Fallos preexistentes observados: ninguno en las suites ejecutadas.
- Fallos introducidos: cero.

## Acciones manuales necesarias

- Ninguna para desplegar esta fase documental/de pruebas.
- Antes de implementar la migración, Dirección debe resolver las diez decisiones
  registradas en la auditoría y debe verificarse la metadata/FLS real de los API
  Names candidatos en un proceso separado y de solo lectura.

## Riesgos y pendientes

- Crítico: colisión semántica de columnas locales legacy y API Names nuevos.
- Crítico: ampliar el filtro Campañas modificaría universo y volumen.
- Crítico: el upsert parcial de Campañas puede sobrescribir columnas generales
  con `null`; además no hay transacción entre las dos tablas.
- Alto: prioridades diferentes entre dashboard, canalización legacy, Calls y
  Opportunities.
- Alto: Exposición tiene fallbacks exclusivos de owner/comercial.
- Pendientes funcionales completos en
  `docs/auditoria-migracion-campos-salesforce.md` y
  `docs/decisiones-negocio-pendientes.md`.

## Confirmación de no regresión

- Universo: NO modificado.
- Conteos: NO modificados.
- Reglas de negocio: NO modificadas.
- Prioridades: NO modificadas.
- Campos Salesforce nuevos: NO integrados.
- SOQL productivo: NO modificado.
- Backfill: NO realizado.
- Producción: NO modificada.

# Integración segura de campos Salesforce (Tarea 2, 2026-09-01)

## Resumen

- Metadata real de Lead validada con `SalesforceClient::describe('Lead')` y un
  SELECT de solo lectura: 14/14 campos existen y son consultables. No se
  imprimieron datos de Lead, URLs, tokens ni credenciales.
- Migración aditiva para conservar raw nuevo y legacy simultáneamente en
  `salesforce_leads` y `campaign_salesforce_leads`: clasificación 255,
  UTM 70, adquiridos legacy 255 y traza JSON. Sin índices nuevos.
- Resolver puro central para valor efectivo, campo ganador, fallback, conflicto
  y raw por fuente, canal, medio, delegación y cinco UTM.
- El sync mensual obtiene/persiste los nuevos campos como opcionales. Su query
  reducida omite esas columnas para no borrar valores previos.
- Campañas mantiene exactamente el WHERE legacy. Enriquece únicamente los Leads
  que ya pertenecían al universo y un Lead UTM-only sigue excluido.
- Se corrigió la doble escritura: Campañas conserva columnas generales y
  `raw_payload`; solo completa/actualiza adquisición y UTM no vacíos. Carga
  existentes por chunk, escribe en batch y usa una transacción corta por chunk.
- No se migraron los consumidores funcionales de Leads, Llamadas,
  Reservas/Ventas ni el attribution builder; los conteos visibles no cambian.

## Archivos

- Nuevos: migración `2026_09_01_090000_add_salesforce_lead_origin_and_utm_fields`,
  `SalesforceLeadFieldResolver` y tests del resolver/migración.
- Modificados: modelos `SalesforceLead` y `CampaignSalesforceLead`, sync mensual,
  sync de Campañas, tests de ambos sync, auditoría transversal, decisiones de
  negocio y este handoff.
- Eliminados: ninguno. No se actualizan `PROJECT_CONTEXT.md` ni `DECISIONS.md`:
  no cambia el límite de módulos ni se adopta una arquitectura difícil de
  revertir; se implementan reglas funcionales ya aprobadas.

## Base de datos y configuración

- Requiere ejecutar la nueva migración antes de activar el código.
- No hay variables de entorno, dependencias, índices ni configuración nueva.
- No se ejecutó migración sobre la base local, backfill ni resincronización.

## Seguridad y rendimiento

- Salesforce se usó solo mediante describe/SELECT. No hubo DML, secretos en
  fixtures o datos productivos en documentación.
- No hay request por Lead, describe en runtime ni N+1. Se conservan chunks y
  retries; la consulta SQL adicional de Campañas es una por chunk de 100.
- Las transacciones empiezan después de la llamada Salesforce y solo abarcan
  las dos escrituras locales. El `raw_payload` general completo nunca se
  reemplaza por el payload parcial de Campañas.

## Pruebas y verificaciones

- Baseline `php artisan test`: 795/795, 5.733 aserciones. Una primera pasada en
  competencia con metadata excedió el benchmark Stock; aislado pasó 2/2 (13) y
  la repetición completa secuencial quedó verde.
- Resolver final: 17/17, 80 aserciones.
- Focal sync + migración: 24/24, 221 aserciones.
- Regresión Leads/Campañas/Llamadas/Opportunities/Reservas/Comisiones: 143/143,
  1.069 aserciones.
- Suite completa final `php artisan test`: 816/816, 5.880 aserciones, correcta.
- Pint focal sobre los 10 PHP de esta tarea: correcto. El Pint global detecta
  deuda de formato preexistente en numerosos archivos ajenos; no se reformateó
  código fuera del alcance.
- `composer validate --no-check-publish --strict`: correcto.
- `composer audit --locked --no-dev`: sin vulnerabilidades conocidas.
- `npm run build`: correcto; avisos no bloqueantes ya conocidos de
  `login-bg.jpg` en runtime y deprecación Node. Se restauraron exclusivamente
  los artefactos generados porque no existe cambio frontend.
- `git diff --check`: correcto. Fallos introducidos: cero.

# Correctivo PR #27 — hidratación legacy de Campañas (2026-09-03)

- Se restauró exactamente la política histórica de
  `fillCampaignFieldsFromRawPayload()` para los doce campos ya consumidos en
  `main`: un valor local no válido según `CampaignValueNormalizer` puede
  recuperarse desde un raw legacy informado.
- Los campos incorporados por la migración permanecen en un bloque separado con
  política blank-only. Un placeholder nuevo no vacío sigue siendo autoritativo
  y no se sustituye desde `raw_payload`.
- El gate, WHERE, Meta Direct Form, first touch, claiming, Opportunities y la
  resolución efectiva de Fase 6 no cambian. No hay consultas, migraciones,
  dependencias, PII ni accesos externos nuevos.
- Regresión focal específica: 13 tests y 28 aserciones. Focal completo de
  Campañas: 97 tests y 748 aserciones. Suite completa: 856 tests y 6.155
  aserciones. Pint write + `--test` sobre los dos PHP del PR: correcto.
- No se ejecutaron backfills, sincronizadores reales ni escrituras en
  Salesforce, Meta o Google. No requiere acciones manuales adicionales al
  despliegue ordinario una vez aprobado el PR.

# Fase 6 — Migración de atribución de Campañas (2026-09-03)

## Resumen y decisiones

- Base utilizada: `origin/main` en
  `95d2254c2b8a9f0c31a71325e17bbea9732dddc5`. Rama:
  `feature/salesforce-campaign-attribution-migration`.
- El WHERE del sync y el gate del builder siguen siendo legacy. Los Leads UTM-only
  continúan excluidos y Meta Direct Form conserva su admisión por portal Meta más
  fuente legacy Facebook.
- Tras admitir el Lead, `SalesforceLeadFieldResolver` resuelve de forma
  independiente campaña, ID, fuente adquirida, medio adquirido y contenido.
  Cualquier nuevo no vacío gana, incluidos placeholders; solo null/vacío/
  whitespace usa fallback.
- Matching, first touch, claiming, deduplicación y ambigüedad no cambian de
  algoritmo. `matched_source_field` identifica el API Name ganador. La versión
  de regla pasa a `2026-09-03.1`.
- La clasificación efectiva para Meta usa `Fuente_origen__c` →
  `LEA_SEL_Fuente_Origen__c` después del gate. `source_acquired` y
  `medium_acquired` usan las parejas UTM adquiridas, no `LEA_SEL_*`.

## Archivos, base de datos y operación

- Producción: `CampaignAttributionBuilderService`.
- Pruebas: nueva regresión `CampaignAttributionFieldMigrationTest`.
- Documentación: auditoría Salesforce, informe de Campañas, documentación
  general, decisiones pendientes y este handoff.
- Sin migraciones, esquema, configuración, frontend ni dependencias. La lectura
  mantiene chunks y consultas existentes; la resolución es O(1) en memoria por
  Lead y no añade N+1 ni llamadas externas.
- `raw_payload` solo hidrata columnas vacías; no sustituye valores locales
  informados y no se copia a trazas. Los diagnósticos agregan campos ganadores
  por dimensión sin nombres, emails ni teléfonos.

## Validación y pendientes

- Baseline focal previo: 84 tests, 720 aserciones, correcto.
- Focal tras implementación: 94 tests, 742 aserciones, correcto.
- Suite completa: 853 tests, 6.149 aserciones, correcta. Pint se aplicó en modo
  write y `--test` quedó verde sobre los dos PHP modificados.
- Composer validate: correcto. Composer audit PHP: sin vulnerabilidades
  conocidas. Build Vite: correcto; los artefactos regenerados se restauraron al
  no existir cambio frontend. `git diff --check`: correcto.
- `npm ci` informó 5 advisories de desarrollo del lock actual (3 high, 2
  critical); `npm audit --omit=dev` informó 0 vulnerabilidades de producción.
  No se modificaron dependencias dentro de esta migración funcional.
- No se ejecutaron sincronizaciones reales, backfill ni escrituras en
  Salesforce/Google/Meta. Continúa pendiente validar con datos autorizados la
  semántica de `utm_id__c` por plataforma y medir el volumen UTM-only.

# Fase 5 — Procedencia nuevo → legacy en Reservas/Ventas (2026-09-03)

## Resumen y archivos

- `SalesforceOpportunitySyncService` prioriza `Lead.Fuente_origen__c` cuando
  una Opportunity no tiene `Portal__c` concluyente. El fallback específico se
  mantiene como `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` →
  `Fuente_Nuevo__c`.
- Se ampliaron las pruebas de resolución, sync, reproceso y fallback
  `leads_raw`, además de la documentación de auditoría, Reservas/Ventas,
  contraste general y decisiones pendientes.
- No se modificaron `OpportunityPortalNormalizer`, el comando de reproceso, el
  dataset, modelos, migraciones, esquema, frontend ni otros informes.

## Decisiones, seguridad y rendimiento

- `SalesforceLeadFieldResolver` es la autoridad para null/vacío/whitespace. Un
  valor nuevo no vacío, incluso placeholder o no normalizable, selecciona el
  Lead y bloquea legacy, fuente de Opportunity y fallbacks posteriores.
- `Opportunity.Portal__c` concluyente conserva prioridad absoluta. El matching
  por email/teléfono, `CreatedDate DESC`, chunks y todos los WHERE permanecen
  intactos; sync y reproceso comparten `resolvePortalForRecord()`.
- La traza añade exclusivamente fuente nueva, legacy, campos ganadores,
  fallback y conflicto. No añade teléfono, email, nombres ni payloads completos.
- `leads_raw` lee `Fuente_origen__c` desde `raw_payload`; no requiere columna ni
  consulta adicional. No hay N+1 ni consultas remotas por Opportunity.

## Base de datos, validación y operación

- Base: `origin/main` en `29210aae97fc329dc7bf41fc855a1c7e791e90ae`.
- Migraciones/configuración: ninguna. No requiere variables de entorno nuevas.
- PHP, Composer y Pint no estaban accesibles localmente al iniciar la tarea;
  la validación ejecutable queda registrada en la entrega final y deberá
  completarse en CI antes del merge.
- Baseline, pruebas focales, suite, Pint y Composer no se ejecutaron por esa
  limitación. La revisión estática de imports/constructores y
  `git diff --check` fueron correctas.
- No se ejecutaron sync Salesforce, reproceso histórico, backfill ni escrituras
  remotas. Acción posterior: abrir PR y validar CI; no ejecutar reproceso de
  datos como parte de esta fase.

## Riesgos pendientes

- La clasificación histórica no cambia hasta un reproceso posterior aprobado.
- `CampaignAttributionBuilderService` y las decisiones pendientes de matching
  temporal/ConvertedOpportunityId continúan expresamente fuera de alcance.

# Fase 3 — Clasificación nuevo → legacy de Leads (2026-09-02)

## Resumen

- Se resolvió el residuo de merge de este documento conservando completas las
  secciones de Tasador y coherencia UTM.
- `LeadClassificationResolver` compone el resolver central de campos nuevos
  con `LeadPortalResolver` como fallback legacy inalterado. Fuente, canal,
  medio y delegación priorizan sus nuevos raw persistidos únicamente cuando no
  son null, vacíos o whitespace.
- Sync, dashboard y el backfill local emplean la misma composición. El
  dashboard recalcula desde raw locales y no confía en `resolved_*` heredados.
  Los audit outputs incorporan raws, resolución, conflicto y fallback por
  dimensión. No se ejecutó el backfill.

## Alcance y seguridad

- Sin cambios de WHERE, universo de Leads, KPIs no clasificatorios,
  delegación comercial, Calls, Reservas/Ventas ni funcionalidad de Campañas.
- No se hicieron DML Salesforce, backfills históricos, migraciones, cambios de
  configuración ni consultas remotas durante la lectura del dashboard.

## Validación pendiente de entorno

- La sesión no expone un ejecutable PHP (ni `PATH` ni las rutas Herd locales
  habituales), por lo que las pruebas, Pint y verificaciones Composer quedan
  pendientes de ejecutarse en un entorno con PHP disponible antes de integrar.

## Corrección de revisión: autorización y trazabilidad

- La clasificación visible de delegación sigue usando nuevo → legacy, pero el
  scope de autorización usa una delegación interna calculada exclusivamente
  con la cadena legacy previa y el fallback contextual de Exposición. Así, un
  valor de `Delegacion_procedencia__c` no amplía ni retira permisos.
- La auditoría ahora muestra la delegación normalizada final y su origen
  efectivo. Cuando Exposición resuelve fuera de `field_resolution`, la traza
  identifica `persona_que_trabajo_delegation`, `owner_delegation` o
  `salesforce_users.user_delegation`, sin falsear un API Name Salesforce.
- Se añadieron regresiones de scope, fallback contextual, materialización del
  sync y backfill local. No se ejecutaron en esta sesión por la ausencia del
  runtime PHP/Composer; deben validarse en CI antes de integrar.

## Corrección CI PR #24: normalización y CSV

- El CI detectó expectativas no canónicas: `Madrid` y `HR MOTOR MADRID`
  normalizan a `Madrid General`; se corrigieron exclusivamente las assertions
  de Fase 3, sin cambiar el normalizador ni la autorización.
- El CSV de conciliación serializa las trazas de resolución estructuradas como
  JSON UTF-8 al emitir cada celda. Las respuestas JSON conservan los arrays y
  los booleanos CSV siguen siendo `Si`/`No`. No se afirma CI verde hasta que
  GitHub complete la nueva ejecución.

# Fase 4 — Procedencia nuevo → legacy de Llamadas (2026-09-02)

- Se añadió una política pura específica de Calls; no usa el resolver del
  dashboard de Leads porque su prioridad legacy es distinta. `Fuente_origen__c`
  gana únicamente en la clasificación visible cuando el fallback relacionado
  ya aplica; null/vacío/whitespace conservan `Portal_Text__c` →
  `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`.
- Sync y reproceso local comparten la política. El reproceso obtiene Leads por
  lote desde persistencia local y conserva la clasificación previa si falta un
  Lead necesario; no consulta Salesforce ni se ha ejecutado sobre datos reales.
- La clasificación operacional legacy permanece separada para origen, duración
  y overflow. Se incrementó `CallClassificationRules::VERSION` por el cambio
  de política visible. No cambiaron Task WHERE, equipos, delegaciones, zonas,
  estados, universos ni otros informes.
- Correctivo de revisión: si el reproceso necesita un Lead pero este no existe
  localmente, conserva `portal_resolved`, fuente de resolución y los cuatro
  valores operativos persistidos (`call_origin`, duración ajustada, overflow y
  motivo), dejando `lead_unavailable_locally` en la traza. Nunca usa el portal
  visible nuevo como sustituto del portal operativo legacy.

## Acciones manuales y pendientes

- En despliegue: aprobar/ejecutar migración y después sincronizar ventanas
  ordinarias según el runbook; esta tarea no ejecuta backfill histórico.
- Pendiente técnico: semántica de `utm_id__c` por plataforma, casos reales
  Google/Meta, medición UTM-only fuera del universo legacy y diseño del backfill.
- Pendiente de implementación: migrar conscientemente Calls,
  Reservations/Sales y `CampaignAttributionBuilderService` en tareas separadas.

## Confirmación

- Universos de Leads, Campañas, Opportunities y Tasks: NO modificados.
- Conteos y clasificación visible: NO modificados.
- `CampaignAttributionBuilderService`: NO modificado.
- Backfill, escritura Salesforce, producción y frontend: NO modificados.
- Campos legacy eliminados o reutilizados: NO.

# Desglose de compras Tasador (2026-09-01)

## Resumen y decisiones

- Desde junio de 2026, `buildAppraiserMonthlySummaryRow()` mantiene un único
  tramo calculado sobre el total conjunto de oportunidades `Tasacion` y
  `Cambio`, pero ahora publica también sus importes desglosados.
- `appraisals_amount` y `changes_amount` se calculan con el mismo rate y
  `purchases_amount` se deriva de su suma. La comisión final continúa usando
  exclusivamente `purchases_amount`, por lo que el correctivo no duplica ni
  modifica el importe pagado.
- No se modificaron tramos, universos, filtros, detalle de compras, lógica
  histórica anterior a junio, frontend ni contratos públicos.

## Archivos y operación

- Modificados: servicio del dashboard comercial, su prueba feature, cálculo
  funcional documentado y este handoff.
- Base de datos, migraciones, configuración, variables de entorno y assets: sin
  cambios. No requiere acciones manuales distintas del despliegue ordinario.

## Seguridad y rendimiento

- El cálculo reutiliza las operaciones ya cargadas, sin SQL, HTTP ni Salesforce
  adicionales. Las colecciones se separan una vez y mantienen complejidad O(n).
- No se incorporan IDs, nombres reales, credenciales ni datos de producción.

## Pruebas

- Regresiones focalizadas iniciales del Tasador: 5 pruebas y 52 aserciones,
  correctas. Cubren 8 Tasaciones + 2 Cambios, tipos aislados, tramo 0-7 y el
  escenario vigente con ventas, financiación y rapidez.
- Test exacto preexistente del Tasador: 1/1 y 15 aserciones. Dashboard completo:
  54/54 y 446 aserciones. API de Comisiones: 22/22 y 138 aserciones.
- Suite completa: 820/820 pruebas y 5.922 aserciones, correcta. Pint focal sobre
  los dos PHP modificados, lint PHP y `git diff --check`: correctos.

# Corrección de coherencia UTM en doble escritura (2026-09-01)

## Resumen

- Causa: el upsert secundario de Campañas mezclaba correctamente los diez raw
  de adquisición/UTM, pero no leía ni actualizaba `field_resolution`; una fila
  general podía conservar una resolución UTM calculada sobre valores antiguos.
- Solución: la lectura masiva existente incorpora `field_resolution`. Después
  de aplicar la preservación incoming no vacío → incoming / vacío → existente,
  recalcula los cinco nodos UTM con `SalesforceLeadFieldResolver` y los mezcla
  sobre el JSON general existente.
- Autoridad: Campañas solo puede sustituir `utm_campaign`, `utm_id`,
  `utm_source`, `utm_medium` y `utm_content`. Conserva exactamente `source`,
  `channel`, `medium`, `delegation`, cualquier otro nodo y `raw_payload`.
- Robustez: JSON nulo, parcial o inválido produce cinco nodos UTM válidos sin
  inventar dimensiones generales ni bloquear el chunk.
- Rendimiento/atomicidad: una única lectura por chunk de 100, upsert masivo,
  misma transacción corta y mismos retries; sin N+1 ni llamadas externas.

## Archivos y alcance

- Modificados: `CampaignLeadSyncService`, su test de caracterización, auditoría
  transversal y este handoff.
- Sin migraciones, configuración, frontend, rutas, Salesforce, backfill ni
  cambios en sync mensual, resolver central, attribution builder, Calls,
  Opportunities o Reservations/Sales.

## Validación

- Baseline antes del cambio: `php artisan test`, 816/816 y 5.880 aserciones.
- Focal Campañas + resolver: 24/24 y 205 aserciones.
- Suite completa final: 817/817 y 5.934 aserciones.
- Pint focal sobre los dos PHP modificados: correcto. Pint global conserva
  exclusivamente deuda preexistente en archivos ajenos; no se reformateó fuera
  de alcance.
- Composer validate: correcto. Composer audit: sin vulnerabilidades conocidas.
- Build Vite: correcto, con avisos preexistentes de imagen runtime y deprecación
  Node; artefactos regenerados restaurados al no existir cambio frontend.
- `git diff --check`: correcto. Fallos introducidos: cero.

# Fase 7A — Backfill histórico controlado de atribución Lead (2026-09-03)

## Resumen y archivos

- Se añadió el comando `salesforce:backfill-lead-attribution-fields`, su servicio
  por lotes, el modelo/migración de histórico y una suite feature dedicada.
- Se actualizaron la auditoría Salesforce, la guía general, el estado de
  decisiones pendientes, la decisión arquitectónica de backfill y este
  handoff. `PROJECT_CONTEXT.md` no cambia porque no se altera la estructura
  general de módulos ni una convención transversal de la aplicación.

## Decisiones y base de datos

- El universo se obtiene mediante la unión ordenada de IDs ya existentes y
  dentro del rango local en `salesforce_leads` y
  `campaign_salesforce_leads`. Salesforce solo recibe consultas `Lead WHERE Id
  IN (...)` sobre IDs 00Q validados.
- Se creó `salesforce_lead_attribution_backfill_history` porque los mecanismos
  existentes no guardaban before/after por fila para ambas tablas. Conserva
  run UUID, tabla, Salesforce ID, motivo, campos cambiados, valores anteriores/
  nuevos y fecha. No guarda PII ni payloads completos.
- Las escrituras son UPDATE masivos y transaccionales por chunk junto con el
  histórico. No existe ruta de insert de Lead. Los resolvers centrales calculan
  `field_resolution` y las materializaciones del dashboard.

## Seguridad y rendimiento

- `--from`/`--to` y un modo exclusivo son obligatorios; `--apply` requiere un
  motivo de al menos 10 caracteres. Dry-run no escribe tablas, histórico ni
  cachés.
- Se consultan solo Id y once campos de atribución, sin nombre, email o teléfono.
  El proceso usa lotes de 100, una consulta Salesforce por lote, lecturas SQL
  agrupadas, UPDATE masivo y transacción corta. No hay N+1 ni red desde HTTP.
- `raw_payload` conserva todas sus claves y solo fusiona las once consultadas;
  el histórico registra únicamente ese subconjunto. Las cachés concretas se
  versionan solo después de cambios aplicados.

## Pruebas y operación

- La suite nueva cubre validación de modo/rango/motivo, dry-run, apply,
  idempotencia, tablas general/especializada, IDs extra/ausentes/inválidos,
  UTM-only, cursor/limit, fallo Salesforce entre lotes y rollback conjunto ante
  error DB.
- Test específico: 9 pruebas y 74 aserciones, correcto. Bloque focal con
  resolvers, sync mensual y Campañas: 56 pruebas y 394 aserciones, correcto.
  Suite completa: 865 pruebas y 6.229 aserciones, correcta. Pint WRITE y
  `--test`, Composer validate/audit, build Vite y `git diff --check`: correctos.
  El build conserva los avisos preexistentes de imagen runtime y deprecación de
  Node; sus artefactos hash se restauraron porque no hay cambio frontend.
- La herramienta está preparada, pero el histórico todavía **NO ha sido
  modificado**. No se ejecutaron `--apply`, backfill real, sincronizadores,
  Calls, Opportunities, reconstrucción de Campañas ni escrituras Salesforce.
- Acción manual posterior: desplegar la migración, revisar primero una ventana
  con `--dry-run`, aprobar motivo/rango y ejecutar `--apply` únicamente mediante
  el runbook operativo correspondiente.

## Correctivo de consistencia previo al merge del PR #28

- La identidad usada para conciliar Salesforce IDs es ahora los primeros 15
  caracteres, conservando exactamente mayúsculas y minúsculas. Las variantes
  local 15 / respuesta 18 se reconocen como el mismo Lead; un cambio de casing
  en esos 15 caracteres continúa representando una identidad distinta.
- El cursor sigue ordenando el `salesforce_id` local literal. Dentro de cada
  lote, representaciones 15/18 equivalentes comparten una consulta lógica y
  cada fila local existente conserva su clave original.
- La consulta Salesforce permanece fuera de la transacción. En `--apply`, las
  filas objetivo se releen con `lockForUpdate()` y `ORDER BY salesforce_id`; solo
  después se fusiona el `raw_payload`, se recalcula la resolución y se construye
  el before/after. Así se preservan cambios concurrentes confirmados por el sync
  periódico y el histórico describe el estado realmente bloqueado.
- Un lock atómico de Laravel sobre el store de caché configurado protege apply
  frente a apply durante seis horas y se libera en `finally`. Dry-run no toma
  este mutex. El lock no sustituye el bloqueo transaccional frente a otros
  escritores.
- Las regresiones cubren equivalencia 15/18, sensibilidad a casing, coexistencia
  de representaciones entre tablas, cursor literal mixto, snapshot concurrente,
  before real y exclusión mutua. La herramienta sigue sin haberse ejecutado
  sobre datos reales y las escrituras Salesforce continúan siendo cero.
- Validación local: backfill 16/16 y 114 aserciones; bloque focal completo 63/63
  y 434 aserciones; suite completa 872/872 y 6.269 aserciones. Pint WRITE y
  `--test`, Composer validate/audit, build Vite y `git diff --check` correctos.
  El build solo emitió los avisos preexistentes y sus artefactos se restauraron
  porque este correctivo no incluye frontend. No hay migraciones nuevas.

# Fase 7B — Reproceso histórico seguro de portales de Opportunities (2026-09-03)

## Resumen y archivos

- Se endureció `reports:reprocess-opportunity-portals` y se extrajo la operación
  a `OpportunityPortalReprocessService`; no se creó un comando paralelo.
- Se añadieron el modelo y la migración de
  `salesforce_opportunity_portal_reprocess_history` y se amplió la suite feature
  del comando. También se actualizaron las guías de Reservas/Ventas, auditoría,
  decisiones y contexto general. `PROJECT_CONTEXT.md` no cambia porque el módulo
  y sus convenciones generales permanecen iguales.

## Decisiones, seguridad y base de datos

- El universo nace únicamente de `salesforce_opportunities` local y del rango
  `[from, to)`. Dry-run/apply son exclusivos; apply exige motivo, toma un mutex
  de seis horas y solo actualiza seis campos de atribución más `updated_at`
  cuando existe un cambio real.
- Salesforce se usa en lectura exclusivamente para el matching Lead agrupado ya
  existente. No se consulta Opportunity ni se invocan create/update remotos.
  No se registra PII en métricas, errores o histórico.
- La tabla nueva guarda run UUID, IDs técnicos, motivo, campos modificados,
  before/after y fecha. El debug se filtra por whitelist y no se guarda
  `raw_payload`. UPDATE e histórico comparten transacción por chunk.

## Concurrencia y rendimiento

- Los chunks son de 100 y avanzan por PK local. La consulta Lead ocurre antes de
  abrir la transacción. Las filas se releen ordenadas con `lockForUpdate()` y se
  valida una huella de todos los inputs de resolución; cualquier cambio fuerza
  reconsulta y reintento, con máximo de tres.
- No hay N+1, HTTP bajo locks, insert/upsert/delete de Opportunity ni
  `Cache::clear()`. La versión de caché se incrementa una vez si hubo cambios
  confirmados, incluso cuando falla un chunk posterior.

## Pruebas y operación

- Baseline previo: 34 pruebas y 166 aserciones, correcto. Suite focal ampliada:
  91 pruebas y 735 aserciones, correcta. Suite completa: 882 pruebas y 6.368
  aserciones, correcta. Pint WRITE y `--test`, Composer validate/audit, build
  Vite y `git diff --check`: correctos. El build solo emitió los avisos
  preexistentes y sus artefactos se restauraron al no existir cambio frontend.
  `npm ci` informó 5 advisories del lock actual (3 high, 2 critical); no se
  alteraron dependencias porque su remediación queda fuera de esta fase.
- La herramienta está preparada, pero el reproceso histórico **NO se ha
  ejecutado** y tampoco se ha realizado un dry-run productivo. Escrituras
  Salesforce: cero. Tras desplegar la migración, cualquier operación real
  requiere conciliación, aprobación y runbook separados.

# Hotfix — Scheduler de Leads Salesforce de Campañas (2026-09-03)

## Resumen y decisiones

- Se añadió al scheduler monitorizado
  `salesforce:sync-campaign-leads --days=120` a las 01:15 `Europe/Madrid`, antes
  de Meta 01:30, Google 01:45, atribución 02:15 y snapshot 03:15.
- Usa `withoutOverlapping(180)`, identificador estable
  `salesforce-sync-campaign-leads` y la infraestructura `$monitor`; un fallo
  abre la alerta operacional existente y un éxito posterior la resuelve.
- El schedule no usa `--fresh`. No se modificaron comando, servicio, SOQL,
  batching, reglas de atribución, universos ni horarios de otras tareas.

## Seguridad, rendimiento y operación

- Se conserva el upsert incremental por lotes y no se añaden queries por Lead.
  El hotfix no contiene credenciales, PII, dependencias, migraciones ni cambios
  de caché adicionales.
- No se ejecutaron sincronización productiva, backfill, reproceso ni escrituras
  Salesforce. Baseline de Campañas: 18 pruebas y 81 aserciones; focal final: 19
  pruebas y 92 aserciones; suite completa: 883 pruebas y 6.379 aserciones.
  `schedule:list`, Pint WRITE/`--test`, Composer validate/audit, build Vite y
  `git diff --check` fueron correctos. Los artefactos del build se restauraron.
  `npm ci` informó 5 advisories del lock actual (3 high, 2 critical), sin cambios
  de dependencias por quedar fuera del alcance de este hotfix.

# Hotfix — Conciliación dry-run de atribución de Campañas (2026-09-04)

## Resumen y decisiones

- `simulationSummary()` solo clasifica `became_unattributed` cuando la fila
  actual tenía `campaign_id` o `campaign_name` y el Lead permanece simulado sin
  identidad. Un Lead sin atribuir que continúa sin atribuir ya no es un falso
  positivo.
- Tras recorrer las filas simuladas se comparan sus Lead IDs con las claves de
  las atribuciones actuales. Las filas actuales ausentes se contabilizan en
  `removed_attribution`, separadas de los Leads que permanecen y pierden campaña.
- Se reutiliza un helper único para reconocer identidad de campaña. No cambian
  `resolveCampaign`, matching, UTM, first touch, exclusiones, persistencia,
  versión de reglas, queries ni universo.

## Seguridad, rendimiento y pruebas

- La conciliación continúa O(n), usando únicamente arrays ya cargados y sin
  queries dentro de los loops. No se añade PII a salida o logs.
- Se añadió una caracterización matricial para atribución igual, pérdida,
  continuidad sin campaña, fila eliminada, alta, cambio de campaña, cambio de
  método y ambigüedad nueva/resuelta. Baseline focal: 32 pruebas y 120
  aserciones; focal tras el cambio: 33 pruebas y 131 aserciones. Suite completa:
  884 pruebas y 6.390 aserciones. Pint WRITE/`--test`, Composer validate/audit,
  build Vite y `git diff --check` fueron correctos; los artefactos del build se
  restauraron al no existir cambio frontend.

# Hotfix — Invariancia del matching Opportunity → Lead (2026-09-04)

## Causa y decisión

- `relatedLeadMatches()` reunía teléfonos brutos de todo el lote y
  `resolvePortal()` comparaba después teléfonos normalizados. Un Lead recuperado
  gracias a otra Opportunity podía convertirse en candidato más reciente de la
  Opportunity objetivo. Además, cualquier resultado Salesforce del grupo
  suprimía globalmente el fallback `leads_raw`.
- Los teléfonos de consulta parten de la clave canónica existente y generan
  siete variantes exactas y finitas para `IN`: compacto, espacio, guion, punto,
  `34` compacto y `+34` compacto/con espacios. No existe `LIKE` con wildcard.
  La validación final sigue usando `normalizePhone()`.
- El fallback local se calcula para cada email sin respuesta remota. El fallback
  telefónico de `leads_raw` se elimina porque requería
  `whereNotNull(raw_payload)->get()` sin índice telefónico.
  Los candidatos se deduplican y ordenan por `CreatedDate` descendente más
  `Lead.Id` ascendente como desempate total.

## Alcance, seguridad y rendimiento

- La política compartida cubre sync normal, `syncBySalesforceIds()` y
  `reports:reprocess-opportunity-portals`. Se agrupan 80 emails o 20 identidades
  telefónicas. Cada consulta de teléfono contiene como máximo 140 valores
  exactos repetidos en dos condiciones `IN`, tres condiciones lógicas contando
  `IsDeleted`, y menos de 5.000 caracteres SOQL en el test máximo. Un chunk de
  100 Opportunities requiere como máximo cinco consultas telefónicas, nunca
  una por Opportunity.
- No cambian precedencia, universo, reservas, ventas, contratos, caídas,
  conteos ni campos raw. No se añadieron migraciones o dependencias y no se
  ejecutaron reproceso histórico, comandos productivos ni escrituras Salesforce.
- La regresión cubre los dos patrones observados, email, formatos `+34` y
  separadores, empate temporal y posiciones a ambos lados de lotes mayores de
  100 Opportunities.
- El primer correctivo partió de 31 pruebas/237 aserciones y sus regresiones
  reprodujeron el defecto antes del cambio. Tras endurecer rendimiento, el focal
  final suma 39 pruebas/287 aserciones; Reservas/Ventas, 93/790; y la suite
  completa, 903/6.537, todas correctas. Pint WRITE y `--test`, Composer
  validate/audit, build Vite y `git diff --check` fueron correctos; los
  artefactos del build se restauraron.
- No se ejecutaron reconstrucción real, backfill, reproceso, sincronización
  productiva ni escritura Salesforce.

# Hotfix — Scheduler de Llamadas Salesforce (2026-09-04)

## Resumen y decisiones

- Se añadió al scheduler monitorizado `salesforce:sync-calls --days=7`, una vez
  al día a las 04:45 `Europe/Madrid`, entre responsables de delegación (04:15) y
  el comienzo del bloque SEO (05:15).
- La tarea usa `withoutOverlapping(60)`, el identificador estable
  `salesforce-sync-calls` y la infraestructura `$monitor`; un fallo abre la
  alerta operacional existente y un éxito posterior la resuelve.
- La ventana móvil de siete días aporta solape operativo y conserva el
  procesamiento idempotente existente. El schedule no usa `--fresh` ni
  `--debug-soql`.

## Alcance, seguridad y rendimiento

- No se modificaron `SalesforceSyncCallsCommand`, `SalesforceCallSyncService`,
  SOQL, universo, clasificación, duración, overflow, batching ni horarios de
  otras tareas. No se añadieron consultas, dependencias o migraciones.
- No se ejecutaron sincronización productiva, reproceso, backfill ni escrituras
  Salesforce. Baseline focal: 21 pruebas y 114 aserciones; focal final: 18
  pruebas y 113 aserciones; suite completa: 885 pruebas y 6.402 aserciones.
  `schedule:list`, Pint WRITE/`--test` sobre los PHP del hotfix, Composer
  validate/audit, build Vite y `git diff --check` fueron correctos; los
  artefactos del build se restauraron. El barrido Pint global sigue señalando
  infracciones preexistentes en PHP ajenos al diff, que no se reformatearon.

# Hotfix — Integridad temporal de Opportunities creadas por Stock (2026-09-04)

## Causa y solución

- `SalesforceSignedSaleSyncService` seleccionaba `LastModifiedDate`, omitía
  `CreatedDate` y no materializaba ninguno. Las Opportunities creadas primero
  por `stock:sync-daily` podían quedar con `created_date` y
  `salesforce_last_modified_at` nulos, fuera de reprocesos por rango temporal.
- El sync parcial de Stock ahora solicita ambos campos y los persiste con el
  mismo parsing Carbon del sincronizador principal. No cambian su WHERE,
  universo, snapshots, importes ni reglas de Stock/Reservas/Ventas.
- Se añadió `salesforce:repair-opportunity-lifecycle-dates`, exclusivamente
  manual. Selecciona filas locales con `created_date IS NULL`, valida IDs `006`,
  consulta Salesforce solo mediante `SELECT Id, CreatedDate, LastModifiedDate`
  en lotes de hasta 100 y no usa `syncBySalesforceIds()`.

## Integridad, seguridad y rendimiento

- Dry-run no escribe. Apply exige motivo de 10–500 caracteres, usa un mutex de
  seis horas, consulta Salesforce antes de la transacción y relee las filas con
  `lockForUpdate()`. Un UPDATE bulk condicionado por `created_date IS NULL`
  puede modificar únicamente `created_date` y `salesforce_last_modified_at`.
- No existen inserts/deletes de Opportunity, invalidación de caché, DML
  Salesforce, PII, reemplazo de `raw_payload` ni cambios en portal/fuente. Las
  muestras de métricas contienen exclusivamente Salesforce IDs.
- La migración nueva crea `salesforce_opportunity_date_repair_runs`, auditoría
  mínima por ejecución apply con UUID, motivo, estado, inicio/fin y contadores;
  se separa del historial de portales por no compartir semántica funcional.
- La herramienta queda preparada pero no se ejecutó ninguna reparación real,
  sincronización productiva ni escritura Salesforce. Baseline focal: 18 pruebas
  y 135 aserciones; focal final: 40 pruebas y 327 aserciones. La segunda suite
  completa quedó verde con 895 pruebas y 6.487 aserciones. La primera corrida
  solo excedió el umbral temporal del benchmark preexistente de recomendaciones
  de Stock (27,95 s); aislado pasó en 9,38 s sin cambios en ese código.
- Pint WRITE/`--test` sobre los PHP del hotfix, Composer validate/audit, build
  Vite y `git diff --check` fueron correctos; los artefactos del build se
  restauraron al no existir cambio frontend.

# Índice telefónico normalizado para Opportunity → Lead (2026-09-07)

## Resumen y archivos

- Se añaden `salesforce_leads.phone_normalized` y
  `mobile_phone_normalized`, `VARCHAR(255)` nullable con índices individuales.
  La longitud conserva cualquier salida posible de los campos raw
  `VARCHAR(255)` después de eliminar caracteres no numéricos. La migración es
  exclusivamente estructural y no incorpora backfill de datos.
- `SalesforcePhoneNormalizer` centraliza sin cambios la semántica que ya usaba
  Reservas/Ventas: elimina caracteres no numéricos y retira `34` solo si quedan
  exactamente nueve dígitos. El sync mensual y el camino que inserta Leads
  generales desde Campañas materializan las claves con ese componente.
- Inventario de escritores revisado: `SalesforceMonthlyLeadsSyncService` es el
  escritor general de teléfono/móvil y de los estados `is_deleted`; su query
  reducida omite ahora también ambas claves para conservarlas. La conciliación
  de ausentes y `syncDeleted()` solo cambian el estado de borrado y no limpian
  claves. `CampaignLeadSyncService` solo puede aportar teléfono a
  `salesforce_leads` cuando crea la fila general ausente, por lo que normaliza
  ese insert; no sobrescribe teléfonos de filas generales existentes. Los
  backfills de atribución y auditoría revisados no escriben teléfono ni estado
  de borrado.
- `SalesforceOpportunitySyncService` consulta localmente por igualdad indexada
  e `is_deleted = false`, recoge únicamente Salesforce Lead IDs y reconsulta
  esos candidatos vivos mediante `Id IN (...)`. Fecha, contacto y procedencia
  final proceden siempre de Salesforce vivo. El fallback remoto exacto de siete
  variantes queda limitado a teléfonos sin candidatos locales; no existe
  `LIKE`, búsqueda regex ni fallback telefónico en `leads_raw`.
- Se crea el comando local
  `salesforce:backfill-lead-phone-normalization`, con modos mutuamente
  excluyentes `--dry-run`/`--apply`, `--limit`, `--after-id`, mutex apply de seis
  horas, lectura y UPDATE bulk por chunks. No consulta Salesforce, no cambia
  teléfonos raw ni actualiza timestamps del Lead.

## Seguridad, rendimiento y despliegue

- La fotografía local solo descubre IDs. Un candidato ausente de Salesforce,
  borrado, no elegible o cuyo contacto vivo ya no coincide se descarta en la
  validación final. No se añaden PII a métricas o logs y no existen escrituras
  Salesforce.
- Por 100 Opportunities se realiza como máximo una consulta local por cada 100
  claves telefónicas. Las reconsultas por ID se agrupan en 100 IDs; el fallback
  remoto agrupa 20 identidades y conserva las consultas de email de 80 valores.
  El número por ID es `ceil(candidatos_locales / 100)` y no existe consulta por
  Opportunity o Lead.
- En MySQL la migración ejecuta dos adiciones de columna y dos índices sobre una
  tabla grande. Debe programarse en ventana controlada y comprobarse el soporte
  de DDL online de la versión concreta; rollback elimina índices y columnas,
  con el mismo riesgo operativo de ALTER. Primero migrar, después dry-run del
  backfill, luego apply explícito y finalmente validar cobertura antes de un
  reproceso histórico de Opportunities.
- No se ejecutaron migración, backfill, reproceso, sincronización productiva ni
  escrituras Salesforce. Baseline focal: 45 pruebas y 356 aserciones. Focal
  final: 74 pruebas y 556 aserciones; suite completa: 925 pruebas y 6.612
  aserciones. Pint WRITE/`--test`, Composer validate/audit, build Vite y
  `git diff --check` fueron correctos. Los artefactos generados por Vite se
  restauraron. El diff permanece sin commit por instrucción expresa.

# Lifecycle de Opportunities eliminadas/ausentes (2026-09-07)

## Resumen y archivos

- Se añaden de forma aditiva `salesforce_opportunities.is_deleted` (boolean,
  default false, sin índice), `salesforce_deleted_at` y
  `deletion_detection_source`, además de una tabla mínima de auditoría de
  ejecuciones de reconciliación. La migración no modifica datos históricos.
- `SalesforceOpportunity` aplica un scope activo global exclusivamente a
  borrados confirmados (`is_deleted=true` + `query_all_deleted`). Una ausencia
  `presence_reconciliation_missing` conserva semántica diagnóstica y permanece
  reportable. Se revisaron los dos escritores reales: solo el mapper completo
  del sync canónico limpia lifecycle y reactiva; Stock conserva la metadata de
  una fila retirada aunque reciba su fotografía parcial. `--fresh` conserva su
  semántica explícita incluyendo filas retiradas.
- El sync canónico consulta `queryAll` por `IsDeleted=true` y
  `SystemModstamp` en chunks temporales de siete días, antes del sync activo.
  Marca únicamente filas locales existentes mediante UPDATE agrupado. Una fila
  activa recibida por el mapper completo limpia toda la metadata de borrado.
- Se crea `salesforce:reconcile-opportunity-presence`, con modos mutuamente
  excluyentes, motivo de 10–500 caracteres en apply, `--limit`, `--after-id`,
  chunks de 100, mutex de seis horas, lock local y métricas JSON. Consulta solo
  `Id`, `IsDeleted` y `SystemModstamp` mediante `queryAll`; no inserta, borra ni
  escribe Salesforce. Un activo previamente confirmado queda contado como
  pendiente de refresh canónico y no se publica con campos locales obsoletos.
- El scope cubre Reservas/Ventas, reservas vivas, Rendimiento, Comisiones y
  auditorías. Las transiciones de una Opportunity confirmada como eliminada se
  excluyen de Rendimiento/auditoría. Campañas conserva Lead, campaña y
  procedencia, y anula solo oportunidad, reserva, venta, compra e importes de la
  Opportunity retirada. Stock carga el lifecycle en batch sin scope e invalida
  snapshots por borrado confirmado. Una ausencia local queda `unchecked` y no
  altera la validez previa del snapshot.

## Seguridad, rendimiento y operación

- Las muestras contienen solo Salesforce IDs. Los fallos remotos se publican
  con código técnico genérico; no hay payloads, nombres, email o teléfono. No se
  modifica matching Opportunity → Lead ni portal/source.
- No existe consulta Salesforce por fila: sync usa ventanas de siete días y la
  reconciliación agrupa hasta 100 IDs. Apply consulta Salesforce fuera de la
  transacción, relee filas con `lockForUpdate`, compara `updated_at` y el estado
  lifecycle observado y ejecuta un único UPDATE CASE por chunk. Dry-run no crea
  auditoría ni escribe.
- No se añade el índice booleano propuesto: no existe `EXPLAIN` que justifique
  una estructura de baja selectividad para unas 40.557 filas. El ALTER se limita
  a columnas; debe planificarse igualmente en ventana controlada. Rollback
  elimina columnas y la tabla técnica, sin hard-delete de Opportunities.
- Procedimiento posterior definido entonces: migrar estructura, ejecutar dry-run
  paginado y revisar `OPPORTUNITY_PRESENCE_METRICS`; cualquier apply requiere
  aprobación y motivo. En esa fase de implementación no se ejecutaron migración,
  reconciliación, sync productivo ni escrituras Salesforce; la ejecución
  productiva posterior queda registrada en la actualización de cierre.
- Archivos productivos revisados/modificados: modelo Opportunity, sync canónico,
  sync parcial de Stock, comando de sync, datasets/auditoría de Rendimiento y
  consumidores SQL directos de Campañas. Nuevos: dos migraciones, modelo de run,
  servicio/comando de presencia y su test feature. Se ampliaron las pruebas de
  sync, Stock, cohortes por fecha, reservas vivas, Rendimiento, Campañas y
  lifecycle temporal; la documentación funcional, auditoría, contexto y
  decisiones quedó alineada.
- Correctivo sénior: una ausencia no certifica borrado, una reaparición no
  reactiva hasta el refresh canónico, Campañas conserva su universo de Leads y
  Stock deja de mantener snapshots válidos para Opportunities confirmadas como
  eliminadas. `SystemModstamp` solo actualiza `salesforce_deleted_at`; las
  reapariciones pendientes incluyen una muestra acotada de IDs y apply orquesta
  la validez Stock inmediatamente, sin otros sincronizadores. El comando local
  `stock:reconcile-sale-validity` permite reintentar solo esa fase. Validación final
  del correctivo: focales 114 pruebas/961 aserciones y suite completa 943
  pruebas/6.715 aserciones, ambas verdes. Pint WRITE/`--test` sobre todos los PHP
  modificados, Composer validate/audit, build Vite y `git diff --check`
  correctos. El `pint --test` global continúa detectando infracciones
  preexistentes en archivos ajenos al diff, que no se reformatearon para
  mantener el alcance. No se ejecutaron migraciones, apply, sync real ni
  escrituras Salesforce.

## Correctivo de casing `SystemModstamp` (2026-09-08)

- Salesforce REST/queryAll devuelve la clave canónica `SystemModstamp`; las dos
  rutas de lifecycle de Opportunities leían `SystemModStamp`, por lo que un
  borrado confirmado podía conservar `salesforce_deleted_at` a null.
- La reconciliación histórica y la detección incremental usan ahora
  `SystemModstamp` tanto en SOQL como al leer el payload. No cambian estados,
  scopes, universos, Stock, Campañas, Reservas/Ventas, chunks, mutex ni número de
  consultas.
- Las fixtures reproducen el casing real y cubren timestamp válido, timestamp
  nulo, ausencia, fila activa, reactivación pendiente e idempotencia. Durante el
  desarrollo del correctivo no se ejecutaron migraciones, reconciliaciones,
  sincronizaciones productivas ni escrituras Salesforce; el despliegue y la
  reconciliación posteriores se detallan a continuación.
- Validación: tests obligatorios 33/208, bloque lifecycle/Stock/Campañas/
  Reservas-Ventas 93/855 y suite completa 945/6.772, todos correctos. Pint WRITE
  y `--test` sobre los cuatro PHP del correctivo, Composer validate/audit, Vite
  y `git diff --check` correctos. El Pint global conserva infracciones
  preexistentes en archivos ajenos al diff; no se reformatearon por alcance.

## Cierre productivo del refactor Salesforce (2026-09-08)

- PR #38 (`8231929cbda7427de40a163ef79e98f1e94c4be1`) desplegó el lifecycle y
  PR #40 (`fe758b0dd7623acff35eb051b17b2b5072ec70b2`) corrigió el API Name
  canónico `SystemModstamp`. Las afirmaciones anteriores de “no ejecutado” se
  referían al momento de implementación y quedan superadas solo para la
  reconciliación de presencia de Opportunities.
- La primera conciliación completa examinó 40.724 filas: 40.718 activas, tres
  borrados confirmados y tres ausencias diagnósticas. El primer apply cambió las
  seis filas y su dry-run posterior quedó en cero cambios.
- Después de PR #40, el apply de reparación actualizó el timestamp técnico de
  los tres borrados. El dry-run final `b68aa962-7c3c-4bda-87b8-b69410d13e44`
  devolvió 40.724 filas sin cambios, errores, concurrencias omitidas ni
  reactivaciones pendientes. Stock terminó con 13.625 válidos, 326 inválidos,
  66 duplicados y cero `unchecked`.
- La prueba sintética de sandbox no pudo ejecutarse. La evidencia productiva sí
  confirmó `queryAll`, `IsDeleted=true`, `SystemModstamp`, persistencia e
  idempotencia. El timestamp sigue siendo evidencia técnica, no fecha funcional
  contractual de borrado.
- Fase 7A y Fase 7B conservan infraestructura terminada, pero no existe evidencia
  de ejecución histórica; no se declaran completadas operacionalmente.

## Jerarquía de avisos de Rendimiento comercial (2026-09-21)

- Se redujo el ruido previo a los KPI de **Rendimiento comercial** sin eliminar
  información. Metodología, universo, fotografía local, cobertura de
  cancelaciones y calidad/atribución se agrupan en el `<details>` nativo
  **Información y calidad de datos**, cerrado por defecto y organizado en cinco
  subsecciones dentro de una única superficie secundaria.
- Permanecen fuera del desplegable el error de carga con `Reintentar`, el aviso
  compacto de mes en curso y un único aviso compacto de limitación. Este último
  se muestra exclusivamente cuando `data_quality.cancellations_available ===
  false`; `data_incident`, eventos históricos no certificados, incidencias de
  atribución y filas fuera del universo no lo activan por sí solos.
- El resumen presenta el formatter de cobertura existente y, si el dataset ya
  se cargó, `dataset_generated_at` como fotografía. Durante una recarga se
  limpian resumen, cobertura, universo, actualización y calidad anteriores sin
  modificar el estado abierto/cerrado elegido por el usuario.
- Correctivo previo a PR: la recarga oculta `performanceLimitationNotice` sin
  vaciar su `textContent`. El mensaje estático definido en Blade se conserva y
  vuelve a ser visible, con contenido, cuando las cancelaciones no son
  evaluables.
- No cambiaron backend, contrato JSON, cálculos, fórmulas, universos, ranking,
  objetivos, atribución, consultas, cachés ni sincronizaciones. No hay
  migraciones, dependencias, variables de entorno ni acciones manuales.
- Seguridad: todos los valores dinámicos continúan asignándose con
  `textContent`; no se añadió `innerHTML`, PII, payload bruto, endpoint, permiso
  ni listener. Rendimiento: no se añadieron peticiones, consultas, recálculos ni
  persistencia; el cambio opera únicamente sobre el dataset ya descargado.
- Archivos fuente modificados: vista Blade, JS y CSS específicos de
  Reservas/Ventas, prueba feature y documentación funcional. El build sustituye
  únicamente los bundles CSS/JS versionados del dashboard y actualiza sus dos
  entradas del manifest; el `app-*.css` regenerado por el entorno fue excluido.
- Validación final tras el correctivo: focal `76` pruebas/`897` aserciones y
  suite completa `1.000` pruebas/`7.474` aserciones, ambas verdes; Pint del
  archivo PHP afectado, `node --check`, build Vite, Composer validate estricto,
  Composer audit, `npm audit --omit=dev` y `git diff --check`, correctos.
  Composer y npm no informaron advisories. Vite mantuvo el aviso no bloqueante
  ya conocido de `/images/login-bg.jpg` resuelto en runtime y un aviso de
  deprecación de Node; no se actualizaron dependencias.

## Cambio compartido en Comisiones Comerciales (2026-09-24)

- Desde junio de 2026, la participación secundaria `shared` se detecta sobre
  entregas (`Venta` o `Cambio`) con `shared_delivery_id` informado y diferente
  del owner. Una `Tasacion` no genera participación aunque lleve ese campo.
- El owner de un `Cambio` conserva íntegramente los 85 EUR y el secundario cobra
  únicamente el importe configurado en `shared_secondary_delivery_amount`
  (30 EUR por defecto). La Opportunity no se reatribuye ni se duplica.
- El contrato público sigue siendo `shared_count`, `shared_amount` y
  `details.shared`. No cambian sincronización Salesforce, modelo, base de datos,
  frontend, tramos, penalizaciones, Tasadores ni meses anteriores a junio.
- Seguridad/rendimiento: se reutiliza la colección mensual ya cargada, con un
  único filtrado O(n), sin consultas, HTTP, Salesforce ni datos reales nuevos.
- Regresiones específicas: escenario Venta + Cambio para el mismo secundario
  `1/27`, autorreferencia y Tasador secundario `1/17`, API `1/22`, correctas.
  Dashboard completo `56/490`, API completa `23/160` y suite completa
  `1.020/7.884`, correctas. Pint `--test` focal, lint de los tres PHP y
  `git diff --check`: correctos.
- No se ejecutaron sincronizaciones ni llamadas Salesforce, migraciones,
  dependencias, build frontend, escrituras productivas, push, merge o deploy.

## OPS-1 — Control operacional auditable (2026-10-15)

### Base y alcance real

- Rama utilizada: feat/ops-1-operational-control, ya existente al iniciar.
- Antes de modificar: git status limpio; HEAD y origin/main, tras git fetch origin,
  ambos e7073d3affa43b7d6a5295292667993b7a164493. Ese es el SHA base real.
- No se ha creado commit: el SHA final de HEAD sigue siendo el de base; los
  cambios se entregan en el worktree. Sin push, merge, PR ni despliegue.
- ROT-5 está integrado mediante PR #75, merge
  137995384260c5e29986556fca221b45a60815f4, verificado en el historial.
- Se implementaron catálogo canónico, ledger, reserva persistente compartida,
  Job asíncrono, monitor de cadencias, panel Design System e historial paginado.
- No se implementaron cierre mensual automático, aprobación de cierres ni correo
  ejecutivo nuevo. No se migraron otros dashboards ni se alteraron fórmulas,
  universos, métricas, atribución, thresholds o compatibilidad legacy/shadow.

### Arquitectura, base de datos y seguridad

- Migración aditiva:
  database/migrations/2026_10_15_120000_create_operational_task_runs.php.
  Crea operational_task_states y operational_task_runs e índice adicional
  ops_alerts_task_state_idx sobre alertas. Solo aplicada por tests sintéticos;
  no se ejecutó contra producción ni la BD funcional local.
- Estado por tarea bloqueado con lockForUpdate dentro de transacciones:
  queued reserva desde el POST; scheduler y manual comparten exclusión.
  withoutOverlapping se conserva con los valores anteriores. Los locks internos
  de Stock, pipeline Interest/F5 y snapshot directo mantienen sus claves.
- 22 tareas; 18 permitidas manualmente con argumentos fijos. Catálogo exacto,
  políticas y allowlist completa en OPERATIONS.md. Solo scheduler: retención,
  Stock, correo SEO y monitor interno.
- POST con CSRF y confirmación, autorización admin en servidor, rechazo de
  campos adicionales, rate limit por actor de 10/min y cooldown persistente
  por tarea de 60 s. Dirección y los demás roles devuelven 403 con CSRF válido.
- Queue connection/queue operations usa database, un intento, timeout 14400 s y
  retry_after 14700 s. No usa la conexión default sync. Actor activo/admin se
  revalida al consumir; una solicitud manual >15 min no ejecuta.
- Un acuse ambiguo de Queue no libera un run ya iniciado. Callbacks de fallo,
  duplicados y liberación se coordinan con la misma reserva. Running/unknown no
  se liberan por TTL; CLI exige confirmación humana de proceso detenido.
- UUID por run, intento, tiempos UTC, exit code, duración, argumentos efectivos
  y actor numérico histórico; scheduler tiene actor null. No se creó Sistema.
- No se retienen stdout, excepciones externas, cuerpos HTTP ni credenciales
  en el nuevo ledger. Se reconocen omisiones SEO/Ads y errores parciales Ads sin
  publicar heartbeat de éxito. Warnings/logs Ads detectados se convierten en
  mensajes constantes sin cuentas ni excepciones crudas.
- Stock conserva firma y opciones, pero su omisión por lock ahora devuelve 2:
  el wrapper la audita como skipped. Revisar cualquier consumidor CLI que
  interpretara el antiguo 0 como ejecución real.
- Comprobación cada cinco minutos, basada en cron Madrid y margen explícito,
  sin ejecutar sincronizaciones. Un éxito antiguo que acaba tarde no encubre
  ocurrencias perdidas. La ausencia de evidencia nunca se convierte en Correcto.
- Retención transversal de terminales >90 días, preservando activos/unknown,
  evidencia apuntada e incidencias abiertas. No se borró información real.

### Reseñas y rendimiento

- Nueva ventana: 02:20 Europe/Madrid, salesforce:sync-commercial-reviews
  --months=2, withoutOverlapping(120). Entre Ads y Stock; sin fresh.
  Los 20 horarios, argumentos y mutex previos se contrastan con baseline
  independiente capturado del SHA base.
- El dataset commercial_reviews y su readiness no cambian: completed,
  completed_at, cobertura mensual y max_age 48 h por defecto.
- Panel sin consultas a Salesforce: estados mediante punteros por PK,
  máximo 22 tareas y 25 runs por página, alertas paginadas, sin N+1.
  Refresh manual explícito; no polling ni comandos largos dentro de HTTP.
- Fechas UTC en persistencia y Europe/Madrid en presentación/cadencia.

### Dependencias y documentación

- Audit inicial: un advisory de symfony/http-foundation y seis advisories npm
  de desarrollo. Corrección mínima del paquete PHP a 8.1.9; requisito nuevo
  PHP >=8.4.1 (CLI local 8.4.21). Lockfile npm actualizado dentro de rangos
  existentes; Vite 8.3.4 y bundles reconstruidos. Manifiestos de dependencias
  sin nuevas dependencias directas.
- Se reconciliaron ROADMAP, PROJECT_CONTEXT y DECISIONS. EXE-4 sustituirá SEO
  por correo ejecutivo global con alertas y medidas deterministas/auditables,
  sin causalidad inferida ni dos correos equivalentes a las 08:00.
  La decisión histórica contraria queda supersedida.
- COMM-CLOSE-SCHEDULE mantiene día 15, mes anterior, snapshots candidatos y
  aprobación humana. Continúa pendiente.
- Deuda UX explícita de Intereses, Llamadas, Campañas, Comisiones y Stock.
  Alertas se migra aquí; Reservas/Ventas ya migrado; Summary/SEO consumidores
  del Design System, pendientes solo de auditoría de residuos.
- Runbook actualizado con deploy, rollback, Queue/cron, diagnóstico, SQL
  sin payloads y recuperación de reservas.

### Verificaciones realmente ejecutadas

- php artisan test, suite final: 1356 pruebas, 12081 aserciones, 1356 correctas, 367042 ms. Resultado local; CI remota no ejecutada.
- Focal operacional anterior a la última prueba concurrente: 46 pruebas y 686 aserciones, correcta. Prueba HTTP concurrente aislada: 1 prueba y 6 aserciones, correcta. La suite final incluye ambas.
- Pint --test sobre todos los PHP modificados/nuevos; php -l sobre todos ellos. Resultados correctos.
- npm ci --ignore-scripts; npm run build: correcto con Vite 8.3.4. node --check sobre los 8 bundles JS: correcto.
- composer validate --strict: correcto. composer audit --locked --no-dev: cero advisories. npm audit y npm audit --omit=dev: cero vulnerabilidades.
- git diff --check: correcto. schedule:list --timezone=Europe/Madrid verificado por tests; no se ejecutó schedule:run contra datos reales.
- La prueba concurrente falló inicialmente por rutas absolutas Windows de caché del fixture; se corrigió usando rutas relativas temporales. Dos kernels HTTP independientes producen 302/429 y exactamente un run/Job queued.
- Build conserva avisos no bloqueantes de imagen login-bg resuelta en runtime y deprecación Node module.register. No se sustituyó esa imagen.

### Despliegue, rollback, límites y pendientes

- Requiere revisar/aplicar la migración aditiva, publicar bundles/configuración y provisionar worker dedicado: php artisan queue:work operations --queue=operations --timeout=14400 --tries=1 --sleep=3. No modificar .env automáticamente.
- Comprobar PHP >=8.4.1 y señales/timeouts del host. El estado ACTUAL de cron/Queue productivos no se infiere del repositorio.
- Checks: migrate:status, schedule:list Madrid, queue:failed, supervisor, cron cada minuto, SQL de estados/runs/jobs y dos intervalos de heartbeat operations-health. Certificar Queue con una acción ordinaria autorizada por admin en despliegue; Codex no la ejecutó.
- Rollback: detener operations y pausar cron, confirmar procesos terminados, volver a código compatible conservando tablas/evidencia. No ejecutar down ni rollback genérico; no consumir jobs nuevos con código antiguo. Mantener worker default.
- Pendientes: certificación en MySQL/MariaDB y bajo carga, cron/supervisor reales, validación visual móvil/desktop/teclado en QA y supervisión externa del heartbeat del propio monitor.
- El detector de omisiones depende de marcadores CLI existentes cubiertos por tests. Mantener ese contrato si cambia el vocabulario. El hardening no es una auditoría exhaustiva de todos los logs legacy; Stock conserva su manejo previo fuera del ledger.
- No se ejecutaron sincronizaciones reales, backfills, cierres, envíos de email, migraciones productivas ni cambios de .env. No se amplió acceso a otros módulos.
- Rama final: feat/ops-1-operational-control; HEAD final: e7073d3affa43b7d6a5295292667993b7a164493; cambios sin commit.
- Commit recomendado: feat(operations): add audited admin task control and scheduler health.

### Inventario exacto de archivos de la entrega

~~~text
A	app/Console/Commands/CheckOperationalTaskHealthCommand.php
M	app/Console/Commands/PruneTransversalDataCommand.php
A	app/Console/Commands/ReleaseOperationalTaskCommand.php
A	app/Console/Commands/RunOperationalTaskCommand.php
M	app/Console/Commands/SyncStockAnalysisCommand.php
M	app/Http/Controllers/Reports/Operations/OperationalAlertController.php
A	app/Jobs/Operations/ExecuteOperationalTask.php
A	app/Models/OperationalTaskRun.php
M	app/Services/Campaigns/GoogleCampaignSyncService.php
M	app/Services/Campaigns/MetaCampaignSyncService.php
M	app/Services/Operations/OperationalAlertService.php
A	app/Services/Operations/OperationalTaskCatalog.php
A	app/Services/Operations/OperationalTaskHealthService.php
A	app/Services/Operations/OperationalTaskOutput.php
A	app/Services/Operations/OperationalTaskService.php
M	composer.lock
M	config/queue.php
A	database/migrations/2026_10_15_120000_create_operational_task_runs.php
M	docs/ai/DECISIONS.md
M	docs/ai/HANDOFF.md
A	docs/ai/OPERATIONS.md
M	docs/ai/PROJECT_CONTEXT.md
M	docs/ai/ROADMAP.md
M	docs/ai/SEO_ANALYTICS.md
M	docs/informe-comisiones.md
M	docs/operaciones-produccion.md
M	package-lock.json
D	public/build/assets/app-34mOoJaZ.js
D	public/build/assets/app-BqB0BBS7.css
A	public/build/assets/app-BvRk9kiK.js
A	public/build/assets/app-DfvlhaOd.css
A	public/build/assets/app-shell-C60se5JH.js
D	public/build/assets/app-shell-DKbHEObD.js
A	public/build/assets/calls-dashboard-CNL44Zwq.js
D	public/build/assets/calls-dashboard-pf9BB5IL.js
A	public/build/assets/campaigns-dashboard-C0wdkSRf.js
D	public/build/assets/campaigns-dashboard-uwC_KUe1.js
A	public/build/assets/commercial-commissions-dashboard-DIO8X5Lc.js
D	public/build/assets/commercial-commissions-dashboard-DjAaqBZU.js
D	public/build/assets/leads-dashboard-B0vS8zzF.js
A	public/build/assets/leads-dashboard-D6fLg-lw.js
D	public/build/assets/reservations-sales-dashboard-C7IIG8FY.js
A	public/build/assets/reservations-sales-dashboard-CtEu6ItD.js
A	public/build/assets/stock-dashboard-a7G92PVg.js
D	public/build/assets/stock-dashboard-BrzfZPFN.js
M	public/build/manifest.json
M	resources/views/reports/operations/alerts.blade.php
M	routes/console.php
M	routes/web.php
A	tests/Concerns/AssertsOperationalSchedule.php
M	tests/Feature/CampaignCommandsTest.php
A	tests/Feature/OperationalTasksTest.php
M	tests/Feature/ReservationsSalesCommercialPerformanceTest.php
M	tests/Feature/SalesforceCallsScheduleTest.php
M	tests/Feature/SeoAnalyticalEvaluationsTest.php
M	tests/Feature/SeoAnalyticalSnapshotsTest.php
M	tests/Feature/SeoExecutiveDailyEmailTest.php
M	tests/Feature/SeoSyncCommandsTest.php
M	tests/Feature/SeoTechnicalHealthSyncTest.php
A	tests/Fixtures/operational_http_request.php
A	tests/Fixtures/operational_schedule_baseline.php
~~~

## 2026-10-15 — OPS-1, correcciones tras revisión 41c1e7a

### Estado y base verificada

Trabajo local revisable, sin commit ni push. Rama existente
feat/ops-1-operational-control; HEAD inicial y final:
41c1e7abf8b60d5e95f4af31287d5820a5b5f21a.
Se comprobó worktree limpio antes de modificar y se ejecutó git fetch origin;
origin/main: e7073d3affa43b7d6a5295292667993b7a164493.
No se crearon ramas, PR ni despliegues. No se ejecutaron integraciones reales,
backfills, correos, liberaciones reales ni migraciones productivas.

### Cambios y decisiones

- Matriz común de retorno: 0 éxito acreditado; 1 fallo técnico; 2 entrada
  inválida; 3 omisión iniciada; 4 bloqueo/estado incierto; 5 evaluación de salud
  con incidencias. check-health usa 0/1/5; release 0 significa liberación auditada,
  nunca éxito de sincronización. La salida nativa del comando se conserva en el run.
- El monitor usa unhealthy y last_completed_id para acreditar evaluación técnica
  sin atribuir éxito global ni generar fallos recursivos. Un monitor técnicamente
  recuperado puede resolver su fallo previo aunque aún encuentre tareas enfermas.
- Inicio de observación persistente: nuevas tareas esperan su primera ocurrencia
  futura más margen; estados previos conservan created_at. Sin runs fabricados,
  sin resolver alertas por el margen inicial ni ocultarlas con un reintento queued.
- Las recuperaciones reconcilian solo la tarea terminada, evitando multiplicar
  incidencias de las otras tareas. El chequeo completo conserva cadencia cinco min.
- Release exige confirmación externa, motivo cerrado y reserva candidata huérfana;
  la auditoría y transición failed son atómicas. Registra momento/correlación y UID
  efectivo cuando existe posix_geteuid; en Windows unverified_cli/null. No identifica
  una persona a partir del entorno. Referencia opcional OPS-NNN, sin texto libre.
- Catálogo único de 22 tareas y 18 manuales, firmas/argumentos ordinarios,
  horarios (reseñas 02:20 Europe/Madrid), locks y cola operations intactos.

### Archivos modificados y finalidad

- app/Console/Commands/CheckOperationalTaskHealthCommand.php: 0/1/5 sanitizados.
- app/Console/Commands/RunOperationalTaskCommand.php: retorno según evidencia real.
- app/Console/Commands/ReleaseOperationalTaskCommand.php: validación y liberación auditada.
- app/Console/Commands/PruneTransversalDataCommand.php: proteger último heartbeat y retener unhealthy.
- app/Models/OperationalTaskRun.php: cast entero de exit_code.
- app/Services/Operations/OperationalTaskCatalog.php: constante de clave del monitor, sin alterar contratos.
- app/Services/Operations/OperationalTaskExitCode.php (nuevo): semántica común de resultados.
- app/Services/Operations/OperationalTaskHealthService.php: bootstrap, heartbeat, incidencias y recuperación.
- app/Services/Operations/OperationalTaskService.php: transiciones y auditoría transaccional de liberaciones.
- database/migrations/2026_10_15_130000_extend_operational_observation_and_release_audit.php (nueva): esquema aditivo.
- resources/views/reports/operations/alerts.blade.php: estado textual/Design System unhealthy.
- tests/Feature/OperationalTaskReviewTest.php (nuevo): regresiones de revisión.
- tests/Feature/OperationalTasksTest.php: fixtures acordes a margen inicial y motivo obligatorio.
- tests/Fixtures/operational_http_request.php: transacción IMMEDIATE solo en fixture SQLite concurrente.
- docs/ai/OPERATIONS.md: matriz, arranque, alertas, auditoría y límites.
- docs/operaciones-produccion.md: despliegue, supervisión, runtime y rollback.
- docs/ai/PROJECT_CONTEXT.md: arquitectura realmente implementada.
- docs/ai/DECISIONS.md: decisiones operacionales.
- docs/ai/ROADMAP.md: estado local pendiente de aprobación/certificación.
- docs/ai/HANDOFF.md: evidencia de esta entrega.

### Base de datos, seguridad y rendimiento

Una migración aditiva: monitoring_started_at y last_completed_id en estados;
siembra del catálogo sin runs y migración de punteros existentes; tabla separada
operational_task_releases con índices run_id y task_key/released_at. No se borra
historial. Retención conserva evidencia apuntada, reservas e incidencias abiertas;
los eventos de liberación no se podan automáticamente.

Se mantiene admin activo + allowlist + CSRF + Queue; no hay endpoint de release ni
argumentos libres. Mensajes constantes, sin stdout, excepciones crudas o identidad
humana inventada. No se modifican .env, credenciales ni dependencias.
Snapshot usa dos consultas acotadas por catálogo/punteros; check añade consulta
por claves/tipos operacionales abiertos usando índice task/state. Sin escanear
report_sync_runs o historial completo ni llamar a Salesforce desde el panel.

### Verificaciones realmente ejecutadas

- php artisan test: 1387/1387 pruebas, 12484 aserciones, 312493 ms, exit 0.
- php artisan test --filter=Operational: 78/78, 1095 aserciones, exit 0.
- Prueba HTTP concurrente aislada: 1/1, 6 aserciones, exit 0.
- Una ejecución completa anterior falló únicamente en el fixture HTTP concurrente
  (302/500 frente a 302/429). Se ajustó SQLite a transacción IMMEDIATE para adquirir
  reserva de escritura antes de leer; no tiene locks por fila. Después pasó aislada,
  el conjunto operacional y la suite completa. No cambia la configuración productiva.
- Pint --test sobre todos los PHP modificados/nuevos: pasa tras aplicar Pint al fixture.
- php -l sobre los 14 archivos PHP/Blade modificados/nuevos: sin errores.
- npm run build: pasa, Vite 8.3.4; assets sin diferencias respecto a HEAD.
  Warnings existentes: login-bg.jpg resuelto en runtime y module.register de Node deprecado.
- composer validate --strict: pasa.
- composer check-platform-reqs --lock --no-dev: pasa con PHP local 8.4.21.
- composer audit --locked --no-dev: sin advisories; npm audit: 0 vulnerabilidades.
- git diff --check: pasa.
- schedule:list --timezone=Europe/Madrid --no-ansi: 22 tareas; reseñas 20 2 * * *.
  Regresión automatizada contrasta todos los horarios, argumentos y mutex previos.
- Sin diferencias en composer.json/lock, package.json/lock, routes/console.php,
  config/queue.php, ExecuteOperationalTask.php ni public/build.
- No se ha modificado JavaScript específico. No se afirma resultado de CI remota.

### Despliegue posterior autorizado, rollback y riesgos pendientes

Aplicar la migración aditiva antes de reactivar el código y worker nuevos, con
plan de mantenimiento y migrate:status previo. Conservar conexión/cola operations,
timeout y retry_after. Reiniciar de forma controlada los workers tras el cambio;
no lanzar sincronizaciones para comprobar instalación. Para rollback del código,
conservar columnas y tabla de auditoría; no ejecutar migration down ni borrar runs.

El operador confirma PHP 8.4.21 en web, CLI y worker, compatible con el mínimo
8.4.1 del composer.lock definitivo. El repositorio conserva ^8.3 en composer.json;
no basta esa restricción para acreditar compatibilidad del lock. Comprobar en
cada entorno real sus extensiones y composer check-platform-reqs --lock --no-dev.
No se han inspeccionado los procesos productivos ni certificado MariaDB/InnoDB,
cron, señales/timeouts, supervisor o retención a escala.

Verificar cron cada minuto, schedule:list en Madrid, servicio del worker dedicado
y evidencia real de queued/started/completed en el panel/BD. Supervisión externa
independiente debe ejecutar check-health y clasificar 0/1/5 para detectar incluso
cron totalmente detenido. Ninguna lista de horarios acredita ejecución real.
Laravel genera ScheduledTaskFailed/log genérico por cualquier retorno no cero,
pero continúa otras tareas; no hay listeners propios que creen alertas recursivas.
Los observadores externos deben clasificar 3/4 como diferimiento y 5 como incidencias,
sin presentarlos como éxito ni como fallo técnico del monitor.

Nunca liberar por timeout solamente: verificar terminación del proceso y sus hijos
en todos los hosts. UID OS no identifica a una persona; atribución humana requiere
auditoría externa autenticada/ticket. Sin confirmación fiable conservar la reserva.
Sin cambios incompatibles de sincronización; operations:release sí exige ahora
--reason además de --confirm-stopped. Adaptar procedimientos existentes.

## 2026-10-15 — PR #78, aislamiento del HTTP concurrente y diagnóstico separado de CI

Base verificada antes de editar: d5b37b8b27ff56d56f04961553cd0b768a58c4ea;
rama feat/ops-1-operational-control, worktree inicialmente limpio. Sin rama nueva.

### Cambios mínimos y causa

Archivos: tests/Feature/OperationalTasksTest.php y este HANDOFF.
Cada proceso recibe rutas exclusivas APP_PACKAGES_CACHE, APP_SERVICES_CACHE,
APP_CONFIG_CACHE y APP_ROUTES_CACHE con prefijo process-0/process-1 dentro del
mismo directorio UUID de prueba. Laravel reconstruye manifiestos mediante
Filesystem::replace/rename antes de llegar a la barrera: compartir el destino
expone la carrera de Windows Acceso denegado. El aislamiento evita esa escritura
compartida sin reintentar rename, añadir sleeps ni reconstruir cachés globales.
Las rutas relativas son resueltas por Laravel contra base_path en Windows/Linux.

Ambos procesos siguen arrancando mediante Symfony Process, comparten SQLite y
barrera, y envían POST real al controlador con un administrador activo sintético.
Se preservan respuestas 302/429, un run queued y un Job operations. El fixture
conserva transaction_mode IMMEDIATE y busy_timeout; no se toca la exclusión
productiva. El finally detiene los procesos y purga la conexión antes de eliminar
los archivos planos, incluidos los manifiestos privados y temporales de rename.
Tras las diez repeticiones no quedan directorios ops-concurrency temporales.

### CI: causa distinta, corrección separada en el test de rate limiting

Se consultaron el PR y los jobs/logs del HEAD d5b37b8:
https://github.com/Hispania-Real-Motor/informes-app-hrmotor/actions/runs/38726591742/job/116393856177
Run CI 172: falla PHP tests en admin_rate_limit_is_per_actor_and_never_replaces_non_admin_403,
esperaba 429 y recibió 302. No es el fallo rename de Windows. El test concurrente
no figura como fallo. Pint/build/whitespace quedaron skipped después del fallo PHP;
no se presentan como verdes. El resumen remoto muestra 1 failed, 1284 warnings,
102 passed y 12472 aserciones; esas warnings no equivalen a fallos de aserciones.

El test elegía first admin sin asegurar que fuera el actor de la sesión que crea
Tests/TestCase. Se reprodujo localmente con dos administradores sintéticos:
el contador de un actor y la petición de otro producen el mismo 302 inesperado.
Ahora el test crea y autentica explícitamente al administrador cuyo contador llena.
No cambia autorización ni rate limiting productivo. La reproducción controlada
falló como se esperaba (1 prueba/1 aserción); la corrección aislada pasa con 13
aserciones, incluidos los doce 403 de Dirección. Ambas correcciones son hunks
independientes del mismo archivo de tests.

### Evidencia local completada

- Concurrente aislado: 1/1, 6 aserciones, 38126 ms, exit 0.
- Diez ejecuciones consecutivas adicionales, sin cambiar código entre ellas:
  10/10, 60 aserciones, 124962 ms acumulados, todas exit 0.
- Clase OperationalTasksTest completa: 41/41, 623 aserciones, 28896 ms, exit 0.
- Pint --test del archivo y de todos los PHP cambiados por el PR frente a
  origin/main: pasa. php -l del archivo: sin errores. git diff --check: pasa.
- composer validate --no-check-publish --strict: pasa; composer audit --locked
  --no-dev: sin advisories. No cambian dependencias.
- npm run build: pasa, assets sin diff. Warnings existentes de login-bg.jpg
  resuelto en runtime y module.register de Node deprecado; no se modifica frontend.

### Seguridad, rendimiento y acciones pendientes

No cambian producción, scheduler, catálogo, permisos, argumentos, reservas,
Queue, fixture HTTP ni phpunit.xml. Sin migraciones, ajustes de configuración,
.env, secretos ni limpieza de caché global. No se ejecutan sincronizaciones,
workers, liberaciones reales, despliegues o integraciones externas.

Se mantiene concurrencia real y tiempos de espera existentes. No cambia arquitectura:
PROJECT_CONTEXT y DECISIONS no requieren actualización por este arreglo de tests.
El commit local correctivo está autorizado; no se hace push por la restricción
previa de requerir autorización expresa. Los checks remotos del nuevo commit
requieren publicación y una nueva ejecución de CI Linux antes de certificar el PR.
No se afirma certificación productiva ni CI verde a partir de resultados locales.

Verificación final de esta corrección: php artisan test completa pasa con
1387/1387 pruebas, 12484 aserciones, 342171 ms y exit 0. Se ejecutó después de las
diez repeticiones y la clase completa, sin cambios de código entre ellas. Solo
resta la ejecución remota sobre el commit publicado; el run 172 sigue acreditando
el fallo del HEAD anterior, no el resultado de esta corrección.

## 2026-10-19 — Cierre productivo OPS-1 y límite del monitor/scheduler

Base real verificada con status/rama/HEAD/fetch: origin/main
3154bd033671b4ad3bc5e5e06f93b39f51331c29 (merge PR #78). Worktree limpio;
checkout anterior feat/ops-1-operational-control en 18522e1. Rama nueva solicitada:
fix/ops-1-monitor-production-closure, creada desde ese origin/main.

### Causa, cambio y alcance revisado

Laravel ScheduleRunCommand convierte toda salida no cero de un comando programado
en ScheduledTaskFailed y reporta excepción. El ledger ya distinguía unhealthy/5,
pero operations:run propagaba 5 como fallo técnico al padre. Se elige flag CLI
--scheduler solo para operations-health en routes/console.php. Tras verificar run
unhealthy/5 con inicio/fin, last_completed_id y reserva liberada, devuelve 0 técnico.
Se mantienen estado y exit_code persistidos, checker y wrapper directos con 5,
alertas y heartbeat. Se rechaza el flag para otras claves; ningún 3/4/fallo se normaliza.
Se descarta modificar el checker o filtrar logs por pérdida de contrato/evidencia.

La finalización y reconciliación se sacan del catch de errores del comando hijo:
si fallan después de persistir unhealthy, el wrapper retorna fallo técnico, no
traduce una evaluación incompleta a éxito. Se añade una consulta por PK al monitor,
sin escanear historia ni multiplicar el chequeo de todas las tareas.

Archivos de código/tests:
- app/Console/Commands/RunOperationalTaskCommand.php: límite explícito del scheduler.
- app/Services/Operations/OperationalTaskService.php: propagación del fallo de finalización.
- routes/console.php: opción solo del monitor, mismos cron/mutex/22 tareas.
- tests/Concerns/AssertsOperationalSchedule.php: verifica la opción exclusiva y calendario.
- tests/Feature/OperationalMonitorSchedulerTest.php (nuevo): scheduler Laravel en callback
  aislado, cero/una/varias incidencias, contrato directo, fallos/código inesperado,
  pérdida de reserva, fallo de auditoría, no recursión y recuperación de tres alertas.

Documentos: docs/operaciones-produccion.md, docs/ai/OPERATIONS.md,
docs/ai/DECISIONS.md, docs/ai/PROJECT_CONTEXT.md, docs/ai/ROADMAP.md y este HANDOFF.
No migraciones, nuevas dependencias, cambios de cola, catálogo, fórmulas, permisos,
UX ni assets. Conexión operations y worker default intactos; argumentos ordinarios
y serialización F2/F5 no cambian. Sin .env ni secretos en el diff.

### Producción y diagnóstico: hechos aportados frente a límites

El operador acredita F2 4281 completed/cutoff 2026-10-15 14:45:15 UTC,
F5 1 completed/fuente 4281, pipeline run 28 success con F2 4283/F5 2 alineados
y dashboards recuperados. Se documentan recuentos agregados y las 15 referencias
no resueltas de F5; no se ejecuta ni recomienda repetir bootstrap/snapshots.
El 19/10 acredita infra-01, Compose OPS independiente informes-ops y run 1622
manual success/0 en operations. Runbook distingue evidencia comunicada de inspección
propia: Codex NO accedió al host. No se copia/inventa el YAML instalado ni se
reconstruye el Compose original ausente. Procedimientos de red/imagen/mount,
provisión/recuperación, logs acotados, jobs fallidos y reservas están documentados.

GA4, Search Console y Tasaciones: se inspeccionan comandos, clientes, config,
report_sync_runs y reconciliación de fingerprints. No hay evidencia actual de
causa OAuth ni defecto específico reproducible que explique los fallos históricos.
El runbook enumera posibles clases de fallo y consultas por índice/dataset/source/
status con límites. Falta estado actual, último fallo/éxito/cutoff y categoría/HTTP
de errores recientes sanitizados. Las alertas no se cierran por despliegue, skipped
o monitor unhealthy. Tasaciones conserva impresión legacy de getMessage(): stdout
no debe compartirse; el ledger lo descarta. Hardening general de CLI queda pendiente.

### Verificaciones completadas antes de cerrar la suite general

- php artisan test --filter=Operational: 94/94, 1249 aserciones, 12689 ms, exit 0.
  Incluye autorización, cola dedicada, doble proceso concurrente, timeout/unknown,
  heartbeat Madrid/DST y las 16 regresiones añadidas. Fixtures iniciales ajustados
  a CallbackEvent (retorno booleano) y a omisiones reales; no se relajan contratos.
- Pint --test en todos los PHP modificados/nuevos: pasa; php -l en los cinco: pasa.
- composer validate --strict y check-platform-reqs --lock --no-dev: pasan, PHP 8.4.21.
- composer audit --locked --no-dev: sin advisories; npm audit: 0 vulnerabilidades.
- npm run build: pasa; warnings existentes login-bg.jpg/runtime y module.register
  deprecado. Assets idénticos a main; no hay JavaScript específico modificado.
- schedule:list --timezone=Europe/Madrid: 22 tareas, reseñas 02:20; solo monitor con flag.
- git diff --check: pasa. Catálogo, enum, Queue, jobs, recursos y lockfiles sin diff.

### Acciones humanas y riesgos pendientes

Correctivo pendiente de revisión y despliegue humano. No hay migraciones ni cambio
de .env; no se interviene en producción. Tras despliegue comprobar ocurrencia
natural, unhealthy/5 persistido y ausencia de error técnico por ese 5; supervisión
externa conserva checker/5 y ausencia de heartbeat. Recuperar solo OPS conforme
al YAML real aprobado, sin reinicios globales que afecten a default.

Pendiente: reinicio real del host, señales/parada bajo carga y tarea de cuatro
horas, valores exactos de logging/grace del YAML, diagnóstico actual de integraciones.
No se ejecutaron sincronizaciones, cargas completas, snapshots, comandos productivos,
reinicios, cambios de alertas reales ni despliegues. Rollback del código conserva
ledgers/worker; reaparece ruido 5 sin destruir evidencia. No hacer merge automático.

Validación general final, realmente ejecutada: php artisan test pasa con
1403/1403 pruebas, 12814 aserciones, 317664 ms y exit 0. Incluye las suites de
integraciones aisladas (GA4/Search Console/Tasaciones). No certifica su estado
externo actual. Se prepara commit y PR hacia main para revisión humana, sin merge
ni despliegue. Resultados remotos posteriores se comunican con evidencia separada.

Revisión final adicional: prueba sintética local (sin red) del mensaje sanitizado
Salesforce SOQL HTTP 400/INVALID_FIELD contra invalidFieldFromMessage devolvió null.
El fallback de retirada de campos de Tasaciones depende de un nombre que el cliente
sanitizado no conserva. Se documenta esta limitación separadamente: no se acredita
como causa productiva, ni se cambia la sincronización ni restaura un payload bruto.
Debe contrastarse con el tipo de error reciente antes de abordar otro correctivo.

PR #79 creado hacia main, rama publicada. CI run 39228596171 del commit 19c08b7:
PHP tests, Pint, Composer/audit, build y whitespace completados con success.
La actualización documental posterior no cambia el código ya probado; no se
extrapola ese resultado a futuros commits sin consultar su ejecución remota.


## 2026-10-23 — Fase A publicitaria y propuesta de estado actual

Rama: fix/campaign-advertising-table-and-current-status.
Base: 01d3aa8ad428ad413f184741d9a4f2314696f1fe. Trabajo en checkout aislado;
no se sobrescriben los cambios ajenos del checkout principal.

### Resumen, decisiones y alcance

Causa raíz: detalle basado en cualquier actividad retenía procedencias sin
gasto publicitario; columnas funcionales variables confundían compras;
Campañas a revisar admitía Salesforce antes de calcular benchmarks. La fuente
histórica de estado no acredita vigencia.

Detalle: Meta/Google, fuente publicitaria y spend finito > 0. Excluye cero,
negativo/desconocido/inválido aunque haya resultados comerciales. Gasto positivo
sin resultados permanece. Conteos por motivo únicamente en DOM. Se retiran
result_count/cost_per_result solo de columnas; Coste por compra usa
cost_per_purchase. Columnas v5 migran a v6 sin tocar otras preferencias. Solo
importes del detalle usan — con tooltip; cero válido conserva formato EUR.

reviewCampaigns filtra elegibles antes de media por tipo/top 8; misma prioridad,
fórmulas y umbrales. Cambio intencional limitado al bloque; cache payload v5
invalida la representación v4. No se cambia universo económico general, API/CSV
legacy, filtros históricos ni rankings ajenos. Estado sigue histórico. Fase B
requiere autorización de nueva persistencia/operación: propuesta en documento
independiente; ninguna decisión arquitectónica implementada.

### Archivos de la tarea

- app/Services/Campaigns/CampaignDashboardDatasetService.php: filtro local del
  bloque revisión y versión de caché, sin cambios económicos.
- resources/js/reports/campaign-presentation.js y campaigns-dashboard.js:
  elegibilidad, formato del detalle, columnas/preferencias y renderizado.
- tests/Feature/CampaignDashboardTest.php y
  tests/js/campaign-presentation.test.mjs: regresiones focalizadas.
- public/build/manifest.json y nuevo bundle de campañas referenciado: build
  conjunto; se conserva el bundle anterior, sin borrados.
- docs/informe-campanas.md y docs/ai/CAMPAIGN_PRESENTATION_DIAGNOSTIC.md:
  reglas vigentes distinguidas de antecedentes.
- docs/ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md: diseño propuesto, no implementado.
- docs/matriz-trazabilidad-kpi.md: registro focalizado pendiente de auditoría,
  sin certificar otros KPIs ni copiar estructura ajena no incluida en la base.
- docs/ai/CAMPAIGN_PHASE_A_REVIEW.md: contrato Docs-Impact para futura PR.
- docs/ai/HANDOFF.md: esta entrada. PROJECT_CONTEXT/DECISIONS no cambian porque
  no se incorpora arquitectura nueva ni decisión irreversible.

### Integridad, seguridad y rendimiento

Sin migraciones, escrituras de datos, sincronizaciones ni cambios de auth/CSP,
permisos, deduplicación, first touch o fórmulas. No se consulta base productiva
ni proveedores. Escape HTML conservado. Filtrado en memoria lineal, sin nuevas
consultas ni red. Los estados actuales no se resuelven desde inversión.

El agente principal ejecutó sonda temporal de paridad con servicio original
obtenido de git show de la base y servicio actual, misma fecha congelada y
fixtures SQLite :memory:. 1 test/27 aserciones correctos, 2,725 s. Mes abierto y
cerrado × Todas/active/inactive: KPIs, filas completas, exportRows y rankings
salvo review_campaigns idénticos. Hashes de seis tablas de métricas,
atribuciones, cierres, snapshots y eventos iguales antes/después. Conteos de
filas 4/4/0 en ambos servicios. La sonda/copia temporal se retiró, sin incluir
código auxiliar en el commit; JSON agregado en storage/app ignorado. Evidencia
sintética local, no auditoría de datos productivos ni prueba de MySQL real.

### Pruebas comunicadas por el agente principal

- PHP focalizado: 50/50, 519 aserciones correctas.
- node --test tests/js/campaign-presentation.test.mjs: 15/15 correctas.
- npm run build final: correcto; manifest y bundle actualizados juntos.
- php -l y Pint focalizado: correctos.
- Suite general: 1432/1432 tests, 13131 aserciones correctas, 375,622 s.
  PHP Herd 8.4 -d max_execution_time=0 vendor/bin/pest --compact; SQLite
  :memory: y APP_KEY sintética solo en proceso, sin modificar .env.
- git diff --check: correcto en revisión documental inicial; repetir final.

La revisión documental fue delegada a report_docs_maintainer, con diff completo,
base y alcance Campañas/Detalle/Campañas a revisar. Se contrastaron dataset,
clientes/sincronizadores, persistencia, formateadores y pruebas. Guía/matriz
esqueleto/validador del checkout principal se leyeron como referencia ajena;
no se incorporan esos cambios por copia. La validación externa requiere refs
finales y root explícito del checkout aislado, y no debe declararse exitosa si
falla por guía o estructura general ausente en esta base.

### Acciones manuales y riesgos

No desplegar ni fusionar automáticamente. Publicar fuentes, manifest y bundle
juntos mediante procedimiento autorizado; comprobar asset servido y QA visual
de columnas, preferencias, exclusiones, tooltips y filtros. Sin variables de
entorno nuevas, migraciones, syncs o invalidación de snapshots. Rollback:
release anterior con assets o revertir código y recompilar, sin operaciones de
base de datos. La caché usa namespace de código; no manipular cierres.

Pendientes: aprobación de Fase B, QA real de navegador, auditoría integral de
trazabilidad KPI y estados de campañas de producción. Desaparición visual no
implica pérdida de filas en API/CSV. No presentar como actuales los estados
históricos ni certificar integraciones remotas desde pruebas simuladas.


Revisión documental ejecutada: Python del runtime con -B -m unittest discover
-s C:/Users/Usuario/Herd/informes-app-hrmotor/tests/documentation -p test_*.py:
27/27 correctas, 4,379 s. Es la suite externa de infraestructura documental,
no certificación integral de la matriz. Comprobación de enlaces Markdown
locales de los seis documentos: cero destinos inexistentes; git diff --check
correcto. La guía y validador no se copiaron desde el checkout principal.


La nueva matriz incorpora el esquema contractual de 23 campos y el índice de
los seis informes/pestañas de Comisiones como pendientes, para permitir control
estructural posterior. No son fichas auditadas ni traslado de reglas de otros
informes. Solo Campañas incluye evidencia focalizada de esta tarea.


Validación documental integral realmente ejecutada mediante módulo externo
scripts/check_documentation_integrity.py del checkout principal (solo lectura),
Python runtime -B: changes_between(base=01d3aa8ad428ad413f184741d9a4f2314696f1fe,
head=HEAD local de Fase A, root=checkout aislado), seguida
de validate(changes, cuerpo CAMPAIGN_PHASE_A_REVIEW.md, root=checkout aislado).
Resultado: FAIL sobre 13 rutas, único error: Falta referencia documental
obligatoria: docs/guia-mantenimiento-documental.md. La guía existe únicamente
como cambio ajeno del checkout principal y no se copia ni se incorpora aquí.
validate_matrix(root=checkout aislado) devuelve cero errores. No se declara
superado el validador integral ni se confunde con los 27 tests externos que sí
pasaron. El agente principal repitió la validación tras incorporar esta
evidencia: mismo único bloqueo por guía ausente; el código funcional no cambia.

## 2026-10-23 — Reconciliación documental de PR #85 con main

Revisión documental delegada a `report_docs_maintainer` en el checkout aislado
de PR #85. Referencias contrastadas: Fase A
`6fe686ad5dda65664af1defb20164dcbc41d2345` y main
`e314c8d48e8deb091206bd8a3aadb3dff910929e`. Este trabajo resuelve la colisión
add/add de la matriz; no incorpora la implementación posterior de Fase B.

Se conserva el contrato general de 23 campos, las secciones de los seis informes,
las seis pestañas de Comisiones y todos los estados pendientes de auditoría de
main. Dentro de Campañas se incorpora el registro focal de Fase A, subordinado
al encabezado del informe; no se transforma en una ficha KPI verificada.
Se conservan referencias funcionales y la propuesta de estado actual como
propuesta no implementada en esta rama. La evidencia sintética no acredita
datos productivos ni estado remoto vigente.

Contraste semántico: el diff funcional de Fase A limita la modificación PHP a
elegibilidad de `reviewCampaigns()` antes del benchmark y a namespace de caché.
La fórmula `cost_per_purchase`, `divide()` con divisor nulo/cero y redondeo de
cuatro decimales permanecen intactos. La selección visual de filas y columnas
no elimina datos de API/CSV; el histórico de estados conserva su limitación de
vigencia. Se contrastaron helpers JavaScript y regresiones de campañas/CSV,
snapshots y atribuciones. No se consultaron bases de datos ni proveedores.

Archivos de esta revisión: `docs/matriz-trazabilidad-kpi.md` y este HANDOFF.
Sin migraciones, configuración funcional ni cambios de seguridad o rendimiento.
La matriz íntegra de main y el registro focal son complementarios: el primero
define el contrato pendiente y el segundo documenta solo el alcance contrastado
de Fase A. El fallo histórico del validador por guía ausente documentado arriba
corresponde a la base antigua; el resultado de esta reconciliación y sus pruebas
debe registrarse por separado tras la validación local final.

Pendientes de cierre por el agente principal: resultados de tests documentales,
validador integral con descripción de PR y pruebas funcionales afectadas;
revisión final de diff y publicación mediante push normal. No se declara una
suite general ejecutada en esta resolución. No fusionar ni desplegar el PR.

Verificación estructural ejecutada durante la revisión: al retirar únicamente
el registro focal añadido, el texto restante coincide literalmente con la
matriz de `origin/main`; 23 campos, seis encabezados de informes y seis filas de
pestañas de Comisiones. Los 21 destinos Markdown locales de la matriz existen,
incluida la propuesta. Sin marcadores de conflicto en la matriz;
`git diff --check` correcto. No sustituye al validador integral ni a los tests.

Cierre local del principal: tests PHP de CampaignDashboardTest50/50,519
aserciones,2m57,697s; JS FaseA15/15; tests documentales36ejecutados,35correctos
y1skip por privilegios Windows de symlink. El primer intento documental dio
5fallos por el destino propuesta ausente únicamente en la fixture; se añadió
su archivo sintético y una regresión que exige error al retirarlo, sin relajar
el script. Guía y tests se revisan juntos conforme al contrato de controles.
Archivos propios de resolución: matriz, HANDOFF, guía y fixture/test documental;
el resto de infraestructura entra intacta desde main mediante merge. No cambia
PHP/JS de aplicación, configuración funcional, seguridad, DB ni rendimiento.

Enlaces locales de matriz/informe/propuesta comprobados; código y assets de A
idénticos a6fe686a; manifest apunta campaigns-dashboard-C4UL5FZw.js y existen
todos sus destinos. Ninguna prueba eliminada ni marcador o conflicto pendiente.
FaseB conserva refs/heads/feat/campaign-current-status-and-number-format en
59e822b8d05f64fab03816c35885d6f52dad7963; su checkout queda intacto. La suite
general no se ejecutó en esta resolución. No se ejecutan migraciones,
sincronizaciones, despliegues ni fusión del PR; solo merge de main hacia su rama.
Descripción de PR preparada con Docs-Impact:updated y Docs-Matrix-Impact:updated.
Validación integral del SHA de merge y push normal quedan como pasos finales;
si aparece concurrencia remota, detenerse sin force. CI remota pendiente.

Validación posterior al merge e913f08bfd8cc1df083c2adbf05fa7dfe3ac0941:
PASS integral sobre15rutas contra main e314c8d con descripción realista de PR.
Padres del merge:6fe686a y e314c8d, sin reescritura de historia. Revisados diff
final, enlaces, ausencia de conflictos y conservación de FaseB. Publicación
autorizada mediante push normal a la rama remota de PR85, sin fusionar la PR;
los resultados de GitHub Actions deberán comprobarse tras la publicación.

## 2026-10-23 — Fase B: estado actual independiente y agrupamiento numérico

### Resumen, base y decisiones

Rama feat/campaign-current-status-and-number-format sobre main e314c8d48e8deb091206bd8a3aadb3dff910929e. Fase A no fusionada: dependencia 6fe686ad incorporada en 2f3791e, sin modificar main. El nuevo encargo autoriza implementar persistencia/operación deshabilitada. La propuesta original queda como antecedente, resuelta con una tabla y auditoría reutilizada.

Estado actual deja de depender de métricas diarias: inventario Meta/Google por lotes, identidad plataforma/cuenta/ID textual, último válido separado de observación, frescura <24h sin intentos posteriores. Metadata current_status aditiva después de caché económica; CSV/filtros históricos intactos. Un filtro histórico activo puede devolver una campaña con estado actual Pausada. Formato es-ES agrupado explícitamente en todo Campañas, sin mutar números ni IDs; ejes sin k/M. Preferencias v7 migradas selectivamente.

### Archivos y cambios de base de datos

- CampaignCurrentStatusService, comando SyncCampaignCurrentStatus, config campaign_current_status y migración aditiva campaign_current_statuses: implementación nueva aislada.
- MetaAdsClient/GoogleAdsClient: métodos paginados independientes; CampaignDashboardDatasetService: decoración posterior a caché solo en filas JSON.
- OperationalTaskCatalog/Service/HealthService y routes/console: tarea habilitable, reserva persistente, comprobación al ejecutar y supervisión que omite deshabilitadas.
- campaign-presentation.js/campaigns-dashboard.js: Estado actual, tooltips, frescura defensiva, agrupamiento y preferencias.
- CampaignCurrentStatusTest, CampaignDashboardTest, AssertsOperationalSchedule y tests JS: regresiones nuevas/mantenidas; assets Vite/manifest generados por el agente principal.
- Validador documental y prueba: mapping explícito del nuevo config. Guía, informe, matriz, decisiones pendientes, operaciones, proposal, PROJECT_CONTEXT, DECISIONS y este HANDOFF: trazabilidad focal. CAMPAIGN_PHASE_B_VALIDATION contiene contrato PR y evidencia.

Una sola tabla nueva con índice único (platform,account_id,campaign_id), índice sync_source y FK nullable al run; down elimina solo esa tabla. Se reutiliza report_sync_runs por cuenta y operational_task_runs para supervisión. No se ejecutó migración real ni se modifican tablas económicas. No se crea una segunda tabla de auditoría.

### Seguridad y rendimiento

Tarea campaigns-sync-current-status deshabilitada por defecto mediante CAMPAIGN_CURRENT_STATUS_ENABLED=false; scheduler cada seis horas minuto37 Europe/Madrid solo tras activación explícita. Ejecución manual conserva admin activo y confirmación existentes. Reserva persistente más lock global14400s, sin endpoint público. Identificadores estrictos, errors sanitizados, sin tokens/payloads completos en logs, sin auth/CSP modificados. No se sincronizan proveedores reales.

Paginación por cuenta, cien páginas/50.000 campañas, upsert500, máximo10cuentas por plataforma. Timeout30s por intento, dos intentos; presupuesto comprobado antes de página a540s más la última página y OAuth Google previo hasta60s. No es un deadline global duro. Fallo de cuenta no publica inventario parcial; otras cuentas completan. Unión de lectura por lote local fuera caché; sin consultas por fila ni red HTTP de dashboard. La cadencia se separa de horarios principales, sin garantizar ausencia absoluta de carga concurrente.

### Evidencia y pruebas

Resultados focales comunicados por el agente principal: CampaignCurrentStatusTest24/24 (154 aserciones), existentes campañas/comandos/sync legacy/operaciones167/167 (2279), integración nueva1/1 (20), JS18/18. Los comandos y resultados finales de suite/build/Pint se registrarán al cierre principal; no se anticipa su éxito. La prueba económica compara KPIs, CSV exacto, filas legacy y seis tablas antes/después de sync simulado con cierre sintético. No se inspecciona producción ni se reconstruye atribución real.

Revisión documental delegada al agente documental: contraste del diff completo contra main, archivos nuevos, clientes, esquema, servicio, proyección, operaciones y pruebas. Matriz focal sin certificar auditoría integral de KPIs. No hay fallback ni copia de código en documentos. Verificación estructural requiere commit final y contrato PR en CAMPAIGN_PHASE_B_VALIDATION; se repetirá tras cierre.

### Acciones humanas y pendientes

No fusionar/desplegar/activar. Migración nueva antes de servir nuevo código web, tarea deshabilitada; código+bundle+manifest del mismo release, sin Node en producción. Activación y primera lectura futuras requieren autorización separada, revisión de cuentas/permisos/carga/retención y supervisión. Rollback deshabilitando tarea, gestionando ejecuciones activas y restaurando release completo, conservando tabla nueva/auditoría y todo histórico. QA visual real, cobertura de proveedores y MariaDB no quedan certificados por mocks/SQLite. Runbook detallado: operaciones-produccion.md.

Ampliación final del contrato: `current_status` incluye `observed_at` y `last_valid_raw_status`, con identidad canónica o null si no es verificable, sin modificar campos legacy. La consulta filtra IDs solicitados y divide en lotes de hasta500 para evitar límites SQL; no lee todo el inventario de las cuentas. Stats por cuenta incorporan operational_run_id,pages,campaigns,recognized,unverifiable; los conteos leídos no confirman códigos desconocidos. Pint focal aplicado y sintaxis PHP correctos; JS18/18 tras ajustes. Suite documental:36 ejecutadas,35 correctas y1 omitida por falta de privilegio Windows para symlink. Build inicial correcto; se repetirá tras el ajuste final del tooltip. Suite general aún en curso al registrar esta ampliación.

Verificación documental del agente: primera repetición encontró cinco fallos de fixture por enlace de matriz a documento nuevo aún no tracked; se sustituyó por HANDOFF existente (sin alterar pruebas). Segunda repetición: resultado comunicado al agente principal al cierre. Validador CLI ejecutado con SHA completo de HEAD previo: no valida todavía la Fase B sin commit (rechaza OutsidePaths no presentes en diff comprometido); repetir tras commit final. Un intento con literal HEAD fue rechazado por exigir SHA de40 caracteres. git diff --check correcto. Estas limitaciones no son fallos de código económico.

Actualización de presentación final: temporizador único para próxima caducidad y revalidación al volver visible la pestaña; actualiza solo texto/title de celdas, sin peticiones ni métricas nuevas. Test de página abierta incluido, JS19/19 correctas según ejecución del agente principal. Bundle final se recompila después de este ajuste; su resultado y la suite general se registrarán al cerrar.

Tercera repetición documental: se retira el enlace Markdown adicional de la matriz a HANDOFF, manteniendo su ruta textual, porque la fixture de tests copia únicamente su inventario explícito de documentos. Los cinco fallos previos fueron de esos destinos ausentes dentro de la fixture, no del repositorio real; la referencia funcional existente permanece enlazada. No se cambió código ni tests para resolverlo.

Resultado documental definitivo del agente: Python -B -m unittest discover -s tests/documentation -p test_*.py,36 ejecutadas,35 correctas y1 skip por privilegio de symlink Windows,37,064s. git diff --check correcto. Validador integral queda a cargo del agente principal tras commit con SHA completo, usando CAMPAIGN_PHASE_B_VALIDATION como cuerpo PR. La comparación de semántica/refs documentales fue focal al informe Campañas y motor operacional afectado; no certifica fichas de otros informes.


Cierre focal del agente principal: PHP estados+dashboard 75/75 y693 aserciones; JS19/19; Pint --test pasado; sintaxis PHP y node --check correctos. Build final correcto con campaigns-dashboard-DlRi4wRD.js y manifest comprobados; se restauran los bundles anteriores eliminados por la limpieza de Vite. Sin cambios en sincronizadores históricos, CampaignInvestmentClosureService ni constructor económico. Ninguna migración, llamada real, despliegue o activación realizados. Revisión documental delegada terminada y diff revisado por el principal. Suite completa y validador contra commit final pendientes todavía; resultados se añaden a continuación al terminar.

### Revisión incremental posterior a 29ab39f — evidencia de auditoría obligatoria

Diff revisado completo: CampaignCurrentStatusService::decorate añade join del último run y exige status completed +referencias no nulas/coincidentes. Se evita que dos null tras retención, o un mismo ID fallido, acrediten frescura de un estado reciente. Regresión nueva cubre ambos casos. Informe/matriz/validación actualizados; no cambia metadata económica, CSV ni número de consultas por lote.

Resultados comunicados por el agente principal: PHP final76/76,696 aserciones (25 estados+51 dashboard); JS19/19; build final correcto DlRi4wRD.js60,68kB y manifest con destinos existentes. Validador pasó sobre29ab39f,34rutas; repetir al final. Primer intento de suite general interrumpido por max_execution_time120 local; reiniciada con subscriber PHPUnitPrepared aislado que restablece límite0 antes de cada test, sin cambios app/ini. Suite general en curso, sin éxito anticipado. Pint se repetirá tras este ajuste. No se ejecutaron sincronizaciones/migraciones reales.

Última revisión B2: diff cellHtml+test real confirma title numérico con mismo texto agrupado visible, conservando razones de costes/estado, escape y datos originales. JS20/20 correctas; Pint --test/PHP-l finales correctos comunicados por el principal. Rebuild posterior en curso: DlRi4wRD es hash de iteración previa, el principal registrará el asset final y suite general al cierre. No se anticipa éxito de esta suite.


Asset definitivo: campaigns-dashboard-DisEWXqJ.js (60,73kB), build completo24,86s y manifest verificados, bundles previos conservados. PHP final76/696 y JS20/20; Pint --test y PHP -l del último endurecimiento correctos. La primera suite general abortó al alcanzar120s reactivados por servicios existentes; el runner final restablece max_execution_time=0 al preparar cada test, solo en CLI, sin cambiar código/configuración productiva. Se detuvo el intento intermedio para ejecutar la versión PHP final. Suite completa final en curso; no se anticipa resultado. Lista completa de archivos y comandos en CAMPAIGN_PHASE_B_VALIDATION.md.

### Ajuste final del contrato de proveedor

Diff incremental revisado: Meta sustituye filtering por parámetro efectivo `effective_status` lista JSON6estados sin rango temporal, conforme a AdAccount.get_campaigns del SDK oficial. El test HTTP exige esa forma y la inclusión de ARCHIVED/DELETED. Servicio y decoder Google rechazan atributos requeridos ausentes; null explícito sigue siendo código no verificable y no renueva último válido. Ausencia invalida cuenta completa y preserva filas anteriores. Sin cambios de economía, identidades o filtros.

Build definitivo comunicado: campaigns-dashboard-DisEWXqJ.js60,73kB junto a manifest; JS20/20. Fuente oficial contrastada por el agente principal: https://raw.githubusercontent.com/facebook/facebook-python-business-sdk/main/facebook_business/adobjects/adaccount.py (AdAccount.get_campaigns). No se realiza consulta a cuentas reales.

### Cierre final Fase B — resultado y limitación general

PHP focal final:76 pruebas/699 aserciones correctas; batería existente campañas/operaciones167/2279 correcta (con solapamiento, no sumar como únicos); JS20/20; documentación35pass+1skipWindows; build, sintaxis, Pint final y diff--check correctos. Bundle vigente DisEWXqJ con manifest y archivos comprobados. Revisión documental delegada finalizada y revisada por el principal.

Suite PHP general no completada: fatal120s en primer intento; repetición CLI con subscriber terminó código1 sin salida concluyente; repetición paralela4workers registra WorkerCrashedException en DashboardMetricPercentStyleTest por Maximumexecutiontime120seconds en Container.php1541. No se modifica código de otros informes ni se declara éxito global. Repetir suite completa en CI con límites/recursos apropiados antes de integrar. Evidencia, comandos y lista completa de archivos en CAMPAIGN_PHASE_B_VALIDATION.md; su cierre prevalece sobre cifras intermedias anteriores.

Sin migración productiva, sincronización real, activación, push, merge ni despliegue. Una tabla operativa aditiva; auditoría reutiliza report_sync_runs. Tarea cada6h a minuto37 Madrid, deshabilitada por defecto; activación explícita posterior separada. Rollback restaura release completo conservando tabla y auditoría; jamás toca históricos. Integridad de KPIs/CSV/cierres/atribuciones demostrada con fixtures, no con producción. QA de cuentas reales, cobertura/permisos, capacidad/MariaDB y suite completa pendientes.

Verificación posterior al commit ff9e005: validador documental PASS35rutas contra e314c8d usando cuerpo PR estructurado; repetición JS20/20; manifest apunta DisEWXqJ y todos sus archivos existen; diff--check correcto y árbol limpio. Commits:2f3791e dependenciaA,29ab39f implementaciónB,ff9e005 endurecimiento/evidencia. No se declara completada la validación general.


## Integración limpia de Fase B tras PR #85 — revisión documental

Worktree aislado y rama feat/campaign-current-status-ready desde origin/main 89282a32b79060fca671adee4bc49741c954308f. Se trasladan con cherry-pick -x solo29ab39f→b8f0a20, ff9e005→5ff5a43,59e822b→2fdc3a7; no se repite2f3791e ni Fase A. El principal contrasta grafo y conserva ramas originales. El agente report_docs_maintainer revisa diff completo, incluida prueba opt-in nueva y corrección DATETIME UTC. Matriz general de main/PR85 preservada, 23 campos/seis informes/fichas pendientes, con impacto focal B sin certificar auditoría integral.

Documentos actualizados: informe-campanas, matriz-trazabilidad-kpi, operaciones-produccion, propuesta, validaciónB, DECISIONS y HANDOFF. Único ajuste de persistencia adicional: cuatro fechas DATETIME civil UTC (observación, verificación, creación y actualización) para evitar conversión de sesión MariaDB; tabla independiente, unicidad textual y auditoría existente, sin migraciones/tablas históricas modificadas. No hay consultas productivas ni sincronizaciones reales. Permisos/escape/error genérico conservados. Lectura local por lotes fuera de caché económica, sin N+1 ni red HTTP. Tarea cada6h Madrid deshabilitada; activación futura expresa.

Resultados comunicados al comenzar cierre documental: DashboardMetricPercentStyleTest pasa aislado1/6 y paralelo2workers1/6; fallo previo fue límite120s de worker, no aserción. Suite general serial aún en curso, sin declarar éxito. MariaDB11.4.13 aislada: migraciones completas correctas e integración inicial4/20; repetición con EXPLAIN y focales MariaDB pendientes. JS20/20, build20,38s correcto con bundleDisEWXqJ idéntico; conserva CSScompartido/manifest y bundles anteriores. Pint aplicado. Principal completará evidencia final, validador documental con SHA final y diffcheck. Historial anterior conserva limitaciones previas; prevalecerá cierre de integración actual.

Despliegue/rollback documentados en operaciones-produccion: migración nueva antes del código web bajo autorización futura, tarea off, publicación atómica código/assets/manifest; rollback de release conserva tabla/auditoría. Pendientes: suite completa final, pruebas documentales, repetición MariaDB y QA/cobertura/capacidad con cuentas reales autorizadas. No merge, despliegue ni activación.

Actualización de experimento MariaDB: 117 pruebas focales; estados 25/160 y operaciones 41/664 correctos, Dashboard con 20 errores SQL (sin fallos de aserción): ISO8601+offset en DATETIME legacy y longitud de IDs sintéticos >18. Fixture B acortada a sf-current-status; restantes pendientes de comparación con main 89282a3 en base aislada distinta. No afirmar suite MariaDB completa correcta ni calificar errores como previos hasta verificar baseline. Suite general SQLite sigue en curso.

Cierre del agente documental: revisión delegada real, sin fallback. Ejecutado python -B -m unittest discover -s tests/documentation -p test_*.py: 37 pruebas en 57,455 s, OK con una omitida por privilegio symlink Windows. Comprobación de enlaces locales de los siete docs modificados correcta; matriz de origin/main conservada literalmente completa como prefijo, sin perder fichas, contrato ni evidencia A. git diff --check correcto. No se realiza commit/push por el agente; principal completa resultados en curso y validador integral con SHA final.

### Cierre incremental de persistencia y pruebas B sobre MariaDB

Las cuatro fechas de la tabla nueva (observación, verificación, creación, actualización) son DATETIME UTC; created_at/updated_at mediante datetimes() de Laravel comprobado. Final MariaDB Integration 4 pruebas/22 aserciones correcto (índice/EXPLAIN, strings, unicidad, UTC entre sesiones, dos upserts concurrentes y reversibilidad sin cambios de esquemas históricos). Focales B MariaDB 67/844 correctos: estados 25/160, operaciones 41/664 y regresión económica B 1/20. Integration no forma parte de phpunit.xml: requiere archivo explícito, flag opt-in y guardas de base local nueva. No son pruebas de datos o proveedores productivos.

Diff contra B original 59e822b en app/resources/config/routes vacío, contrastado por el agente documental. Ajustes adicionales limitados a tipos temporales de migración nueva, fixture B acortada y nuevo test Integration. PHP -l 15 archivos correcto; Pint final pendiente de repetición tras última aserción. Diagnóstico del timeout porcentual contrastado con manual PHP set_time_limit: 0 ilimitado, contador reiniciado y Windows mide tiempo real; no hubo fallo de aserción del test aislado/paralelo. Suite general y baseline MariaDB aún en curso: no se certifica éxito global ni se extrapola el diagnóstico a otros errores.

### Comparación MariaDB con main confirmada

El principal ejecutó Dashboard en main 89282a3 mediante worktree de control y base MariaDB nueva independiente: 50 pruebas/326 aserciones, 31 correctas y 19 errores, sin fallos de aserción. Comparación programática normalizando solo esquema confirma los mismos 19 nombres, SQLSTATE y columnas del experimento B: 8 errores 22007 por fechas ISO8601+offset en interest_sync_cutoff_at y 11 errores 22001 por IDs sintéticos interest_id/salesforce_id >18 caracteres. La fixture adicional B se corrigió y sus focales 67/844 pasan. Las cuatro pruebas Integration pasan con 22 aserciones.

La evidencia externa phaseb-mariadb-baseline-comparison.json está sanitizada, sin SQL, datos ni secretos. No se presenta Dashboard completo MariaDB como correcto: los 19 casos de main necesitan revisión separada de compatibilidad/fixtures; no se cambian para silenciar resultados ni se extrapola a producción. Suite general SQLite sigue en curso; resultado final a cargo del principal.

### Primer intento general completo de integración

El runner serial terminó 1458 pruebas/13447 aserciones en 1269,583 s: 0 errores y 2 fallos de rendimiento. StockRecommendationCandidatePaginationTest::test_calcula_un_volumen_equivalente_a_produccion_sin_agotar_el_tiempo_de_peticion midió 33,468168 s (límite <20); CallsEndpointDoesNotTimeoutTest::test_summary_responde_con_volumen_razonable, 5,683645 s (límite <5). DashboardMetricPercentStyleTest pasó. Tests y servicios afectados Stock/Calls son idénticos a main. Se registró concurrencia con otras validaciones; no se declara causa demostrada. Principal repite clases aisladas y suite sin carga pesada; no se alteran umbrales, pruebas ni lógica de otros informes. No afirmar suite general verde mientras no termine la repetición. Instancia MariaDB temporal detenida ordenadamente tras completar pruebas; no producción.

Repetición aislada de las dos clases de rendimiento tras detener MariaDB: StockRecommendationCandidatePaginationTest y CallsEndpointDoesNotTimeoutTest correctas, 3 pruebas/16 aserciones en 16,258 s, sin cambiar umbrales. Segunda suite general serial completa en curso, sin otras validaciones pesadas concurrentes; éxito global todavía no acreditado.

## Cierre definitivo de suite general de integración Fase B

Segunda suite completa serial SQLite (Unit y Feature) finalizada correctamente: **1458 pruebas/13447 aserciones, 0 errores, 0 fallos y 0 omitidas**, exit 0, 494,710877 s (8 min 14,71 s). Evidencia externa JUnit phaseb-final-full-suite.xml. Se ejecutó sin MariaDB, builds ni tests concurrentes, sin modificar umbrales ni eliminar pruebas. Primer intento completo con dos fallos de rendimiento queda registrado como historia, no como resultado final. Duración total JUnit de casos finales: Stock 14,812110 s/3 aserciones, Calls 2,699829 s/3 y porcentual 1,521623 s/6; no confundir con medición interna usada por el umbral.

Persisten como límite separado los 19 errores Dashboard/MariaDB reproducidos también en main; no se declara toda la suite MariaDB verde. Focales B MariaDB 67/844 e Integration 4/22 correctos; Integration no incluida en phpunit.xml. JS 20/20 y build correcto, bundle/manifest preservados. Principal completa Pint/sintaxis/validador documental tras commit y revisa diff final. Tarea off, sin activación ni despliegue, sin histórico alterado.


### Cierre estático de integración Fase B

Pint --test y PHP -l: 15 archivos correctos. Pruebas documentales finales: 37, con 36 correctas y una omitida por symlink Windows. git diff --check correcto. Configuración sin variable de activación: enabled=false, manual_allowed=false y scheduler_filters_pass=false; cron 37 */6 * * *, Europe/Madrid. Suite general final: 1458 pruebas/13447 aserciones, cero errores, fallos u omisiones, 494,710877 s. Validador integral se ejecuta sobre el SHA del commit de integración. No se ha activado la tarea ni publicado o desplegado código.

Validador integral ejecutado contra e26dcb2 (base 89282a3), con descripción realista incluida en CAMPAIGN_PHASE_B_VALIDATION.md: PASS, 33 rutas cambiadas. Se conserva la descripción con Docs-Impact, Docs-Matrix-Impact y excepciones operacionales justificadas. Rama local preparada para revisión; sin push ni PR publicados.

## 2026-10-23 — Protección de identidad del estado actual (base 46f283f)

- Alcance: Campañas / Detalle. Durante mergeRows se registran cuentas canónicas de las contribuciones publicitarias por la clave económica existente. Múltiples cuentas, incluida desconocida junto con conocida, marcan identidad ambigua. La atribución Salesforce sin cuenta no crea una cuenta publicitaria adicional. No se cambia rowKey ni la conciliación.
- Contrato: decorate omite lookup de la fila ambigua; current_status conserva formato aditivo y añade identity_ambiguous:true/reason:ambiguous_account_identity, sin cuenta, códigos ni fechas operativas; tooltip Estado no disponible explica la ambigüedad. Una cuenta inequívoca conserva estado y frescura. ExportRows retira metadata y CSV permanece igual. Payload v6 evita reutilización de caché sin marca.
- Archivos funcionales revisados: CampaignDashboardDatasetService.php, CampaignCurrentStatusService.php, campaign-presentation.js; tests CampaignDashboardTest.php, CampaignCurrentStatusTest.php y campaign-presentation.test.mjs; bundle/manifest. Documentación actualizada: informe-campanas.md, matriz-trazabilidad-kpi.md, CAMPAIGN_CURRENT_STATUS_PROPOSAL.md, CAMPAIGN_PHASE_B_VALIDATION.md y este HANDOFF.
- Base de datos: sin nuevas tablas, migraciones ni escrituras históricas. Seguridad: no se exponen cuentas adicionales ni credenciales; tooltip constante y escape existente. Rendimiento: registro lineal durante agregación y omisión de lookup ambiguo, sin consultas N+1/red. Sin cambios de arquitectura global ni decisión irreversible que requieran PROJECT_CONTEXT o DECISIONS.
- Evidencia comunicada: PHP focal79/735 y JS21/21 correctos; build repetido10,12s correcto, bundle campaigns-dashboard-Bo45id63.js; primer intento UTF8 corregido. Regresión dos cuentas con mismo ID/nombre normalizado comprueba inversión350, KPIs/CSV/métricas estables y estado neutral; filtro de cuenta mantiene230/Pausada. Suite general nueva en curso; no sustituirla por el resultado anterior. Principal añadirá cierre/commit/validador.
- Revisión documental delegada: contraste de diff funcional/tests y guía; solo docs modificadas por el agente documental. No se certifica auditoría integral de KPIs ni datos productivos. Se conserva historia de la limitación anterior, señalando que esta proyección ya se neutraliza.
- Manual/pending: publicar release con bundle/manifest del mismo build y caché v6; no requiere nueva migración ni activación. No merge/deploy/sync real. Limitaciones MariaDB previas siguen registradas, no resueltas por este ajuste. Validar documentación sobre SHA final tras commit.

Verificacion documental del ajuste: 37 tests del validador en 46,565 s, 36 correctos y uno omitido por symlink Windows; 33 enlaces locales de los cinco documentos revisados existen, git diff --check correcto. Principal confirma Pint y PHP -l de cuatro PHP, node --check de dos modulos y manifest/bundle correctos. El validador integral debe repetirse tras commit final con SHA completo; no valida los cambios aun sin commit.


### Validación general de la protección de identidad — intentos registrados

Primer intento completo: 1461 pruebas/13483 aserciones, 0 errores, 1 fallo, 0 omitidas, 637,456082 s. Único fallo: StockRecommendationCandidatePaginationTest::test_calcula_un_volumen_equivalente_a_produccion_sin_agotar_el_tiempo_de_peticion, cálculo 23,0175765 s frente al umbral <20. Repetición aislada de la clase: 2 pruebas/13 aserciones, 0 errores y 1 fallo, 33,478901 s; mismo caso, cálculo 25,3288557 s. No se modifica Stock ni el test, no se eliminan pruebas ni se relajan umbrales. La segunda ejecución completa está en curso y sus resultados se registrarán al terminar. Estos fallos no acreditan una regresión económica ni se presentan como éxito global.

Comandos: PHP CLI con extensiones existentes y runner serial de pruebas, --compact --filter='CampaignCurrentStatusTest|CampaignDashboardTest' (79/735); suite general sin --filter, --log-junit para cada ejecución; Stock aislado con --filter=StockRecommendationCandidatePaginationTest; node --test tests/js/campaign-presentation.test.mjs (21/21); npm run build (repetición correcta), Pint --test y PHP -l en los cuatro PHP modificados, node --check de ambos módulos, git diff --check. Solo bases sintéticas de pruebas, sin proveedores reales. El runner CLI evita límites de tiempo heredados en Windows; no cambia archivos ni configuración de aplicación.


### Resultado definitivo de esta corrección

Segunda suite general completa serial: **1461 pruebas, 13483 aserciones, 0 errores, 0 fallos, 0 omitidas**, exit 0, 380,208754 s. JUnit externo identity-final-full-suite.xml. Incluye campañas, atribuciones, cierres y el caso Stock antes fallido, sin modificar umbrales ni eliminar pruebas. Los intentos anteriores quedan registrados como variabilidad local observada; no se afirma una causa demostrada. Código PHP y pruebas congelados durante ambas ejecuciones. Focales 79/735 y JS21/21 correctos; Pint, PHP -l, node --check y diffcheck correctos. Revisión documental delegada finalizada y revisada: 36 correctas/1 omitida, 33 enlaces existentes. No se ejecutaron nuevas pruebas MariaDB ni operaciones productivas; ninguna migración nueva.

Diff de esta tarea contra 46f283f: dos servicios PHP, helper de presentación, tres archivos de pruebas, cinco documentos, bundle Bo45id63 y manifest (13 archivos). Solo cambia metadato/tooltip; rowKey, sumas, fórmulas, filtros y CSV económico se conservan. Despliegue futuro: publicar código y assets/manifest juntos; payload v6 omite cachés v5 sin marca automáticamente. Sin migraciones adicionales ni activación. Rollback: restaurar release 46f283f con su bundle/manifest; conserva todos los datos, pero vuelve a la limitación de identidad anterior y requiere revisión antes de activar sincronización. No hubo push, merge, despliegue ni sincronización real.

Validador integral ejecutado correctamente contra el commit de la corrección: PASS para las 13 rutas de esta tarea (base 46f283f) y para las 34 rutas del alcance completo B (base 89282a3), con sus contratos Docs-Impact/Docs-Matrix-Impact. Diff final revisado y sin cambios fuera del alcance; árbol limpio al entregar. Mensaje de commit: fix(campaigns): neutralize current status for ambiguous account identity.

## Iteración visual y diagnóstico operacional de Campañas (2026-10-26)

- Base: `e015b6b43fac0a881de62cb7440929fa3ed4ee34`, rama nueva `fix/campaign-dashboard-visual-sync-diagnostic` sobre Fase B avanzada; no se toca `main`, el checkout productivo ni los otros worktrees. Informe afectado: Campañas, filtros, comparativa, tarjetas KPI y panel administrativo de diagnóstico.
- Causa: las ayudas hacían crecer el bloque de filtros; la comparativa quedaba bajo los KPIs y fabricaba una tarjeta cero para un proveedor ausente; «Conciliación por origen» ocupaba espacio pese a que se pidió retirarla solo de pantalla; el botón de auditoría crecía verticalmente en cada tarjeta. El rótulo «Última sync Google Ads» empleaba `MAX(campaign_platform_daily_metrics.synced_at)`, fecha de fila de métricas que no acredita la última ejecución correcta. Los formateadores agrupados ya existían en la base, pero faltaban regresiones para los ejemplos observados en varios renderizadores.
- Solución: filtros compactos sin textos auxiliares visibles, comparativa justo bajo filtros con acentos Meta/Google y sin tarjetas inventadas, eliminación del bloque visual de conciliación, «Auditar KPI» en la cabecera superior derecha, pruebas ampliadas de formato `es-ES`. El panel muestra ejecución correcta y último intento desde una sola lectura de `operational_task_states`/`operational_task_runs`, separando el último `synced_at` de métricas; marca la ejecución antigua tras 36 h y muestra ausencia veraz. Fecha UTC convertida a Europe/Madrid. Cada apertura solicita evidencia nueva sin caché HTTP; solo se deduplican peticiones simultáneas. `source_reconciliation`, importes, KPIs, atribuciones, filtros, CSV y `current_status` conservan contrato y semántica.
- Archivos de código y pruebas de esta tarea: `app/Http/Controllers/Reports/Campaigns/CampaignDashboardController.php` (retira prop de vista ya sin uso), `app/Services/Campaigns/CampaignDashboardDatasetService.php`, `resources/views/reports/campaigns/index.blade.php`, `resources/js/reports/campaigns-dashboard.js`, `resources/css/reports/leads-dashboard.css`, `tests/Feature/CampaignDashboardTest.php`, `tests/Feature/ReportAccessManagementTest.php`, `tests/js/campaign-presentation.test.mjs`; `public/build/manifest.json` y bundles generados. Documentos: `docs/informe-campanas.md`, `docs/operaciones-produccion.md` y este HANDOFF. La matriz de 23 campos queda sin edición: no cambia fuente, universo, fecha pivote, filtro, fórmula, deduplicación, redondeo ni auditoría de ningún KPI; el campo nuevo es diagnóstico operacional, no indicador económico. La auditoría integral de la matriz sigue pendiente.
- Base de datos: sin migraciones, escrituras ni cambios de tablas. Seguridad: diagnóstico condicionado al permiso administrativo ya existente; escape HTML mantenido; no se exponen credenciales ni cuentas adicionales, no hay acceso remoto durante HTTP ni sincronizaciones ejecutadas. Rendimiento: una consulta local por resumen administrativo, fuera de la caché económica, sin N+1. Se conserva el cálculo `source_reconciliation` del JSON por compatibilidad, por lo que retirarlo de pantalla no reduce esa carga backend.
- Pruebas: PHP focal `CampaignDashboardTest`, `CampaignCurrentStatusTest` y `ReportAccessManagementTest`: 89/89, 819 aserciones; JavaScript 25/25; `npm run build` correcto, manifest de 27 entradas y sin archivos referenciados ausentes; Pint focal correcto. Suite completa inicial: 1462 pruebas, dos fallos: una aserción de acceso obsoleta corregida y verificada en focal; `OperationalTasksTest::test_cadences_never_executed_stale_and_madrid_dst` reproducido también aislado sobre la base sin cambios. Reejecución de la suite excluyendo únicamente ese caso preexistente: 1461/1461, sin fallos ni omisiones. La suite sin exclusiones no se declara superada. Pruebas del validador documental: 37 ejecutadas, 36 correctas y una omitida; diff documental sin errores de espacios. No se hicieron pruebas contra proveedores ni datos productivos.
- Acciones manuales futuras: revisión visual en escritorio y móvil con permisos reales; verificar en el monitor operativo el último éxito/último intento y el último dato de métricas de Google antes de diagnosticar un desfase productivo. Publicar código y assets Vite/manifest del mismo release solo tras autorización; no requiere migración nueva ni activar tareas. Rollback de código con bundle/manifest previos; no tocar históricos ni cierres. Riesgo pendiente: comandos ejecutados fuera de `operations` pueden no tener run acreditado; la ausencia de ejecución en el panel no prueba ausencia de llamada al proveedor. No se ha verificado la situación real de Google en producción.
- Revisión documental delegada al agente `report_docs_maintainer` sobre diff completo; actualización focal de Campañas, operación y HANDOFF, sin cambios cosméticos de matriz ni otros informes. El validador documental integral requiere SHA final y descripción de PR tras commit; no declarar su resultado antes de ejecutarlo.

## PR #86: fixture de cadencia DST y contrato documental (2026-10-26)

- El job CI `121507012800` (PHP 8.4.27, Ubuntu) falló en `OperationalTasksTest.php:240`: `stale` era `false` en vez de `true`. La migración de `RefreshDatabase` fijaba `monitoring_started_at` al reloj real (26/10/2026); después el test viajaba al 25/10/2026, antes del inicio de supervisión, de modo que ninguna ejecución era exigible. El mismo caso falla aislado en `main` 89282a3 y en el head anterior del PR; no se identificó defecto en `OperationalTaskHealthService` ni en las cadencias.
- La corrección solo modifica `tests/Feature/OperationalTasksTest.php`: fija en SQLite de pruebas una activación sintética anterior al día simulado y añade regresiones para las dos ocurrencias de las 02:20 en `Europe/Madrid`, tarea nunca ejecutada pendiente y después vencida, y tarea de estado actual deshabilitada. El catálogo, scheduler, reservas y bloqueos permanecen intactos.
- El job documental `121507012366` falló porque la descripción de PR estaba vacía. El diff acumulado desde `main` incluye Fase B, matriz/documentación y la iteración visual. La descripción debe declarar `Docs-Impact: updated`, `Docs-Matrix-Impact: updated` y las cuatro rutas operacionales ajenas al mapa en listas JSON de `Docs-Outside-Paths`/`Docs-Outside-Evidence`. No se altera la matriz en este commit correctivo.
- Base de datos: sin migraciones ni escrituras fuera de SQLite de pruebas. Seguridad: no cambia autorización, credenciales ni ejecución de tareas; no se ejecutan proveedores reales. Rendimiento: sin cambio de producción ni consultas añadidas. No cambia ningún informe, KPI, atribución, CSV, cierre o asset; por tanto, esta corrección no exige actualizar documentos funcionales de informes ni `PROJECT_CONTEXT.md`.
- Pruebas locales: reproducción aislada del fallo en `main`; test DST corregido 1/1; suite operacional 43/43, 678 aserciones; suite PHP completa sin exclusiones 1464/1464, sin fallos ni omisiones; pruebas documentales 36 correctas y una omitida por entorno. Pint focal, sintaxis PHP y `git diff --check` correctos. El validador integral pasó para las 41 rutas del head previo con el cuerpo exacto propuesto; debe repetirse con el SHA del commit correctivo antes de actualizar el PR.
- Acción manual: publicar un push normal del commit correctivo y actualizar la descripción de PR #86; observar ambas comprobaciones GitHub Actions. No hacer merge, despliegue ni activar la sincronización de estados. Riesgo conocido: la ejecución local usa PHP 8.4.21 en Windows frente a PHP 8.4.27 en Ubuntu CI; el lockfile de Composer es el mismo y la verificación definitiva en esa versión corresponde al rerun de CI.

## Fase C Campañas — catálogo publicitario y diagnóstico pendiente (2026-10-26)

- Rama `feat/campaign-phase-c-final`, base `f714dad79f653869b8e06e3b4b7ab45403cdffaa`. Informe y secciones: Campañas / Detalle, comparativa por plataforma, rankings, filtros, CSV de campañas, snapshots nuevos, columna Estado actual y tarjetas KPI. El diff funcional se contrastó antes de documentar. Archivos documentales modificados: `docs/informe-campanas.md`, `docs/matriz-trazabilidad-kpi.md`, `docs/operaciones-produccion.md`, `docs/ai/CAMPAIGN_SALESFORCE_CLEANUP_PROPOSAL.md` y este HANDOFF.
- Archivos de aplicación, pruebas y assets modificados: `app/Services/Campaigns/CampaignDashboardDatasetService.php`, `app/Http/Controllers/Reports/Campaigns/CampaignDashboardController.php`, `app/Console/Commands/RefreshCampaignsReportCommand.php`, `resources/views/reports/campaigns/index.blade.php`, `resources/js/reports/campaigns-dashboard.js`, `resources/css/reports/leads-dashboard.css`, `tests/Feature/CampaignDashboardTest.php`, `tests/Feature/CampaignCommandsTest.php`, `tests/js/campaign-presentation.test.mjs`, `public/build/manifest.json` y los bundles Vite de campañas y estilos de leads referenciados por él.
- Decisión: `CampaignDashboardDatasetService::campaignEntityRows()` restringe entidades expuestas a Meta/Google y los snapshots nuevos aplican esa misma elegibilidad. `campaign_universes.salesforce_only` permanece como lista vacía por compatibilidad; rankings y CSV de entidades cambian deliberadamente su universo. Los KPI agregados, atribuciones de Interest, auditorías y `source_reconciliation` conservan Salesforce-only. Opciones de campaña proceden de métricas de Meta/Google. Se versiona la caché de payload/opciones (`v7`/`v3`). El endpoint de clasificación solo acepta plataformas publicitarias. El filtro visible se llama Estado, con valores y semántica históricos intactos; se quita el subtítulo interno de comparativa y se reduce el botón de auditoría con línea de 22 px.
- Persistencia: no se crea ni modifica esquema. No existe tabla física de catálogo. `CampaignAttributionBuilderService::salesforceOnlyCampaign()` sigue guardando identidad comercial Salesforce en `campaign_attributions` y `campaign_lead_attributions`; impedirlo alteraría resultados económicos. Los snapshots previos y posibles clasificaciones Salesforce anteriores no se tocan. La propuesta de limpieza es solo inventario/dry-run de lectura; no hay borrado seguro identificado ni comando apply autorizado.
- Estado operativo: `campaign_current_statuses` sigue siendo la única fuente local; `CAMPAIGN_CURRENT_STATUS_ENABLED=false` por defecto. No se consultaron tabla, runs, cuentas ni credenciales productivas ni se ejecutaron APIs. «Estado no disponible» no prueba un fallo de proveedor: el runbook exige confirmar tabla, configuración efectiva, runs, IDs, frescura de 24 h, códigos, errores parciales y ambigüedad antes de atribuir causa. Primera sincronización y activación periódica requieren autorizaciones separadas.
- Seguridad: clasificación restringida sin ampliar permisos, sin secretos ni datos personales en documentación, sin eliminación de relaciones comerciales. Rendimiento: filtro de filas en memoria, opciones mediante consulta distinta agregada de métricas, sin N+1 ni llamadas remotas HTTP; versionado dirigido de caché, sin vaciar el sistema. Riesgos: rankings y CSV de entidades cambian por el universo solicitado, y snapshots antiguos podrían contener identidades Salesforce; no afirmar limpieza física ni estado remoto verificado. La revisión visual de áreas clicables en escritorio/móvil y la verificación productiva de estado quedan pendientes.
- Pruebas funcionales comunicadas por el agente principal al estabilizar código: focal 102/102, suite PHP completa 1465/1465, JavaScript 25/25 y build Vite correcto. Pruebas documentales: 37 ejecutadas, 36 correctas y una omitida por entorno; `git diff --check` correcto. La validación integral del PR requiere el SHA del commit final y el cuerpo `Docs-Impact: updated` / `Docs-Matrix-Impact: updated`, por lo que el agente principal debe ejecutarla después de integrar estos documentos. No hay migración ni variable de entorno nueva. Acción manual futura: desplegar código/manifest/assets del mismo build bajo procedimiento aprobado; rollback por release anterior completo, sin tocar datos históricos. Mensaje de commit sugerido: `docs(campaigns): clarify advertising entities and Salesforce attribution in phase C`.

## 2026-10-28 — Disponibilidad controlada de Interests

- Base `2e326b5f8df6cfc4fec7073f709a523c85f98668`. El servicio del dashboard convierte la ausencia o cambio del contexto F2/F5 exigido en `InterestDatasetUnavailable`, con JSON 503 sanitizado y sin caché. El frontend muestra indisponibilidad. Se añaden pruebas de F2 fallido para summary, tablas y auditoría JSON y de F5 no alineado para summary. Véase [contrato del informe](../reglas-negocio-leads.md#disponibilidad-del-dashboard).
- Archivos funcionales: `app/Services/Reports/Leads/SalesforceInterestDashboardDatasetService.php`, `app/Services/Reports/Leads/InterestDatasetUnavailable.php`, `resources/js/reports/leads-dashboard.js`, `tests/Feature/SalesforceInterestDashboardDatasetTest.php`, `tests/js/leads-unavailable.test.mjs`. Documentación: `docs/reglas-negocio-leads.md`, `docs/operaciones-produccion.md`, `docs/ai/HANDOFF.md`. El [procedimiento de investigación](../operaciones-produccion.md#investigación-pendiente-del-incidente-interests-f2-5729) no presupone base certificada ni capacidad de reconstrucción implementada.
- Decisión: rechazar la lectura sin contexto válido; no se selecciona un run anterior porque la tabla viva puede contener escrituras parciales. No cambia fórmula, universo, fuente ni trazabilidad de KPI; matriz sin cambios. No hay migraciones, configuración ni cambios de datos. Se mantienen permisos y se evita incluir identificadores en el error; no se modifican consultas ni sincronización.
- Pruebas focales PHP: 18 casos y 112 aserciones, con advertencias por ausencia de .env y caché Pest en vendor de solo lectura; prueba frontend `tests/js/leads-unavailable.test.mjs` superada, según ejecución del agente principal. Verificación documental integral pendiente del commit y descripción de PR. La garantía ante fallo durante streaming CSV, la arquitectura de generaciones, F2/F5 atómicos, recuperación #5729 y medición de carga siguen pendientes. El backup citado por el usuario no es restaurable; no se ha verificado otro. No ejecutar sincronizaciones, restauraciones ni alterar estados históricos como parte de este cambio.
- Verificaciones adicionales: Pint pasó en los tres PHP modificados; 38 pruebas documentales, con una omisión; build Vite correcto en directorio externo de pruebas, sin actualizar los assets versionados. Si este componente se despliega, debe construirse y publicarse el conjunto coherente de manifest y assets. No se ejecutó prueba representativa MySQL ni suite PHP completa. El mecanismo de generaciones no está implementado y este cambio no está listo para satisfacer el encargo completo.

## 2026-10-28 — F2: atomicidad por bloque, continuación de PR #92

- Diff adicional sobre `5385dec3555fa806d354ba2f2a697b9679b5a572`: `SalesforceInterestChunkPersister` encapsula cada bloque en una transacción; `SalesforceInterestSyncService` elimina la repetición diagnóstica de filas tras un fallo general y conserva el error sanitizado de bloque. La prueba focal verifica rollback dentro del bloque y ausencia de escrituras diagnósticas. La documentación funcional y este handoff recogen el alcance; la nota de respaldo en operaciones se conserva del trabajo concurrente.
- No existen generaciones, cuarentena individual, publicación atómica F2/F5 ni aislamiento de consumidores. Los bloques anteriores pueden seguir persistidos tras el fallo; el 503 existente no hace disponible una versión certificada previa. No se certifica el estado de la tabla actual. La matriz KPI no cambia: no se modifica fórmula, campo API, universo, filtros, identidad, cálculo ni exportación; cambia la persistencia ante fallos.
- El operador comunicó una copia posterior a #5729 de 105 tablas y 437 MB comprimidos, con gzip y checksum correctos; la restauración no se ha probado. No acredita el estado anterior ni permite certificar la tabla. El backup anterior mencionado era irrecuperable. Scheduler comunicado detenido en la última comprobación: verificar su estado real en despliegue.
- Según ejecución del agente principal, pruebas F2 SQLite en Docker: 16 casos, 88 aserciones, sin fallos, con advertencias por `.env` y caché. No se ejecutaron sincronizaciones reales ni se consultaron datos productivos. No se añaden migraciones ni configuración. Seguridad: no se amplían permisos y se mantiene sanitización del error de bloque; desaparece la auditoría diagnóstica individual para fallos generales.
- Tras sustituir el trigger de la fixture, el caso de rollback pasó en SQLite y MariaDB 11.8 aislados (una prueba y tres aserciones en cada motor, con advertencias de entorno). La primera versión de la fixture falló en MariaDB porque crear el trigger dentro de la transacción de prueba provocó un commit implícito de DDL; se sustituyó por una excepción sintética tras la actualización. No equivale a una prueba exhaustiva de fallos, concurrencia ni carga de la aplicación.
- Medición preliminar sintética aislada comunicada por el agente principal en MariaDB 11.8: tabla reducida y payload aproximado de 512 bytes; 270.000 inserciones en 7,74 s, copia en 7,24 s y cambios por lotes en 15,47 s; dos generaciones ocuparon 370.081.792 bytes de datos y 63.733.760 de índices, memoria observada 403 MiB y lectura `count` de 337 ms. Los materiales reproducibles están en `tests/performance/`; no hubo prueba formal de simultaneidad. No representa esquema, volumen ni carga productivos y no demuestra viabilidad horaria.
- Pendientes para producción: arquitectura de generaciones y bootstrap con fuente verificable, aislamiento de todos los consumidores, conflictos auditables, F2/F5 alineados, pruebas MySQL de integración completas y concurrencia, exportaciones, carga representativa y plan verificable de despliegue/rollback. No desplegar ni reactivar scheduler por este cambio. El agente principal debe revisar el diff y ejecutar el validador con HEAD y descripción definitivos.

- Revisión de consumidores compartidos: docs/informe-reservas-ventas.md, docs/informe-llamadas.md y docs/informe-campanas.md documentan la dependencia de la tabla viva F2 y el límite de integridad tras fallo; el 503 específico de Interests no se aplica automáticamente a esos informes. No se alteran sus fórmulas, filtros, atribuciones ni exportaciones. La protección de estos consumidores mediante generaciones sigue pendiente.

## 2026-10-28 — PR #92: núcleo F2/F5 por generaciones, revisión documental

- Base contrastada `edf2d97b6e5624936ed5cfd12fa790889fea3df2` y diff de trabajo, incluidos nuevos Store, Reader, CLI, migración y pruebas. Se delegó la revisión documental; no se modificó código desde el agente documental. Las entradas anteriores que dicen «no existen generaciones» describen la fase previa.
- Implementación: versiones inmutables, pertenencias copiadas por bloques, cuarentena individual cifrada, base certificada, F5 ligado exactamente al F2 candidato, puntero/watermark transaccionales y lectores fijados. Los detalles están en [arquitectura y operación](../salesforce-interest-generations.md). Cinco tablas nuevas; no se modifica ni certifica el estado legacy automáticamente.
- Documentos modificados en esta revisión: `docs/salesforce-interest-generations.md`, `docs/reglas-negocio-leads.md`, `docs/informe-reservas-ventas.md`, `docs/informe-llamadas.md`, `docs/informe-campanas.md`, `docs/informe-comisiones.md`, `docs/informe-stock.md`, `docs/ai/SEO_ANALYTICS.md`, `docs/Documentacion_general_informes_y_contraste_salesforce.md`, `docs/matriz-trazabilidad-kpi.md`, `docs/decisiones-negocio-pendientes.md`, `docs/operaciones-produccion.md`, `docs/ai/PROJECT_CONTEXT.md`, `docs/ai/DECISIONS.md` y este handoff. La matriz registra cambio de fuente/versionado; no inventa fichas KPI completas.
- Seguridad: evidencia cifrada, errores sanitizados, CLI sin endpoint público, permisos existentes conservados. No se consultaron Salesforce ni bases de datos desde la revisión documental. El backup posterior al incidente conserva la limitación de restauración no probada; no constituye fuente histórica. No hay resolución automática de conflictos ni borrado de evidencia.
- Pruebas comunicadas por el agente principal durante la revisión: cuatro suites focales SQLite 71/71 y 361 aserciones; núcleo de 22 pruebas superado en MariaDB, revalidación MariaDB ampliada en curso; JS 2/2; carrera MariaDB de dos procesos con un ganador. No se declara suite global verde: fixtures y regresiones heredadas requieren cierre. Los resultados finales deben incorporarse por el agente principal antes del commit.
- Rendimiento: ensayo sintético de 270.000 deltas con payload de 2 KB, versiones y pertenencias; lectura concurrente p95 superior a 11 s. No acredita frecuencia horaria ni SLO; faltan dimensiones/distribución reales e impacto completo de F5. Métricas y comandos reproducibles en `tests/performance/README.md`, mantenido por el agente principal.
- Pendientes: regresión integral de consumidores, contratos de cargas compuestas fuera de Interests, cobertura inicial verificable de borrados/relaciones, rendimiento representativo y autorización operacional. La evidencia inicial consiste en comprobaciones humanas más digest, no certificación automática de origen. La cuarentena bloquea toda fila de conflicto, sin flujo implantado de resolución. No apto para producción ni encargo completo acreditado.
- Acciones futuras: revisión de PR, ensayo de migración/rollback, validación independiente de primera candidata, despliegue aprobado y verificación de scheduler. No desplegar, ejecutar extracción real, restaurar backup o reactivar tareas desde esta revisión. Validador documental definitivo requiere HEAD que incluya estos cambios y cuerpo final de PR con `Docs-Impact: updated` y `Docs-Matrix-Impact: updated`.
- Comprobación documental de esta revisión: `git diff --check -- docs` sin errores; `validate_matrix` sobre árbol real devuelve cero errores. La suite documental inicial ejecutó 38 pruebas (seis fallos, una omitida): los seis fallos corresponden a la fixture que todavía no incorpora el destino nuevo `docs/salesforce-interest-generations.md`. Se comunicó al agente principal para actualizar fixture y repetir; no se modificaron tests desde el agente documental. Validador de PR final pendiente de HEAD/cuerpo definitivos.
- Actualización del agente principal: MariaDB final focal 38 pruebas correctas/148 aserciones; SQLite focal 71 correctas/361 aserciones. El padre amplió el mapeo explícito `InterestGeneration` y su test e incorporó el runbook a la fixture documental. Se actualiza la guía para ese contrato de control; el agente documental no modificó scripts ni tests. La suite global y el validador final quedan a cargo del agente principal.
- Revalidación documental tras corregir la fixture por el agente principal: 39 pruebas ejecutadas, correctas con una omisión; `git diff --check -- docs` sin errores. El validador por SHA no se ejecutó para el diff todavía sin commit: pendiente del HEAD y cuerpo de PR definitivos, sin sustituirlo por el chequeo de una revisión anterior.

## 2026-10-28 — PR #92: revisión posterior del núcleo y regresiones

- Referencias revisadas: `edcb357` (núcleo), `f84e97e` y `9c4dc2c` (adaptación de fixtures), más diff pendiente. `CampaignPublicationReadiness` mantiene `blocked` ante ausencia de base/evidencia; `InterestGenerationReader` traduce ausencia de runs F2/F5 a `InterestDatasetUnavailable`, sin adoptar legacy. Se conserva la fijación frente a intentos nuevos fallidos o en curso.
- Las pruebas de consumidores y dependencias ahora materializan explícitamente generaciones sintéticas, renuevan el scope al representar un nuevo job y separan una publicación de un mero run. Estos helpers son exclusivos de tests. No autorizan copiar ni certificar datos vivos y no prueban por sí solos integridad global de materializaciones dependientes.
- Infraestructura: mapa explícito de Store/Reader para los cuatro consumidores Interest; proveedor/cliente Salesforce/retención para los seis informes; precheck mensual para Interests/Reservas y SEO para Interests/Campañas. La CI crea `.env` vacío en su checkout efímero con configuración sintética del job para evitar avisos; no importa secretos ni configuración del usuario.
- Documentación de esta revisión: `docs/ai/HANDOFF.md`, `docs/guia-mantenimiento-documental.md`, `docs/reglas-negocio-leads.md`, `docs/informe-campanas.md`, `docs/salesforce-interest-generations.md`. Matriz sin nueva fórmula o fuente: ya registra el cambio de versionado; estos ajustes precisan disponibilidad y fixtures. No hay migración adicional ni configuración productiva en este ajuste.
- Evidencia comunicada por el agente principal: build Vite correcto, 40 pruebas documentales con una omitida, validador de 69 rutas correcto y CI documental verde. La suite funcional pasó por ejecuciones con 289, 100 y 31 fallos; la ejecución completa actual en Linux efímero sigue pendiente de resultado. No se declara verde ni se extrapola el éxito documental al funcional. El padre añadirá el resultado exacto final y SHA.
- Permanecen los bloqueos: p95 concurrente superior a 11 segundos sin SLO representativo acreditado, tokens de carga multipetición fuera de Interests sin garantía completa y materializaciones dependientes no certificadas conjuntamente. No apto para producción; no despliegue, sincronización real, restauración ni reactivación de scheduler en esta tarea.
- Comprobación propia posterior: validador documental con base `2e326b5f8df6cfc4fec7073f709a523c85f98668`, SHA completo resuelto de `9c4dc2c` y cuerpo local de PR: PASS, 79 rutas. `git diff --check -- docs` correcto. El diff posterior todavía sin commit requiere repetir el validador con el nuevo SHA definitivo.

## 2026-10-28 — PR #92: protección de apropiación concurrente

- Revisión documental sobre `f3c3a473ffc0c897e7c2a9311d71ec55bfb403c2`, comparada con el alcance acumulado desde `edf2d97`. La asociación F2/candidata entra en el `try`; solo tras éxito de `bindRun` un error puede marcar esa candidata fallida. Un segundo extractor rechazado falla su propio run sin cambiar propietario ni estado del candidato existente y sin consulta remota. Prueba nueva en `InterestGenerationTest`.
- Archivos documentales de este ajuste: `docs/salesforce-interest-generations.md` y este handoff. No cambian KPIs, fuente, filtros, esquema, configuración ni permisos por esta corrección de apropiación. La matriz ya registra el contrato de generaciones del diff acumulado; no se añaden fichas o fórmulas nuevas.
- Resultado local comunicado por el agente principal: 1.523 pruebas correctas y nueve fallidas en la suite completa; seis por ZIP no disponible, una por `vite.config` ausente en la copia efímera y dos fixtures corregidas posteriormente. Es el resultado observado, no una suite verde ni una revalidación de las correcciones. Nueva CI pendiente y ejecución MariaDB de 39 pruebas en curso; el padre registrará resultados definitivos.
- Continúan los límites de carga representativa, p95 concurrente superior a 11 s, consumidores multipetición fuera de Interests y certificación conjunta de materializaciones. No apto para producción; sin despliegue, acceso productivo, cambios de scheduler o recuperación de datos ejecutada.

## 2026-10-28 — PR #92: verificación de la implementación por generaciones

- Núcleo implementado: versiones inmutables, membresías por candidata, conflictos cifrados, alineación F2/F5 y publicación breve mediante puntero bloqueado con comparación de base. Una migración agrega cinco tablas; no importa ni certifica legacy. No hay nuevas dependencias runtime.
- MariaDB 11.8 local aislada: `pest tests/Feature/InterestGenerationTest.php tests/Feature/SalesforceInterestSyncServiceTest.php --compact`, **39 passed, 152 assertions**. La advertencia final corresponde únicamente a la caché de Pest en vendor montado de solo lectura.
- SQLite local: repetición de núcleo, Llamadas, reconciliación inversa y diseño, **50 passed, 269 assertions**. Suite completa previa: 1.523 passed y nueve fallos; seis por extensión ZIP ausente, uno por archivo no copiado y dos regresiones de fixtures posteriormente corregidas. CI comprueba el entorno completo; no se confunden estos resultados.
- Frontend: `node --test tests/js/leads-unavailable.test.mjs`, **2 passed**; build Vite local correcto (12,21 s). Documentación: `python -m unittest discover -s tests/documentation`, 40 pruebas, **39 correctas y una omitida** por capacidad de symlinks de Windows. Validador documental sobre 239aed3: PASS, 87 rutas. Pint y `git diff --check` correctos en cambios revisados.
- Rendimiento: ensayo sintético MariaDB de 270.000 modificaciones y carrera de publicación con dos procesos. Un ganador y un rechazo. Métricas, límites y comandos reproducibles en `tests/performance/README.md`; p95 concurrente >11 s, frecuencia horaria no acreditada.
- Bloqueos esenciales: carga representativa con tamaño/distribución reales y F5, EXPLAIN/SLO/binlog/retención; coherencia entre múltiples peticiones fuera de Interests y linaje conjunto de materializaciones dependientes. La suite funcional no sustituye esas garantías. La implementación sigue en borrador y no se declara apta para producción ni íntegramente finalizada.
- Operación pendiente: revisión de PR, ensayo de migración y restauración en entorno autorizado separado, confirmar scheduler, inicialización vacía y reconciliación independiente antes de primera certificación. El backup posterior a #5729 no prueba estado histórico; no se ha accedido a él. Despliegue y rollback detallados en `docs/salesforce-interest-generations.md`. Ningún comando productivo ejecutado.
- Seguridad: sin endpoints de publicación; evidencia cifrada y mensajes sanitizados, permisos existentes conservados, sin resolución automática de asociaciones ni limpieza de generaciones. El rollback de esquema rechaza generaciones existentes.
- Revisión documental delegada a `report_docs_maintainer` y contrastada por el agente principal. Mensaje de commit del núcleo: `feat: isolate Salesforce Interests F2/F5 generations`.

### Archivos de esta iteración (desde edf2d97)
- `.github/workflows/ci.yml`
- `app/Console/Commands/DebugCampaignAttributionCommand.php`
- `app/Console/Commands/ManageInterestGenerationCommand.php`
- `app/Console/Commands/PruneTransversalDataCommand.php`
- `app/Console/Commands/RefreshMonthlyCommercialReportCommand.php`
- `app/Console/Commands/ReprocessCallsClassificationCommand.php`
- `app/Console/Commands/SalesforceSyncInterestActivitiesCommand.php`
- `app/Console/Commands/SalesforceSyncInterestsCommand.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Campaigns/CampaignAttributionBuilderService.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- `app/Services/Campaigns/CampaignPublicationReadiness.php`
- `app/Services/Reports/Calls/SalesforceCallSyncService.php`
- `app/Services/Reports/Leads/SalesforceInterestDashboardDatasetService.php`
- `app/Services/Reports/ReservationsSales/CommercialPerformanceAuditService.php`
- `app/Services/Reports/ReservationsSales/CommercialPerformanceDatasetService.php`
- `app/Services/Reports/ReservationsSales/OpportunityInterestAttributionService.php`
- `app/Services/Salesforce/InterestGenerationReader.php`
- `app/Services/Salesforce/InterestGenerationStore.php`
- `app/Services/Salesforce/SalesforceClient.php`
- `app/Services/Salesforce/SalesforceInterestActivitySyncService.php`
- `app/Services/Salesforce/SalesforceInterestChunkPersister.php`
- `app/Services/Salesforce/SalesforceInterestLeadDependencySyncService.php`
- `app/Services/Salesforce/SalesforceInterestOpportunityDependencySyncService.php`
- `app/Services/Salesforce/SalesforceInterestOpportunityReconciliationService.php`
- `app/Services/Salesforce/SalesforceInterestReconciliationService.php`
- `app/Services/Salesforce/SalesforceInterestReportingPipelineService.php`
- `app/Services/Salesforce/SalesforceInterestSyncService.php`
- `app/Services/Salesforce/SalesforceOpportunityInterestReconciliationService.php`
- `app/Services/SeoAnalytics/SalesforceOrganicInterestProjectionService.php`
- `app/Services/SeoAnalytics/SeoIntegrationReadinessService.php`
- `app/Services/SeoAnalytics/SeoSourceStatusDatasetService.php`
- `database/migrations/2026_10_28_120000_create_salesforce_interest_generations.php`
- `docs/Documentacion_general_informes_y_contraste_salesforce.md`
- `docs/ai/DECISIONS.md`
- `docs/ai/HANDOFF.md`
- `docs/ai/PROJECT_CONTEXT.md`
- `docs/ai/SEO_ANALYTICS.md`
- `docs/decisiones-negocio-pendientes.md`
- `docs/guia-mantenimiento-documental.md`
- `docs/informe-campanas.md`
- `docs/informe-comisiones.md`
- `docs/informe-llamadas.md`
- `docs/informe-reservas-ventas.md`
- `docs/informe-stock.md`
- `docs/matriz-trazabilidad-kpi.md`
- `docs/operaciones-produccion.md`
- `docs/reglas-negocio-leads.md`
- `docs/salesforce-interest-generations.md`
- `resources/js/reports/leads-dashboard.js`
- `scripts/check_documentation_integrity.py`
- `tests/Concerns/ProvidesRot4CampaignContext.php`
- `tests/Feature/CallsReprocessClassificationCommandTest.php`
- `tests/Feature/CampaignAttributionFieldMigrationTest.php`
- `tests/Feature/CampaignCommandsTest.php`
- `tests/Feature/CampaignDashboardTest.php`
- `tests/Feature/CampaignPublicationReadinessTest.php`
- `tests/Feature/Concerns/MirrorsLeadFixturesToInterestReporting.php`
- `tests/Feature/ExecutiveDailyDatasetTest.php`
- `tests/Feature/ExecutiveSummaryPageTest.php`
- `tests/Feature/InterestGenerationTest.php`
- `tests/Feature/MonthlyCommercialInterestTimezoneTest.php`
- `tests/Feature/MonthlyCommercialReportBuilderDataTest.php`
- `tests/Feature/ReservationsSalesCohortAndDuplicateQualityTest.php`
- `tests/Feature/ReservationsSalesCommercialPerformanceTest.php`
- `tests/Feature/ReservationsSalesInterestAttributionTest.php`
- `tests/Feature/SalesforceCallSyncServiceTest.php`
- `tests/Feature/SalesforceInterestActivitySyncTest.php`
- `tests/Feature/SalesforceInterestDashboardDatasetTest.php`
- `tests/Feature/SalesforceInterestLeadDependencySyncTest.php`
- `tests/Feature/SalesforceInterestOpportunityDependencySyncTest.php`
- `tests/Feature/SalesforceInterestOpportunityReconciliationTest.php`
- `tests/Feature/SalesforceInterestReconciliationTest.php`
- `tests/Feature/SalesforceInterestSyncServiceTest.php`
- `tests/Feature/SalesforceOpportunityInterestReconciliationTest.php`
- `tests/Feature/SeoSalesforceOrganicSyncTest.php`
- `tests/Feature/SeoSyncCommandsTest.php`
- `tests/Feature/TransversalRetentionCommandTest.php`
- `tests/Support/InterestGenerationFixture.php`
- `tests/documentation/test_check_documentation_integrity.py`
- `tests/js/leads-unavailable.test.mjs`
- `tests/performance/README.md`
- `tests/performance/interest-generation-publication-race.php`
- `tests/performance/interest-generations.php`

### CI confirmada

- SHA de código 239aed3e343538e8edac8db97aad74de1ab75858: CI #210 (40687478965) y Documentation integrity #26 (40687479070) completadas con success. Incluye 1.533 pruebas PHP correctas (13.989 aserciones), Composer validate/audit, Pint sobre 63 archivos, build frontend y whitespace. El commit documental posterior registra esta evidencia sin cambiar código.
## 2026-10-28 — endurecimiento de certificación inicial

- `InterestGenerationStore::certify` compara la evidencia inicial con el manifiesto calculado de la candidata (filas, borrados y SHA-256) y exige el `run_id` y corte F5 exactos. Los indicadores booleanos ya no bastan.
- Se actualizaron el comando operativo, fixtures de rendimiento y pruebas de sincronización. La prueba de regresión rechaza un JSON con checks favorables pero sin manifiesto/F5 verificables.
- Commit: `3e4e2e7ddf0ed527fa6b8f538952c47f8b0ac9a1`. CI #213 y documentación #31 están en curso; documentación #30 ya pasó.
- Siguen pendientes para producción: EXPLAIN y ensayo de rendimiento con distribución real, SLO, F5 completo, y contrato de lineage para materializaciones fuera de Interests. No se ejecutó producción.
## 2026-10-28 — evidencia independiente de primera generación

- `InterestGenerationStore::manifest` ahora expone `identifiers_sha256`, separado del hash interno de la candidata.
- La certificación inicial exige evidencia externa identificable con huella de identidades, filas, borrados y huellas de relaciones/cobertura. Rechaza `candidate_manifest` y `legacy_table`; los checks booleanos no son suficientes.
- Se actualizaron fixtures sintéticas, benchmark y procedimiento operativo. Commit de código: `1b37f266845c6ff8de771e4755c6c119074d108c1`.
- El lineage de materializaciones dependientes fuera de Interests sigue siendo una barrera explícita para producción; no se certifica por inferencia.
- Se añadió `tests/performance/interest-generation-explain.sql` con planes MariaDB reproducibles para lectura paginada, ID y origen. La captura sobre volumen/distribución real sigue pendiente y es requisito antes de producción.
- Se añadió lineage explícito `interest_generation_id` a `seo_salesforce_organic_interest_daily_metrics`; la proyección SEO lo fija junto con run y cutoff. Filas históricas NULL requieren reconstrucción y no certifican una generación.
- EXPLAIN MariaDB aislado ejecutado: lectura paginada usa PRIMARY de versiones y `salesforce_interest_members_version_id_foreign` con `Using index`; muestras por ID/origen resultaron `Impossible WHERE` por no existir esos valores. No se añade índice especulativo; se requiere repetir con distribución real.
- Corrección de CI de esta iteración: la migración SEO usa los namespaces Laravel completos (`Illuminate\\Database\\Migrations\\Migration`, `Blueprint`, `Schema`). El validador también exigió `Docs-Outside-*` para el único modelo funcional sin mapa automático; el cuerpo de la PR ya lo declara con JSON válido.
- Corrección puntual posterior: `manifest()` captura `$identityHash` en el callback `chunkById()`, manteniendo el hash incremental determinista de ID, contenido y borrado. No cambia el contrato de certificación ni el esquema.
- Optimización puntual: `InterestGenerationStore::rows()` parte ahora de memberships y usa `sf_im_version_idx` (`generation_id, version_id`), conservando el join a versiones inmutables. El plan aislado resultante es `Using where; Using index` + `eq_ref`; la base no tenía filas para medir latencias comparables.
