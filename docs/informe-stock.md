# Informe de Stock

Actualizado: 2026-09-21.

## Fuentes y fotografía local

- Inventario: Salesforce `Product2`.
- Ventas: Salesforce `Opportunity`, congeladas en
  `salesforce_sale_snapshots`.
- Logística: `Logistica__c`.
- Capacidades: importación CSV/XLSX y `stock_delegations`.
- Histórico: `stock_daily_snapshots`.

La réplica compartida de Opportunity materializa `Captador_de_cita__c` y su
nombre relacionado como `appointment_setter_id` y `appointment_setter_name` para
atribuciones futuras. No intervienen en fuentes, cálculos, KPIs ni presentación
de Stock.

La ruta administrativa del roster de Contact Center está restringida a
administradores y solo mantiene identidad/vigencia para el refresco local de
Users; no altera las fuentes, cálculos ni KPIs de Stock.

El stock actual considera `Disponible`, `Reservado` y `Bloqueado`. Las ventas no
se reconstruyen con el Product2 actual: el snapshot conserva los valores
económicos del momento de la venta y posteriormente solo se reconcilia su
validez contra Opportunity.

La cabecera muestra período de ventas, fuente y corte de la fotografía local. La
versión de reglas no ocupa una pill visual independiente.

## Ventas válidas y duplicados

Una venta válida requiere:

- RecordType Venta o Cambio;
- contrato CV firmado;
- fecha de firma informada y dentro del período analizado;
- etapa distinta de `Cerrada Perdida`;
- vehículo de interés informado.

`Cerrada Perdida` queda fuera de ventas, rotación, rankings y recomendaciones.

Si un vehículo tiene varias ventas base-válidas:

1. se conserva la firma más reciente;
2. las anteriores quedan como `duplicate_not_selected` y guardan la Opportunity
   seleccionada;
3. si varias comparten exactamente la fecha más reciente, todas quedan como
   `duplicate_ambiguous`, no suman y requieren revisión en Salesforce;
4. `LastModifiedDate` no se usa como desempate.

Los snapshots invalidados se conservan para auditoría y no reescriben sus
importes congelados.

## Universo y antigüedad

Para contexto y capacidad se usa todo el stock Disponible, Reservado y
Bloqueado. Para proponer traslados se usan únicamente vehículos Disponibles y
operativos.

Todos los Disponibles operativos se evalúan. Los 60 y 90 días son niveles de
urgencia, no filtros de entrada:

| Prioridad | Regla principal |
|---|---|
| Normal | menos de 60 días y sin otra señal prioritaria |
| 60 días | desde 60 días |
| 90 días | desde 90 días o señales fuertes configuradas |

Los tramos del resumen son excluyentes: 0–59, 60–89, 90–119, 120–180, más de
180 y Sin fecha. Su suma debe coincidir con el stock total filtrado.

## Catálogo canónico

Salesforce es el catálogo de referencia. `stock:sync-salesforce-catalog` lee los
picklists activos de `Product2` y los guarda en `stock_catalog_values`. Los
aliases locales solo relacionan variantes históricas con un valor oficial
activo; no constituyen un segundo catálogo.

Para cada dimensión se conserva:

- valor bruto;
- valor normalizado;
- valor canónico;
- regla aplicada.

`salesforce_vehicles.catalog_normalization` mantiene esa traza. Valores no
operativos como pruebas, formación o fuera de stock se excluyen de rankings y
recomendaciones, pero siguen ocupando capacidad cuando forman parte del stock
real.

## Ranking y plan de traslados

Cada vehículo muestra:

1. destino ideal teórico;
2. segunda alternativa;
3. tercera alternativa;
4. destino ejecutable asignado por el plan conjunto, si existe.

El ranking compara primero marca, modelo y combustible; después usa
kilometraje/similitud y el score de ventas, rotación, segmento, precio y stock
existente. La matriculación todavía no participa porque no se ha confirmado su
API Name exacto en Salesforce.

Un destino teórico puede estar completo. En ese caso se muestra como no
ejecutable, con exceso previsto y plazas que habría que liberar. El plan conjunto
consume capacidad virtualmente y nunca asigna más vehículos que plazas libres.
No crea reservas persistentes, órdenes logísticas ni cadenas automáticas entre
tiendas.

La conciliación visual separa:

- contexto: total, disponibles, reservados y bloqueados;
- evaluación: disponibles evaluados, catálogo no operativo y sin alternativas;
- plan: asignados y sin asignar por capacidad;
- urgencia: normal, 60 días y 90 días.

## Planificador de traslado dirigido

La pestaña Recomendaciones incluye una simulación read-only en la que el usuario
elige delegación de origen, delegación de destino y número de vehículos. No crea
órdenes, reservas, movimientos, cambios de delegación ni escrituras en
Salesforce; tampoco persiste la propuesta ni modifica capacidades.

El universo dirigido se obtiene de la fotografía local completa, sin aplicar los
filtros generales de la pantalla. Solo incluye vehículos `is_in_stock=true`, en
estado `Disponible`, pertenecientes al origen seleccionado y operativos según
`StockCatalogNormalizer`. Reservados, Bloqueados, otras delegaciones y valores
de catálogo no operativos quedan excluidos.

Cada candidato se evalúa directamente contra el único destino seleccionado. La
evaluación reutiliza la misma implementación vehículo→delegación, estadísticas
de 120 días, normalización, pesos y penalización por falta de histórico que el
motor general; no recorre el resto de destinos ni replica la fórmula. La
ordenación es determinista:

1. score comercial descendente;
2. prioridad de Stock descendente (`priority`, `review`, `normal`);
3. días en stock descendentes;
4. matrícula o, si falta, ID estable ascendente.

