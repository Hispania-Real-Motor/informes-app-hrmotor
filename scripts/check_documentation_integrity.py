#!/usr/bin/env python3
"""Validate documentation changes in a PR using its complete Git tree diff."""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
GENERAL = "docs/Documentacion_general_informes_y_contraste_salesforce.md"
MATRIX = "docs/matriz-trazabilidad-kpi.md"
GUIDE = "docs/guia-mantenimiento-documental.md"
FINANCIAL_DOC = "docs/informe-comisiones-financieras.md"
COMMERCIAL_CALC_DOC = "docs/Calculo_comisiones_comerciales.txt"
CONTROL_PATHS = {
    ".gitignore",
    ".github/workflows/ci.yml",
    "AGENTS.md",
    ".codex/agents/report_docs_maintainer.toml",
    ".github/workflows/docs-integrity.yml",
    "scripts/check_documentation_integrity.py",
    "tests/documentation/test_check_documentation_integrity.py",
    GUIDE,
}
VALIDATOR_TEST = "tests/documentation/test_check_documentation_integrity.py"
DOCS = {
    "interests": "docs/reglas-negocio-leads.md",
    "reservations": "docs/informe-reservas-ventas.md",
    "calls": "docs/informe-llamadas.md",
    "campaigns": "docs/informe-campanas.md",
    "commissions": "docs/informe-comisiones.md",
    "stock": "docs/informe-stock.md",
}
ALL = frozenset(DOCS)
SOURCE_PREFIXES = (
    ("app/Services/Reports/Leads/", {"interests"}),
    ("app/Services/Reports/MonthlyCommercial/", {"interests", "reservations"}),
    ("app/Services/Reports/ReservationsSales/", {"reservations"}),
    ("app/Services/Reports/ReservasVentas/", {"reservations"}),
    ("app/Services/Reports/Calls/", {"calls"}),
    ("app/Services/Campaigns/", {"campaigns"}),
    ("app/Services/Reports/CommercialCommissions/", {"commissions"}),
    ("app/Services/Reports/AreaManagerCommissions/", {"commissions"}),
    ("app/Services/Reports/CallCenterCommissions/", {"commissions"}),
    ("app/Services/Reports/ContactCenterCommissions/", {"commissions"}),
    ("app/Services/Reports/FinancialCommissions/", {"commissions"}),
    ("app/Services/Reports/Stock/", {"stock"}),
    ("app/Services/Salesforce/SalesforceInterest", {"interests", "reservations", "calls", "campaigns"}),
    ("app/Services/Salesforce/SalesforceOpportunity", {"reservations", "campaigns", "commissions", "stock"}),
    ("app/Http/Controllers/Reports/Leads/", {"interests"}),
    ("app/Http/Controllers/Reports/ReservationsSales/", {"reservations"}),
    ("app/Http/Controllers/Reports/Calls/", {"calls"}),
    ("app/Http/Controllers/Reports/Campaigns/", {"campaigns"}),
    ("app/Http/Controllers/Reports/CommercialCommissions/", {"commissions"}),
    ("app/Http/Controllers/Reports/Stock/", {"stock"}),
    ("app/Http/Requests/Reports/ReservationsSales/", {"reservations"}),
    ("resources/views/reports/leads/", {"interests"}),
    ("resources/views/reports/reservations-sales/", {"reservations"}),
    ("resources/views/reports/calls/", {"calls"}),
    ("resources/views/reports/campaigns/", {"campaigns"}),
    ("resources/views/reports/commercial-commissions/", {"commissions"}),
    ("resources/views/reports/stock/", {"stock"}),
    ("resources/views/components/reports/", ALL),
    ("resources/views/reports/partials/", ALL),
    ("resources/views/reports/summary/", ALL),
)
EXACT = {
    "app/Console/Commands/SalesforceSyncOpportunitiesCommand.php": {"reservations", "campaigns", "commissions", "stock"},
    "config/campaign_current_status.php": {"campaigns"},
    "config/commercial_commissions.php": {"commissions"},
    "config/stock.php": {"stock"},
    "config/reports.php": ALL,
    "config/salesforce.php": ALL,
    "routes/web.php": ALL,
    "routes/api.php": ALL,
    "resources/js/reports/leads-dashboard.js": {"interests"},
    "resources/js/reports/reservations-sales-dashboard.js": {"reservations"},
    "resources/js/reports/calls-dashboard.js": {"calls"},
    "resources/js/reports/campaigns-dashboard.js": {"campaigns"},
    "resources/js/reports/campaign-presentation.js": {"campaigns"},
    "resources/js/reports/commercial-commissions-dashboard.js": {"commissions"},
    "resources/js/reports/stock-dashboard.js": {"stock"},
    "resources/js/reports/app-shell.js": ALL,
    "app/Services/Reports/ReportSyncRunService.php": ALL,
    "app/Http/Controllers/Api/CommercialCommissionApiController.php": {"commissions"},
    "app/Models/SalesforceOpportunity.php": {"reservations", "campaigns", "commissions", "stock"},
    "app/Models/SalesforceInterest.php": {"interests", "reservations", "calls", "campaigns"},
    "app/Models/SalesforceLead.php": {"interests", "campaigns"},
    "app/Models/SalesforceCall.php": {"calls"},
    "app/Models/SalesforceVehicle.php": {"stock"},
    "app/Models/SalesforceUser.php": {"interests", "reservations", "commissions"},
    "app/Models/ReportSyncRun.php": ALL,
}
MODEL_PREFIXES = (
    ("SalesforceInterest", {"interests", "reservations", "calls", "campaigns"}),
    ("SalesforceOpportunity", {"reservations", "campaigns", "commissions", "stock"}),
    ("SalesforceCall", {"calls"}),
    ("Campaign", {"campaigns"}),
    ("CommercialCommission", {"commissions"}),
    ("CommercialDelegation", {"commissions"}),
    ("Stock", {"stock"}),
    ("SalesforceSale", {"stock"}),
)
TOKEN_DOMAINS = (
    ("interest", {"interests", "reservations", "calls", "campaigns"}),
    ("opportunity", {"reservations", "campaigns", "commissions", "stock"}),
    ("lead", {"interests", "campaigns"}),
    ("call", {"calls"}),
    ("campaign", {"campaigns"}),
    ("commission", {"commissions"}),
    ("stock", {"stock"}),
    ("vehicle", {"stock"}),
)
FINANCIAL_SOURCES = (
    "app/Services/Reports/FinancialCommissions/",
    "resources/views/reports/commercial-commissions/partials/financial-tab.blade.php",
    "app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php",
    "config/commercial_commissions.php",
)
COMMERCIAL_FORMULA_SOURCES = {
    "app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php",
    "app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php",
    "app/Http/Controllers/Reports/CommercialCommissions/CommercialCommissionFormulaSettingsController.php",
    "config/commercial_commissions.php",
}
GENERIC_REASONS = {
    "sin impacto", "no impact", "no aplica", "n/a", "ninguno",
    "refactor sin cambios", "solo refactor", "solo tests", "tests only",
    "documentacion no necesaria", "no cambia nada",
}


