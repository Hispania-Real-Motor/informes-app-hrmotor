# SEO/Analytics

## Alcance operativo

Los Lotes 2 a 7 incorporan datos persistidos de Google Search Console, una
proyección diaria aislada de Interests orgánicos desde FOUNDATION-2 y Conversiones web
orgánicas GA4. `GET
/informes/seo-analytics` consulta exclusivamente la base local y configuración:
no llama Google, Salesforce, GA4 ni SISTRIX.

El motor comparativo y las evaluaciones analíticas versionadas ya están
implementados. Continúan fuera el crawler general y SISTRIX/GEO. Salud técnica
monitoriza un conjunto acotado, incluidos robots/sitemaps, sin convertirse en
crawler. El correo ejecutivo diario representa evaluaciones existentes y no
incorpora scoring, reglas o inferencias propias.

## Correo ejecutivo diario

`seo:send-executive-daily-email` genera y envía todos los días, también cuando
todo está correcto, una fotografía ejecutiva a las 08:00 Europe/Madrid. Consume
solo la comparativa materializada, sus evaluaciones versionadas, el estado
compartido de Search Console/Salesforce/GA4 y el resumen factual de Salud
técnica. No ejecuta sincronizaciones, builders, evaluadores, Google, Salesforce,
SISTRIX ni OpenAI.

El correo muestra siempre las seis métricas del registry con su fecha real,
valor actual, referencia, variación, estado y lectura de Lote 6. Una evaluación
stale continúa como `not-evaluable`/«Evaluación pendiente de actualizar» y la
ausencia de snapshot produce guiones, nunca ceros. Las oportunidades favorables
conservan `observation + favorable`. Salud técnica expone contadores sin recibir
clasificación analítica; `outside_sitemap_urls` solo aparece cuando el scan fue
completo.

Administrador y Director gestionan entre 1 y 10 destinatarios normalizados en
`/informes/seo-analytics/configuracion`. Se almacenan en
`seo_executive_email_settings`; las credenciales y transporte continúan
exclusivamente en `MAIL_*`. `log`, `array` y el remitente fallback
`hello@example.com` no se consideran preparados para envío real.

Cada fecha se congela una vez en `seo_executive_daily_reports`; el SHA-256 del
JSON canónico permite comprobar su integridad y no incluye destinatarios. El
ledger `seo_executive_email_deliveries` tiene unique fecha+hash de destinatario,
envía individualmente y usa un claim atómico `failed -> sending` antes del SMTP,
sin mantener transacciones durante red. `sent` nunca se reenvía; `failed` puede
reintentarse con el mismo payload; `sending` no se reclama automáticamente por
la incertidumbre de entrega tras una caída. Un fallo del transporte convierte
`sending -> failed`; una vez que el transporte retorna correctamente, un fallo
o un resultado inconcluyente al confirmar `sending -> sent` conserva `sending`
y hace fallar el comando para exigir reconciliación manual, nunca un reenvío
automático. Cada comando registra
`ReportSyncRun` sin emails ni secretos.

## Persistencia y cierre

- `seo_search_console_daily_metrics`: agregados diarios exactos por property,
  fecha, ámbito (`ALL`/`ESP`) y segmento (`all`/`brand`/`non_brand`). Conserva
  histórico y solo admite datos finales. Timezone: `America/Los_Angeles`.
- `seo_search_console_dimension_metrics`: último ranking top para 7, 28 y 90
  días de query ESP, page ESP y country global. El conjunto se obtiene completo
  antes de sustituirse atómicamente. No es un dataset exhaustivo ni fuente de
  verdad para KPI, ceros o alertas.
- `seo_salesforce_organic_interest_daily_metrics`: proyección vigente del conteo
  diario de `salesforce_interests` activos cuya condición es exactamente
  `medium = 'Orgánico'`. Usa `functional_created_at` persistido en UTC y
  calendario `Europe/Madrid`; ayer es el último día local cerrado y los días
  cubiertos sin Interests son ceros reales. Cada fila conserva el ID y cutoff
  del F2 exacto utilizado.
