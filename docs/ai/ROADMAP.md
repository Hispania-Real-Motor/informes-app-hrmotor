# Roadmap controlado de implementación

Actualizado: 2026-09-30.

## Trabajo transversal autorizado

### AUTH-PASSWORD-RESET — Recuperación de contraseña de usuarios de Informes

- **Prioridad:** P0 seguridad.
- **Estado:** `aprobada`.
- **Rama activa:** `feat/auth-password-reset`.
- **SHA base verificado:** `6c26cce4a09349c30ebd289c73cf5ea985b9166f`.
- **Revisión sénior pre-PR:** aprobada sobre el HEAD
  `29a1311a103e52b0036c5e24429ea2b7ca7ddeb3`.
- **Motivo:** flujo completo de recuperación/restablecimiento por email para
  usuarios reales `ReportUser`, sin sustituir el login manual existente ni usar
  el broker `users` de `App\Models\User`.
- **Paralelismo autorizado:** este lote se ejecuta de forma transversal en
  paralelo al trabajo Salesforce Interest porque no depende técnicamente de
  Leads, Interests, sincronizadores, dashboards ni universos analíticos.
- **Criterios de revisión:** no enumeración de cuentas, envío desacoplado por
  Job cifrado, tokens seguros y de un solo uso, expiración configurable de 60
  minutos, rate limiting, correo por Laravel Mail, passwords hasheadas por
  `ReportUser`, invalidación de tokens previos y remember cookies previas,
  pruebas verdes, Pint y documentación.
- **Condición operacional de despliegue:** el entorno debe usar cola realmente
  asíncrona con `QUEUE_CONNECTION=database` y debe existir un worker Laravel
  Queue operativo.
- **Pendiente para cierre:** abrir PR, CI verde, merge y validación operacional.

## Salesforce Interest foundation

- `SF-INTEREST-FOUNDATION-1` — **completada y certificada en shadow**: revisión sénior aprobada y commit
  `2b9ab559e1b1016e3e623fcd45462e1a0fd43541` publicado. La migración se aplicó
  en MySQL local y shadow; producción permanece intacta. No existe consumo
  funcional.
- `SF-INTEREST-FOUNDATION-2` — **completada y certificada en shadow y contra
  Salesforce SandboxRefreshed en full + incremental**. Commit funcional
  `b69f0f88435e7509a039d30e4d62bf64468cd87b`. La migración y el persister están
  certificados en MySQL local y shadow. El bootstrap read-only contra Salesforce
  SandboxRefreshed completó 65 registros en dos páginas, sin errores ni
  escrituras, y dejó el watermark UTC en `2026-09-28 14:31:28`. El primer
  incremental real cubrió `2026-09-28T14:26:28+00:00` →
  `2026-09-28T15:06:10+00:00` con overlap de 300 segundos: 2 páginas, 0 registros
  y 0 errores. El run quedó completed, avanzó el watermark a `15:06:10 UTC` y
  mantuvo 65 Interests activos sin duplicados observados. Quedan certificados
  full, incremental, watermark, overlap, query/queryAll, lifecycle, persistencia,
  auditoría y read-only. Producción permanece intacta y no existe scheduler.
- `SF-INTEREST-FOUNDATION-3` — **completada y certificada en shadow**: revisión
  sénior aprobada, migración aplicada y reconciliación certificada sobre el
  snapshot FOUNDATION-3A. El run 3 materializó 1.059.486 filas a partir de
  1.059.421 Leads y 65 Interests: 4 relaciones exactas, 0 origins missing,
  5.904 Leads con master inmediato y un único conflicto por alignment `other`,
  conservado como hallazgo de integridad. La retención dejó únicamente el
  detalle del run vigente y mantuvo las métricas históricas. No existen
  consumidores funcionales ni scheduler; producción permanece intacta.
- `SF-INTEREST-FOUNDATION-3A` — **completada y certificada en shadow contra
  Salesforce SandboxRefreshed**: revisión sénior aprobada y migraciones
  aplicadas. El run 1 quedó ligado al run FOUNDATION-2 número 2688 y a su cutoff
  UTC `2026-09-29T11:28:13+00:00`; resolvió mediante queryAll los cuatro
  migration origins ausentes del universo legacy como 3 activos y 1 eliminado,
  sin missing, invalid o errores, y descubrió 1 master activo. La fuente continúa
  separada de `salesforce_leads`, que no se amplió. No tiene scheduler ni
  consumidores funcionales; producción permanece intacta.
