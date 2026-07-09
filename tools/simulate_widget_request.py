#!/usr/bin/env python3
"""Simulate the Proxy Health Assessment widget collection path.

This is intentionally close to the Zabbix module controller request flow:
resolve hosts, collect important items, process items, disk candidates,
configuration items, problems and 30-day trends in batches.
"""

from __future__ import annotations

import argparse
import gc
import json
import os
import re
import sys
import time
import tracemalloc
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import parse_qs, urlparse

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

TREND_KEYS = [
    "system.cpu.util",
    "vm.memory.size[pused]",
    "vm.memory.utilization",
    "vfs.fs.size[/,pused]",
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


def process_name(key: str) -> str:
    match = re.match(r"^zabbix\[process,([^,]+),", key or "")
    return match.group(1) if match else key


def now_ms(start: float) -> float:
    return round((time.perf_counter() - start) * 1000, 2)


def mem_mb() -> tuple[float, float]:
    current, peak = tracemalloc.get_traced_memory()
    return round(current / 1024 / 1024, 2), round(peak / 1024 / 1024, 2)


def log(event: str, start: float, **context: Any) -> None:
    current, peak = mem_mb()
    payload = {
        "event": event,
        "total_ms": now_ms(start),
        "py_current_mb": current,
        "py_peak_mb": peak,
        **context,
    }
    print(json.dumps(payload, ensure_ascii=False), flush=True)


def chunks(values: list[Any], size: int):
    for index in range(0, len(values), size):
        yield values[index:index + size]


def dedupe_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    by_id = {}
    for item in items:
        by_id[str(item.get("itemid"))] = item
    return list(by_id.values())


class ZabbixApi:
    def __init__(self, url: str, token: str, timeout: int):
        self.url = url.rstrip("/")
        if not self.url.endswith("/api_jsonrpc.php"):
            self.url += "/api_jsonrpc.php"
        self.timeout = timeout
        self.req_id = 0
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json-rpc",
        })

    def call(self, method: str, params: dict[str, Any]) -> tuple[list[dict[str, Any]], float, int]:
        self.req_id += 1
        request_start = time.perf_counter()
        response = self.session.post(
            self.url,
            data=json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": self.req_id}),
            timeout=self.timeout,
        )
        elapsed = (time.perf_counter() - request_start) * 1000
        response.raise_for_status()
        body = response.json()
        if "error" in body:
            raise RuntimeError(f"{method}: {body['error']}")
        return body["result"], elapsed, len(response.content)


def parse_request_url(url: str) -> dict[str, str]:
    parsed = urlparse(url)
    return {key: values[-1] for key, values in parse_qs(parsed.query).items()}


def collect_hosts(api: ZabbixApi, settings: dict[str, str], start: float) -> list[dict[str, Any]]:
    groupid = settings.get("host_groupid", "")
    hosts, elapsed, size = api.call("host.get", {
        "output": ["hostid", "host", "name", "status", "available", "proxy_hostid"],
        "groupids": [groupid],
        "sortfield": "name",
    })
    active = [host for host in hosts if str(host.get("status")) == "0"]
    log("collect.host_group_hosts", start, hosts_raw=len(hosts), hosts_ready=len(active), api_ms=round(elapsed, 2), bytes=size)
    return active


def collect_items(api: ZabbixApi, hostids: list[str], start: float) -> list[dict[str, Any]]:
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
    items = exact + processes
    log(
        "collect.assessment_items",
        start,
        exact_items=len(exact),
        process_items=len(processes),
        items=len(items),
        api_ms=round(elapsed_exact + elapsed_process, 2),
        bytes=bytes_exact + bytes_process,
    )
    return items


def collect_disk_items(api: ZabbixApi, hostids: list[str], items: list[dict[str, Any]], start: float) -> list[dict[str, Any]]:
    output = ["itemid", "hostid", "name", "key_", "lastvalue", "lastclock", "value_type", "units", "status", "state", "error"]
    disk_items, elapsed, size = api.call("item.get", {
        "output": output,
        "hostids": hostids,
        "search": {"key_": "vfs.fs.size"},
        "startSearch": True,
        "sortfield": "name",
    })
    by_host: dict[str, list[tuple[int, float, float, str, dict[str, Any]]]] = defaultdict(list)
    for item in disk_items:
        match = re.match(r"^vfs\.fs\.size\[(.+),p(used|free)\]$", item.get("key_", ""))
        if not match:
            continue
        try:
            value = float(item.get("lastvalue"))
        except (TypeError, ValueError):
            continue
        filesystem, mode = match.groups()
        used = value if mode == "used" else 100 - value
        priority = (
            0 if filesystem == "/" and mode == "used"
            else 1 if filesystem == "/" and mode == "free"
            else 2 if mode == "used"
            else 3
        )
        by_host[str(item["hostid"])].append((priority, -used, used, mode, item))

    added = 0
    for candidates in by_host.values():
        candidates.sort(key=lambda row: (row[0], row[1]))
        _priority, _sort, used, mode, selected = candidates[0]
        selected = dict(selected)
        selected["key_"] = "vfs.fs.size[/,pused]"
        selected["name"] = "Selected disk usage, % used"
        selected["lastvalue"] = str(used)
        selected["units"] = "%"
        selected["_trend_mode"] = "pfree" if mode == "free" else "raw"
        items.append(selected)
        added += 1

    log("collect.disk_items", start, items_with_disk=len(items), disk_candidates=len(disk_items), disk_selected_added=added, api_ms=round(elapsed, 2), bytes=size)
    return items


