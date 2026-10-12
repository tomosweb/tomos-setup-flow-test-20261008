import importlib.util
import unittest
from pathlib import Path

script = Path(__file__).resolve().parents[1] / "scripts/check_tomos_core_update.py"
spec = importlib.util.spec_from_file_location("check_tomos_core_update", script)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

OLD = "1" * 40
NEW = "2" * 40


class EligibilityTest(unittest.TestCase):
    def test_same_version(self):
        self.assertEqual(module.evaluate(NEW, {"schema": 1, "core_sha": NEW, "compatible_from": []})["status"], "current")

    def test_approved_upgrade(self):
        self.assertEqual(module.evaluate(OLD, {"schema": 1, "core_sha": NEW, "compatible_from": [OLD]})["status"], "update_available")

    def test_unknown_upgrade_rejected(self):
        self.assertEqual(module.evaluate(OLD, {"schema": 1, "core_sha": NEW, "compatible_from": []})["status"], "requires_review")

    def test_invalid_sha_rejected(self):
        self.assertEqual(module.evaluate("main", {"schema": 1, "core_sha": NEW, "compatible_from": []})["status"], "invalid_current")

    def test_invalid_manifest_rejected(self):
        self.assertEqual(module.evaluate(OLD, {"schema": 1, "core_sha": "latest", "compatible_from": [OLD]})["status"], "invalid_manifest")


if __name__ == "__main__":
    unittest.main()