- `SF-INTEREST-FOUNDATION-4` — **aprobada** en la rama
  `feat/sf-interest-foundation-4-opportunity`, nacida del SHA
  `6c26cce4a09349c30ebd289c73cf5ea985b9166f`; HEAD publicado previo
  `9a0ee5e3c7439aecaee201090eb27c11d5a7655b`. El snapshot 4A original fue
  validado en local y shadow, pero sus 20 referencias quedaron `not_local`.
  La auditoría read-only demostró que las 20 son IDs Opportunity canónicos,
  existen y están activas en SandboxRefreshed, mientras una Opportunity local
  de control no existía en ese mismo org. Sin atribuir una causa no demostrada,
  la réplica legacy no constituye evidencia compatible con FOUNDATION-2.
  FOUNDATION-4A queda certificada en shadow sobre el F2 run 2688 y cutoff
  2026-09-29T11:28:13+00:00. Los dependency runs 1 y 2 resolvieron las 20
  referencias Opportunity como activas mediante queryAll, sin missing, deleted,
  invalid ni errores. Las reconciliaciones dependency-backed 3 y 4
  materializaron 65 Interests: 20 inverse_unique/present_active y 45 no_inverse/
  not_applicable, con 0 requires_review, errores o cleanup_errors. La retención
  conserva metadata histórica y únicamente el detalle del último snapshot.
  salesforce_opportunities legacy no fue ampliada ni modificada. Producción
  permanece intacta. FOUNDATION-4B permanece **bloqueada** hasta demostrar 
  mediante describe el API Name, tipo, `referenceTo` y FLS de un lookup
  Opportunity → `Interes__c`; no se presume `HRM_Interes_Origen__c` 
  ni se reutilizan campos parecidos.
- Antes de automatizar el pipeline manual FOUNDATION-2→FOUNDATION-3A→FOUNDATION-3
  queda pendiente evaluar un lock compartido o serialización equivalente; no se
  implementa scheduler ni hardening de concurrencia en FOUNDATION-3A.
- La misma decisión queda pendiente para FOUNDATION-2→dependencias
  Opportunity→FOUNDATION-4A antes de cualquier automatización; cada etapa tiene
  lock propio y revalidación de fuente, pero no existe scheduler ni lock común.
- FOUNDATION-2 materializa persona y fecha mediante
  `SalesforceInterestFoundationResolver::materialize()` antes de cualquier
  escritura bulk; no podrá depender del evento Eloquent `saving`.
- **Contrato confirmado; implementación pendiente:** Contact se resuelve por
  Lead convertido/Account; Quote sigue Opportunity→Interest; Task usa
  `ActivityDate` con `CreatedDate` como desempate y Event usa `StartDateTime`;
  Opportunity fotografía la atribución; el orden continuo es
  Lead/Account/Contact→Interest→Opportunity/Quote→Task/Event→hitos→
  deletes/merges→integridad; pools proceden de `HRM_Reparto_Regla__c`; cutover
  debe registrar UTC y hora local.
- **Realmente pendiente:** rollback completo por ejecución histórica, métricas
  persistentes del batch, campos Contact Center sin equivalente, exportación
  completa de excepciones, snapshot definitivo y timestamps reales de cutover,
  conciliación final de pools productivos, tipos de descarte y controles de
  integridad aún no implementados.

Este documento es la **fuente única de verdad del trabajo pendiente**. El
histórico de trabajo ya entregado y sus validaciones permanece en
[`HANDOFF.md`](HANDOFF.md); los contratos estables del sistema permanecen en
[`PROJECT_CONTEXT.md`](PROJECT_CONTEXT.md) y las decisiones aprobadas que deben
persistir, en [`DECISIONS.md`](DECISIONS.md).

## Reglas de mantenimiento

- Cada trabajo pendiente debe tener un ID único y una ficha en este documento.
- Al activar una tarea se deben completar su rama y SHA base, cambiar su estado
  a `en_progreso` y registrar un punto de reanudación verificable.
- El orden del roadmap expresa la secuencia de trabajo preferida. Cada ficha
  distingue ese predecesor planificado de sus dependencias técnicas reales.
  Una operación independiente bloqueada puede quedar `bloqueada`, con su punto
  de reanudación, y permitir avanzar a la siguiente tarea P0 técnicamente
  independiente únicamente tras revisión y decisión expresa.
- Una tarea no se considera terminada por estar implementada: pasa por
  `en_revision`, `aprobada` y finalmente `cerrada` cuando también se han
  completado merge, operación y validación aplicables.
