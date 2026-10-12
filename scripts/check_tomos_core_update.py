#!/usr/bin/env python3
"""Offline validation of a proposed Tomos GitHub Core version update.

No GitHub API calls, writes or deployment. Run:
python3 scripts/check_tomos_core_update.py --current <40-hex-sha> --manifest docs/experiments/stable-core-manifest.json
"""
import argparse
import json
import re
import sys
from pathlib import Path

SHA = re.compile(r"^[0-9a-f]{40}$")
MANAGED_WORKFLOW = ".github/workflows/github-pages.yml"


def evaluate(current, manifest):
    if not isinstance(current, str) or not SHA.fullmatch(current):
        return {"status": "invalid_current", "update": False}
    if not isinstance(manifest, dict) or manifest.get("schema") != 1:
        return {"status": "invalid_manifest", "update": False}
    target = manifest.get("core_sha")
    compatible = manifest.get("compatible_from")
    if not isinstance(target, str) or not SHA.fullmatch(target):
        return {"status": "invalid_manifest", "update": False}
    if not isinstance(compatible, list) or any(not isinstance(s, str) or not SHA.fullmatch(s) for s in compatible):
        return {"status": "invalid_manifest", "update": False}
    if current == target:
        return {"status": "current", "update": False}
    if current not in compatible:
        return {"status": "requires_review", "update": False}
    return {"status": "update_available", "update": True, "target": target}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--current", required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    args = parser.parse_args()
    try:
        manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        print(json.dumps({"status": "invalid_manifest", "error": str(exc)}))
        return 2
    result = evaluate(args.current, manifest)
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["status"] in ("current", "update_available", "requires_review") else 2


if __name__ == "__main__":
    sys.exit(main())
