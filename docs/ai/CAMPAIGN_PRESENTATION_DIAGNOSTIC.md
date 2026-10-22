# Correcciones de presentación de campañas — 2026-10-21

Base: `d459e9f68970e683b78bd552457089807cc9ab37`, verificada tras fetch. Rama: `fix/campaign-status-empty-rows-cost-display`. Árbol inicial limpio, rama nueva desde origin/main. Sin push ni despliegue.

## Causa raíz y alcance aprobado

Meta recupera estados por lotes, pero los persiste solamente junto a filas de insights del período sincronizado. Google persiste campaign.status junto a resultados diarios. La fecha de gasto no representa la fecha de cambio de estado. Ni synced_at de una métrica ni un inventario de identificadores garantizan que una campaña sin métricas recientes conserve su estado vigente. Los cierres antiguos pueden carecer de estados.

El dataset usa MIN de estados para su comportamiento histórico; campaignIsActive también admite intereses sin estados. Cambiar estos valores afecta filtros, universo económico y alertas. El responsable confirmó expresamente conservar los filtros actuales en esta entrega. Por ello NO se modifican MIN, campaignIsActive, etiquetas legacy del JSON/CSV ni los servicios. Esos campos son compatibilidad histórica, nunca evidencia de estado vigente. La sustitución global por Estado no disponible de la primera entrega queda reemplazada por la regla de presentación histórica de 2026-10-22 descrita al final de este documento. Un tooltip y el aviso junto al filtro explican la diferencia. No se afirma que se haya implementado un inventario de estados vigentes ni una clasificación actual activa/pausada/inactiva: requiere otra entrega aprobada, independiente de inversión y cierres.

La tabla aplica un predicado lineal de actividad exclusivamente al renderizar el detalle. Considera gasto, impresiones, clics, leads/conversiones de plataforma, intereses, oportunidades, reservas (también vivas/caídas), ventas, compras, tasaciones e importes de venta/tasación. Cualquier valor numérico distinto de cero conserva la fila, incluso negativo. Ausencia no se convierte en cero confirmado: simplemente no constituye evidencia de actividad cuantitativa. Los null originales permanecen en memoria y API. No se filtran exportaciones, rankings, conciliación ni tarjetas.

Costes: cero numérico se muestra como euros cero; null con inversión conocida y denominador cero/ausente es `No calculable`; fuente de inversión desconocida es `Sin datos`; costes de Salesforce-only son `No aplica`. El formateador global se conserva. Los gráficos de este informe dejan de convertir null en cero; su tooltip de coste y tarjetas de comparación usan el mismo criterio contextual. Los rankings sin información suficiente siguen mostrando Sin datos; no se inventan denominadores ni resultados. Ninguna fórmula del backend cambia.

Login: se elimina únicamente la URL inexistente y el autofocus nativo de login/recuperación; reset ya carecía de autofocus. Se conservan gradientes, orden DOM, formularios, CSRF y cabeceras. Sin foco forzado ni cambios en CSP. Mensajes postMessage de extensiones son externos salvo evidencia de origen en la aplicación.

## Dos campañas: verificado frente a pendiente

| Nombre solicitado | Plataforma/ID real | Tipo funcional | Período y cifras | Diagnóstico individual |
| --- | --- | --- | --- | --- |
| META-ES-260928-PLD-FORMULARIOS_VERIFICADOS | Pendiente de lectura autorizada | Pendiente; no inferido del nombre | Pendientes | No hay acceso a registros productivos en esta tarea |
| META-ES-260910-CNV-CATALOGO_WEB_ESPAÑA | Pendiente de lectura autorizada | Pendiente; no inferido del nombre | Pendientes | No hay acceso a registros productivos en esta tarea |

Contrato verificado en resultCountForRow: venta → sales; tasacion → purchases; exposicion → opportunities; branding → leads_salesforce; otros → máximo entre sales, purchases, opportunities y leads_salesforce. Coste = spend / ese resultado, null cuando resultado <= 0. resultCountForTotals usa el contexto seleccionado; Todos suma ventas y compras, y Otros usa el máximo de los totales, que no necesariamente coincide con sumar máximos por campaña. Se conserva esta diferencia aprobada.

La tabla oculta cost_per_result en Venta, Tasación, Exposición y Branding, también en sus subcontextos normalizados; en Todos y Otros depende de la selección de columnas guardada. El backend puede devolver un coste correcto que no sea visible en ese contexto. Ninguna de estas causas se atribuye a las dos campañas sin inspeccionar sus datos.