- El roadmap no duplica el historial de entregas cerradas. Al cerrar una tarea,
  se conserva solo la información mínima necesaria para entender dependencias;
  el detalle de ejecución se traslada a `HANDOFF.md`.
- No se cambian fórmulas, contratos JSON ni semánticas existentes fuera de una
  tarea que lo autorice expresamente.

### Estados

| Estado | Uso |
|---|---|
| `pendiente` | Aprobada para planificar, todavía sin rama activa ni cambios. |
| `en_progreso` | Existe una única rama funcional activa y trabajo en curso. |
| `bloqueada` | No puede continuar; la ficha identifica el bloqueo y el punto exacto de reanudación. |
| `en_revision` | Implementación, pruebas y documentación terminadas; rama pendiente de revisión previa al PR o revisión en curso. |
| `aprobada` | Revisión completada y autorizada para PR/merge, todavía no cerrada operacionalmente. |
| `cerrada` | Merge y, cuando corresponda, validación operacional completados. |

## Protocolo de desarrollo y revisión

1. Cada tarea comienza desde `main` limpio y actualizado con `git fetch origin`,
   `git switch main` y `git pull --ff-only origin main`.
2. Se crea una rama funcional por tarea o lote, nacida del nuevo `main`.
3. El WIP funcional máximo es una rama.
4. Solo se admite una segunda rama simultánea para una incidencia urgente y
   expresamente identificada como tal.
5. Codex implementa, prueba, documenta, hace commit y publica la rama.
6. Codex no crea el PR inicialmente.
7. La rama se revisa antes de abrir el PR.
8. Las correcciones posteriores a la primera revisión se publican como commits
   nuevos; no se usa `amend` ni `force-push` salvo instrucción expresa.
9. Tras la aprobación de la rama se abre el PR.
10. La CI debe estar completamente verde antes del merge.
11. Tras el merge se elimina la rama funcional.
12. La siguiente tarea nace del `main` resultante del merge anterior.

## Línea base y límites actuales

- Rama base de este roadmap: `main`.
- SHA base verificado para el trabajo transversal AUTH activo:
  `6c26cce4a09349c30ebd289c73cf5ea985b9166f`.
- El PR #54 de preparación documental está cerrado y fusionado. La rama remota
  `docs/roadmap-executive-v1` se eliminó después de verificar que seguía
  apuntando al commit aprobado `22e2f6496dc376ad6236854e56434e8a8aa0f3cc`.
- El PR #53 de Reservas/Ventas ya está fusionado. No es trabajo pendiente.
- El PR #55 de RV-3 está cerrado y fusionado en el SHA
  `8acb8e461cc675b32162f174af771a5cbc15e8e8`; su rama
  `audit/rv-3-historical-validation` fue eliminada después del merge.
- El PR #56 de cierre documental de RV-3 está cerrado y fusionado; el `main`
  resultante es `6bebc0a1d9dc11ef9c5ebf62fcf7c16ea3c9e923`.
- El PR #57 de RV-1 está cerrado y fusionado con CI correcta en el SHA
  `f40637bfbab786f8909c2ce4e3b99435937542f6`; la rama
  `feat/rv-1-commercial-performance-ux` fue eliminada después del merge.
- El PR #58 de cierre documental de RV-1 está cerrado y fusionado; el `main`
  resultante es `3d3354f6fd4a3605dd3207b9448fa5be114e8d80`.
- El PR #59 de RV-2 está cerrado y fusionado en el SHA
  `d57d460922cddbfe4e8abf4f9dceb9bffd134613`. Tanto la CI del PR como la CI
  posterior de `main` (`CI #132`) finalizaron correctamente; la rama
  `feat/rv-2-direction-summary-production-periods` fue eliminada después del
  merge.
- Actualmente existe la rama funcional transversal activa
  `feat/auth-password-reset` en estado `en_revision`. El siguiente paso
  operacional de Reservas/Ventas sigue siendo su despliegue controlado; no se ha
  realizado todavía.
- Las fichas con rama o SHA `por asignar` no autorizan iniciar trabajo: deben
  completarse al activar formalmente la tarea.

## Secuencia de trabajo preferida