- `seo_salesforce_organic_daily_metrics`: histórico Lead previo a ROT-5. Se
  conserva para auditoría y rollback, pero no alimenta la métrica vigente.
- `seo_ga4_organic_daily_metrics`: total diario GA4 por property, fecha y
  ámbito (`ALL`/`ESP`), con `key_events DECIMAL(18,6)`.
- `seo_ga4_organic_key_event_daily_metrics`: detalle España por fecha y
  `event_name`, identificado mediante SHA-256 sin sustituir el nombre original.

Search Console determina `closed-through` consultando una ventana reciente con
`dataState=all` y dimensión `date`. Si existe `metadata.first_incomplete_date`,
el cutoff es el día anterior; si no existe, se usa la última fecha devuelta. Sin
una fecha fiable, la sync falla y no persiste días abiertos. Las cuatro series
exactas se consultan con `dataState=final`; una fecha cerrada ausente se guarda
como `0 clicks`, `0 impressions`, `ctr=null`, `position=null`.

## Conversiones web orgánicas GA4

La definición cerrada es `keyEvents` con atribución event-scoped
`defaultChannelGroup = "Organic Search"` y `platform = "web"`. España añade
`countryId = "ES"`; Search Console conserva independientemente `ESP`. No se usa
`sessionDefaultChannelGroup`, `sessionMedium` ni Organic Social. Los totales
global y España proceden de reportes agregados propios; nunca se derivan del
detalle por evento.

GA4 conserva crédito decimal/fraccional y solo redondea a dos decimales en la
vista. Los valores `TYPE_FLOAT` recibidos como strings, incluida notación
científica, se normalizan exactamente a `DECIMAL(18,6)` sin punto flotante PHP;
si requieren más de seis decimales reales o exceden 12 enteros, la sync falla
antes de persistir y conserva los datos anteriores. `Interés orgánico Salesforce`
y `Conversiones web orgánicas (GA4)` son
cardinalidades distintas: no se suman, deduplican ni sustituyen. La sync verifica
property, timezone, al menos un web stream y al menos un Key Event configurado.
Lista Data Streams y Key Events mediante Admin API read-only; nunca consulta
Measurement Protocol secrets.

GA4 no ofrece el `dataState=final` de Search Console. El cutoff es operativo:
fecha actual en la timezone real de la property menos
`SEO_GA4_REPORTING_LAG_DAYS` (default 3; rango seguro 2–7). La ventana móvil se
resincroniza completa para absorber revisiones posteriores de procesamiento y
atribución. Cada página `runReport` se rechaza antes de interpretar sus filas si
GA4 indica `subjectToThresholding`, `dataLossFromOtherRow` o
`samplingMetadatas` no vacío. Solo una respuesta sin esas señales puede
rellenar con cero las fechas cubiertas ausentes; el detalle no fabrica ceros. El
detalle del rango se reemplaza solo dentro de una transacción después de
descargar y validar todos los reportes. Una incidencia de calidad conserva los
datos anteriores y finaliza el `ReportSyncRun` como fallo técnico.

## Salud técnica SEO

Salud técnica monitoriza un conjunto deliberadamente acotado; no es un crawler.
Los candidatos se seleccionan en este orden: raíz de
`SEO_TECHNICAL_SITE_URL`, URLs de `SEO_TECHNICAL_STRATEGIC_URLS` y ranking local
Search Console de páginas España/90 días para la property configurada. Home
siempre es estratégica. No se recorren enlaces HTML, no se descargan assets, no
se ejecuta JavaScript y el sitemap no incorpora automáticamente todas sus URLs.
Stock no aporta candidatos directos porque `SalesforceVehicle` carece de una URL
pública verificable; una ficha solo entra si está configurada o aparece en el
ranking local de Search Console.

