import importlib.util
import unittest
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "scripts/site_update_presentation.py"
spec = importlib.util.spec_from_file_location("site_update_presentation", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PresentationTest(unittest.TestCase):
    def test_approved_update_visible(self):
        self.assertEqual(module.presentation({"status": "update_available", "update": True}), {"show_update": True, "label": "サイトを更新"})

    def test_other_states_hidden(self):
        for status in ("current", "requires_review", "invalid_current", "invalid_manifest", "error", None):
            with self.subTest(status=status):
                self.assertEqual(module.presentation({"status": status, "update": False}), {"show_update": False})

    def test_fail_closed(self):
        self.assertEqual(module.presentation({"status": "update_available", "update": False}), {"show_update": False})
        self.assertEqual(module.presentation(None), {"show_update": False})


if __name__ == "__main__":
    unittest.main()