| Orden | ID | Lote | Prioridad | Estado | Predecesor planificado | Dependencia técnica real principal |
|---:|---|---|---|---|---|---|
| 1 | RV-3 | Validación histórica Reservas/Ventas | P0 | `cerrada` | Ninguno | Evidencia cerrada disponible para RV-1 |
| 2 | RV-1 | Cierre ejecutivo de Rendimiento comercial | P0 | `cerrada` | RV-3 | Evidencia de RV-3 para cancelaciones `N/D` y cero de ventas caídas |
| 3 | RV-2 | Producción y períodos de Resumen Dirección | P0 | `cerrada` | RV-1 | Contratos temporales, reglas y universos existentes; no depende técnicamente de RV-1 |
| 4 | SF-7A-OPS | Cierre operacional Salesforce Fase 7A | P0 | `pendiente` | RV-2 | Herramienta, migración, runbook y autorización propios; no depende de la UX de RV |
| 5 | SF-7B-OPS | Cierre operacional Salesforce Fase 7B | P0 | `pendiente` | SF-7A-OPS | Herramienta, fechas locales, runbook y autorización propios; no depende de RV ni de 7A |
| 6 | EXE-1 | Motor ejecutivo V1 | P0 | `pendiente` | SF-7B-OPS | Contrato y pruebas V1; no depende técnicamente de 7A/7B por ser agnóstico de módulo |
| 7 | EXE-2 | Datos ejecutivos diarios | P0 | `pendiente` | EXE-1 | Contrato de EXE-1 y fuentes canónicas locales; no depende técnicamente de los backfills 7A/7B |
| 8 | EXE-3 | Resumen Ejecutivo global | P0 | `pendiente` | EXE-2 | Contratos de EXE-1 y datasets de EXE-2 |
| 9 | EXE-4 | Correo ejecutivo piloto | P0 | `pendiente` | EXE-3 | Dataset ejecutivo global aprobado |
| 10 | EXE-5 | Piloto y calibración | P0 | `pendiente` | EXE-4 | Flujo piloto ejecutable y auditable |
| 11 | TRANS-1 | Correcciones transversales | P1 | `pendiente` | EXE-5 | Inventario aprobado de incidencias verificadas |
| 12 | UX-LEADS | UX Leads | P1 | `pendiente` | TRANS-1 | Alcance UX de Leads aprobado |
| 13 | UX-CALLS | UX Llamadas | P1 | `pendiente` | TRANS-1 | Alcance UX de Llamadas aprobado |
| 14 | UX-CAMPAIGNS | UX Campañas | P1 | `pendiente` | TRANS-1 | Alcance UX de Campañas aprobado |
| 15 | SEO-SIMPLIFY | Simplificación SEO | P2 | `pendiente` | EXE-5 | Alcance de simplificación aprobado y contratos SEO actuales |
| 16 | ANALYTICS-EXT | Ampliación del motor analítico | P2 | `pendiente` | EXE-5 | Evidencia del piloto y métricas aprobadas |
| 17 | AI-LATER | IA posterior | P3 | `pendiente` | ANALYTICS-EXT | Caso de uso, gobernanza y contrato aprobados |
| 18 | GEO-AI-LATER | GEO/IA posterior | P3 | `pendiente` | ANALYTICS-EXT | Decisión funcional y alcance aprobados |

## Lote previo: cierre definitivo de Reservas/Ventas

### RV-3 — Validación histórica

- **Estado:** `cerrada` el 2026-09-23 mediante el PR #55, fusionado en
  `8acb8e461cc675b32162f174af771a5cbc15e8e8` con CI correcta. La rama funcional
  fue eliminada y no quedan acciones operativas pendientes dentro de RV-3.
- **Conclusión:** julio conserva cancelaciones `N/D`/`null`, validadas por
  cobertura histórica `partial`; agosto conserva `sales_dropped = 0`, validado
  por la ausencia total de candidatos brutos. No se demostró ninguna
  discrepancia ni se realizaron cambios runtime.
- **Dependencia para RV-1:** representar el motivo de `N/D` mediante la cobertura
  real y conservar el cero demostrado, sin cambiar fórmulas. Esta dependencia
  quedó aplicada y cerrada mediante el PR #57 de RV-1.
- **Detalle auditable:** evidencia, seguridad y trazabilidad completa en
  [`HANDOFF.md`](HANDOFF.md).

### RV-1 — Cierre ejecutivo de Rendimiento comercial

- **Estado:** `cerrada` el 2026-09-23 mediante el PR #57, fusionado en
  `f40637bfbab786f8909c2ce4e3b99435937542f6` con CI correcta. La rama funcional
  fue eliminada y no quedan acciones operativas pendientes dentro de RV-1.