Toda petición usa `HRMotor-SEO-Health/1.0`, timeouts acotados y un guard SSRF
único. Solo admite HTTP/HTTPS, puertos 80/443 y hosts DNS ASCII canónicos y
exactos del sitio o de `SEO_TECHNICAL_ALLOWED_HOSTS`; rechaza userinfo, IP
literal o numérica ambigua, percent escapes, trailing dot, localhost y toda
resolución que no sea global. Si una sola A/AAAA no es global, se rechaza el
host completo. Cada redirect se resuelve y valida antes de seguirlo, con
redirects automáticos desactivados y máximo 5.

El transporte usa Guzzle `>=7.15.2,<8` (lock 7.15.3), desactiva explícitamente
el proxy de entorno, conserva TLS/hostname verification y fija mediante
`CURLOPT_RESOLVE` la IP global validada para evitar DNS rebinding. No se envían
cookies, Proxy-Authorization ni Authorization, y no se persisten bodies o
headers completos.

`robots.txt` se construye desde el origen y solo aporta su estado HTTP y
directivas `Sitemap:`; no se interpretan `Allow`/`Disallow`. Los sitemaps de
robots se combinan con `SEO_TECHNICAL_SITEMAP_URLS`, se deduplican y soportan
`urlset`, `sitemapindex`, referencias recursivas y gzip acotado. El XML usa
`LIBXML_NONET`, nunca expansión de entidades. Límites duros/configurados: 50
documentos, 100.000 URLs escaneadas y 10 MiB por documento/descompresión. Una
URL encontrada queda `in_sitemap=true`; una ausencia solo es `false` si todo el
scan terminó, y es `null` si no existe sitemap, falla o queda parcial.

`sitemap_scan_complete=true` exige simultáneamente un descubrimiento concluyente
de fuentes y el procesamiento completo de sus documentos. `robots.txt` 2xx solo
es concluyente si el body se leyó íntegramente y todas sus directivas `Sitemap:`
fueron válidas; 404/410 acredita que no aporta directivas. Cualquier otro estado,
error, truncamiento o directiva rechazada por seguridad deja el scan parcial sin
solicitar destinos bloqueados. En un scan parcial, una pertenencia encontrada
conserva `true`, pero una ausencia permanece `null`.

Cada URL recibe un único GET más redirects válidos. En HTML 2xx se observan
`meta robots`/`googlebot`, `X-Robots-Tag`, canonical y coincidencia con la URL
final; otros content types no se parsean. El body se limita a 512 KiB y se
descarta. La lectura streaming itera hasta EOF o límite+1, usa `read_timeout` y
cierra siempre el stream. Un fallo posterior a headers produce
`body_read_error` para esa URL y no aborta las demás. En un body truncado, una
señal positiva noindex o canonical múltiple se conserva; la ausencia de
noindex/canonical o la unicidad/coincidencia canonical quedan no evaluables.
HTTP 4xx/5xx, noindex, redirects y errores de red se persisten como hechos
técnicos, no como estados analíticos ni `OperationalAlert` individuales.

`seo_technical_urls` conserva el registro activo/inactivo y metadata de origen;
`seo_technical_url_checks` conserva un check idempotente por URL/día. El comando
`seo:sync-technical-health` hace toda la red antes de la transacción corta de
persistencia y publica `ReportSyncRun` (`seo_technical_health`,
`public_website`). Se programa a las 06:00 Europe/Madrid con lock de 120 minutos.
El panel Salud técnica lee exclusivamente BD/config, muestra el último run
completado aunque exista uno fallido/en curso posterior, y no participa en el
common cutoff ni en el selector 7/28/90. Límites: 200 candidatos por defecto,
500 absoluto, 150 automáticos de Search Console y 100 filas visibles.

Quedan expresamente fuera: indexabilidad completa, evaluación RFC de robots,
crawler general, Core Web Vitals/Lighthouse, render JavaScript, schema.org,
scoring, severidad, recomendaciones y alertas analíticas. La retención del nuevo
histórico no se inventa en este lote. También queda pendiente un mapping
verificable Stock -> URL pública.

## Marca y detalle

