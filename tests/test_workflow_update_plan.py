import importlib.util
import sys
import unittest
from pathlib import Path

scripts = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(scripts))
path = scripts / "plan_workflow_update.py"
spec = importlib.util.spec_from_file_location("plan_workflow_update", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
OLD, NEW = "1" * 40, "2" * 40
WORKFLOW = "name: Tomos GitHub Pages\non:\n  push:\n  TOMOS_VERSION: " + OLD + "\n  TOMOS_REPOSITORY: https://github.com/tomosweb/tomos.git\n"
BLOB = module.git_blob_sha(WORKFLOW)


def manifest(core=NEW, from_=(OLD,), blob=BLOB, recorded_core=OLD):
    return {"schema": 1, "core_sha": core, "compatible_from": list(from_), "workflow_sources": [{"blob_sha": blob, "core_sha": recorded_core}]}


class WorkflowUpdatePlanTest(unittest.TestCase):
    def test_approved_exact_workflow_updates_only_version(self):
        result = module.plan(WORKFLOW, BLOB, manifest())
        self.assertEqual(result["status"], "update_available")
        self.assertEqual(result["path"], ".github/workflows/github-pages.yml")
        self.assertEqual(result["expected_blob_sha"], BLOB)
        self.assertEqual(result["proposed_content"], WORKFLOW.replace(OLD, NEW))
        self.assertEqual(result["proposed_blob_sha"], module.git_blob_sha(result["proposed_content"]))

    def test_idempotent_same_version(self):
        self.assertEqual(module.plan(WORKFLOW, BLOB, manifest(core=OLD, from_=()))["status"], "current")

    def test_unknown_compatibility_not_updated(self):
        self.assertEqual(module.plan(WORKFLOW, BLOB, manifest(from_=()))["status"], "requires_review")

    def test_unrecognized_customized_workflow_not_updated(self):
        edited = WORKFLOW + "# customized\n"
        self.assertEqual(module.plan(edited, module.git_blob_sha(edited), manifest())["status"], "requires_review")

    def test_race_stale_blob_not_updated(self):
        self.assertEqual(module.plan(WORKFLOW, "0" * 40, manifest())["status"], "stale_read")

    def test_malformed_duplicate_pin_blocked(self):
        duplicate = WORKFLOW + "  TOMOS_VERSION: " + OLD + "\n"
        self.assertEqual(module.plan(duplicate, module.git_blob_sha(duplicate), manifest(blob=module.git_blob_sha(duplicate)))["status"], "requires_review")

    def test_manifest_blob_core_mismatch_blocked(self):
        self.assertEqual(module.plan(WORKFLOW, BLOB, manifest(recorded_core=NEW))["status"], "requires_review")

    def test_invalid_manifest_rejected(self):
        self.assertEqual(module.plan(WORKFLOW, BLOB, manifest(blob="main"))["status"], "invalid_manifest")
        self.assertEqual(module.plan(WORKFLOW, BLOB, {"schema": 1, "core_sha": NEW, "compatible_from": [OLD], "workflow_sources": []})["status"], "invalid_manifest")

    def test_repeated_source_rejected(self):
        m = manifest()
        m["workflow_sources"].append(m["workflow_sources"][0].copy())
        self.assertEqual(module.plan(WORKFLOW, BLOB, m)["status"], "invalid_manifest")

    def test_sha_is_standard_git_blob_id(self):
        self.assertEqual(module.git_blob_sha("test content\n"), "d670460b4b4aece5915caf5c68d12f560a9fe3e4")


if __name__ == "__main__":
    unittest.main()