La capacidad del destino es informativa. Se muestran stock actual, capacidad,
plazas libres, unidades solicitadas y propuestas, stock y ocupación previstos y
exceso actual/proyectado. Un destino lleno, sobreocupado o sin capacidad
configurada sigue siendo evaluable y nunca recorta la lista; el stock previsto
usa exclusivamente los vehículos realmente propuestos. Cada simulación queda
limitada a 150 vehículos, el mismo máximo de renderizado soportado por la página,
para acotar respuestas manipuladas.

Este flujo no sustituye al plan general “Vehículos propuestos para traslado”. El
plan general sigue buscando alternativas para cada vehículo y consumiendo
capacidad virtual conjunta; el simulador dirigido fija un único destino y no usa
la capacidad como criterio de ejecución. Ambos comparten exclusivamente el
scoring y la clasificación de prioridad. Durante una petición de simulación no
se calcula ni renderiza el plan general; un enlace permite volver a él. Así el
cálculo dirigido escala con candidatos del origen × un destino, sin pagar el
recorrido por todas las delegaciones.

## Capacidad, ratios y calidad

- Capacidad libre = plazas totales − stock total.
- Ocupación = stock total / plazas totales.
- Si una delegación tiene stock cero, el ratio ventas/stock es no disponible; no
  se sustituye por el número de ventas.
- Las alertas visuales usan todo el stock, no la muestra filtrada.
- El nombre de delegación aplica un filtro común a stock, ventas y rankings.

Calidad del dato incluye, entre otros, entradas/delegaciones/catálogos ausentes,
entregados que continúan en stock, firmas inconsistentes, `Cerrada Perdida`,
duplicados de venta, entradas futuras, tiendas sin capacidad, variantes de
catálogo y valores no operativos.

Exportación: `/informes/stock/exportar/calidad-dato.xlsx`.

## Rendimiento

- El universo se calcula antes de paginar.
- Recomendaciones materializa perfiles compactos globales y desarrolla motivos
  completos solo para la página visible.
- La pestaña Capacidades no construye el dataset analítico.
- Calidad se calcula únicamente cuando la sección lo necesita.
- Las consultas de ventas seleccionan columnas concretas y evitan hidratación
  innecesaria.

## Operación

El sincronizador compartido del snapshot directo Opportunity→Interest registra
ahora `reference_fingerprint` en las estadísticas de su ejecución completada.
Esta evidencia se utiliza para la publicación de Campañas y no modifica las
filas de Opportunity o venta usadas por Stock, sus snapshots, universo,
deduplicación, fechas, fórmulas ni exportaciones.

```bash
php artisan stock:sync-salesforce-catalog
php artisan stock:sync-daily --sales-days=180 --logistics-days=365
```

Scheduler vigente: 03:30 `Europe/Madrid`, con
`stock:sync-daily --sales-days=14 --logistics-days=30` y bloqueo de solapamiento
durante 120 minutos.

El sincronizador parcial de ventas de Stock selecciona y materializa también
`Opportunity.CreatedDate` y `Opportunity.LastModifiedDate` como `created_date` y
`salesforce_last_modified_at`. Estos metadatos temporales son necesarios para
que las filas creadas inicialmente por Stock puedan participar posteriormente
en herramientas acotadas por fecha, sin alterar las reglas ni el universo de
ventas.

## Controles de catálogo y venta (2026-08-11)

- La fecha de matriculación verificada en el describe de `Product2` es
  `PRO_DAT_Fecha_de_matriculacion__c` (tipo `date`, nillable). Se sincroniza
  localmente como `salesforce_vehicles.registration_date`; no altera pesos ni
  fórmulas del ranking hasta contar con una regla funcional explícita.
- Una venta solo es válida si dispone de firma y fecha de firma. La ausencia de
  fecha queda auditable como `missing_signed_date` y no entra en ventas,
  rotación ni recomendaciones.
- Los aliases solo se aplican cuando tienen estado `approved`, apuntan a un
  valor `Product2` activo y han quedado auditados con usuario, fecha y motivo.
  Los aliases heredados quedan `legacy_unverified` hasta su aprobación manual.
  La aprobación exige el permiso independiente
  `stock.catalog_aliases.approve`; Administrador/IT lo tiene por defecto.
- La fotografía diaria se actualiza de forma transaccional e idempotente: al
  repetir la misma fecha conserva una única fila por vehículo y retira filas
  de vehículos que ya no pertenecen al stock de esa fotografía.

Pendiente funcional: la fuente local no define inequívocamente qué estados de
`Logistica__c` constituyen “logística activa”. No se ha inventado esa condición
ni se ha alterado el score/recomendación hasta validar el universo operativo.

Archivos principales:

- `app/Services/Reports/Stock/StockDashboardDatasetService.php`;
- `app/Services/Reports/Stock/StockRecommendationService.php`;
- `app/Services/Reports/Stock/StockSaleValidityService.php`;
- `app/Services/Reports/Stock/StockCatalogNormalizer.php`;
- `app/Services/Reports/Stock/SalesforceStockCatalogSyncService.php`.

## PR #92 — alcance compartido de reconciliación Interest

La presencia Interest usada por `SalesforceOpportunityInterestReconciliationService`
se fija a la generación publicada. El cambio pertenece a evidencia compartida de
relaciones Opportunity; no modifica en este diff cálculos, pestañas, filtros,
antigüedad ni exportaciones de Stock. El mapeo conservador incluye Stock por su
fuente Opportunity compartida. No se declara por ello una certificación nueva de
KPIs o snapshots de Stock.
[Contrato y pendientes de regresión](salesforce-interest-generations.md).
