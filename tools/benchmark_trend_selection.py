#!/usr/bin/env python3
import argparse
import json
import math
import os
import re
import time
from collections import Counter, defaultdict

import requests


IMPORTANT_KEYS = [
    "agent.ping", "system.cpu.load[all,avg1]", "system.cpu.num", "system.cpu.util",
    "system.uptime", "vm.memory.size[total]", "vm.memory.size[pavailable]", "vm.memory.size[pused]",
    "vm.memory.utilization", "vfs.fs.size[/,pused]", "vfs.fs.size[/,pfree]",
    "proc.num[zabbix_proxy]", "zabbix[uptime]",
    "zabbix[version]", "zabbix[hosts]", "zabbix[items]", "zabbix[items_unsupported]",
    "zabbix[requiredperformance]", "zabbix[preprocessing_queue]", "zabbix[queue,10m]",
    "zabbix[proxy,{HOST.HOST}, lastaccess]", "zabbix[proxy_buffer,state,current]",
    "zabbix[proxy_buffer,state,changes]", "zabbix[proxy_buffer,buffer,pused]",
    "zabbix[rcache,buffer,pfree]", "zabbix[rcache,buffer,pused]",
    "zabbix[wcache,history,pfree]", "zabbix[wcache,history,pused]",
    "zabbix[wcache,index,pused]", "zabbix[wcache,trend,pused]",
    "zabbix[vcache,buffer,pused]", "zabbix[vmware,buffer,pused]", "zabbix[proxy_history]",
    "zabbix[queue]", "zabbix[discovery_queue]", "zabbix[wcache,values]",
    "zabbix[wcache,values,float]", "zabbix[wcache,values,uint]",
    "zabbix[wcache,values,str]", "zabbix[wcache,values,text]",
    "zabbix[wcache,values,log]", "zabbix[wcache,values,not supported]",
]

OLD_TREND_KEYS = [
    "system.cpu.util",
    "vm.memory.size[pused]",
    "vm.memory.utilization",
    "vfs.fs.size[/,pused]",
    "vfs.fs.size[/,pfree]",
    "zabbix[proxy_buffer,buffer,pused]",
    "zabbix[rcache,buffer,pfree]",
    "zabbix[rcache,buffer,pused]",
    "zabbix[wcache,history,pfree]",
    "zabbix[wcache,history,pused]",
    "zabbix[wcache,index,pused]",
    "zabbix[wcache,trend,pused]",
    "zabbix[vcache,buffer,pused]",
    "zabbix[vmware,buffer,pused]",
]

NEW_TREND_KEYS = [
    key for key in OLD_TREND_KEYS
    if key != "vfs.fs.size[/,pfree]"
]

PROCESS_PREFIX = "zabbix[process,"
PROCESS_CONFIG_MAP = {
    "agent poller": "num.StartAgentPollers",
    "browser poller": "num.StartBrowserPollers",
    "discoverer": "num.StartDiscoverers",
    "discovery worker": "num.StartDiscoverers",
    "history syncer": "num.StartDBSyncers",
    "http agent poller": "num.StartHTTPAgentPollers",
    "http poller": "num.StartHTTPPollers",
    "icmp pinger": "num.StartPingers",
    "ipmi poller": "num.StartIPMIPollers",
    "java poller": "num.StartJavaPollers",
    "odbc poller": "num.StartODBCPollers",
    "poller": "num.poller",
    "preprocessing worker": "num.StartPreprocessors",
    "snmp poller": "num.StartSNMPPollers",
    "snmp trapper": "num.StartSNMPTrapper",
    "trapper": "num.StartTrappers",
    "unreachable poller": "num.StartPollersUnreachable",
    "vmware collector": "num.StartVMwareCollectors",
}
NON_CONFIGURABLE_PROCESSES = {
    "availability manager", "configuration syncer", "data sender", "discovery manager",
    "heartbeat sender", "housekeeper", "internal poller", "ipmi manager",
    "preprocessing manager", "self-monitoring", "task manager",
}


def api_url(value):
    value = value.rstrip("/")
    if value.endswith("/api_jsonrpc.php"):
        return value
    return f"{value}/api_jsonrpc.php"


