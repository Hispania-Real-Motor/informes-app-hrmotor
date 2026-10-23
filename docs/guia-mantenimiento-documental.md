# Mantenimiento documental de informes

## Instalación y reconocimiento

El agente de proyecto está en `.codex/agents/report_docs_maintainer.toml`,
ubicación admitida para agentes personalizados de Codex. El archivo contiene
`name`, `description` y `developer_instructions`; hereda modelo y permisos de la
sesión principal. No se requiere `.codex/config.toml`: los subagentes están
habilitados por defecto en versiones actuales. `.gitignore` solo permite
versionar este TOML y mantiene ignorados los demás datos de `.codex`.

Al abrir una sesión nueva desde la raíz del repositorio, pide a Codex que
delegue una **revisión de solo lectura** a `report_docs_maintainer` y que espere
su respuesta. Comprueba que el hilo del agente muestra ese nombre, recibe las
instrucciones del TOML y no modifica código de aplicación. Si la interfaz no
permite seleccionar o invocar el agente, registra `NO VERIFICADO` para el
reconocimiento y usa el procedimiento fallback. Las instrucciones limitan su
conducta, pero no establecen un sandbox de solo lectura: el agente principal
debe revisar el diff.

## Procedimiento tras feature, bugfix o hotfix

1. Estabiliza código y pruebas funcionales. Comprueba rama, árbol de trabajo y
   `git diff` completo contra la base de la tarea.
2. Identifica rutas, informes y pestañas reales; incluye sincronizadores,
   normalizadores, snapshots, atribución, configuración, vistas y tests.
3. Invoca `report_docs_maintainer` con base y HEAD, diff y alcance: «Actualiza
   solo documentación de los informes afectados; comprueba fuentes, cálculos,
   configuración, tests y exportación. Mantén documentos funcionales existentes
   y matriz. Devuelve discrepancias y archivos modificados». Espera el cierre.
4. Revisa los documentos funcionales, el general cuando haya regla transversal,
   la matriz, decisiones pendientes y el handoff. Conserva historia y separa
   reglas vigentes, históricas y aún pendientes. Cada KPI documentado debe
   apuntar a una pestaña real y cumplir los 23 campos del contrato de la matriz.
5. Ejecuta el validador y sus pruebas; revisa `git diff --check`, el diff
   documental y `git status`. Incluye código y documentación en la misma PR.

Para un refactor o una sincronización que conserve exactamente el resultado,
explica la evidencia del no impacto mediante el contrato de PR. No agregues
ediciones cosméticas. Un cambio de fórmula económica, coeficiente mensual,
fuente, universo, filtro o resultado exige revisar el documento del informe y
declarar el impacto en la matriz. Si la ficha todavía no está auditada, registra
el impacto pendiente con evidencia, sin inventar indicadores. El validador solo
comprueba el diff y la estructura; una persona contrasta la semántica.

## Auditoría inicial posterior

Encargarla separadamente. Recorrer las seis áreas y las seis pestañas de
Comisiones, contrastar código y tests con fuentes, poblar fichas sin inventar
datos, marcar discrepancias, revisar documentos funcionales anteriores y
validar en lotes pequeños. Esta instalación no realiza dicha auditoría.

## Fallback sin subagentes

El agente principal aplica los pasos anteriores directamente, registra
«fallback: agente personalizado no invocado» en HANDOFF y la PR y nunca afirma
que se ejecutó una delegación. Revisa igualmente el diff y las pruebas.

## Contrato de descripción de PR

Para cambios funcionales mapeados con documentación y matriz modificadas:

```text
Docs-Impact: updated
Docs-Matrix-Impact: updated
```

`updated` exige que `docs/matriz-trazabilidad-kpi.md` figure como archivo
modificado en el diff completo de la PR. Que la matriz exista en el repositorio
no basta. Si el cambio funcional actualiza el documento de un informe pero no
varía ningún KPI, fuente, universo, filtro, fórmula ni trazabilidad, declara:

```text
Docs-Impact: updated
Docs-Matrix-Impact: unchanged
Docs-Matrix-Reason: El ajuste cambia solo el texto de ayuda de la pestaña; el universo, los filtros y los cálculos permanecen iguales.
Docs-Matrix-Evidence: app/Services/Reports/Calls/CallDashboardDatasetService.php
```