Las únicas variantes son las de `seo_analytics.brand_variants` (por defecto
`hr motor`, `hrmotor`, `hr-motor`, `hrmotor.com`). Una única capa genera regex
RE2 case-insensitive para `includingRegex`/`excludingRegex` y clasifica las
query rows locales. Una expresión vacía o superior al límite falla; nunca se
trunca ni se inventan errores ortográficos, fabricantes o competidores.

`ALL / all` y `ESP / all` son los totales exactos de referencia. `ESP / brand`
y `ESP / non_brand` son subconjuntos observables obtenidos mediante filtros de
consulta. Search Console excluye consultas anonimizadas al aplicar esos filtros,
por lo que ambos segmentos pueden sumar menos que `ESP / all`; la diferencia no
se interpreta como error, cero ni tercer segmento.

Por ejecución se realizan 14 consultas Search Analytics: una de cutoff, cuatro
series diarias exactas y nueve rankings (tres dimensiones por tres periodos),
además del refresh OAuth cuando proceda. Límites: 1.000 queries, 1.000 pages y
100 countries por periodo; el dashboard muestra como máximo 50 queries y 50
pages.

## Salesforce y compatibilidad legacy

ROT-5 no consulta Salesforce. El comando `seo:sync-salesforce-organic` conserva
su nombre por compatibilidad operativa, pero proyecta exclusivamente el último
snapshot F2 canónico (`salesforce_interests`/`salesforce`) si el último run por
ID está `completed` y tiene cutoff. Cuenta exactamente Interests con
`is_deleted = false` y `medium = 'Orgánico'`; no usa UTM, fuente, canal ni
fallback Lead. La fecha es `functional_created_at` y los límites de día Madrid
se convierten explícitamente a UTC.

El día efectivo máximo es el menor entre ayer en Madrid y el día local del
cutoff F2 menos uno. El run F2 se revalida tras leer, antes de persistir y antes
de publicar; cualquier nuevo run `running`, `failed` o `completed` invalida la
proyección. La ventana cubierta se publica atómicamente, incluidos sus ceros.

La tabla `seo_salesforce_organic_daily_metrics`, la identidad
`salesforce_organic_leads`, sus snapshots, evaluaciones y reglas históricas se
conservan sin reinterpretación. La serie vigente usa
`salesforce_organic_interests` y `salesforce-organic-interests`. Una migración
crea una nueva versión activa de reglas copiando exactamente los umbrales de la
versión anterior y sustituyendo solo esa identidad. Interests orgánicos y
conversiones web GA4 son métricas independientes y no se suman.

## Dashboard

Rangos cerrados permitidos: los valores textuales exactos `7`, `28` y `90`;
default 28 para cualquier otro valor. El cutoff publicado de cada fuente procede
del último `ReportSyncRun` completado con `source_cutoff_at`; un run fallido o en
curso posterior no invalida datos completados anteriores. Search Console exige
además que la property del run coincida con la configurada.

Un KPI solo está disponible si las filas locales cubren todos los días desde el
inicio hasta el cutoff, sin huecos. Las filas sincronizadas con cero son ceros
reales; una fila ausente significa cobertura incompleta y se representa como
ausencia. Resumen y Tráfico y conversión usan el menor cutoff común de Search
Console, Salesforce y GA4 disponibles. Búsquedas y páginas usa exclusivamente el periodo propio de
Search Console, por lo que puede terminar en una fecha distinta. Si solo existe
una fuente, sus métricas disponibles no quedan bloqueadas por la ausente. Las
secciones son Resumen, Tráfico y conversión, Búsquedas y páginas, Salud técnica
y GEO/IA.

- KPI España: clicks, impressions, CTR, posición ponderada, Interés orgánico
  Salesforce y Conversiones web orgánicas GA4 como métricas separadas.
- `CTR = SUM(clicks) / SUM(impressions)`; posición = suma de
  `daily_position * daily_impressions` dividida por impressions.
