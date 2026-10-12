import json
import sys
import unittest
from pathlib import Path

root = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(root / "scripts"))
from plan_workflow_update import git_blob_sha, plan


class WorkflowBaselineTest(unittest.TestCase):
    def test_real_legacy_workflow_matches_registered_baseline(self):
        workflow = (root / ".github/workflows/github-pages.yml").read_text(encoding="utf-8")
        manifest = json.loads((root / "docs/experiments/stable-core-manifest.json").read_text(encoding="utf-8"))
        expected = manifest["workflow_sources"][0]["blob_sha"]
        self.assertEqual(git_blob_sha(workflow), expected)
        self.assertEqual(plan(workflow, expected, manifest), {"status": "current", "update": False})


if __name__ == "__main__":
    unittest.main()
