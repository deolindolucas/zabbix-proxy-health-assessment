#!/usr/bin/env python3
import json
import os
import re
import sys
from copy import deepcopy

import requests


API_URL = os.getenv("ZABBIX_API_URL", "https://webmonitor.com.br/api_jsonrpc.php")
TOKEN = os.getenv("ZABBIX_TOKEN", "")
TEMPLATE_ID = os.getenv("ZABBIX_TEMPLATE_ID", "88293")
DRY_RUN = os.getenv("DRY_RUN", "0") in {"1", "true", "yes", "sim"}

DISCARD_UNCHANGED_HEARTBEAT = "20"
HEARTBEAT_24H = "24h"


def call(method, params):
    call.counter += 1
    response = requests.post(
        API_URL,
        headers={
            "Authorization": f"Bearer {TOKEN}",
            "Content-Type": "application/json-rpc",
        },
        data=json.dumps({
            "jsonrpc": "2.0",
            "method": method,
            "params": params,
            "id": call.counter,
        }),
        timeout=120,
    )
    response.raise_for_status()
    body = response.json()
    if "error" in body:
        raise RuntimeError(f"{method}: {body['error']}")
    return body["result"]


call.counter = 0


def is_recommendation(item):
    name = (item.get("name") or "").lower()
    key = (item.get("key_") or "").lower()
    return (
        "recomend" in name
        or "recomend" in key
        or "recommend" in name
        or "recommend" in key
    )


def normalize_preprocessing(preprocessing):
    steps = []
    for step in preprocessing or []:
        copy = {
            "type": str(step.get("type", "")),
            "params": step.get("params", ""),
            "error_handler": str(step.get("error_handler", "0")),
            "error_handler_params": step.get("error_handler_params", ""),
        }
        steps.append(copy)
    return steps


def with_heartbeat(preprocessing):
    steps = normalize_preprocessing(preprocessing)
    filtered = [
        step for step in steps
        if not (
            str(step.get("type")) == DISCARD_UNCHANGED_HEARTBEAT
            and step.get("params") == HEARTBEAT_24H
        )
    ]
    filtered.append({
        "type": DISCARD_UNCHANGED_HEARTBEAT,
        "params": HEARTBEAT_24H,
        "error_handler": "0",
        "error_handler_params": "",
    })
    return filtered


def main():
    if not TOKEN:
        raise SystemExit("Missing ZABBIX_TOKEN")

    items = call("item.get", {
        "output": ["itemid", "name", "key_", "history", "trends", "type", "value_type", "status"],
        "templateids": [TEMPLATE_ID],
        "selectPreprocessing": ["type", "params", "error_handler", "error_handler_params", "sortorder"],
        "sortfield": "name",
    })

    updates = []
    before = {
        "template_id": TEMPLATE_ID,
        "items_total": len(items),
        "history_90d": 0,
        "recommendation": 0,
        "updates": [],
    }

    for item in items:
        recommendation = is_recommendation(item)
        has_90d = str(item.get("history", "")).strip() == "90d"
        if has_90d:
            before["history_90d"] += 1
        if recommendation:
            before["recommendation"] += 1

        should_update = has_90d or recommendation
        if not should_update:
            continue

        update = {
            "itemid": item["itemid"],
            "history": "30d",
            "preprocessing": with_heartbeat(item.get("preprocessing", [])),
        }
        if recommendation:
            update["trends"] = "0"

        current_pre = normalize_preprocessing(item.get("preprocessing", []))
        changed = (
            str(item.get("history", "")) != update["history"]
            or normalize_preprocessing(item.get("preprocessing", [])) != update["preprocessing"]
            or (recommendation and str(item.get("trends", "")) != "0")
        )
        if changed:
            updates.append(update)
            before["updates"].append({
                "itemid": item["itemid"],
                "name": item.get("name"),
                "key": item.get("key_"),
                "history_before": item.get("history"),
                "history_after": update["history"],
                "trends_before": item.get("trends"),
                "trends_after": update.get("trends", item.get("trends")),
                "recommendation": recommendation,
                "preprocessing_before": len(current_pre),
                "preprocessing_after": len(update["preprocessing"]),
            })

    print(json.dumps(before, indent=2, ensure_ascii=False))

    if DRY_RUN:
        print("dry_run=true")
        return

    for update in updates:
        call("item.update", update)

    after_items = call("item.get", {
        "output": ["itemid", "name", "key_", "history", "trends"],
        "templateids": [TEMPLATE_ID],
        "selectPreprocessing": ["type", "params", "sortorder"],
        "sortfield": "name",
    })
    after_summary = {
        "updated": len(updates),
        "remaining_history_90d_in_touched_scope": [],
        "recommendation_with_trends": [],
        "missing_heartbeat_in_touched_scope": [],
    }
    touched = {update["itemid"] for update in updates}
    for item in after_items:
        recommendation = is_recommendation(item)
        in_scope = item["itemid"] in touched or recommendation or str(item.get("history")) == "90d"
        pre = normalize_preprocessing(item.get("preprocessing", []))
        has_heartbeat = bool(pre and pre[-1]["type"] == DISCARD_UNCHANGED_HEARTBEAT and pre[-1]["params"] == HEARTBEAT_24H)
        if in_scope and str(item.get("history")) == "90d":
            after_summary["remaining_history_90d_in_touched_scope"].append(item["key_"])
        if recommendation and str(item.get("trends")) != "0":
            after_summary["recommendation_with_trends"].append(item["key_"])
        if in_scope and not has_heartbeat:
            after_summary["missing_heartbeat_in_touched_scope"].append(item["key_"])
    print(json.dumps(after_summary, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