- Resto del mundo resta solo magnitudes aditivas y recalcula ratios; la posición
  solo se muestra si puede ponderarse válidamente.
- `—` significa ausencia; `0` se conserva cuando la fuente cubrió realmente el
  día.
- Readiness es estado técnico neutral, no uno de los cinco estados analíticos.

## Comparativa diaria y snapshots analíticos

El motor transversal `SameWeekdayComparisonEngine` compara una observación
cerrada con D-7, D-14, D-21 y D-28 exactos. La referencia semanal es la media
de las muestras disponibles y requiere al menos 3/4. D-364 es opcional y no
condiciona la evaluación semanal. Una fila persistida con cero es una muestra
válida; una fila ausente o un valor diario `NULL` permanece ausente. Si falta el
actual se registra `missing_current`; con menos de tres referencias se registra
`insufficient_history`.

La versión estable es `same_weekday_v1`. La diferencia absoluta es
`actual - referencia`; la variación relativa se guarda como ratio y solo existe
si la referencia es distinta de cero. Los valores fuente se trasladan a escala
8 sin alterar su precisión; únicamente medias, diferencias y ratios derivados
usan aritmética PHP con redondeo explícito a ocho decimales. El motor no conoce
SEO, Eloquent ni Blade y no asigna scoring, severidad ni estado analítico.

`analytical_metric_snapshots` conserva snapshots factuales por módulo, métrica,
scope, identidad SHA-256 de fuente y fecha. Guarda valores actual/D-7/D-14/D-21/
D-28/D-364, cobertura, baseline, diferencias, versión y cutoff real. Search
Console y GA4 se aíslan por la property actualmente configurada; Salesforce usa
la identidad interna no secreta `salesforce-organic-interests`. Los seis KPI son:

- `search_console_clicks`, `search_console_impressions`,
  `search_console_ctr` y `search_console_position`: property actual, ESP/all,
  fila final.
- `salesforce_organic_interests`: scope neutral `all`, sin inventar geografía.
- `ga4_organic_key_events`: property actual y ESP.

Cada fuente usa su propio último cutoff completado; no se degrada al cutoff
común descriptivo. El builder carga una serie acotada por fuente, construye en
memoria y hace upsert transaccional por chunks. `seo:build-analytical-snapshots
--days=30` reconstruye de forma idempotente 1–90 fechas, no borra históricos y
no realiza HTTP. El scheduler lo ejecuta a las 06:15 Europe/Madrid con lock de
120 minutos y `ReportSyncRun` local. Los snapshots no participan en cutoffs de
fuente ni se purgan hasta aprobar una retención específica.

El panel Resumen lee únicamente el último snapshot compatible con las identities
actuales. Es independiente del selector descriptivo 7/28/90, muestra la fecha
cerrada de cada fuente y no renderiza severidad. Salud técnica queda excluida
hasta definir una métrica estable con denominador/cohorte verificable.
Los valores fuente de métricas de conteo (actual y D-364) se presentan como
enteros, mientras que sus medias y diferencias derivadas conservan dos
decimales para no ocultar fracciones válidas.

## Evaluación analítica versionada

Los snapshots son proyecciones factuales locales, no registros append-only: el
rolling rebuild puede actualizar una misma identidad/fecha cuando una fuente
revisa datos recientes. La interpretación vive en una capa separada:
`analytical_rule_sets` versiona el contrato,
`analytical_metric_rules` materializa sus seis reglas y
`analytical_metric_evaluations` conserva el resultado de aplicar una versión a
un snapshot. Cada evaluación captura current, baseline, diferencia absoluta,
diferencia relativa, evaluabilidad y motivo exactos usados, además de un
fingerprint SHA-256 canónico de esos inputs, métrica, fecha y versión del motor.
El fingerprint excluye D-364 y timestamps porque no intervienen en v1. Así, el
dashboard no combina una clasificación anterior con hechos revisados: muestra
«Evaluación pendiente de actualizar» hasta que el evaluador actualiza
idempotentemente esa evaluación. Las señales históricas renderizan los valores
capturados, no los valores mutables de la proyección actual.

