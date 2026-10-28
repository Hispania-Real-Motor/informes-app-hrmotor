# Decisiones de negocio pendientes

Actualizado: 2026-09-08.

Este documento contiene exclusivamente decisiones que el código no puede tomar
sin una definición funcional o una validación externa. Las decisiones ya
implantadas se resumen al final y no deben volver a tratarse como pendientes.

## Pendientes reales

### Leads

Dirección ha cerrado la prioridad nuevo → legacy, la validez basada únicamente
en null/vacío/whitespace, la resolución independiente, la prioridad de
delegación incluida Exposición, la política de conflictos y las cinco parejas
UTM. La clasificación de Leads (fuente, canal, medio y delegación) ya consume
estas reglas; no deben volver a tratarse como decisiones pendientes.

Permanecen como validaciones técnicas u operativas para fases posteriores:

- verificar con casos reales de Google y Meta la semántica de `utm_id__c` por
  plataforma; se conserva como identificador secundario y no decide el nombre;
- medir Leads que solo tienen UTM nuevos y quedan fuera del universo legacy de
  Campañas antes de proponer cualquier ampliación del filtro;
- Llamadas, Reservas/Ventas y el attribution builder ya consumen los campos
  nuevos en su clasificación efectiva, conservando sus prioridades legacy
  específicas y sin modificar sus universos.

Las siguientes reglas del alcance ya implantado permanecen cerradas y no se
reabren por esta auditoría:

- `Venta` incluye únicamente `Venta` y `Venta con cambio`;
- `Lead` y `Ayvens` quedan fuera;
- los leads válidos `Sin clasificar`, `Sin comercial elegible` y `Sin delegación
  comercial` permanecen dentro del total y se muestran como calidad del dato;
- los perfiles comerciales elegibles son `Compra/Venta` y
  `Comerciales Partner Community`.

### Reservas / Ventas

La prioridad de procedencia del Lead relacionado queda cerrada como
`Fuente_origen__c` → fallback legacy, considerando informado cualquier valor no
vacío. No se modifica el matching existente por email/teléfono ni su orden por
`CreatedDate DESC`.

- Definir, si Dirección quiere utilizarlo, qué benchmark debe alimentar las
  conclusiones automáticas: objetivo, media ponderada, media simple o período
  anterior. Hasta entonces no se emiten recomendaciones basadas en benchmark.

### Llamadas

- Clasificar operativamente los usuarios del perfil `Pruebas comunidad
  comercial`. El perfil no se excluye automáticamente; debe revisarse con
  `reports:audit-calls-profile` y aprobar una regla explícita si procede.
- Validar con una muestra de centralita si se mantienen definitivamente los 5
  segundos descontados en llamadas directas y los 10 segundos en portales. La
  regla actual continúa marcada como provisional.

### Campañas

- Revisar y clasificar por ID las campañas que permanezcan como
  `pending_review`. El nombre puede sugerir una campaña de prueba, pero no la
  excluye de los KPIs ejecutivos sin una clasificación explícita guardada.
- Aprobar o ajustar los umbrales de muestra y coste utilizados para alertas de
  rendimiento cuando Dirección disponga de benchmarks económicos definitivos.

### Comisiones

- Validar funcionalmente las fórmulas e importes antes de aprobar cada cierre
  mensual. La aplicación ya soporta estados, snapshot, aprobación, reapertura y
  ajustes, pero un mes no se vuelve definitivo sin aprobación de Dirección o
  Administrador/IT.
- La regla de reseñas vigente se mantiene: reseñas creadas en el mes por
  `OwnerId` divididas entre las operaciones elegibles. Si Dirección quiere
  convertirla en una métrica uno-a-uno por oportunidad deberá aprobar una nueva
  fórmula; no se cambia implícitamente.

### Stock

- Confirmar mediante metadata de Salesforce el API Name exacto de la fecha o
  año de matriculación de `Product2`. Hasta entonces la matriculación no puede
  participar en el ranking de comparables.

## Operaciones históricas pendientes

