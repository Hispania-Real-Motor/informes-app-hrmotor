"""Deterministic tests for PR documentation policy; no Laravel or network."""

from __future__ import annotations

import os
import subprocess
import sys
import tempfile
import tomllib
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
import check_documentation_integrity as checker  # noqa: E402


def fixture_root(root: Path) -> None:
    for path in (checker.GENERAL, checker.GUIDE, *checker.DOCS.values(),
                 "docs/informe-comisiones-financieras.md",
                 "docs/Calculo_comisiones_comerciales.txt",
                 "docs/ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md",
                 "docs/salesforce-interest-generations.md",
                 "docs/decisiones-negocio-pendientes.md"):
        target = root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("fixture\n", encoding="utf-8")
    target = root / checker.MATRIX
    source = ROOT / checker.MATRIX
    if not checker.local_regular_file(ROOT, source):
        raise ValueError(f"Matriz no regular o enlace simbólico: {checker.MATRIX}")
    target.write_text(source.read_text(encoding="utf-8"), encoding="utf-8")


def updated(matrix_evidence: str | None = None) -> str:
    body = "Docs-Impact: updated"
    if matrix_evidence:
        body += ("\nDocs-Matrix-Impact: unchanged\n"
                 "Docs-Matrix-Reason: El ajuste solo cambia el texto presentado; "
                 "el universo, los filtros y la fórmula del KPI conservan sus valores.\n"
                 f"Docs-Matrix-Evidence: {matrix_evidence}")
    return body


def no_impact(scope: str, evidence: str) -> str:
    return ("Docs-Impact: none\n" f"Docs-Scope: {scope}\n"
            "Docs-Reason: La extracción conserva filtros, fechas pivote y payload; "
            "la comparación de regresión verifica los mismos resultados.\n"
            f"Docs-Evidence: {evidence}")