def collect_config(api: ZabbixApi, hostids: list[str], start: float) -> list[dict[str, Any]]:
    config, elapsed, size = api.call("item.get", {
        "output": ["itemid", "hostid", "name", "key_", "lastvalue", "lastclock", "value_type", "units", "status", "state", "error"],
        "hostids": hostids,
        "search": {"key_": "num."},
        "startSearch": True,
        "inherited": True,
        "sortfield": "name",
    })
    log("collect.config_items", start, config_items=len(config), api_ms=round(elapsed, 2), bytes=size)
    return config


def collect_problems(api: ZabbixApi, hostids: list[str], start: float) -> list[dict[str, Any]]:
    problems, elapsed, size = api.call("problem.get", {
        "output": ["eventid", "objectid", "name", "severity", "clock", "r_eventid"],
        "hostids": hostids,
        "recent": False,
        "sortfield": ["eventid"],
        "sortorder": "DESC",
        "selectTags": "extend",
    })
    log("collect.problems", start, problems=len(problems), api_ms=round(elapsed, 2), bytes=size)
    return problems


def is_trend_item(item: dict[str, Any]) -> bool:
    if str(item.get("value_type")) not in {"0", "3"}:
        return False
    key = item.get("key_", "")
    if key in TREND_KEYS:
        return True
    if not key.startswith(PROCESS_PREFIX):
        return False
    name = process_name(key)
    return name in PROCESS_CONFIG_MAP and name not in NON_CONFIGURABLE_PROCESSES


def collect_trends(api: ZabbixApi, items: list[dict[str, Any]], batch_size: int, start: float) -> dict[str, float | None]:
    numeric = dedupe_items([item for item in items if is_trend_item(item)])
    time_till = int(datetime.now(timezone.utc).timestamp())
    time_from = int((datetime.now(timezone.utc) - timedelta(days=30)).timestamp())
    log("trend.numeric_items", start, numeric=len(numeric), batches=(len(numeric) + batch_size - 1) // batch_size)

    result: dict[str, float | None] = {}
    total_rows = 0
    total_bytes = 0
    for index, batch in enumerate(chunks(numeric, batch_size), start=1):
        before, before_peak = mem_mb()
        rows, elapsed, size = api.call("trend.get", {
            "output": ["itemid", "num", "value_avg"],
            "itemids": [item["itemid"] for item in batch],
            "time_from": time_from,
            "time_till": time_till,
            "sortfield": "clock",
        })
        grouped_sum: dict[str, float] = defaultdict(float)
        grouped_num: dict[str, float] = defaultdict(float)
        for row in rows:
            itemid = str(row.get("itemid"))
            try:
                n = float(row.get("num") or 0)
                avg = float(row.get("value_avg") or 0)
            except (TypeError, ValueError):
                continue
            grouped_sum[itemid] += avg * n
            grouped_num[itemid] += n
        for item in batch:
            itemid = str(item.get("itemid"))
            result[itemid] = grouped_sum[itemid] / grouped_num[itemid] if grouped_num[itemid] else None

        row_count = len(rows)
        total_rows += row_count
        total_bytes += size
        after_calc, peak_calc = mem_mb()
        del rows
        del grouped_sum
        del grouped_num
        gc.collect()
        after_gc, peak_gc = mem_mb()
        log(
            "trend.batch",
            start,
            batch=index,
            batch_items=len(batch),
            rows=row_count,
            api_ms=round(elapsed, 2),
            bytes=size,
            total_rows=total_rows,
            total_bytes=total_bytes,
            mb_before=before,
            mb_after_calc=after_calc,
            mb_after_gc=after_gc,
            peak_before=before_peak,
            peak_after_calc=peak_calc,
            peak_after_gc=peak_gc,
            result_values=len(result),
        )
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True, help="Full widget URL or Zabbix base URL.")
    parser.add_argument("--api-url", default="", help="Override JSON-RPC URL/base.")
    parser.add_argument("--token", default=os.getenv("ZABBIX_TOKEN", ""))
    parser.add_argument("--batch-size", type=int, default=30)
    parser.add_argument("--timeout", type=int, default=120)
    args = parser.parse_args()

    if not args.token:
        print("Missing token. Use --token or ZABBIX_TOKEN.", file=sys.stderr)
        return 2

    settings = parse_request_url(args.url)
    base_url = args.api_url or "https://webmonitor.com.br/api_jsonrpc.php"
    api = ZabbixApi(base_url, args.token, args.timeout)
    tracemalloc.start()
    start = time.perf_counter()
    log("start", start, url_host=urlparse(args.url).netloc or "webmonitor.com.br", host_groupid=settings.get("host_groupid"), batch_size=args.batch_size)

    hosts = collect_hosts(api, settings, start)
    hostids = [host["hostid"] for host in hosts]
    items = collect_items(api, hostids, start)
    items = collect_disk_items(api, hostids, items, start)
    items = dedupe_items(items)
    log("collect.dedupe_items", start, items=len(items))
    config = collect_config(api, hostids, start)
    problems = collect_problems(api, hostids, start)
    trends = collect_trends(api, items, args.batch_size, start)
    log("finish", start, hosts=len(hosts), items=len(items), config_items=len(config), problems=len(problems), trend_values=len(trends))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
