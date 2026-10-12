#!/usr/bin/env python3
"""Offline, fail-closed planner for the legacy pinned Core workflow.

This deliberately performs NO GitHub writes and cannot upgrade a site's workflow
structure. Callers must fetch an authenticated *developer-controlled* release
manifest, and must independently check the GitHub App workflow-write permission.
The returned expected_blob_sha must be used for optimistic concurrency control.
"""
import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

from check_tomos_core_update import evaluate

SHA = re.compile(r"^[0-9a-f]{40}$")
# Only this exact top-level YAML env field may be modified. No YAML parsing or
# formatting changes, to preserve every other byte of the user's workflow.
PIN = re.compile(r"^  TOMOS_VERSION: ([0-9a-f]{40})[ \t]*$", re.MULTILINE)


def git_blob_sha(content):
    if not isinstance(content, str):
        raise TypeError("expected UTF-8 text")
    raw = content.encode("utf-8")
    return hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()


def plan(workflow_text, observed_blob_sha, release):
    """Return status and (only for approved updates) a proposed full file.

    release: trusted, immutable manifest delivered by the Tomos developer.
    The manifest must not be loaded from arbitrary user repository content.
    This function is strictly a read-only planner, not an authorizer or writer.
    """
    if not isinstance(workflow_text, str) or not isinstance(observed_blob_sha, str) or not SHA.fullmatch(observed_blob_sha):
        return {"status": "invalid_input", "update": False}
    if git_blob_sha(workflow_text) != observed_blob_sha:
        return {"status": "stale_read", "update": False}
    if not isinstance(release, dict) or release.get("schema") != 1:
        return {"status": "invalid_manifest", "update": False}
    sources = release.get("workflow_sources")
    if not isinstance(sources, list) or not sources or len(sources) > 128:
        return {"status": "invalid_manifest", "update": False}
    known_sources = {}
    for item in sources:
        if not isinstance(item, dict) or set(item) != {"blob_sha", "core_sha"}:
            return {"status": "invalid_manifest", "update": False}
        blob_sha, core_sha = item["blob_sha"], item["core_sha"]
        if not isinstance(blob_sha, str) or not SHA.fullmatch(blob_sha) or not isinstance(core_sha, str) or not SHA.fullmatch(core_sha):
            return {"status": "invalid_manifest", "update": False}
        if blob_sha in known_sources:
            return {"status": "invalid_manifest", "update": False}
        known_sources[blob_sha] = core_sha

    # Unknown or user-modified workflows MUST NOT be patched automatically.
    current_core = known_sources.get(observed_blob_sha)
    if current_core is None:
        return {"status": "requires_review", "update": False}
    matches = list(PIN.finditer(workflow_text))
    if len(matches) != 1 or matches[0].group(1) != current_core:
        return {"status": "requires_review", "update": False}
    result = evaluate(current_core, release)
    if result.get("status") != "update_available":
        return result
    target = result["target"]
    start, end = matches[0].span(1)
    proposed = workflow_text[:start] + target + workflow_text[end:]
    return {
        "status": "update_available",
        "update": True,
        "path": ".github/workflows/github-pages.yml",
        "expected_blob_sha": observed_blob_sha,
        "proposed_blob_sha": git_blob_sha(proposed),
        "proposed_content": proposed,
        "from_core": current_core,
        "to_core": target,
    }


def main():
    parser = argparse.ArgumentParser(description="Dry-run only: no GitHub mutation")
    parser.add_argument("--workflow", type=Path, required=True)
    parser.add_argument("--expected-blob-sha", required=True)
    parser.add_argument("--release", type=Path, required=True)
    args = parser.parse_args()
    try:
        workflow = args.workflow.read_text(encoding="utf-8")
        release = json.loads(args.release.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        print(json.dumps({"status": "invalid_input", "message": str(exc)}))
        return 2
    result = plan(workflow, args.expected_blob_sha, release)
    # Never print a potentially sensitive entire workflow into diagnostics.
    safe_result = {key: value for key, value in result.items() if key != "proposed_content"}
    print(json.dumps(safe_result, ensure_ascii=False))
    return 0 if result.get("status") in ("current", "update_available", "requires_review") else 2


if __name__ == "__main__":
    sys.exit(main())