## Procedimiento reproducible de solo lectura

1. Con un usuario existente autorizado, abrir el informe. Anotar fechas exactas, contexto/subcontexto, tipo de interés, estado, cuenta y resto de filtros. No ejecutar sync, refresh de datos remotos, backfill ni cierres. Para comparar universos, usar también estado Todas; el filtro inicial sigue siendo Activas por compatibilidad.
2. En Red del navegador inspeccionar la solicitud existente `/informes/campanas/data/summary` o `/informes/campanas/data/campaigns` con esos mismos parámetros. No copiar cookies, tokens, HAR completos ni información personal a documentos. Conservar el resultado solo en el entorno autorizado.
3. Buscar ambos nombres exactos entre campaigns/items. Registrar platform, account_id, campaign_id y campaign_type devueltos. Si hay varias filas/cuentas, conservar las identidades separadas; si no existe fila, comprobar Todas y filtros antes de concluir que faltan datos. La clasificación procede del resolver/mapeos operativos del backend, nunca del nombre.
4. Para cada fila registrar spend, leads_salesforce, opportunities, reservations, sales, purchases, result_count, cost_per_result y campaign_source_type. Anotar si la inversión procede de un cierre consultando investment_closure en summary. No abrir ni regenerar el cierre.
5. Elegir el denominador según el contrato anterior. Comparar result_count con ese campo y cost_per_result con spend / result_count si > 0. Con resultado cero el null es esperado; con Salesforce-only la inversión/coste no aplica. Si hay discrepancia, preservar la respuesta exacta y detener cualquier corrección económica.
6. En el navegador confirmar que la fila usada es la misma de la respuesta, que el contexto permite la columna y que Columnas tiene habilitado Coste por resultado. Anotar valor recibido, texto renderizado y tooltip; no usar un ranking o total como si fuese la fila individual.
7. Para auditar persistencia usar una conexión de lectura autorizada o réplica, transacción consistente de solo lectura y consultas parametrizadas por platform, account_id y campaign_id ya obtenidos. Revisar campaign_platform_daily_metrics (metric_date, synced_at, estados, spend) y mapeos operativos existentes. No interpretar el último día de gasto como vigencia. Evitar dumps de atribuciones con datos personales.
8. Comparar antes/después las mismas respuestas económicas, IDs del CSV y hashes privados de cierres/snapshots/eventos y atribuciones usando el mismo corte de datos. No publicar esos contenidos. Cualquier diferencia económica bloquea el despliegue; nunca compensarla cambiando fórmulas o importes.

## Evidencia y límites

Los servicios, modelos, controladores, rutas, permisos, fórmulas, migraciones y sincronización permanecen idénticos al commit base. Las pruebas sintéticas comparan íntegramente seis tablas antes/después de consultas de meses cerrados, filtros y contextos, y comprueban igualdad de filas API/exportación incluyendo una campaña vacía. Fixture: inversión 125, intereses 1, ventas 0, coste por venta null. Tests adicionales verifican denominadores 2/3/4/5/5, inversión 120, costes 60/40/30/24/24, inversión cero con resultado positivo y divisor cero. No constituyen una auditoría de datos productivos ni una prueba multiconexión MySQL.

Comprobaciones manuales pendientes: escritorio/móvil, teclado completo, login normal/incorrecto, recuperación/reset e iframe en navegador real; repetir sin extensiones para atribuir mensajes. Las pruebas HTTP cubren formularios y autenticación, pero no prueban foco o geometría del navegador.

Comandos de verificación (PHP 8.4 de Herd, fuera del PATH inicial; usar su ruta absoluta si procede):

```powershell
git status --short
git fetch origin
git rev-parse origin/main
git switch --create fix/campaign-status-empty-rows-cost-display origin/main
php artisan test --filter=CampaignDashboardTest --compact
php artisan test --filter='dashboard_reads_preserve|approved_result_denominators' --compact
php artisan test --filter='ReportsAuthMiddlewareTest|ReportUserPasswordResetTest|CampaignDashboardTest' --compact
php artisan test --compact
php -d max_execution_time=0 vendor/bin/pest --compact
php -d max_execution_time=0 vendor/bin/pest --filter=StockRecommendationCandidatePaginationTest --compact
php vendor/bin/pint --test
php vendor/bin/pint --test tests/Feature/CampaignDashboardTest.php tests/Feature/ReportsAuthMiddlewareTest.php
node --test tests/js/campaign-presentation.test.mjs
node --check resources/js/reports/campaigns-dashboard.js
npm run build
git diff --check
git diff d459e9f68970e683b78bd552457089807cc9ab37 --name-only -- app database routes
```