class Zabbix:
    def __init__(self, url, token, timeout):
        self.url = api_url(url)
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json-rpc",
        })
        self.timeout = timeout
        self.req_id = 0

    def call(self, method, params):
        self.req_id += 1
        payload = {"jsonrpc": "2.0", "method": method, "params": params, "id": self.req_id}
        start = time.perf_counter()
        response = self.session.post(self.url, data=json.dumps(payload), timeout=self.timeout)
        elapsed = time.perf_counter() - start
        response.raise_for_status()
        body = response.json()
        if "error" in body:
            raise RuntimeError(f"{method}: {body['error']}")
        return body["result"], elapsed, len(response.content)


def chunks(values, size):
    for index in range(0, len(values), size):
        yield values[index:index + size]


def process_name(key):
    match = re.match(r"^zabbix\[process,([^,]+),", key or "")
    return match.group(1) if match else key


def num(value):
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def dedupe(items):
    by_id = {}
    for item in items:
        key = str(item.get("itemid") or f"{item.get('hostid')}|{item.get('key_')}")
        by_id[key] = item
    return list(by_id.values())


def collect_hosts(api, host_group, server_host):
    groups, elapsed_group, bytes_group = api.call("hostgroup.get", {
        "output": ["groupid", "name"],
        "filter": {"name": [host_group]},
    })
    if not groups:
        raise RuntimeError(f"Host group not found: {host_group}")

    hosts, elapsed_hosts, bytes_hosts = api.call("host.get", {
        "output": ["hostid", "host", "name", "status", "available", "proxy_hostid"],
        "groupids": groups[0]["groupid"],
        "sortfield": "name",
    })

    by_id = {host["hostid"]: host for host in hosts if host.get("status") == "0"}
    elapsed_server = 0.0
    bytes_server = 0
    if server_host:
        servers, elapsed_server, bytes_server = api.call("host.get", {
            "output": ["hostid", "host", "name", "status", "available", "proxy_hostid"],
            "filter": {"host": [server_host]},
            "sortfield": "name",
        })
        if servers and servers[0].get("status") == "0":
            by_id[servers[0]["hostid"]] = servers[0]

    return list(by_id.values()), {
        "hostgroup_get_s": elapsed_group,
        "host_get_s": elapsed_hosts,
        "server_get_s": elapsed_server,
        "host_api_bytes": bytes_group + bytes_hosts + bytes_server,
    }


def collect_items(api, hostids):
    output = ["itemid", "hostid", "name", "key_", "lastvalue", "lastclock", "value_type", "units", "status", "state", "error"]
    exact, elapsed_exact, bytes_exact = api.call("item.get", {
        "output": output,
        "hostids": hostids,
        "filter": {"key_": IMPORTANT_KEYS},
        "inherited": True,
        "sortfield": "name",
    })
    processes, elapsed_process, bytes_process = api.call("item.get", {
        "output": output,
        "hostids": hostids,
        "search": {"key_": PROCESS_PREFIX},
        "startSearch": True,
        "inherited": True,
        "sortfield": "name",
    })
    return exact + processes, {
        "item_exact_s": elapsed_exact,
        "item_process_s": elapsed_process,
        "item_api_bytes": bytes_exact + bytes_process,
        "exact_items": len(exact),
        "process_items": len(processes),
    }


def enrich_disk_items(api, items, hostids):
    output = ["itemid", "hostid", "name", "key_", "lastvalue", "lastclock", "value_type", "units", "status", "state", "error"]
    disk_items, elapsed, payload_bytes = api.call("item.get", {
        "output": output,
        "hostids": hostids,
        "search": {"key_": "vfs.fs.size"},
        "startSearch": True,
        "sortfield": "name",
    })
    by_host = defaultdict(list)
    for item in disk_items:
        match = re.match(r"^vfs\.fs\.size\[(.+),p(used|free)\]$", item.get("key_", ""))
        value = num(item.get("lastvalue"))
        if not match or value is None:
            continue
        filesystem, mode = match.groups()
        used = value if mode == "used" else 100 - value
        priority = 0 if filesystem == "/" and mode == "used" else 1 if filesystem == "/" and mode == "free" else 2 if mode == "used" else 3
        by_host[item["hostid"]].append((priority, -used, used, mode, item))
    added = 0
    for candidates in by_host.values():
        candidates.sort(key=lambda row: (row[0], row[1]))
        _priority, _sort, used, mode, item = candidates[0]
        item = dict(item)
        item["key_"] = "vfs.fs.size[/,pused]"
        item["name"] = "Selected disk usage, % used"
        item["lastvalue"] = str(used)
        item["units"] = "%"
        item["_trend_mode"] = "pfree" if mode == "free" else "raw"
        items.append(item)
        added += 1
    return {
        "disk_item_get_s": elapsed,
        "disk_api_bytes": payload_bytes,
        "disk_candidates": len(disk_items),
        "disk_selected_added": added,
    }