`seo_rules_v1` define cuatro métricas de volumen por variación relativa con
bandas inclusivas 10/20/35 % y puertas de materialidad: clicks 50/10,
impresiones 1000/100, leads 5/2 y conversiones GA4 10/3 (baseline/cambio
absoluto mínimos). Un baseline bajo limita el estado a Observación y baseline
cero nunca genera porcentajes infinitos. CTR usa diferencias absolutas de
0,5/1/2 puntos porcentuales; Posición usa 0,5/1/2 posiciones y considera
favorable una disminución. D-364 es solo contexto y no interviene en v1.

ROT-5 crea una versión activa posterior que conserva exactamente esos umbrales
y sustituye únicamente la regla `salesforce_organic_leads` por
`salesforce_organic_interests`. Las versiones, reglas, snapshots y evaluaciones
Lead anteriores siguen siendo historia inmutable y no se mezclan con la nueva
identidad.

Los estados persistidos son `ok`, `observation`, `deviation`, `critical` y
`not-evaluable`; las direcciones son `stable`, `favorable`, `unfavorable` y
`not_evaluable`. Una mejora material conserva su banda matemática, pero se
representa como Observación favorable con la lectura «Oportunidad / posible
anomalía»; nunca se convierte automáticamente en un incidente crítico.

`/informes/seo-analytics/configuracion` permite exclusivamente a Administrador
y Director editar los valores numéricos y exige un motivo. Las identidades,
unidades, modos y direcciones se resuelven en servidor. Cada guardado crea v2,
v3, etc., conserva las versiones/evaluaciones anteriores y reevalúa solo los
seis snapshots actuales. Una transacción con bloqueo y la versión base impide
sobrescrituras concurrentes. El actor se conserva como ID histórico sin FK a
`report_users`, para no impedir la eliminación física prevista por ese módulo.
Ante conflicto el redirect descarta todo el old input y vuelve a renderizar la
versión activa; un formulario obsoleto no puede reaplicarse sobre la nueva base
sin que el usuario introduzca deliberadamente otra vez sus cambios.

`seo:evaluate-analytical-snapshots` evalúa el último snapshot por defecto, no
hace HTTP y se agenda a las 06:30 Europe/Madrid con lock de 120 minutos. El
backfill manual admite 1–90 días únicamente mientras v1 siga siendo la versión
activa inicial; después de crear v2 no existe reinterpretación retroactiva
automática. El dashboard muestra la evaluación más reciente aplicable por
snapshot y un máximo de 50 señales no `ok` de los últimos 30 días.

## Operación y seguridad

```bash
php artisan seo:diagnose-integrations
php artisan seo:diagnose-integrations --live
php artisan seo:sync-search-console --days=120
php artisan seo:sync-salesforce-organic --days=120
php artisan seo:sync-ga4-organic --days=120
php artisan seo:build-analytical-snapshots --days=30
php artisan seo:evaluate-analytical-snapshots
```

Los tres comandos de ingesta (`seo:sync-search-console`,
`seo:sync-salesforce-organic` y `seo:sync-ga4-organic`) aceptan `--days` entre
1 y 480; sus schedulers usan 120 días a las 05:15, 05:30 y 05:45
Europe/Madrid. `seo:build-analytical-snapshots` acepta 1–90 y, cuando se omite
la opción, consume `analytical_comparison.snapshot_refresh_days` (30); el
scheduler de las 06:15 invoca ese default sin duplicar el literal. Todos usan `withoutOverlapping(120)` y
alertas técnicas administrativas.
Search Console y GA4 sin configurar devuelven `SKIPPED` exitoso y no inventan
datos. La proyección Salesforce SEO es local: exige F2 completed y estable, y
falla de forma sanitizada si esa precondición no se cumple. Los fallos quedan en
`ReportSyncRun` y no sustituyen datos completed anteriores. No se persisten
tokens, secretos ni payloads raw.
