# Descripción de revisión — Fase A de Campañas

Docs-Impact: updated
Docs-Matrix-Impact: updated

## Resolución posterior del PR #85 con main

El merge documental e913f08 incorpora main e314c8d y conserva Fase A6fe686a.
La matriz general mantiene su contrato y se añade el registro focal de Campañas.
La guía y el validador ya forman parte de esta rama: el bloqueo histórico por
guía ausente descrito al final no sigue vigente. Una fixture documental incluye
el enlace de la propuesta y una regresión verifica que retirarlo produce error.
Fase B no se integra: continúa como propuesta dentro de esta rama.

Verificación de esta resolución: PHP de campañas50/50,519aserciones; JS15/15;
documentación35correctas y1skipWindows; validador integral PASS15rutas con
descripción estructurada; enlaces, manifest/assets de A, conflictos y diffcheck
correctos. No se ejecuta nuevamente la suite general ni se recompilan assets
porque no se cambia código de aplicación. Las cifras generales posteriores
pertenecen a la ejecución histórica de Fase A, no a esta resolución.

## Alcance y evidencia histórica de Fase A

El detalle publicitario admite solo Meta/Google con inversión positiva
verificable y elimina Resultados/Coste por resultado. Coste por compra usa su
métrica existente. Los importes no calculables usan — con motivo específico;
las preferencias de columnas v5 se migran a v6. Campañas a revisar aplica el
universo publicitario antes de sus medias/top 8. La economía y exportación se
conservan; el bloque de revisión cambia de forma deliberada y acotada.

Fase B solo propuesta, pendiente de aprobación de persistencia y automatización;
no se presenta el histórico como estado actual. Sin migraciones, syncs,
reconstrucciones ni despliegue. Véase HANDOFF.md para resultados y límites.

La matriz incorporada es un registro focalizado pendiente de auditoría integral,
no la certificación de los seis informes. La guía y el validador documental
existentes como cambios ajenos del checkout principal no forman parte de esta
base. Se leen como referencia sin copiarlos; su validación estructural externa
puede fallar por esas referencias/contratos ausentes. Conservar ese resultado
como límite, sin afirmar validación integral exitosa.


Validación final comunicada por el agente principal: PHP focal 50/50 (519
aserciones), JS 15/15, suite general 1432/1432 (13131 aserciones), build y
Pint/sintaxis focal correctos. Paridad original/actual con meses abiertos y
cerrados: 27 aserciones, cifras/filas/exports y hashes de seis tablas idénticos;
se exceptúa deliberadamente el bloque review_campaigns. Es evidencia sintética
local, no inspección de producción. Documentación: 27 tests externos correctos
y enlaces locales verificados. Validación estructural ejecutada entre base 01d3aa8 y HEAD local de Fase A,
13 rutas: FAIL únicamente por falta de docs/guia-mantenimiento-documental.md.
La guía ajena está ausente de esta base y no se copia infraestructura no
autorizada. La estructura de la nueva matriz no presenta errores.