class IntegrityTests(unittest.TestCase):
    def test_shared_generation_infrastructure_is_not_outside_reports(self) -> None:
        for path in ("app/Providers/AppServiceProvider.php",
                     "app/Services/Salesforce/SalesforceClient.php",
                     "app/Console/Commands/PruneTransversalDataCommand.php"):
            self.assertEqual(checker.ALL, checker.domains_for(path))
        self.assertEqual({"interests", "reservations"}, checker.domains_for(
            "app/Console/Commands/RefreshMonthlyCommercialReportCommand.php"))
        self.assertEqual({"interests", "campaigns"}, checker.domains_for(
            "app/Services/SeoAnalytics/SalesforceOrganicInterestProjectionService.php"))

    def test_generation_services_map_to_interest_consumers(self) -> None:
        for service in ("Store", "Reader"):
            self.assertEqual(
                {"interests", "reservations", "calls", "campaigns"},
                checker.domains_for(f"app/Services/Salesforce/InterestGeneration{service}.php"),
            )

    def test_salesforce_opportunities_command_maps_to_its_report_consumers(self) -> None:
        self.assertEqual(
            {"reservations", "campaigns", "commissions", "stock"},
            checker.domains_for("app/Console/Commands/SalesforceSyncOpportunitiesCommand.php"),
        )

    def test_current_campaign_status_configuration_maps_only_to_campaigns(self) -> None:
        self.assertEqual({"campaigns"}, checker.domains_for("config/campaign_current_status.php"))

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        fixture_root(self.root)

    def check(self, changes: list[checker.Change], body: str) -> list[str]:
        return checker.validate(changes, body, self.root)

    def git_fixture(self):
        def run(*args: str, input_bytes: bytes | None = None) -> str:
            result = subprocess.run(["git", *args], cwd=self.root, input=input_bytes,
                                    capture_output=True, check=True)
            return result.stdout.decode("utf-8").strip()

        run("init", "-b", "main")
        run("config", "user.name", "Docs Test")
        run("config", "user.email", "docs-test@example.invalid")
        run("add", ".")
        run("commit", "-m", "base")
        return run

    def test_single_domain_needs_functional_document(self) -> None:
        source = "app/Services/Reports/Calls/CallMetricsAggregator.php"
        self.assertIn("Falta actualización funcional", " ".join(self.check([checker.Change("M", source)], updated())))
        self.assertEqual([], self.check([checker.Change("M", source), checker.Change("M", checker.DOCS["calls"])], updated(source)))

    def test_multiple_domains_require_each_document(self) -> None:
        changes = [checker.Change("M", "app/Services/Reports/Calls/CallMetricsAggregator.php"),
                   checker.Change("M", "app/Services/Campaigns/CampaignDashboardDatasetService.php"),
                   checker.Change("M", checker.DOCS["calls"])]
        self.assertIn(checker.DOCS["campaigns"], " ".join(self.check(changes, updated())))
        changes.append(checker.Change("M", checker.DOCS["campaigns"]))
        self.assertEqual([], self.check(changes, updated("app/Services/Reports/Calls/CallMetricsAggregator.php")))

    def test_shared_normalizer_requires_all_mapped_domains(self) -> None:
        path = "app/Services/Salesforce/SalesforceInterestFoundationResolver.php"
        self.assertEqual({"interests", "reservations", "calls", "campaigns"}, checker.domains_for(path))
        errors = self.check([checker.Change("M", path)], updated())
        self.assertEqual(4, sum("Falta actualización funcional" in item for item in errors))

    def test_economic_formula_and_month_configuration(self) -> None:
        for path in ("app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php",
                     "config/commercial_commissions.php"):
            with self.subTest(path=path):
                errors = self.check([checker.Change("M", path)], updated())
                self.assertIn(checker.DOCS["commissions"], " ".join(errors))

    def test_refactor_with_structured_no_impact(self) -> None:
        path = "app/Services/Reports/Calls/CallPortalNormalizer.php"
        self.assertEqual([], self.check([checker.Change("M", path)], no_impact("calls", path)))

    def test_sync_without_kpi_change_needs_specific_reason(self) -> None:
        path = "app/Services/Reports/Calls/SalesforceCallSyncService.php"
        self.assertEqual([], self.check([checker.Change("M", path)], no_impact("calls", path)))
        self.assertTrue(self.check([checker.Change("M", path)], "Docs-Impact: none\nDocs-Scope: calls\nDocs-Reason: sin impacto\nDocs-Evidence: " + path))

    def test_deletion_and_rename_fail_even_with_docs(self) -> None:
        for change in (checker.Change("D", "docs/informe-llamadas.md"),
                       checker.Change("R100", "docs/informe-llamadas-nuevo.md", "docs/informe-llamadas.md")):
            with self.subTest(change=change):
                errors = self.check([change], updated())
                self.assertTrue(any("prohibid" in item for item in errors))

    def test_document_only_and_tests_only(self) -> None:
        self.assertEqual([], self.check([checker.Change("M", checker.DOCS["calls"])], updated()))
        path = "tests/Feature/SalesforceCallSyncServiceTest.php"
        self.assertEqual([], self.check([checker.Change("M", path)], no_impact("tests-only", path)))

    def test_unknown_functional_path_fails_with_none(self) -> None:
        path = "app/Services/Reports/NewReport/NewKpiService.php"
        errors = self.check([checker.Change("A", path)], no_impact("outside-reports", path))
        self.assertIn("Ruta funcional sin mapa", " ".join(errors))

    def test_new_tab_in_existing_domain_needs_document(self) -> None:
        path = "resources/views/reports/commercial-commissions/partials/new-tab.blade.php"
        self.assertIn(checker.DOCS["commissions"], " ".join(self.check([checker.Change("A", path)], updated())))

    def test_missing_or_generic_contract(self) -> None:
        path = "app/Services/Campaigns/CampaignValueNormalizer.php"
        self.assertIn("Docs-Impact", " ".join(self.check([checker.Change("M", path)], "")))
        self.assertIn("Docs-Reason", " ".join(self.check([checker.Change("M", path)],
            f"Docs-Impact: none\nDocs-Scope: campaigns\nDocs-Reason: refactor\nDocs-Evidence: {path}")))

    def test_spaces_unicode_and_nul_git_format(self) -> None:
        raw = "M\0docs/un espacio ñ.md\0R100\0viejo/á b.php\0nuevo/á b.php\0".encode()
        parsed = checker.parse_name_status(raw)
        self.assertEqual("docs/un espacio ñ.md", parsed[0].path)
        self.assertEqual("viejo/á b.php", parsed[1].old_path)
        self.assertEqual("nuevo/á b.php", parsed[1].path)

    def test_evidence_path_with_spaces_and_comma(self) -> None:
        path = "app/Services/Reports/Calls/Call, versión 2.php"
        body = ("Docs-Impact: none\nDocs-Scope: calls\n"
                "Docs-Reason: La extracción conserva filtros, fechas pivote y payload; "
                "la comparación de regresión verifica los mismos resultados.\n"
                'Docs-Evidence: ["app/Services/Reports/Calls/Call, versión 2.php"]')
        self.assertEqual([], self.check([checker.Change("M", path)], body))

    def test_matrix_references_and_contract(self) -> None:
        self.assertEqual([], checker.validate_matrix(self.root))
        matrix = self.root / checker.MATRIX
        matrix.write_text(matrix.read_text(encoding="utf-8") + "\n[rota](inexistente.md)\n", encoding="utf-8")
        self.assertIn("referencia inexistente", " ".join(checker.validate_matrix(self.root)))

    def test_campaign_proposal_reference_is_required_when_linked(self) -> None:
        proposal = self.root / "docs/ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md"
        self.assertTrue(proposal.is_file())
        self.assertEqual([], checker.validate_matrix(self.root))
        proposal.unlink()
        self.assertIn("ai/CAMPAIGN_CURRENT_STATUS_PROPOSAL.md",
                      " ".join(checker.validate_matrix(self.root)))

    def test_complete_merge_base_diff_across_commits_and_stale_branch(self) -> None:
        def run(*args: str) -> str:
            result = subprocess.run(["git", *args], cwd=self.root, capture_output=True, text=True, check=True)
            return result.stdout.strip()

        run("init", "-b", "main")
        run("config", "user.name", "Docs Test")
        run("config", "user.email", "docs-test@example.invalid")
        source = self.root / "app/Services/Reports/Calls/CallMetricsAggregator.php"
        source.parent.mkdir(parents=True)
        source.write_text("base", encoding="utf-8")
        run("add", ".")
        run("commit", "-m", "base")
        base = run("rev-parse", "HEAD")
        run("switch", "-c", "feature")
        source.write_text("change", encoding="utf-8")
        run("add", ".")
        run("commit", "-m", "code")
        doc = self.root / checker.DOCS["calls"]
        doc.write_text("updated", encoding="utf-8")
        run("add", ".")
        run("commit", "-m", "docs")
        head = run("rev-parse", "HEAD")
        changes = checker.changes_between(base, head, self.root)
        self.assertEqual({source.relative_to(self.root).as_posix(), checker.DOCS["calls"]},
                         {change.path for change in changes})
        self.assertEqual([], self.check(changes, updated(source.relative_to(self.root).as_posix())))
        run("switch", "main")
        (self.root / "unrelated.txt").write_text("later", encoding="utf-8")
        run("add", ".")
        run("commit", "-m", "base moved")
        newer_base = run("rev-parse", "HEAD")
        self.assertEqual(changes, checker.changes_between(newer_base, head, self.root))

    def test_agent_toml_and_workflow_security(self) -> None:
        agent_path = ROOT / ".codex/agents/report_docs_maintainer.toml"
        workflow_path = ROOT / ".github/workflows/docs-integrity.yml"
        self.assertTrue(checker.local_regular_file(ROOT, agent_path))
        self.assertTrue(checker.local_regular_file(ROOT, workflow_path))
        agent = tomllib.loads(agent_path.read_text(encoding="utf-8"))
        self.assertEqual("report_docs_maintainer", agent["name"])
        self.assertTrue(agent["description"] and agent["developer_instructions"])
        self.assertNotIn("model", agent)
        workflow = workflow_path.read_text(encoding="utf-8")
        self.assertIn("contents: read", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("fetch-depth: 0", workflow)
        self.assertIn("  docs-integrity:", workflow)
        self.assertNotIn("pull_request_target", workflow)
        self.assertNotIn("workflow_run", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertIn("branches: [main]", workflow)
        self.assertIn("types: [opened, synchronize, reopened, edited, ready_for_review]", workflow)
        self.assertIn("actions/setup-python@", workflow)
        self.assertIn("python-version: '3.12'", workflow)
        self.assertLess(workflow.index("Verify validator entry points"),
                        workflow.index("Test documentation validator"))
        self.assertIn("git ls-files -s -z", workflow)

    def test_control_changes_require_guide_and_validator_tests(self) -> None:
        change = checker.Change("M", "scripts/check_documentation_integrity.py")
        errors = self.check([change], no_impact("outside-reports", change.path))
        self.assertIn("Docs-Impact: updated", " ".join(errors))
        self.assertIn(checker.GUIDE, " ".join(errors))
        self.assertIn(checker.VALIDATOR_TEST, " ".join(errors))
        self.assertEqual([], self.check([change, checker.Change("M", checker.GUIDE),
                                         checker.Change("M", checker.VALIDATOR_TEST)], updated()))

    def test_functional_doc_without_matrix_requires_explicit_decision(self) -> None:
        source = "app/Services/Reports/Calls/CallMetricsAggregator.php"
        changes = [checker.Change("M", source), checker.Change("M", checker.DOCS["calls"])]
        self.assertIn("Docs-Matrix-Impact", " ".join(self.check(changes, updated())))
        self.assertIn(checker.MATRIX, " ".join(self.check(changes, "Docs-Impact: updated\nDocs-Matrix-Impact: updated")))
        self.assertEqual([], self.check(changes + [checker.Change("M", checker.MATRIX)],
                                        "Docs-Impact: updated\nDocs-Matrix-Impact: updated"))

    def test_matrix_unchanged_needs_concrete_reason_and_changed_evidence(self) -> None:
        source = "app/Services/Reports/Calls/CallMetricsAggregator.php"
        changes = [checker.Change("M", source), checker.Change("M", checker.DOCS["calls"])]
        body = "Docs-Impact: updated\nDocs-Matrix-Impact: unchanged"
        self.assertIn("Docs-Matrix-Reason", " ".join(self.check(changes, body)))
        self.assertIn("Docs-Matrix-Evidence", " ".join(self.check(changes, body)))
        self.assertIn("Docs-Matrix-Evidence", " ".join(self.check(changes, updated("no-existe.php"))))
        self.assertEqual([], self.check(changes, updated(source)))

    def test_keyword_free_executable_path_requires_mapping_or_explicit_exception(self) -> None:
        source = "app/Services/Operations/ThresholdCalculator.php"
        changes = [checker.Change("A", source)]
        self.assertIn("Ruta funcional sin mapa", " ".join(self.check(changes, no_impact("outside-reports", source))))
        exception = (no_impact("outside-reports", source)
                     + f"\nDocs-Outside-Paths: [\"{source}\"]"
                     + "\nDocs-Outside-Reason: Esta clase pertenece al monitor operativo; "
                       "solo calcula sus umbrales y no suministra datos a ninguno de los seis informes."
                     + f"\nDocs-Outside-Evidence: {source}")
        self.assertEqual([], self.check(changes, exception))
        malformed = exception.replace(f'Docs-Outside-Paths: ["{source}"]',
                                      'Docs-Outside-Paths: ["app/Other.php"]')
        self.assertIn("Docs-Outside-Paths", " ".join(self.check(changes, malformed)))

    def test_financial_service_requires_specialized_document(self) -> None:
        source = "app/Services/Reports/FinancialCommissions/FinancialCommissionDashboardService.php"
        changes = [checker.Change("M", source), checker.Change("M", checker.DOCS["commissions"])]
        errors = self.check(changes, updated(source))
        self.assertIn("docs/informe-comisiones-financieras.md", " ".join(errors))
        self.assertEqual([], self.check(changes + [checker.Change("M", "docs/informe-comisiones-financieras.md")], updated(source)))

    def test_commercial_formula_requires_calculation_document_or_exception(self) -> None:
        source = "app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php"
        changes = [checker.Change("M", source), checker.Change("M", checker.DOCS["commissions"])]
        self.assertIn("docs/Calculo_comisiones_comerciales.txt", " ".join(self.check(changes, updated(source))))
        body = (updated(source) + "\nDocs-Commercial-Formula-Impact: unchanged\n"
                "Docs-Commercial-Formula-Reason: Solo se corrige el texto de ayuda de la configuración; "
                "los parámetros económicos y el cálculo final mantienen sus valores.\n"
                f"Docs-Commercial-Formula-Evidence: {source}")
        self.assertEqual([], self.check(changes, body))

    def test_shared_formula_configuration_requires_both_specialized_documents(self) -> None:
        source = "app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php"
        changes = [checker.Change("M", source), checker.Change("M", checker.DOCS["commissions"])]
        errors = " ".join(self.check(changes, updated(source)))
        self.assertIn(checker.FINANCIAL_DOC, errors)
        self.assertIn(checker.COMMERCIAL_CALC_DOC, errors)

    def test_financial_exception_needs_reason_and_evidence(self) -> None:
        source = "app/Services/Reports/FinancialCommissions/FinancialCommissionDashboardService.php"
        changes = [checker.Change("M", source), checker.Change("M", checker.DOCS["commissions"])]
        body = updated(source) + "\nDocs-Financial-Impact: unchanged"
        self.assertIn("Docs-Financial-Reason", " ".join(self.check(changes, body)))
        body += ("\nDocs-Financial-Reason: Se reorganiza la presentación interna; "
                 "los importes y la selección de responsables financieros conservan el mismo resultado.\n"
                 f"Docs-Financial-Evidence: {source}")
        self.assertEqual([], self.check(changes, body))
        self.assertEqual([], self.check([checker.Change("M", source)], no_impact("commissions", source)))

    def test_unmapped_laravel_paths_without_keywords_are_detected(self) -> None:
        for source in ("app/Jobs/NormalizeInput.php", "config/thresholds.php",
                       "routes/console.php", "database/seeders/DefaultValues.php",
                       "resources/js/widgets/table.ts", "resources/views/widgets/panel.blade.php"):
            with self.subTest(source=source):
                self.assertTrue(checker.unknown_functional(source))

    def test_control_files_are_reviewed_in_pairs(self) -> None:
        for path in (".github/workflows/docs-integrity.yml",
                     "scripts/check_documentation_integrity.py",
                     checker.VALIDATOR_TEST, checker.GUIDE, "AGENTS.md"):
            with self.subTest(path=path):
                errors = self.check([checker.Change("M", path)], updated())
                self.assertIn("control", " ".join(errors).lower())
                combined = {path, checker.GUIDE, checker.VALIDATOR_TEST}
                self.assertEqual([], self.check([checker.Change("M", item) for item in combined], updated()))
        extra_test = "tests/documentation/test_new_policy.py"
        self.assertIn(checker.GUIDE, " ".join(self.check([checker.Change("A", extra_test)], updated())))
        self.assertEqual([], self.check([checker.Change("A", extra_test), checker.Change("M", checker.GUIDE)], updated()))

    def test_unmapped_deleted_and_renamed_paths_remain_rejected(self) -> None:
        for change in (checker.Change("D", "app/Services/Operations/ThresholdCalculator.php"),
                       checker.Change("R100", "app/Services/Operations/ThresholdCalculator.php",
                                      "app/Services/Operations/OldCalculator.php")):
            with self.subTest(change=change):
                self.assertIn("prohibid", " ".join(self.check([change], updated())))

    def test_type_change_rejected_for_document_code_and_control(self) -> None:
        for path in (checker.DOCS["calls"], "app/Services/Reports/Calls/CallPortalNormalizer.php",
                     ".github/workflows/docs-integrity.yml"):
            with self.subTest(path=path):
                self.assertIn(path, " ".join(self.check([checker.Change("T", path)], updated())))
                self.assertIn("tipo", " ".join(self.check([checker.Change("T", path)], updated())).lower())

    def test_real_git_type_change_to_symlink_fails(self) -> None:
        run = self.git_fixture()
        base = run("rev-parse", "HEAD")
        target = checker.DOCS["calls"]
        blob = run("hash-object", "-w", "--stdin", input_bytes=b"../../outside.txt")
        run("update-index", "--cacheinfo", f"120000,{blob},{target}")
        run("commit", "-m", "replace regular document with symlink")
        head = run("rev-parse", "HEAD")
        changes = checker.changes_between(base, head, self.root)
        self.assertEqual(["T"], [change.status for change in changes])
        self.assertIn("Cambio de tipo", " ".join(self.check(changes, updated())))

    def test_real_git_new_symlink_fails_without_following_target(self) -> None:
        run = self.git_fixture()
        base = run("rev-parse", "HEAD")
        target = "docs/nueva-referencia.md"
        blob = run("hash-object", "-w", "--stdin", input_bytes=b"../../outside.txt")
        run("update-index", "--add", "--cacheinfo", f"120000,{blob},{target}")
        run("commit", "-m", "add symlink")
        head = run("rev-parse", "HEAD")
        changes = checker.changes_between(base, head, self.root)
        self.assertEqual(["A"], [change.status for change in changes])
        self.assertIn("enlace simbólico", " ".join(self.check(changes, updated())).lower())

    def test_real_git_modified_symlink_fails(self) -> None:
        run = self.git_fixture()
        target = "docs/referencia-existente.md"
        first = run("hash-object", "-w", "--stdin", input_bytes=b"../first.txt")
        run("update-index", "--add", "--cacheinfo", f"120000,{first},{target}")
        run("commit", "-m", "existing symlink")
        base = run("rev-parse", "HEAD")
        second = run("hash-object", "-w", "--stdin", input_bytes=b"../../second.txt")
        run("update-index", "--cacheinfo", f"120000,{second},{target}")
        run("commit", "-m", "change symlink target")
        head = run("rev-parse", "HEAD")
        changes = checker.changes_between(base, head, self.root)
        self.assertEqual(["M"], [change.status for change in changes])
        self.assertIn("enlace simbólico", " ".join(self.check(changes, updated())).lower())

    def test_matrix_reference_does_not_follow_symlink_outside_repo(self) -> None:
        outside = tempfile.TemporaryDirectory()
        self.addCleanup(outside.cleanup)
        target = Path(outside.name) / "outside.md"
        target.write_text("outside", encoding="utf-8")
        link = self.root / "docs/external-link.md"
        try:
            os.symlink(target, link)
        except OSError as exc:
            self.skipTest(f"El sistema no permite crear symlinks en la prueba: {exc}")
        matrix = self.root / checker.MATRIX
        matrix.write_text(matrix.read_text(encoding="utf-8") + "\n[fuera](external-link.md)\n", encoding="utf-8")
        self.assertFalse(checker.local_regular_file(self.root, link))
        self.assertIn("referencia inexistente", " ".join(checker.validate_matrix(self.root)))

    def test_gitignore_and_main_ci_require_control_review(self) -> None:
        for path in (".gitignore", ".github/workflows/ci.yml"):
            with self.subTest(path=path):
                errors = " ".join(self.check([checker.Change("M", path)], no_impact("outside-reports", path)))
                self.assertIn("Docs-Impact: updated", errors)
                self.assertIn(checker.GUIDE, errors)
                self.assertIn(checker.VALIDATOR_TEST, errors)
                self.assertEqual([], self.check([checker.Change("M", path), checker.Change("M", checker.GUIDE),
                                                 checker.Change("M", checker.VALIDATOR_TEST)], updated()))

    def test_unexpected_git_status_fails_closed(self) -> None:
        errors = self.check([checker.Change("U", checker.DOCS["calls"])], updated())
        self.assertIn("Estado Git", " ".join(errors))

    def test_initial_documentation_infrastructure_pr_remains_valid(self) -> None:
        paths = {".gitignore", "AGENTS.md", ".codex/agents/report_docs_maintainer.toml",
                 ".github/workflows/docs-integrity.yml", checker.GUIDE, checker.MATRIX,
                 "scripts/check_documentation_integrity.py", checker.VALIDATOR_TEST,
                 "docs/ai/HANDOFF.md"}
        self.assertEqual([], self.check([checker.Change("A", path) for path in paths], updated()))


if __name__ == "__main__":
    unittest.main()