La evidencia debe ser una ruta funcional modificada en la PR; la justificación
debe ser técnica y concreta. El revisor humano comprueba que `unchanged` sea
cierto, especialmente ante cambios de fuentes, universos, filtros o fórmulas.

Para cambios sin efecto en reglas, origen, fórmula, resultado, presentación ni
auditoría, incluidos PR solo de tests, se exige un bloque estructurado:

```text
Docs-Impact: none
Docs-Scope: campaigns
Docs-Reason: El cambio extrae una función sin variar filtros ni resultados; el test de regresión mantiene el mismo payload.
Docs-Evidence: app/Services/Campaigns/CampaignDashboardDatasetService.php
```

`Docs-Scope` usa claves del validador (`interests`, `reservations`, `calls`,
`campaigns`, `commissions`, `stock`, `tests-only` o `outside-reports`). Puede
listar varias separadas por comas. `Docs-Evidence` nombra al menos una ruta
modificada que justifica la afirmación. Para rutas que contienen comas, usa una
lista JSON en una sola línea, por ejemplo `Docs-Evidence: ["ruta, especial.php"]`.
`Docs-Reason` debe ser técnica y concreta.
En una PR solo documental, `Docs-Impact: updated` basta. La CI comprueba formato
y rutas; el revisor humano decide si el motivo es verdadero.

Todo PHP bajo `app/`, `config/`, `routes/` y `database/`, JavaScript/TypeScript
bajo `resources/js/` y Blade bajo `resources/views/` es candidato funcional.
El detector no usa palabras clave para decidir si una ruta desconocida puede
omitirse. Si pertenece a un informe, amplía el mapa de impacto del validador,
su prueba y esta guía. Para un módulo legítimo ajeno a los seis informes, cita
**exactamente** las rutas funcionales sin mapa y aporta una excepción revisable:

```text
Docs-Impact: none
Docs-Scope: outside-reports
Docs-Reason: El cambio ajusta solo los códigos de salida del monitor operativo y no alimenta fuentes, cálculos ni vistas de los seis informes.
Docs-Evidence: app/Services/Operations/OperationalTaskExitCode.php
Docs-Outside-Paths: ["app/Services/Operations/OperationalTaskExitCode.php"]
Docs-Outside-Reason: OperationalTaskExitCode define los estados del monitor y los comandos operativos; no participa en las reglas de los seis informes.
Docs-Outside-Evidence: app/Services/Operations/OperationalTaskExitCode.php
```

`Docs-Outside-Paths` es una lista JSON de rutas modificadas, sin duplicados.
La evidencia también debe citar una ruta modificada relevante. La excepción
queda sujeta a revisión humana; `outside-reports` nunca exime automáticamente
una ruta ejecutable desconocida. En una PR mixta, añade los mismos campos para
las rutas ajenas y documenta todos los informes mapeados.

Para código de Financieros (servicio, vista o configuración financiera), un
`Docs-Impact: updated` exige actualizar `docs/informe-comisiones.md` y
`docs/informe-comisiones-financieras.md`. Para las rutas de fórmula comercial
registradas en el validador exige además evaluar y, cuando corresponda,
modificar `docs/Calculo_comisiones_comerciales.txt`. Si uno de los documentos
especializados no cambia realmente, usa `Docs-Financial-Impact: unchanged` o
`Docs-Commercial-Formula-Impact: unchanged`, respectivamente, con los campos
`-Reason` y `-Evidence` del mismo prefijo. Cita la ruta de código modificada y
explica por qué ese documento no cambia. `Docs-Impact: none` cubre refactors
sin variación funcional con su razón y evidencia ordinarias.

Ejemplo local (el archivo de texto contiene la descripción de la PR):

```text
python scripts/check_documentation_integrity.py --base <SHA-base> --head <SHA-HEAD> --pr-body-file <ruta-descripcion>
python -m unittest discover -s tests/documentation -p "test_*.py"
```

El script calcula `git merge-base` y compara el árbol completo desde esa base
hasta HEAD, con nombres terminados en NUL. Rechaza eliminaciones (`D`),
renombrados (`R`), cambios de tipo (`T`) y estados no admitidos. Consulta con
`git ls-tree` el modo de cada ruta modificada en HEAD: solo admite archivos
regulares `100644` y `100755`; un symlink nuevo o modificado (`120000`) y un
gitlink (`160000`) fallan incluso si el estado del diff es `A` o `M`. La
consulta se limita a las rutas del diff y no sigue destinos de enlaces. Al
comprobar documentos y referencias locales también se rechazan componentes
symlink antes de leer archivos. No ejecuta PHP, no lee secretos ni accede a
servicios.