- **Conclusión:** Rendimiento comercial dispone del cierre ejecutivo aprobado:
  cumplimiento explícito, **Estado**, ratios de actividad mensual, vista V5 con
  presets, columnas ejecutivas fijas, formato `es-ES`, cobertura de margen y
  explicación de cancelaciones `N/D`; el cero validado de ventas caídas se
  conserva. No cambiaron backend, fórmulas, universos, ranking, deduplicación,
  permisos ni endpoints.
- **Dependencia para RV-2:** partir del contrato vigente de Reservas/Ventas y
  mantener la separación entre producción del período y cohorte, sin cambios
  silenciosos de semántica o claves JSON.
- **Detalle auditable:** implementación, pruebas, seguridad y trazabilidad
  completa en [`HANDOFF.md`](HANDOFF.md).

### RV-2 — Producción y períodos de Resumen Dirección

- **Estado:** `cerrada` el 2026-09-23 mediante el PR #59, fusionado en
  `d57d460922cddbfe4e8abf4f9dceb9bffd134613`. La CI del PR y la CI posterior
  de `main` (`CI #132`) fueron correctas; la rama funcional fue eliminada y no
  quedan acciones funcionales pendientes dentro de RV-2.
- **Conclusión:** Resumen Dirección separa **Producción del período**,
  **Cohorte de oportunidades creadas en el período** y estado actual. La
  producción aplica los hitos propios de reservas y ventas, períodos `[start,
  end)`, reglas y deduplicación vigentes; el contrato nuevo es aditivo y las
  claves legacy conservan su semántica.
- **Operación:** RV-2 no incorporó migraciones propias ni realizó operaciones
  Salesforce. El despliegue controlado del bloque Reservas/Ventas aún no se ha
  realizado y es el siguiente paso operacional.
- **Dependencia para SF-7A-OPS:** RV-2 queda disponible como predecesor
  planificado, pero SF-7A-OPS continúa `pendiente`, sin rama ni SHA de
  activación, hasta completar por separado el despliegue y su validación.
- **Detalle auditable:** implementación, pruebas, seguridad y trazabilidad
  completa en [`HANDOFF.md`](HANDOFF.md).

## Salesforce: cierres operacionales pendientes

### SF-7A-OPS — Cierre operacional de Fase 7A

- **Fase/lote:** Salesforce 7A, backfill histórico de atribución Lead.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** RV-2.
- **Dependencias técnicas reales:** herramienta y migración ya implementadas
  según `HANDOFF.md`, más runbook, rango, motivo y autorización operativa. La UX
  de RV-1/RV-2 no es dependencia técnica.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** requiere runbook, rango, motivo y
  autorización operativa separados; no autoriza escritura Salesforce.
- **Punto exacto de reanudación:** conciliar migraciones y ejecutar primero el
  dry-run aprobado sobre el rango acordado.
- **Criterios de aceptación:** dry-run conciliado; apply local autorizado,
  auditable e idempotente; validación posterior sin PII; incidencias y punto de
  reanudación documentados; cero escrituras Salesforce.

### SF-7B-OPS — Cierre operacional de Fase 7B

- **Fase/lote:** Salesforce 7B, reproceso histórico de portales Opportunity.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** SF-7A-OPS.
- **Dependencias técnicas reales:** herramienta implementada, fechas locales
  necesarias conciliadas, runbook, rango, motivo y autorización operativa. No
  depende técnicamente de RV-1/RV-2 ni del cierre de SF-7A-OPS.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** requiere runbook, rango, motivo y
  autorización operativa separados; Salesforce solo puede usarse para lectura
  de Leads dentro del contrato existente.
- **Punto exacto de reanudación:** verificar `created_date`, migraciones y rango
  `[from, to)`; ejecutar primero dry-run productivo aprobado.
- **Criterios de aceptación:** dry-run conciliado; apply local autorizado,
  reanudable y auditable; contratos y precedencias preservados; validación
  posterior documentada; cero escrituras Salesforce.

## Resumen Ejecutivo V1

Las decisiones persistentes de esta sección están registradas también en
[`DECISIONS.md`](DECISIONS.md). Aquí se usan como límites de aceptación del
trabajo pendiente, no como afirmación de funcionalidad ya implementada.

### Contrato funcional aprobado

- Acceso V1 exclusivo para **Administrador** y **Dirección**, con visión global.
- La arquitectura debe admitir futuros scopes de Area Manager y Manager sin
  rehacer la lógica; esos accesos no se implementan ni conceden en V1.
- Las alertas evalúan el último día cerrado. El mes en curso hasta ese corte se
  presenta únicamente como contexto informativo.
