"""Map internal Core update eligibility to user-facing UI state.

This module does not authorize or perform repository writes.
"""

def presentation(result):
    status = result.get("status") if isinstance(result, dict) else None
    if status == "update_available" and result.get("update") is True:
        return {"show_update": True, "label": "サイトを更新"}
    return {"show_update": False}
