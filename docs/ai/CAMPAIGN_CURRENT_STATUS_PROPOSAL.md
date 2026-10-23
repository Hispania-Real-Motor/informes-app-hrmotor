# Propuesta de Fase B: estado publicitario actual

Fecha: 2026-10-23. Estado: pendiente de aprobación humana explícita. No implementada.
Base inspeccionada: 01d3aa8ad428ad413f184741d9a4f2314696f1fe y diff de Fase A.

## Evidencia y necesidad

MetaAdsClient::campaigns() ya lee id, name, status y effective_status por cuenta,
con paginación de cursor. MetaCampaignSyncService relaciona esa lectura con
insights y solo persiste estados junto a métricas diarias. GoogleAdsClient
ofrece searchDailyMetrics() y searchAttributionIdentifiers(); su search()
privado utiliza searchStream. GoogleCampaignSyncService persiste campaign.status
con segments.date. Ninguna de esas filas garantiza estado de campañas sin gasto
ni fecha del último cambio. MIN de estados no acredita vigencia.

campaign_platform_daily_metrics y campaign_investment_closure_snapshots son
históricos económicos y no son almacenamiento operativo adecuado. La tabla
campaign_platform_identifiers contiene IDs de anuncios/grupos/campañas y
raw_payload, sin estado normalizado ni última verificación de campaña. Los
sincronizadores la reemplazan por cuenta y la atribución la utiliza: no debe
reutilizarse para esta finalidad. operational_task_runs monitoriza ejecuciones,
no almacena inventario de estado por identidad publicitaria. No se modifica
ninguna de estas fuentes para introducir el estado actual.

## Persistencia propuesta, todavía no creada

Se recomiendan dos tablas nuevas independientes, con nombres finales sujetos a
revisión de arquitectura, migraciones y retención antes de su aprobación:

- Estado de campaña: clave única (platform, account_id, campaign_id), nombre
  recibido, estado bruto y normalizado, last_verified_at en UTC y referencia al
  intento que verificó la fila. Índice por identidad y por cuenta/verificación.
  No guarda importes, atribuciones ni snapshots económicos.
- Estado de consulta por cuenta: clave (platform, account_id), último intento,
  última lectura completa satisfactoria, último fallo y estado del intento
  (completo, parcial o fallido), con timestamps UTC y categoría de error
  sanitizada. El fallo de una cuenta no invalida cuentas distintas.

Actualizar únicamente campañas realmente verificadas y los metadatos de su
cuenta. Un error conserva el estado previo como antiguo; nunca lo refresca como
si hubiera habido éxito. Un corte parcial no acredita una lectura completa,
no actualiza filas no verificadas y no marca ausencias como eliminadas. No
reconstruir ni tocar histórico financiero. Una campaña no devuelta puede estar
fuera del alcance de permisos/filtros/paginación; no equivale a eliminada.

## Lecturas propuestas y límites

Operaciones por cuenta, fuera de peticiones HTTP del informe, con paginación y
resultados parciales explícitos. Reutilizar catálogo/monitor operativo solo tras
aprobación. Cadencia propuesta cada 6 horas, con bloqueo por cuenta contra
solapamientos. Ningún usuario podrá dispararla sin permiso explícito.

Meta: evaluar reutilizar la lectura campaigns() existente y effective_status,
con ajuste aprobado de límites y garantías de inventario. Su timeout actual es
120 segundos; los nuevos límites siguientes no están implementados.

Google: añadir, tras aprobación, una lectura paginada mediante googleAds:search
con SELECT campaign.id, campaign.name, campaign.status FROM campaign, sin
métricas ni segmentación temporal. No existe hoy un método dedicado a esa
consulta: searchDailyMetrics()/searchAttributionIdentifiers() no la sustituyen.
Validar alcance de campañas removidas y permisos de ambas plataformas mediante
clientes simulados y contrato de proveedor antes de afirmar cobertura completa.

Límites propuestos: 30 segundos por página, 10 minutos por cuenta, 100 páginas
o 50.000 campañas, escrituras por lotes de 500. Cualquier límite agotado deja el
intento incompleto. Reintentos acotados dentro del presupuesto total, sin
registrar payloads, tokens ni errores remotos brutos. Validar identidad de
cuenta y campaign ID; no unir por nombre ni por gasto. Normalización de cuenta
por plataforma y restricción de unicidad deben probarse antes de migrar.

## Frescura y presentación propuestas

Fresco únicamente si la última verificación satisfactoria tiene antigüedad
entre cero y 24 horas y no hay un fallo o consulta parcial posterior de esa
cuenta. Un timestamp futuro es inválido. Un fallo posterior fuerza Estado no
actualizado aunque el último éxito tenga menos de 24 horas. Se conservan raw,
normalizado y fecha previa para trazabilidad, sin presentarlos como vigentes.
Ausencia de lectura o estado bruto desconocido: Estado no disponible. Lectura
válida pero antigua/incompleta/fallida después: Estado no actualizado.

Mapeo explícito propuesto: Meta ACTIVE → Activa; PAUSED → Pausada; DELETED o
ARCHIVED → Eliminada/Archivada según código; WITH_ISSUES → Con incidencias;
IN_PROCESS → En proceso. Google ENABLED → Activa; PAUSED → Pausada; REMOVED →
Eliminada. Valores futuros o desconocidos permanecen neutrales, con fecha de
última consulta satisfactoria en tooltip y estado bruto escapado cuando se
exponga. No se agrupan todas las situaciones como Inactiva.

La futura lectura local será aditiva, autorizada por informe y cuenta, en lote
por las identidades del conjunto consultado, sin N+1. Su contrato concreto
requiere revisión antes de crear endpoints o alterar respuestas. CSV, filtros
históricos, KPIs y cierres mantienen sus valores. El filtro histórico activo
puede coexistir con estado actual pausado: son dimensiones distintas y deben
explicarse, sin reinterpretar su universo económico.

La columna solo se llamará Estado actual cuando lectura, persistencia,
frescura, permisos y pruebas estén implementados y aprobados. La Fase A sigue
mostrando Estado histórico; ningún endpoint, scheduler ni migración nuevos se
han creado en esta tarea.

## Pruebas y aprobación pendientes

Clientes simulados: Meta/Google, campañas pausadas o activas sin gasto reciente,
identidad por plataforma/cuenta/ID y misma ID en dos cuentas, paginación,
resultados parciales, timeouts, límites y ausencia de campaña sin inferir baja.
Pruebas temporales de ausencia, antigüedad, timestamp futuro, éxito seguido de
fallo y fallo aislado por cuenta. Probar filtro histórico activo y estado actual
pausado; normalización neutral de códigos desconocidos; ninguna llamada remota
durante peticiones HTTP; conservación byte a byte de economía, API/CSV legacy,
atribuciones y cierres; autorización, escape y errores sanitizados.

Se solicita revisión humana de persistencia, contrato aditivo, frescura,
operación programada, permisos y límites antes de implementar. Este documento
es una propuesta, no autorización ni registro de decisión arquitectónica
adoptada. No requiere migraciones ni acciones de infraestructura en Fase A.