- Métricas iniciales: Leads, Reservas y Ventas.
- Baseline principal: media de D-7, D-14, D-21 y D-28; V1 exige 4/4.
- D-364 es una referencia complementaria y nunca cambia por sí sola el estado.
- Estados: **Correcto**, **Atención**, **Desviación**, **Crítico** y **No
  evaluable**. Dirección: **Favorable**, **Desfavorable** y **Estable**. Salud
  del dato: **Actualizado**, **Parcial**, **Desactualizado** e **Incidencia**.
- Una incidencia de datos no genera una alerta de negocio.
- Como máximo se publican cinco alertas ejecutivas. Se ordenan primero por
  severidad; dentro de ella, las desfavorables preceden a las favorables; después
  se usa impacto económico solo cuando exista un dato fiable y, en su defecto,
  volumen absoluto. Nunca se inventa impacto económico.
- V1 no usa IA. Las acciones recomendadas son fijas, versionadas, con clave única
  y auditables.
- Una causa solo se presenta como confirmada cuando los datos la demuestran. En
  caso contrario, el texto debe ser **Posible causa a revisar**.
- SISTRIX y GEO/IA quedan fuera y ocultos en V1.
- El correo ejecutivo general se limita durante el piloto a
  `carlos.torres@hrmotor.es` y se programa a las 08:00 `Europe/Madrid`.
- El correo SEO existente continúa independiente mientras SEO no forme parte del
  correo ejecutivo general.

### Umbrales aprobados

El baseline es la media de los cuatro mismos días anteriores. El contrato
conceptual separa signo, magnitud, diferencia absoluta y dirección:

```text
variacion_pct = ((actual - baseline) / baseline) * 100
magnitud_variacion_pct = abs(variacion_pct)
diferencia_absoluta = abs(actual - baseline)
```

Las bandas de estado se aplican exclusivamente sobre
`magnitud_variacion_pct`. Los mínimos de Leads, Reservas y Ventas se aplican
exclusivamente sobre `diferencia_absoluta`. Un nivel de alerta solo se alcanza si
se cumplen simultáneamente su banda de magnitud porcentual y su diferencia
absoluta mínima. Si una puerta absoluta no se cumple, no se puede asignar ese
nivel. La especificación ejecutable deberá fijar con pruebas todos los límites
antes de implementar el motor.

| Estado | `magnitud_variacion_pct` |
|---|---:|
| Correcto | `< 15 %` |
| Atención | `>= 15 %` y `< 25 %` |
| Desviación | `>= 25 %` y `<= 40 %` |
| Crítico | `> 40 %` |

| Métrica | Baseline mínimo evaluable | Atención | Desviación | Crítico |
|---|---:|---:|---:|---:|
| Leads | 100 | 30 | 50 | 100 |
| Reservas | 5 | 3 | 5 | 8 |
| Ventas | 5 | 3 | 5 | 8 |

Reglas de evaluabilidad:

- el estado y la dirección son dimensiones separadas: una subida grande puede
  ser **Crítico / Favorable** y una caída grande, **Crítico / Desfavorable**;
- no se define todavía una tolerancia para la dirección **Estable**; su contrato
  exacto debe fijarse mediante especificación y pruebas antes de EXE-1;
- si `actual = 0` y el baseline alcanza el mínimo evaluable, el resultado es
  **Crítico** y **Desfavorable**;
- si falta D-7, D-14, D-21 o D-28, el día está incompleto o existe una incidencia
  de sincronización, el resultado es **No evaluable** y la salud nunca se
  presenta como actualizada; debe reflejar dato parcial o incidencia según la
  evidencia;
- un resultado no evaluable no genera alerta de negocio.

### EXE-1 — Motor ejecutivo V1

- **Fase/lote:** Resumen Ejecutivo, motor analítico.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** SF-7B-OPS.
- **Dependencias técnicas reales:** contrato funcional V1 y matriz de pruebas.
  El motor es agnóstico de módulo y no depende técnicamente del cierre de 7A/7B.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** antes de código deben formalizarse pruebas
  de frontera para bandas, mínimos absolutos y dirección `Estable`, sin inventar
  tolerancias no aprobadas.
- **Punto exacto de reanudación:** diseñar un contrato de entrada/salida agnóstico
  de módulo y una matriz de pruebas de reglas V1.
- **Criterios de aceptación:** motor determinista, sin IO ni IA; 4/4 referencias;
  D-364 complementario; estados, dirección, salud y reason codes versionados;
  ausencia distinta de cero; acciones fijas con clave única; causas demostradas
  separadas de posibles causas; pruebas completas de límites y regla de cero.