El último comando no devuelve archivos: la lógica económica y persistencia siguen idénticas. Pint global informa deuda de estilo en 61 archivos sin modificaciones; el control focalizado pasa. La suite completa ejecutada directamente termina con 1.428/1.429 tests correctos (13.085 aserciones): falla el umbral de rendimiento de stock, 43,07 s frente a <20 s; stock no tiene cambios en esta rama. El intento previo por artisan agotó max_execution_time=120 y no produjo un resumen válido. Resultados de la repetición aislada y detalle final en HANDOFF.md.

## Despliegue y rollback (no ejecutados)

Desplegar el commit revisado mediante el procedimiento habitual y generar `npm run build` con dependencias del lockfile. En esta revisión se incluye el bundle de campañas generado junto con su referencia del manifest, sin cambios de compilación de otros informes. No requiere migraciones, variables nuevas, invalidación de dataset económico, sync ni backfill. Verificar caché de assets y las comprobaciones manuales antes de promover. Rollback: restaurar el release anterior con sus assets o revertir este commit y recompilar; no ejecutar rollback de base de datos ni tocar snapshots.


## Revisión de presentación posterior a 681bfea (2026-10-21)

Se distinguen los dos tipos reales de procedencia: salesforce_campaign_without_spend muestra No aplica para costes publicitarios; salesforce_origin muestra Sin datos porque su inversión no está verificada, incluso si el JSON contiene un coste cero. Para otras procedencias, un cero solo se presenta como conocido con inversión conocida y denominador disponible distinto de cero. El valor JSON no se modifica.

El helper costPresentation centraliza texto, motivo y valor exclusivamente visual. Los tooltips distinguen divisor cero, denominador ausente, inversión desconocida, procedencia sin inversión verificable y métrica no aplicable. Los costes sin evidencia tampoco dibujan puntos cero en gráficos.

Las líneas se generan por tramos contiguos: un período null interrumpe la línea, incluso entre dos valores válidos. Una serie enteramente nula no dibuja líneas ni puntos; un final null no reutiliza el último valor conocido. Ceros legítimos y valores negativos se conservan. El recorrido sigue siendo lineal, sin consultas ni llamadas remotas.

El selector se titula Estado histórico (filtro) y sus opciones indican según criterio histórico. Sus valores active/inactive y su lógica permanecen idénticos; Estado no disponible sigue significando que NO se ha implementado la identificación del estado remoto vigente. Sin cambios de API, KPIs, fórmulas, snapshots, atribuciones o permisos.


## Corrección de visibilidad histórica — 2026-10-22

Base verificada tras fetch: 72d7fb968bb61c168dcd38c9c624882380ab1b72. Nueva rama fix/campaign-historical-status-visibility, inicio limpio. La UI ahora traduce las etiquetas PHP existentes a Activa (histórico)/Inactiva (histórico) solo cuando existe evidencia local del estado relevante: effective_status en Meta, status en Google. No duplica la regla activa/inactiva. Sin estado, con UNKNOWN/UNSPECIFIED, con etiqueta ausente o procedencia Salesforce, muestra Estado no disponible. Actividad cuantitativa y clasificación histórica son conceptos distintos; las filas vacías siguen sujetas únicamente a su regla visual de actividad.

Confirmada la asignación temprana de campaign_status_label: sin estados y con atribución posterior, API/CSV pueden conservar Inactiva/null y el filtro final aplicar el fallback de intereses como activo. Esa semántica legacy se mantiene; corregirla queda fuera del alcance y requeriría aprobación. En esas filas no se usa la etiqueta para afirmar un estado visual. Las pruebas PHP caracterizan la discrepancia y verifican que las etiquetas con evidencia coinciden con campaignIsActive y mainCampaignRows; también comparan API/exportRows y los valores efectivos del CSV.

No cambian backend, filtros, fórmulas, importes, permisos, atribuciones ni persistencia. Se mantienen tests de integridad existentes. Los assets versionados de campañas y manifest se actualizan con el build; publicación conjunta en el despliegue habitual, nunca solo fuentes o manifest. No se despliega. Sigue pendiente la verificación de estado remoto vigente y QA visual del navegador. Resultados actuales de pruebas y archivos en HANDOFF.md.