@dataclass(frozen=True)
class Change:
    status: str
    path: str
    old_path: str | None = None
    new_mode: str | None = None


def git(*args: str, root: Path = ROOT) -> bytes:
    result = subprocess.run(
        ["git", *args], cwd=root, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, check=False,
    )
    if result.returncode:
        raise ValueError(f"Git falló en {args[0]}: {result.stderr.decode('utf-8', 'replace').strip()}")
    return result.stdout


def parse_name_status(raw: bytes) -> list[Change]:
    parts = raw.split(b"\0")
    if parts[-1:] == [b""]:
        parts.pop()
    changes: list[Change] = []
    index = 0
    while index < len(parts):
        status = parts[index].decode("ascii")
        index += 1
        if not status or status[0] not in "ACDMRTUXB":
            raise ValueError(f"Estado Git desconocido: {status!r}")
        old = None
        if status[0] in "RC":
            if index + 1 >= len(parts):
                raise ValueError("Diff Git incompleto para renombrado/copia")
            old = parts[index].decode("utf-8", "surrogateescape")
            index += 1
        if index >= len(parts):
            raise ValueError("Diff Git incompleto")
        path = parts[index].decode("utf-8", "surrogateescape")
        index += 1
        changes.append(Change(status, path.replace("\\", "/"), old))
    return changes