### EXE-2 — Datos ejecutivos diarios Leads/Reservas/Ventas

- **Fase/lote:** Resumen Ejecutivo, adaptadores de datos.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-1.
- **Dependencias técnicas reales:** contrato de entrada de EXE-1 y fuentes
  canónicas locales. No depende técnicamente de los backfills de 7A/7B.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** reutilizar universos canónicos y datos
  locales; no duplicar fórmulas ni consultar proveedores durante el render.
- **Punto exacto de reanudación:** inventariar el contrato canónico y el cutoff
  certificado de cada una de las tres métricas.
- **Criterios de aceptación:** series diarias reconciliables para Leads, Reservas
  y Ventas; último día cerrado explícito; 4/4 referencias; frescura y cobertura
  verificables; scopes preparados sin conceder acceso futuro; sin PII ni ceros
  inventados; rendimiento por lotes y sin N+1.

### EXE-3 — Resumen Ejecutivo global

- **Fase/lote:** Resumen Ejecutivo, dashboard.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-2.
- **Dependencias técnicas reales:** contrato del motor EXE-1 y datasets diarios
  reconciliables de EXE-2.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** acceso V1 solo Administrador/Dirección;
  SISTRIX y GEO/IA ocultos.
- **Punto exacto de reanudación:** definir autorización server-side y jerarquía
  de la vista global conforme al Design System existente.
- **Criterios de aceptación:** visión global; máximo cinco alertas con orden
  aprobado; último día cerrado y contexto MTD claramente separados; salud del
  dato fuera de alertas de negocio; causas y acciones con trazabilidad; interfaz
  accesible, responsive y sin exposición de identificadores o datos sensibles.

### EXE-4 — Correo piloto 08:00

- **Fase/lote:** Resumen Ejecutivo, distribución piloto.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-3.
- **Dependencias técnicas reales:** dataset y presentación ejecutiva global
  aprobados.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** único destinatario aprobado
  `carlos.torres@hrmotor.es`; el correo SEO existente no se sustituye.
- **Punto exacto de reanudación:** diseñar payload congelado, ledger idempotente
  y autorización de configuración reutilizando patrones aprobados donde encajen.
- **Criterios de aceptación:** envío a las 08:00 `Europe/Madrid`; destinatario
  único del piloto; contenido idéntico al estado ejecutivo congelado; reintentos
  sin duplicados; errores auditables sin secretos ni PII; correo SEO sin cambios.

### EXE-5 — Piloto y calibración

- **Fase/lote:** Resumen Ejecutivo, validación funcional.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-4.
- **Dependencias técnicas reales:** flujo piloto ejecutable y auditable.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** cualquier ajuste de umbral o prioridad debe
  aprobarse y versionarse; no se reescribe el histórico.
- **Punto exacto de reanudación:** acordar ventana de piloto, responsables y
  plantilla de revisión de falsos positivos/negativos.
- **Criterios de aceptación:** ejecución piloto trazable; revisión de cobertura,
  utilidad y ruido; ajustes aprobados como nueva versión; incidencias resueltas o
  registradas con reanudación exacta; autorización expresa antes de ampliar
  destinatarios o roles.

## Trabajo posterior

Las tareas siguientes no están activas. Su detalle se completará cuando se
aproximen a ejecución, sin adelantar contratos ni inventar arquitectura.

### TRANS-1 — Correcciones transversales pendientes

- **Fase/lote:** Leads/Llamadas/Campañas.
- **Prioridad:** P1. **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-5. **Dependencias técnicas reales:** inventario
  aprobado de incidencias verificadas. **Rama:** por asignar. **SHA base:** por
  registrar.
- **Bloqueos/decisiones:** inventario y priorización pendientes.
- **Punto de reanudación:** consolidar incidencias abiertas verificadas de los
  tres módulos sin mezclar cambios funcionales no relacionados.
- **Aceptación:** lotes independientes, contratos preservados, seguridad y
  rendimiento verificados, pruebas y documentación completas.

### UX-LEADS — UX Leads

- **Fase/lote:** evolución UX. **Prioridad:** P1. **Estado:** `pendiente`.
- **Predecesor planificado:** TRANS-1. **Dependencias técnicas reales:** alcance
  UX de Leads aprobado. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** alcance funcional pendiente de inventario.
- **Punto de reanudación:** auditoría de uso, accesibilidad y deuda visual.
- **Aceptación:** alcance aprobado, sin cambios de métricas, migración compatible
  con Design System y validación responsive/accesible.