Estas operaciones no son decisiones funcionales ni código pendiente. Sus
herramientas están terminadas, pero requieren aprobación, dry-run y ejecución
controlada conforme a `operaciones-produccion.md`:

- Fase 7A: no existe evidencia de ejecución del backfill histórico de campos de
  atribución de Leads; no debe declararse el histórico modificado.
- Fase 7B: no existe evidencia de dry-run productivo ni de ejecución del
  reproceso histórico de portales de Opportunities.

El lifecycle/presencia de Opportunities no pertenece a esta lista: quedó
desplegado, reconciliado e idempotente en producción el 8 de septiembre de 2026.

## Decisiones cerradas e implementadas

| Informe | Decisión implantada |
|---|---|
| Leads / Campañas (capa técnica) | Cada campo nuevo informado gana; solo null/vacío/whitespace hace fallback legacy; los placeholders no vacíos son válidos; conflictos quedan trazados; UTM se resuelve campo a campo y `utm_id__c` no sustituye el nombre. |
| Leads | Normalización única de tipos, Venta sin Lead/Ayvens, calidad comercial visible, eliminados/fusionados fuera de KPIs activos y auditoría por Lead ID. |
| Reservas / Ventas | El selector de fecha define toda la cohorte; reserva/firma repetida para el mismo vehículo y fecha cuenta una vez y genera incidencia auditable. |
| Llamadas | Solo Tasks de llamada con `CallObject`; `ABANDONED` nunca es atendida ni desbordada; clasificación versionada y reproceso únicamente manual y trazable. |
| Campañas | Google/Meta, Salesforce-only, prueba, pendiente, ambiguo y sin atribuir están separados; first touch no duplica entidades; las pruebas se excluyen solo por clasificación persistida. |
| Campañas / Fase 6 | El gate de universo sigue siendo legacy; una vez admitido el Lead, las cinco parejas UTM se resuelven nuevo → legacy de forma independiente y Fuente/Medio adquiridos dejan de usar `LEA_SEL_*`. |
| Comisiones | Mes único en seis pestañas y exports; mes actual provisional; cierre económico persistente, auditable y reproducible; ajustes posteriores no sobrescriben silenciosamente un definitivo. |
| Stock | Catálogo canónico de Salesforce, todo Disponible evaluado, 60/90 como prioridad, Top 3 teórico, plan conjunto sin sobreasignar capacidad y sin reservas logísticas persistentes. |
| Stock | Entre ventas válidas del mismo vehículo gana la firma más reciente; un empate exacto queda como `duplicate_ambiguous` y no suma. |

No deben introducirse excepciones por IDs concretos ni ajustes manuales para
cuadrar cifras. Toda nueva decisión debe centralizarse, versionarse cuando
afecte históricos y acompañarse de pruebas y auditoría por ID.

## 2026-10-23 — Estado actual de campañas: decisión de implementación autorizada

El encargo Fase B autoriza persistencia independiente, contrato JSON aditivo y tarea cada seis horas **deshabilitada por defecto**. La propuesta de dos tablas se resuelve con una sola `campaign_current_statuses` y reutilización de `report_sync_runs`/catálogo operacional. No queda pendiente decidir esa arquitectura en esta rama; sí la revisión del diff, integración, migración productiva y activación por operador. No cambia ninguna decisión de atribución, fórmula o cierres. Evidencia y límites: [decisión](ai/DECISIONS.md), [contrato funcional](informe-campanas.md) y [runbook](operaciones-produccion.md). No consta ejecución remota ni verificación de campañas reales.

## Actualización PR #92 — reglas de origen y primera publicación

La cuarentena de orígenes por generación está implementada, pero permanece
pendiente la decisión documentada de negocio para cada colisión y cualquier
cambio de la regla de unicidad. No se presume que el entrante o el propietario
sea correcto. No existe resolución automática ni autorización para reasignar.
La primera publicación requiere conciliación independiente y aprobación
operativa explícita; el backup posterior al incidente no la sustituye.
Estas decisiones son distintas de las reglas históricas de clasificación Lead.
Véase [procedimiento y limitaciones](salesforce-interest-generations.md).