def changes_between(base: str, head: str, root: Path = ROOT) -> list[Change]:
    for sha in (base, head):
        if not re.fullmatch(r"[0-9a-fA-F]{40}", sha):
            raise ValueError("Los SHA base y head deben contener 40 dígitos hexadecimales")
        git("cat-file", "-e", f"{sha}^{{commit}}", root=root)
    merge_base = git("merge-base", base, head, root=root).decode("ascii").strip()
    raw = git("diff", "--name-status", "-z", "-M", merge_base, head, "--", root=root)
    changes = parse_name_status(raw)
    paths = sorted({change.path for change in changes if change.status[0] != "D"})
    modes: dict[str, str] = {}
    for offset in range(0, len(paths), 64):
        tree = git("ls-tree", "-r", "-z", "--full-tree", head, "--",
                   *(f":(literal){path}" for path in paths[offset:offset + 64]), root=root)
        for entry in tree.split(b"\0"):
            if not entry:
                continue
            metadata, separator, name = entry.partition(b"\t")
            if not separator:
                raise ValueError("Entrada Git sin ruta en ls-tree")
            mode = metadata.split(b" ", 1)[0].decode("ascii")
            path = name.decode("utf-8", "surrogateescape").replace("\\", "/")
            modes[path] = mode
    missing = set(paths).difference(modes)
    if missing:
        raise ValueError(f"No se pudo verificar el tipo Git de: {', '.join(sorted(missing))}")
    return [Change(change.status, change.path, change.old_path, modes.get(change.path))
            for change in changes]


def local_regular_file(root: Path, path: Path) -> bool:
    """Check a repository-local regular file without following any symlink component."""
    root_path = Path(os.path.abspath(root))
    candidate = Path(os.path.abspath(path))
    if not candidate.is_relative_to(root_path):
        return False
    current = root_path
    for part in candidate.relative_to(root_path).parts:
        current /= part
        if current.is_symlink():
            return False
    return candidate.is_file()


def domains_for(path: str) -> set[str]:
    if path in EXACT:
        return set(EXACT[path])
    matched: set[str] = set()
    for prefix, domains in SOURCE_PREFIXES:
        if path.startswith(prefix):
            matched.update(domains)
    if matched:
        return matched
    if path.startswith("app/Models/"):
        name = path.rsplit("/", 1)[-1]
        for prefix, domains in MODEL_PREFIXES:
            if name.startswith(prefix):
                matched.update(domains)
        return matched
    if path.startswith("app/Console/Commands/") or path.startswith("database/migrations/"):
        name = path.rsplit("/", 1)[-1].lower()
        for token, domains in TOKEN_DOMAINS:
            if token in name:
                matched.update(domains)
        return matched
    return matched


def functional_candidate(path: str) -> bool:
    if path.startswith(("app/", "config/", "routes/", "database/")):
        return path.endswith(".php")
    if path.startswith("resources/js/"):
        return path.endswith((".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx", ".vue"))
    return path.startswith("resources/views/") and path.endswith(".blade.php")


def unknown_functional(path: str) -> bool:
    return functional_candidate(path) and not domains_for(path)


def contract(body: str) -> tuple[dict[str, str], list[str]]:
    errors: list[str] = []
    fields: dict[str, str] = {}
    for key in ("Docs-Impact", "Docs-Scope", "Docs-Reason", "Docs-Evidence",
                "Docs-Matrix-Impact", "Docs-Matrix-Reason", "Docs-Matrix-Evidence",
                "Docs-Outside-Paths", "Docs-Outside-Reason", "Docs-Outside-Evidence",
                "Docs-Financial-Impact", "Docs-Financial-Reason", "Docs-Financial-Evidence",
                "Docs-Commercial-Formula-Impact", "Docs-Commercial-Formula-Reason",
                "Docs-Commercial-Formula-Evidence"):
        matches = re.findall(rf"(?im)^{re.escape(key)}:[ \t]*(.*?)[ \t]*$", body)
        if len(matches) > 1:
            errors.append(f"{key} aparece varias veces")
        if matches:
            fields[key] = matches[0].strip()
    if fields.get("Docs-Impact") not in {"updated", "none"}:
        errors.append("Falta Docs-Impact: updated|none en la descripción de la PR")
    return fields, errors