## GitHub Actions y protección de rama

`.github/workflows/docs-integrity.yml` ejecuta el job estable `docs-integrity`
en PR hacia `main` cuando se abre, sincroniza, reabre, edita su descripción o
pasa a lista para revisión (`opened`, `synchronize`, `reopened`, `edited`,
`ready_for_review`). Usa Python 3.12 para `tomllib`, `contents: read`, checkout
completo y sin credenciales persistidas. Lee el cuerpo de la PR desde el evento
JSON; los SHA se pasan por variables de entorno. Ejecuta los tests de
infraestructura y el validador, sin editar archivos ni hacer push. Antes de
ejecutar Python, comprueba desde Git que `scripts/`, `tests/` y
`tests/documentation/` siguen siendo árboles y que el script y las pruebas
documentales rastreadas son archivos regulares; así no ejecuta un entry point
convertido en symlink. La comprobación se limita a esas rutas de control.

Un administrador debe configurar manualmente `docs-integrity` como required
status check en la protección de `main` después de que aparezca en una PR. La
Action por sí sola no impide una fusión si esa protección no está configurada.
La CI previa sigue separada.

`.gitignore` y `.github/workflows/ci.yml` se tratan como controles críticos:
el primero determina qué archivos de infraestructura pueden entrar al diff y
el segundo puede alterar las comprobaciones generales de la PR. Sus cambios,
como los de `docs-integrity.yml`, `AGENTS.md`, el TOML, el script, la guía o
`tests/documentation/`, requieren `Docs-Impact: updated`, la guía y pruebas
documentales en el diff. No se aplica esta regla al resto de archivos de
`.github/` sin relación con estos controles.

## Límites

Las fixtures de pruebas deben incluir los destinos locales enlazados desde la
matriz vigente. La reconciliación del PR #85 conserva la propuesta de estado
actual de Campañas como documento pendiente: su fixture existe para verificar
el enlace, sin implementar Fase B. Una regresión comprueba que retirar ese
destino sigue produciendo un error de referencia inexistente.

El mapa por rutas es deliberadamente conservador: cambios en rutas compartidas
pueden pedir documentos de varios informes. Una ruta ejecutable sin mapa debe
mapearse o justificarse expresamente como ajena. No se infiere la semántica del
diff ni se verifica que las fórmulas explicadas sean correctas. La validez de
las justificaciones `none` y `unchanged`, la pertenencia real de un KPI a una
pestaña y el contenido de las fichas requieren revisión humana. La auditoría
inicial de la matriz sigue pendiente. Una PR puede alterar su propio validador;
la protección de rama y la revisión deben cubrir esos cambios. El validador
exige que workflow, script, pruebas, guía, agente e instrucciones de
mantenimiento se revisen junto con la guía y las pruebas de control. También
protege `.gitignore` y la CI principal por las razones anteriores. Ninguna de
estas comprobaciones sustituye la revisión humana del diff de esos archivos.

`AGENTS.md` exige actualizar `docs/ai/HANDOFF.md` tras toda modificación del
proyecto. No se añade aquí un bloqueo automático para ese archivo: el validador
opera sobre PR completas y no puede distinguir con certeza una corrección
editorial de una tarea funcional ni verificar que el contenido del handoff
describe la tarea. El agente principal debe revisarlo en el protocolo de
entrega; la revisión humana de la PR comprueba su presencia y exactitud.

### Mapeo añadido para configuración de estado actual de Campañas

`config/campaign_current_status.php` se mapea explícitamente al informe `campaigns` en el validador y su prueba de regresión; pertenece al contrato de estado operativo del detalle, aunque no modifica KPIs económicos. Las modificaciones del motor central `OperationalTaskCatalog`, `OperationalTaskService`, `OperationalTaskHealthService` y `routes/console.php` requieren declarar las rutas exactas en `Docs-Outside-Paths` cuando su diff se limita a activación/reserva/supervisión de tareas, además de documentar Campañas por las rutas funcionales mapeadas. La excepción no elimina la revisión humana ni permite omitir cambios reales de informes.