def old_trend_item(item):
    if item.get("value_type") not in ("0", "3"):
        return False
    key = item.get("key_", "")
    return key in OLD_TREND_KEYS or key.startswith(PROCESS_PREFIX)


def new_trend_item(item):
    if item.get("value_type") not in ("0", "3"):
        return False
    key = item.get("key_", "")
    if key in NEW_TREND_KEYS:
        return True
    if not key.startswith(PROCESS_PREFIX):
        return False
    process = process_name(key)
    return (
        process in PROCESS_CONFIG_MAP
        and PROCESS_CONFIG_MAP[process] != ""
        and process not in NON_CONFIGURABLE_PROCESSES
    )


def trend_key_family(item):
    key = item.get("key_", "")
    if key.startswith(PROCESS_PREFIX):
        return f"process:{process_name(key)}"
    return key


def collect_trend_probe(api, items, now, batch_size):
    metrics = {
        "trend_items": len(items),
        "batches": math.ceil(len(items) / batch_size) if items else 0,
        "trend_get_s": 0.0,
        "trend_rows": 0,
        "trend_api_bytes": 0,
        "max_batch_rows": 0,
        "max_batch_s": 0.0,
    }
    for batch in chunks(items, batch_size):
        rows, elapsed, payload_bytes = api.call("trend.get", {
            "output": ["itemid", "num", "value_avg"],
            "itemids": [item["itemid"] for item in batch],
            "time_from": now - 30 * 86400,
            "time_till": now,
        })
        row_count = len(rows)
        metrics["trend_get_s"] += elapsed
        metrics["trend_rows"] += row_count
        metrics["trend_api_bytes"] += payload_bytes
        metrics["max_batch_rows"] = max(metrics["max_batch_rows"], row_count)
        metrics["max_batch_s"] = max(metrics["max_batch_s"], elapsed)
    return metrics


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-url", required=True)
    parser.add_argument("--token", default=os.getenv("ZABBIX_TOKEN"), required=False)
    parser.add_argument("--host-group", default="Zabbix Proxies")
    parser.add_argument("--server-host", default="Usc-aws-zbx-7-0")
    parser.add_argument("--batch-size", type=int, default=120)
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--no-trends", action="store_true")
    args = parser.parse_args()

    if not args.token:
        raise SystemExit("Provide --token or ZABBIX_TOKEN.")

    api = Zabbix(args.api_url, args.token, args.timeout)
    now = int(time.time())
    started = time.perf_counter()

    hosts, host_metrics = collect_hosts(api, args.host_group, args.server_host)
    hostids = [host["hostid"] for host in hosts]
    items, item_metrics = collect_items(api, hostids)
    disk_metrics = enrich_disk_items(api, items, hostids)
    items = dedupe(items)

    old_items = [item for item in items if old_trend_item(item)]
    new_items = [item for item in items if new_trend_item(item)]
    removed = [item for item in old_items if item["itemid"] not in {new_item["itemid"] for new_item in new_items}]

    result = {
        "host_group": args.host_group,
        "server_host": args.server_host,
        "hosts": len(hosts),
        "items_after_dedupe": len(items),
        **host_metrics,
        **item_metrics,
        **disk_metrics,
        "old_selection": {
            "trend_items": len(old_items),
            "batches": math.ceil(len(old_items) / args.batch_size),
            "top_families": Counter(trend_key_family(item) for item in old_items).most_common(15),
        },
        "new_selection": {
            "trend_items": len(new_items),
            "batches": math.ceil(len(new_items) / args.batch_size),
            "top_families": Counter(trend_key_family(item) for item in new_items).most_common(15),
        },
        "removed_by_new_rule": {
            "trend_items": len(removed),
            "top_families": Counter(trend_key_family(item) for item in removed).most_common(20),
        },
    }

    if not args.no_trends:
        result["old_trend_get"] = collect_trend_probe(api, old_items, now, args.batch_size)
        result["new_trend_get"] = collect_trend_probe(api, new_items, now, args.batch_size)

    result["total_probe_s"] = round(time.perf_counter() - started, 3)
    print(json.dumps(result, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