### UX-CALLS — UX Llamadas

- **Fase/lote:** evolución UX. **Prioridad:** P1. **Estado:** `pendiente`.
- **Predecesor planificado:** TRANS-1. **Dependencias técnicas reales:** alcance
  UX de Llamadas aprobado. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** alcance funcional pendiente de inventario.
- **Punto de reanudación:** auditoría de uso, accesibilidad y deuda visual.
- **Aceptación:** alcance aprobado, sin cambios silenciosos de clasificación,
  migración compatible con Design System y validación responsive/accesible.

### UX-CAMPAIGNS — UX Campañas

- **Fase/lote:** evolución UX. **Prioridad:** P1. **Estado:** `pendiente`.
- **Predecesor planificado:** TRANS-1. **Dependencias técnicas reales:** alcance
  UX de Campañas aprobado. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** alcance funcional pendiente de inventario.
- **Punto de reanudación:** auditoría de uso, accesibilidad y deuda visual.
- **Aceptación:** alcance aprobado, atribución preservada, migración compatible
  con Design System y validación responsive/accesible.

### SEO-SIMPLIFY — Simplificación SEO

- **Fase/lote:** SEO/Analytics. **Prioridad:** P2. **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-5. **Dependencias técnicas reales:** alcance de
  simplificación aprobado y contratos SEO actuales. **Rama:** por asignar. **SHA
  base:** por registrar.
- **Bloqueos/decisiones:** el correo SEO sigue independiente hasta decisión
  expresa de integración.
- **Punto de reanudación:** inventariar información esencial, secundaria y
  técnica del dashboard actual.
- **Aceptación:** simplificación aprobada sin perder trazabilidad, cobertura,
  contratos o correo existente.

### ANALYTICS-EXT — Ampliación del motor analítico

- **Fase/lote:** analítica transversal. **Prioridad:** P2. **Estado:** `pendiente`.
- **Predecesor planificado:** EXE-5. **Dependencias técnicas reales:** evidencia
  del piloto y métricas aprobadas. **Rama:** por asignar. **SHA base:** por
  registrar.
- **Bloqueos/decisiones:** métricas y nuevos módulos todavía no aprobados.
- **Punto de reanudación:** evaluar el piloto y proponer extensiones basadas en
  casos de uso demostrados.
- **Aceptación:** contrato versionado y agnóstico de módulo, migración compatible,
  evaluación determinista y costes de datos/rendimiento medidos.

### AI-LATER — IA posterior

- **Fase/lote:** capacidades futuras. **Prioridad:** P3. **Estado:** `pendiente`.
- **Predecesor planificado:** ANALYTICS-EXT.
- **Dependencias técnicas reales:** caso de uso, gobernanza y contrato aprobados.
- **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** fuera de V1; sin caso de uso, gobernanza ni contrato
  aprobados.
- **Punto de reanudación:** ninguno hasta decisión formal posterior al motor
  analítico ampliado.
- **Aceptación:** por definir tras aprobación; no introducir IA antes.

### GEO-AI-LATER — GEO/IA posterior

- **Fase/lote:** capacidades futuras. **Prioridad:** P3. **Estado:** `pendiente`.
- **Predecesor planificado:** ANALYTICS-EXT.
- **Dependencias técnicas reales:** decisión funcional y alcance aprobados.
- **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** GEO/IA y SISTRIX están fuera y ocultos en V1.
- **Punto de reanudación:** ninguno hasta decisión funcional expresa.
- **Aceptación:** por definir tras aprobación; no exponer módulos, datos ni
  navegación de GEO/IA en V1.

## Restricciones permanentes de seguridad y operación

- No registrar secretos, tokens, credenciales, datos productivos sensibles, PII
  ni datos identificativos adicionales en el roadmap o sus evidencias.
- `carlos.torres@hrmotor.es` es la única excepción actualmente aprobada como
  requisito funcional del piloto. Cualquier otro destinatario requiere una
  nueva decisión aprobada.
- Una tarea documental no autoriza conexiones externas, escrituras Salesforce,
  operaciones sobre producción, despliegues ni cambios de runtime.
- Los scopes se resuelven en servidor bajo mínimo privilegio; ocultar UI nunca
  sustituye autorización.
- `null`, ausencia, cobertura parcial e incidencia no se convierten en cero.
- El impacto económico solo se usa cuando un dato fiable lo acredita; nunca se
  estima ni extrapola para priorizar una alerta.
