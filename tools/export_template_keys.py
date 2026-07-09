#!/usr/bin/env python3
import argparse
import json
import os
import ssl
import urllib.request


def call(api_url, token, method, params, rpc_id):
    data = json.dumps({
        "jsonrpc": "2.0",
        "method": method,
        "params": params,
        "id": rpc_id,
    }).encode()
    request = urllib.request.Request(
        api_url,
        data=data,
        headers={
            "Content-Type": "application/json-rpc",
            "Authorization": f"Bearer {token}",
        },
        method="POST",
    )
    context = ssl._create_unverified_context()
    with urllib.request.urlopen(request, timeout=60, context=context) as response:
        decoded = json.loads(response.read().decode())
    if "error" in decoded:
        raise RuntimeError(f"{method}: {decoded['error']}")
    return decoded.get("result", [])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-url", required=True)
    parser.add_argument("--template", action="append", default=[])
    parser.add_argument("--templateid", action="append", default=[])
    parser.add_argument("--out")
    args = parser.parse_args()

    token = os.environ.get("ZBX_TOKEN", "")
    if not token:
        raise SystemExit("ZBX_TOKEN is required")

    rpc_id = 1

    def rpc(method, params=None):
        nonlocal rpc_id
        result = call(args.api_url, token, method, params or {}, rpc_id)
        rpc_id += 1
        return result

    resolved = {}
    for templateid in args.templateid:
        rows = rpc("template.get", {
            "output": ["templateid", "host", "name"],
            "templateids": [templateid],
            "limit": 1,
        })
        for row in rows:
            resolved[row["templateid"]] = row

    for name in args.template:
        rows = rpc("template.get", {
            "output": ["templateid", "host", "name"],
            "filter": {"host": [name]},
            "limit": 1,
        })
        if not rows:
            rows = rpc("template.get", {
                "output": ["templateid", "host", "name"],
                "filter": {"name": [name]},
                "limit": 1,
            })
        if not rows:
            rows = rpc("template.get", {
                "output": ["templateid", "host", "name"],
                "search": {"host": name, "name": name},
                "searchByAny": True,
                "limit": 10,
            })
        for row in rows:
            resolved[row["templateid"]] = row

    templates = []
    for templateid, template in sorted(resolved.items(), key=lambda pair: pair[1]["name"]):
        items = rpc("item.get", {
            "output": ["itemid", "hostid", "name", "key_", "type", "value_type", "status"],
            "hostids": [templateid],
            "sortfield": "key_",
        })
        templates.append({
            "template": template,
            "item_count": len(items),
            "keys": [{
                "name": item["name"],
                "key": item["key_"],
                "value_type": item["value_type"],
                "status": item["status"],
            } for item in items],
        })

    payload = {
        "generated_at": __import__("datetime").datetime.utcnow().isoformat() + "Z",
        "templates": templates,
    }
    text = json.dumps(payload, ensure_ascii=False, indent=2)
    if args.out:
        with open(args.out, "w", encoding="utf-8") as file:
            file.write(text)
    print(text)


if __name__ == "__main__":
    main()