def parse_paths(raw: str, field: str, errors: list[str], json_only: bool = False) -> set[str]:
    try:
        if raw.startswith("["):
            parsed = json.loads(raw)
            if not isinstance(parsed, list) or not parsed or not all(
                isinstance(item, str) and item.strip() for item in parsed
            ) or len(set(parsed)) != len(parsed):
                raise ValueError("lista vacía, duplicada o inválida")
            return set(parsed)
        if json_only:
            raise ValueError("se requiere una lista JSON")
        result = {item.strip() for item in raw.split(",") if item.strip()}
        if result:
            return result
    except (ValueError, json.JSONDecodeError):
        pass
    errors.append(f"{field} debe ser una lista válida de rutas" + (" JSON" if json_only else ""))
    return set()


def validate_justification(fields: dict[str, str], prefix: str, paths: set[str],
                           relevant: set[str], errors: list[str]) -> None:
    reason = fields.get(f"{prefix}-Reason", "")
    if len(reason) < 60 or len(reason.split()) < 9 or reason.casefold().strip(" .") in GENERIC_REASONS:
        errors.append(f"{prefix}-Reason debe explicar técnicamente la excepción (al menos 60 caracteres y 9 palabras)")
    evidence = parse_paths(fields.get(f"{prefix}-Evidence", ""), f"{prefix}-Evidence", errors)
    if evidence and (not evidence.issubset(paths) or not evidence.intersection(relevant)):
        errors.append(f"{prefix}-Evidence debe citar una ruta relevante modificada en la PR")


def validate_matrix(root: Path) -> list[str]:
    path = root / MATRIX
    if not local_regular_file(root, path):
        return [f"Falta archivo regular seguro: {MATRIX}"]
    content = path.read_text(encoding="utf-8")
    errors: list[str] = []
    for heading in ("Contrato de ficha KPI", "Intereses (ruta histórica Leads)",
                    "Reservas / Ventas", "Llamadas", "Campañas", "Comisiones",
                    "Stock", "Fichas verificadas"):
        if not re.search(rf"(?m)^#+\s+{re.escape(heading)}\s*$", content):
            errors.append(f"Matriz: falta sección {heading}")
    for field in range(1, 24):
        if not re.search(rf"(?m)^{field}\.\s+\S", content):
            errors.append(f"Matriz: falta campo contractual {field}")
    for tab in ("Comerciales", "Delegaciones", "Call Center", "Contact Center", "Área Manager", "Financieros"):
        if not re.search(rf"(?m)^\|\s*{re.escape(tab)}\s*\|", content):
            errors.append(f"Matriz: falta pestaña de Comisiones {tab}")
    for target in re.findall(r"\[[^\]]+\]\(([^)]+)\)", content):
        if target.startswith(("http:", "https:", "#")):
            continue
        candidate = path.parent / target.split("#", 1)[0]
        if not local_regular_file(root, candidate):
            errors.append(f"Matriz: referencia inexistente {target}")
    return errors


def validate(changes: list[Change], body: str, root: Path = ROOT) -> list[str]:
    errors: list[str] = []
    paths = {change.path for change in changes}
    for change in changes:
        status = change.status[0] if change.status else "?"
        if status == "D":
            errors.append(f"Eliminación prohibida: {change.path}")
        elif status == "R":
            errors.append(f"Renombrado prohibido: {change.old_path} -> {change.path}")
        elif status == "T":
            errors.append(f"Cambio de tipo prohibido (Git T): {change.path}")
        elif status not in {"A", "M"}:
            errors.append(f"Estado Git no permitido ({change.status}): {change.path}")
        if change.new_mode == "120000":
            errors.append(f"Enlace simbólico prohibido (modo Git 120000): {change.path}")
        elif change.new_mode is not None and change.new_mode not in {"100644", "100755"}:
            errors.append(f"Tipo Git no regular prohibido ({change.new_mode}): {change.path}")
    affected: set[str] = set()
    mapped_functional: set[str] = set()
    unknown: set[str] = set()
    for path in paths:
        mapped = domains_for(path)
        affected.update(mapped)
        if functional_candidate(path):
            if mapped:
                mapped_functional.add(path)
            else:
                unknown.add(path)
    for required in (GENERAL, MATRIX, GUIDE, *DOCS.values(),
                     FINANCIAL_DOC, COMMERCIAL_CALC_DOC,
                     "docs/decisiones-negocio-pendientes.md"):
        if not local_regular_file(root, root / required):
            errors.append(f"Falta referencia documental regular y segura: {required}")
    if MATRIX in paths or "scripts/check_documentation_integrity.py" in paths:
        errors.extend(validate_matrix(root))
    fields, contract_errors = contract(body)
    errors.extend(contract_errors)
    impact = fields.get("Docs-Impact")
    if unknown:
        declared = parse_paths(fields.get("Docs-Outside-Paths", ""), "Docs-Outside-Paths", errors, json_only=True)
        if declared != unknown:
            errors.append("Ruta funcional sin mapa documental: Docs-Outside-Paths debe enumerar exactamente las rutas ajenas a informes; las rutas de informes deben mapearse")
        validate_justification(fields, "Docs-Outside", paths, unknown, errors)
    elif any(key.startswith("Docs-Outside-") for key in fields):
        errors.append("Docs-Outside-Paths solo se admite para rutas funcionales sin mapa")

    control_changes = {path for path in paths if path in CONTROL_PATHS or path.startswith("tests/documentation/")}
    validator_tests = {path for path in paths if path.startswith("tests/documentation/") and path.endswith(".py")}
    if control_changes:
        if impact != "updated":
            errors.append("Los controles documentales requieren Docs-Impact: updated")
        if GUIDE not in paths:
            errors.append(f"Cambios de controles documentales requieren revisar {GUIDE}")
        if not validator_tests:
            errors.append(f"Cambios de controles documentales requieren revisar pruebas en tests/documentation/ (por ejemplo {VALIDATOR_TEST})")
    if impact == "updated":
        for domain in sorted(affected):
            if DOCS[domain] not in paths:
                errors.append(f"Falta actualización funcional de {domain}: {DOCS[domain]}")
        if mapped_functional:
            matrix_impact = fields.get("Docs-Matrix-Impact")
            if matrix_impact == "updated":
                if MATRIX not in paths:
                    errors.append(f"Docs-Matrix-Impact: updated requiere modificar {MATRIX} en el diff")
            elif matrix_impact == "unchanged":
                validate_justification(fields, "Docs-Matrix", paths, mapped_functional, errors)
            else:
                errors.append("Código funcional con Docs-Impact: updated requiere Docs-Matrix-Impact: updated|unchanged")
        specialized = (
            ("Financial", FINANCIAL_DOC, {p for p in mapped_functional if p.startswith(FINANCIAL_SOURCES)}),
            ("Commercial-Formula", COMMERCIAL_CALC_DOC, mapped_functional.intersection(COMMERCIAL_FORMULA_SOURCES)),
        )
        for label, document, sources in specialized:
            if sources and document not in paths:
                prefix = f"Docs-{label}"
                if fields.get(f"{prefix}-Impact") == "unchanged":
                    validate_justification(fields, prefix, paths, sources, errors)
                else:
                    errors.append(f"Falta revisión de {document}; modificarlo o justificar {prefix}-Impact: unchanged")
        if not affected and not any(path.startswith("docs/") or path == "AGENTS.md" for path in paths):
            if not control_changes:
                errors.append("Docs-Impact: updated sin ningún documento actualizado")
    elif impact == "none":
        scope = {item.strip() for item in fields.get("Docs-Scope", "").split(",") if item.strip()}
        expected = affected or ({"tests-only"} if paths and all(p.startswith("tests/") for p in paths) else {"outside-reports"})
        if scope != expected:
            errors.append(f"Docs-Scope debe ser {', '.join(sorted(expected))}")
        validate_justification(fields, "Docs", paths, mapped_functional or paths, errors)
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True, help="SHA de la rama base de la PR")
    parser.add_argument("--head", required=True, help="SHA de la rama head de la PR")
    body_group = parser.add_mutually_exclusive_group(required=True)
    body_group.add_argument("--event-json", type=Path, help="GITHUB_EVENT_PATH")
    body_group.add_argument("--pr-body-file", type=Path, help="Archivo UTF-8 con la descripción de la PR")
    args = parser.parse_args()
    try:
        if args.event_json:
            event = json.loads(args.event_json.read_text(encoding="utf-8"))
            body = event["pull_request"].get("body") or ""
        else:
            body = args.pr_body_file.read_text(encoding="utf-8")
        changes = changes_between(args.base, args.head)
        errors = validate(changes, body)
    except (ValueError, OSError, KeyError, json.JSONDecodeError) as exc:
        print(f"FAIL: {exc}")
        return 1
    if errors:
        print("FAIL: integridad documental")
        for error in errors:
            print(f"- {error}")
        return 1
    print(f"PASS: integridad documental ({len(changes)} rutas cambiadas)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
