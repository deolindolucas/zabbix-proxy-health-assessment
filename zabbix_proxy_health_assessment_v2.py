#!/usr/bin/env python3
"""
Standalone Zabbix Proxy Health Assessment v3.0.

Requirements:
    python -m pip install zabbix-utils openpyxl

Example:
    python zabbix_proxy_health_assessment_v2.py ^
      --api-url https://webmonitor.com.br ^
      --token TOKEN ^
      --host-group "Zabbix/Proxies" ^
      --output-xlsx webmonitor_proxy_health_assessment_v3_0.xlsx
"""

from __future__ import annotations

import argparse
import json
import math
import re
import sys
import zipfile
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

try:
    from zabbix_utils import ZabbixAPI
    from openpyxl import Workbook
    from openpyxl.styles import Alignment, Font, PatternFill
    from openpyxl.worksheet.datavalidation import DataValidation
    from openpyxl.worksheet.table import Table, TableStyleInfo
    from openpyxl.utils import get_column_letter
except ImportError as exc:  # pragma: no cover - user-facing bootstrap guard.
    raise SystemExit(
        "Dependencia ausente.\n"
        "Instale com: python -m pip install zabbix-utils openpyxl"
    ) from exc


IMPORTANT_KEYS = [
    "agent.ping",
    "system.cpu.load[all,avg1]",
    "system.cpu.num",
    "system.cpu.util",
    "system.uptime",
    "vm.memory.size[total]",
    "vm.memory.size[pavailable]",
    "vm.memory.size[pused]",
    "vm.memory.utilization",
    "proc.num[zabbix_proxy]",
    "zabbix[uptime]",
    "zabbix[version]",
    "zabbix[hosts]",
    "zabbix[items]",
    "zabbix[items_unsupported]",
    "zabbix[requiredperformance]",
    "zabbix[preprocessing_queue]",
    "zabbix[queue,10m]",
    "zabbix[proxy,{HOST.HOST}, lastaccess]",
    "zabbix[proxy_buffer,state,current]",
    "zabbix[proxy_buffer,state,changes]",
    "zabbix[proxy_buffer,buffer,pused]",
    "zabbix[rcache,buffer,pfree]",
    "zabbix[rcache,buffer,pused]",
    "zabbix[wcache,history,pfree]",
    "zabbix[wcache,history,pused]",
    "zabbix[wcache,index,pused]",
    "zabbix[wcache,trend,pused]",
    "zabbix[vcache,buffer,pused]",
    "zabbix[vmware,buffer,pused]",
    "zabbix[proxy_history]",
    "zabbix[queue]",
    "zabbix[discovery_queue]",
    "zabbix[wcache,values]",
    "zabbix[wcache,values,float]",
    "zabbix[wcache,values,uint]",
    "zabbix[wcache,values,str]",
    "zabbix[wcache,values,text]",
    "zabbix[wcache,values,log]",
    "zabbix[wcache,values,not supported]",
]
PROCESS_KEY_PREFIX = "zabbix[process,"
TREND_EXCLUDED_KEYS = {"zabbix[wcache,values]"}
TREND_KEYS = set(IMPORTANT_KEYS) - TREND_EXCLUDED_KEYS

PROCESS_CONFIG_MAP = {
    "agent poller": "num.StartAgentPollers",
    "browser poller": "num.StartBrowserPollers",
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
RECOMMENDED_CONFIG_MAP = {
    "agent poller": "num.recomendado.agent",
    "browser poller": "num.recomendado.browser",
    "discovery worker": "num.recomendado.discoverers",
    "http poller": "num.recomendado.http",
    "http agent poller": "num.recomendado.httpagent",
    "icmp pinger": "num.recomendado.pingers",
    "ipmi poller": "num.recomendado.ipmi",
    "java poller": "num.recomendado.java",
    "odbc poller": "num.recomendado.odbc",
    "poller": "num.recomendado.pollers",
    "preprocessing worker": "num.recomendado.preprocessors",
    "snmp poller": "num.recomendado.snmp",
    "trapper": "num.recomendado.trappers",
    "unreachable poller": "num.recomendado.unreachable",
    "vmware collector": "num.recomendado.vmware",
}
CACHE_CONFIG_MAP = [
    ("Configuration cache", "num.CacheSize", "num.CacheSize.bytes", "num.recomendado.CacheSize", [("zabbix[rcache,buffer,pused]", "pused"), ("zabbix[rcache,buffer,pfree]", "pfree")]),
    ("History write cache", "num.HistoryCacheSize", "num.HistoryCacheSize.bytes", "num.recomendado.HistoryCacheSize", [("zabbix[wcache,history,pused]", "pused"), ("zabbix[wcache,history,pfree]", "pfree")]),
    ("History index cache", "num.HistoryIndexCacheSize", "num.HistoryIndexCacheSize.bytes", "num.recomendado.HistoryIndexCacheSize", [("zabbix[wcache,index,pused]", "pused")]),
    ("Trend write cache", "num.trendcachesize", "num.TrendCacheSize.bytes", "num.recomendado.TrendCacheSize", [("zabbix[wcache,trend,pused]", "pused")]),
    ("Value cache", "num.valueCacheSize", "num.ValueCacheSize.bytes", "num.recomendado.ValueCacheSize", [("zabbix[vcache,buffer,pused]", "pused")]),
    ("Proxy memory buffer", "", "", "", [("zabbix[proxy_buffer,buffer,pused]", "pused")]),
    ("VMware cache", "num.VMwareCacheSize", "", "", [("zabbix[vmware,buffer,pused]", "pused")]),
]
CACHE_DEFAULTS = {
    "num.CacheSize": "8M",
    "num.HistoryCacheSize": "16M",
    "num.HistoryIndexCacheSize": "4M",
    "num.trendcachesize": "4M",
    "num.valueCacheSize": "8M",
    "num.VMwareCacheSize": "8M",
}
CACHE_TARGET_LOAD = 0.60


def normalize_zabbix_url(url: str) -> str:
    url = url.rstrip("/")
    if url.endswith("/api_jsonrpc.php"):
        return url[: -len("/api_jsonrpc.php")]
    return url


def connect_zabbix(url: str, token: str):
    api = ZabbixAPI(url=url)
    api.login(token=token)
    return api


def rpc(api, method: str, params: dict):
    service_name, method_name = method.split(".", 1)
    service = getattr(api, service_name)
    function = getattr(service, method_name)
    return function(**params)


def chunked(values, size):
    for idx in range(0, len(values), size):
        yield values[idx : idx + size]


def to_number(value):
    try:
        if value in (None, ""):
            return None
        num = float(value)
        return None if math.isnan(num) else num
    except Exception:
        return None


def bytes_to_gib(value):
    num = to_number(value)
    return None if num is None else num / 1024 / 1024 / 1024


def parse_size_to_bytes(value):
    if value in (None, ""):
        return None
    if isinstance(value, (int, float)):
        return float(value)
    match = re.match(r"^\s*(\d+(?:\.\d+)?)\s*([KMGT]?B?|[KMGT])?\s*$", str(value), re.I)
    if not match:
        return None
    number = float(match.group(1))
    unit = (match.group(2) or "B").upper()
    if unit in ("K", "M", "G", "T"):
        unit += "B"
    multipliers = {
        "B": 1,
        "KB": 1024,
        "MB": 1024 ** 2,
        "GB": 1024 ** 3,
        "TB": 1024 ** 4,
    }
    multiplier = multipliers.get(unit)
    return round(number * multiplier) if multiplier else None


def positive_number(value):
    number = to_number(value)
    return number if number is not None and number > 0 else None


def epoch(value):
    num = to_number(value)
    return datetime.fromtimestamp(num, tz=timezone.utc) if num else None


def excel_dt(value):
    dt = epoch(value)
    return dt.replace(tzinfo=None) if dt else None


def age_seconds(collected_at: datetime, clock):
    dt = epoch(clock)
    return max(0, int((collected_at - dt).total_seconds())) if dt else None


def age_text(collected_at: datetime, clock):
    sec = age_seconds(collected_at, clock)
    if sec is None:
        return ""
    if sec < 60:
        return f"{sec}s"
    if sec < 3600:
        return f"{sec // 60}m"
    if sec < 86400:
        return f"{sec // 3600}h {(sec % 3600) // 60}m"
    return f"{sec // 86400}d {(sec % 86400) // 3600}h"


def version_patch(version):
    match = re.match(r"^(\d+)\.(\d+)\.(\d+)", str(version or ""))
    return int(match.group(3)) if match else None


def process_name_from_key(key):
    match = re.match(r"^zabbix\[process,([^,]+),", key or "")
    return match.group(1) if match else key


def host_interface(host):
    interfaces = host.get("interfaces") or []
    main = next((iface for iface in interfaces if str(iface.get("main")) == "1"), None) or (interfaces[0] if interfaces else None)
    if not main:
        return ""
    return main.get("ip") if str(main.get("useip")) == "1" else main.get("dns") or main.get("ip") or ""


def severity_name(sev):
    names = ["Not classified", "Information", "Warning", "Average", "High", "Disaster"]
    try:
        return names[int(sev)]
    except Exception:
        return str(sev or "")


def is_relevant_problem(problem):
    if int(problem.get("severity") or 0) >= 5:
        return True
    text = str(problem.get("name") or "").lower()
    words = [
        "proxy", "zabbix", "poller", "trapper", "preprocessing", "queue", "cache",
        "history", "configuration", "version", "cpu", "mem", "memory", "disco",
        "disk", "filesystem", "load", "unsupported", "processo", "process",
    ]
    return any(word in text for word in words)


def trend_stats(rows, mode="raw"):
    total = 0
    weighted = 0.0
    max_value = None
    min_value = None
    for row in rows:
        count = int(row.get("num") or 0)
        avg = to_number(row.get("value_avg"))
        value_max = to_number(row.get("value_max"))
        value_min = to_number(row.get("value_min"))
        if mode == "pfree":
            avg = 100 - avg if avg is not None else None
            used_max = 100 - value_min if value_min is not None else None
            used_min = 100 - value_max if value_max is not None else None
            value_max, value_min = used_max, used_min
        if avg is not None and count:
            weighted += avg * count
            total += count
        if value_max is not None:
            max_value = value_max if max_value is None else max(max_value, value_max)
        if value_min is not None:
            min_value = value_min if min_value is None else min(min_value, value_min)
    return {
        "points": len(rows),
        "samples": total,
        "avg30d": weighted / total if total else None,
        "max30d": max_value,
        "min30d": min_value,
    }


def collect_trends(api, items, now_ts):
    numeric = [
        item for item in items
        if item.get("value_type") in ("0", "3")
        and (item.get("key_") in TREND_KEYS or str(item.get("key_", "")).startswith(PROCESS_KEY_PREFIX))
    ]
    stats = {}
    for batch in chunked(numeric, 120):
        rows = rpc(
            api,
            "trend.get",
            {
                "output": ["itemid", "clock", "num", "value_min", "value_avg", "value_max"],
                "itemids": [item["itemid"] for item in batch],
                "time_from": now_ts - 30 * 86400,
                "time_till": now_ts,
            },
        )
        grouped = defaultdict(list)
        for row in rows:
            grouped[row["itemid"]].append(row)
        for itemid, item_rows in grouped.items():
            stats[itemid] = trend_stats(item_rows)
    return stats


def get_host_group(api, host_group_name):
    groups = rpc(
        api,
        "hostgroup.get",
        {
            "output": ["groupid", "name"],
            "filter": {"name": [host_group_name]},
        },
    )
    if not groups:
        raise RuntimeError(f"Host group not found: {host_group_name}")
    return groups[0]


def collect_base(api, zabbix_url, host_group_name, template_id=None):
    now_ts = int(datetime.now(timezone.utc).timestamp())
    host_group = get_host_group(api, host_group_name)
    host_params = {
        "output": ["hostid", "host", "name", "status", "available", "proxy_hostid"],
        "selectInterfaces": ["ip", "dns", "type", "main", "useip"],
        "selectParentTemplates": ["templateid", "host", "name"],
        "groupids": [host_group["groupid"]],
        "sortfield": "name",
    }
    if template_id:
        host_params["templateids"] = [str(template_id)]
    hosts = rpc(api, "host.get", host_params)
    hosts = [host for host in hosts if host.get("status") == "0"]
    hostids = [host["hostid"] for host in hosts]
    if not hostids:
        raise RuntimeError(f"No enabled hosts found for host group {host_group_name}")

    items = rpc(
        api,
        "item.get",
        {
            "output": [
                "itemid", "hostid", "name", "key_", "lastvalue", "lastclock",
                "value_type", "units", "status", "state", "error",
            ],
            "hostids": hostids,
            "inherited": True,
            "sortfield": "name",
        },
    )
    relevant_items = [
        item for item in items
        if item["key_"] in IMPORTANT_KEYS
        or item["key_"].startswith(PROCESS_KEY_PREFIX)
    ]
    trends30d = collect_trends(api, relevant_items, now_ts)

    problems = rpc(
        api,
        "problem.get",
        {
            "output": ["eventid", "objectid", "name", "severity", "clock", "acknowledged"],
            "hostids": hostids,
            "recent": False,
            "sortfield": "eventid",
            "sortorder": "DESC",
        },
    )
    triggerids = sorted({problem.get("objectid") for problem in problems if problem.get("objectid")})
    triggers = []
    if triggerids:
        triggers = rpc(
            api,
            "trigger.get",
            {
                "output": ["triggerid", "description", "priority", "value", "status", "state", "error"],
                "triggerids": triggerids,
                "selectHosts": ["hostid", "host", "name", "status"],
                "selectItems": ["itemid", "hostid", "name", "key_", "status", "state", "error"],
            },
        )

    return {
        "collected_at": datetime.now(timezone.utc).isoformat(),
        "api_url": zabbix_url,
        "host_group": {"groupid": host_group["groupid"], "name": host_group["name"]},
        "template_filter": str(template_id or ""),
        "hosts": hosts,
        "items": relevant_items,
        "trends30d": trends30d,
        "problems": problems,
        "triggers": triggers,
    }


def filter_data_hosts(data, excluded_hosts):
    excluded = {value.strip().casefold() for value in excluded_hosts if value and value.strip()}
    if not excluded:
        return []

    removed = []
    kept_hostids = set()
    removed_hostids = set()
    for host in data.get("hosts", []):
        aliases = {str(host.get("host") or "").casefold(), str(host.get("name") or "").casefold()}
        if aliases & excluded:
            removed.append(host)
            removed_hostids.add(host["hostid"])
        else:
            kept_hostids.add(host["hostid"])

    data["hosts"] = [host for host in data.get("hosts", []) if host["hostid"] in kept_hostids]
    data["items"] = [item for item in data.get("items", []) if item.get("hostid") in kept_hostids]
    data["proxy_config_items"] = [
        item for item in data.get("proxy_config_items", [])
        if item.get("hostid") in kept_hostids
    ]

    kept_triggerids = set()
    filtered_triggers = []
    for trigger in data.get("triggers", []):
        hosts = [host for host in trigger.get("hosts") or [] if host.get("hostid") in kept_hostids]
        items = [item for item in trigger.get("items") or [] if item.get("hostid") in kept_hostids]
        if hosts:
            trigger = dict(trigger)
            trigger["hosts"] = hosts
            trigger["items"] = items
            filtered_triggers.append(trigger)
            kept_triggerids.add(trigger["triggerid"])
    data["triggers"] = filtered_triggers
    data["problems"] = [
        problem for problem in data.get("problems", [])
        if problem.get("objectid") in kept_triggerids
    ]
    return removed


def collect_proxy_config(api, data):
    hostids = [host["hostid"] for host in data["hosts"] if host.get("status") == "0"]
    config_items = rpc(
        api,
        "item.get",
        {
            "output": [
                "itemid", "hostid", "name", "key_", "lastvalue", "lastclock",
                "value_type", "units", "state", "status", "error",
            ],
            "hostids": hostids,
            "search": {"key_": "num."},
            "sortfield": "name",
        },
    )
    data["proxy_config_source"] = {
        "source": "host_items_key_search",
        "key_search": "num.",
        "collected_at": datetime.now(timezone.utc).isoformat(),
        "item_keys": sorted({item["key_"] for item in config_items}),
    }
    data["proxy_config_items"] = sorted(
        {item["itemid"]: item for item in config_items}.values(),
        key=lambda item: (item.get("hostid", ""), item.get("name", "")),
    )


def fs_mode_from_key(key):
    match = re.match(r"^vfs\.fs\.size\[(.+),p(used|free)\]$", key)
    return (match.group(1), match.group(2)) if match else ("", "")


def enrich_disk_metrics(api, data):
    hostids = [host["hostid"] for host in data["hosts"] if host.get("status") == "0"]
    items = rpc(
        api,
        "item.get",
        {
            "output": [
                "itemid", "hostid", "name", "key_", "lastvalue", "lastclock",
                "value_type", "units", "status", "state", "error",
            ],
            "hostids": hostids,
            "search": {"key_": "vfs.fs.size"},
            "sortfield": "name",
        },
    )
    pct_items = [item for item in items if re.match(r"^vfs\.fs\.size\[.+,p(?:used|free)\]$", item.get("key_", ""))]
    itemids = [item["itemid"] for item in pct_items if item.get("value_type") in ("0", "3")]
    now_ts = int(datetime.now(timezone.utc).timestamp())
    trend_rows = []
    for itemid_batch in chunked(itemids, 120):
        trend_rows.extend(
            rpc(
                api,
                "trend.get",
                {
                    "output": ["itemid", "clock", "num", "value_min", "value_avg", "value_max"],
                    "itemids": itemid_batch,
                    "time_from": now_ts - 30 * 86400,
                    "time_till": now_ts,
                },
            )
        )
    trends_by_item = defaultdict(list)
    for row in trend_rows:
        trends_by_item[row["itemid"]].append(row)

    by_host = defaultdict(list)
    for item in pct_items:
        by_host[item["hostid"]].append(item)

    selected = []
    for hostid in hostids:
        candidates = []
        for item in by_host.get(hostid, []):
            filesystem, mode = fs_mode_from_key(item["key_"])
            value = to_number(item.get("lastvalue"))
            if value is None:
                continue
            used = value if mode == "used" else 100 - value
            priority = 0 if filesystem == "/" and mode == "used" else 1 if filesystem == "/" and mode == "free" else 2 if mode == "used" else 3
            candidates.append((priority, -used, filesystem, mode, item, used))
        if not candidates:
            continue
        _, _, filesystem, mode, source, used = sorted(candidates)[0]
        synthetic = dict(source)
        synthetic["itemid"] = f"disk_selected_pused_{hostid}"
        synthetic["key_"] = "vfs.fs.size[/,pused]"
        synthetic["name"] = f"Selected disk usage, % used ({filesystem or 'unknown'})"
        synthetic["lastvalue"] = str(used)
        synthetic["units"] = "%"
        selected.append((synthetic, source, mode))

    existing = {(item["hostid"], item["key_"]): idx for idx, item in enumerate(data.get("items", []))}
    for synthetic, source, mode in selected:
        key = (synthetic["hostid"], synthetic["key_"])
        if key in existing:
            data["items"][existing[key]] = synthetic
        else:
            data.setdefault("items", []).append(synthetic)
        data.setdefault("trends30d", {})[synthetic["itemid"]] = trend_stats(
            trends_by_item.get(source["itemid"], []),
            "pused" if mode == "used" else "pfree",
        )
    data["disk_selection_note"] = (
        "vfs.fs.size[/,pused] selected per proxy: prefer / pused, then / pfree converted, "
        "then highest percentage filesystem available."
    )


def item_by_host(data):
    result = defaultdict(dict)
    for item in data.get("items", []):
        result[item["hostid"]][item["key_"]] = item
    return result


def config_by_host(data):
    result = defaultdict(dict)
    for item in data.get("proxy_config_items", []):
        result[item["hostid"]][item["key_"]] = item
    return result


def orphan_reason(problem, trigger_by_id):
    trigger = trigger_by_id.get(problem.get("objectid"))
    if not trigger:
        return "Trigger nao retornada pela API ou sem contexto valido"
    if trigger.get("status") != "0":
        return "Trigger desabilitada"
    items = trigger.get("items") or []
    if not items:
        return "Trigger sem itens associados retornados pela API"
    disabled = next((item for item in items if item.get("status") != "0"), None)
    if disabled:
        return f"Item desabilitado: {disabled.get('key_') or disabled.get('name') or disabled.get('itemid')}"
    return ""


def cache_used(value, mode):
    number = to_number(value)
    if number is None:
        return None
    return 100 - number if mode == "pfree" else number


def max_of(values):
    numbers = [to_number(value) for value in values]
    numbers = [value for value in numbers if value is not None]
    return max(numbers) if numbers else None


def build_rows(data):
    collected_at = datetime.fromisoformat(data["collected_at"].replace("Z", "+00:00"))
    items_by_host = item_by_host(data)
    config_items_by_host = config_by_host(data)

    trigger_by_id = {trigger["triggerid"]: trigger for trigger in data.get("triggers", [])}
    trigger_to_host = {}
    for trigger in data.get("triggers", []):
        for host in trigger.get("hosts") or []:
            trigger_to_host[trigger["triggerid"]] = host["hostid"]

    active_problems = []
    orphan_problems = []
    problems_by_host = defaultdict(list)
    orphan_by_host = defaultdict(list)
    for problem in data.get("problems", []):
        reason = orphan_reason(problem, trigger_by_id)
        hostid = trigger_to_host.get(problem.get("objectid"))
        if reason:
            orphan_problems.append(problem)
            if hostid:
                orphan_by_host[hostid].append(problem)
        else:
            active_problems.append(problem)
            if hostid:
                problems_by_host[hostid].append(problem)

    host_rows = []
    process_rows = []
    proxy_config_rows = []
    process_config_rows = []
    cache_config_rows = []

    for host in data.get("hosts", []):
        if host.get("status") != "0":
            continue
        hostid = host["hostid"]
        host_items = list(items_by_host.get(hostid, {}).values())
        cfg_items = config_items_by_host.get(hostid, {})
        for cfg in cfg_items.values():
            proxy_config_rows.append({
                "Host": host.get("name") or host.get("host"),
                "Config item": cfg.get("name"),
                "Key": cfg.get("key_"),
                "Valor": to_number(cfg.get("lastvalue")) if to_number(cfg.get("lastvalue")) is not None else cfg.get("lastvalue"),
                "Unidade": cfg.get("units") or "",
                "Ultima coleta": excel_dt(cfg.get("lastclock")),
                "Estado": "Normal" if cfg.get("state") == "0" else "Not supported",
                "Erro": cfg.get("error") or "",
            })

        process_items = [item for item in host_items if item.get("key_", "").startswith(PROCESS_KEY_PREFIX)]
        for item in process_items:
            process_name = process_name_from_key(item["key_"])
            process_rows.append({
                "Host": host.get("name") or host.get("host"),
                "Processo": process_name,
                "Key": item["key_"],
                "Busy atual": to_number(item.get("lastvalue")),
                "Media 30d": data.get("trends30d", {}).get(item["itemid"], {}).get("avg30d"),
                "Maximo 30d": data.get("trends30d", {}).get(item["itemid"], {}).get("max30d"),
                "Ultima coleta": excel_dt(item.get("lastclock")),
                "Estado": "Normal" if item.get("state") == "0" else "Not supported",
                "Erro": item.get("error") or "",
            })
            config_key = PROCESS_CONFIG_MAP.get(process_name, "")
            rec_key = RECOMMENDED_CONFIG_MAP.get(process_name, "")
            cfg = cfg_items.get(config_key) if config_key else None
            rec = cfg_items.get(rec_key) if rec_key else None
            process_config_rows.append({
                "Host": host.get("name") or host.get("host"),
                "Processo": process_name,
                "Status": None,
                "Acao sugerida": None,
                "Busy atual %": to_number(item.get("lastvalue")),
                "Busy media 30d %": data.get("trends30d", {}).get(item["itemid"], {}).get("avg30d"),
                "Valor configurado": to_number(cfg.get("lastvalue")) if cfg else None,
                "Valor recomendado": to_number(rec.get("lastvalue")) if rec else None,
                "Ultima coleta config": excel_dt(cfg.get("lastclock")) if cfg else None,
            })

        for cache_name, config_key, bytes_key, recommended_key, candidates in CACHE_CONFIG_MAP:
            cache_key, mode, cache_item = None, None, None
            for candidate_key, candidate_mode in candidates:
                candidate_item = items_by_host.get(hostid, {}).get(candidate_key)
                if candidate_item:
                    cache_key, mode, cache_item = candidate_key, candidate_mode, candidate_item
                    break
            if not cache_item:
                continue
            cfg = cfg_items.get(config_key) if config_key else None
            cfg_bytes = cfg_items.get(bytes_key) if bytes_key else None
            recommended = cfg_items.get(recommended_key) if recommended_key else None
            configured_value = (
                cfg.get("lastvalue")
                if cfg and cfg.get("lastvalue") not in (None, "")
                else CACHE_DEFAULTS.get(config_key)
            )
            configured_bytes = (
                positive_number(cfg_bytes.get("lastvalue")) if cfg_bytes else None
            ) or parse_size_to_bytes(configured_value)
            usage_now = cache_used(cache_item.get("lastvalue"), mode)
            usage_avg = cache_used(data.get("trends30d", {}).get(cache_item["itemid"], {}).get("avg30d"), mode)
            usage_for_recommendation = max_of([usage_now, usage_avg])
            recommended_bytes = positive_number(recommended.get("lastvalue")) if recommended else None
            if recommended_bytes is None and configured_bytes is not None and usage_for_recommendation is not None:
                recommended_bytes = math.ceil((configured_bytes * (usage_for_recommendation / 100)) / CACHE_TARGET_LOAD)
            cache_config_rows.append({
                "Host": host.get("name") or host.get("host"),
                "Cache": cache_name,
                "Cache key": cache_key,
                "Uso atual %": usage_now,
                "Uso media 30d %": usage_avg,
                "Parametro config": config_key,
                "Valor configurado": to_number(configured_value) if to_number(configured_value) is not None else configured_value,
                "Valor configurado bytes": configured_bytes,
                "Valor recomendado bytes": recommended_bytes,
                "Status": None,
                "Acao sugerida": None,
                "Ultima coleta cache": excel_dt(cache_item.get("lastclock")),
                "Ultima coleta config": excel_dt(cfg.get("lastclock")) if cfg else None,
            })

        def item(key):
            return items_by_host.get(hostid, {}).get(key)

        def item_value(key):
            obj = item(key)
            return obj.get("lastvalue") if obj else None

        def stat(key, field):
            obj = item(key)
            return data.get("trends30d", {}).get(obj["itemid"], {}).get(field) if obj else None

        relevant = [problem for problem in problems_by_host.get(hostid, []) if is_relevant_problem(problem)]
        disasters = [problem for problem in problems_by_host.get(hostid, []) if int(problem.get("severity") or 0) >= 5]
        orphan_relevant = [problem for problem in orphan_by_host.get(hostid, []) if is_relevant_problem(problem)]
        orphan_disasters = [problem for problem in orphan_by_host.get(hostid, []) if int(problem.get("severity") or 0) >= 5]
        total_items = to_number(item_value("zabbix[items]"))
        unsupported = to_number(item_value("zabbix[items_unsupported]"))
        load_avg1 = to_number(item_value("system.cpu.load[all,avg1]"))
        cpu_num = to_number(item_value("system.cpu.num"))
        version = item_value("zabbix[version]")

        host_rows.append({
            "Host ID": hostid,
            "Host": host.get("host"),
            "Nome": host.get("name") or host.get("host"),
            "Interface": host_interface(host),
            "Versao": version,
            "Patch versao": version_patch(version),
            "Processo proxy": to_number(item_value("proc.num[zabbix_proxy]")),
            "Idade ultimo acesso (s)": age_seconds(collected_at, item_value("zabbix[proxy,{HOST.HOST}, lastaccess]")),
            "Hosts": to_number(item_value("zabbix[hosts]")),
            "Itens": total_items,
            "Itens unsupported": unsupported,
            "Unsupported %": unsupported / total_items if total_items else None,
            "VPS atual": to_number(item_value("zabbix[wcache,values]")),
            "Fila 10m": to_number(item_value("zabbix[queue,10m]")),
            "Preproc queue": to_number(item_value("zabbix[preprocessing_queue]")),
            "CPU atual %": to_number(item_value("system.cpu.util")),
            "CPU media 30d %": stat("system.cpu.util", "avg30d"),
            "Load/core atual": load_avg1 / cpu_num if load_avg1 is not None and cpu_num else None,
            "Memoria atual %": to_number(item_value("vm.memory.size[pused]")) if item("vm.memory.size[pused]") else to_number(item_value("vm.memory.utilization")),
            "Memoria media 30d %": stat("vm.memory.size[pused]", "avg30d") if item("vm.memory.size[pused]") else stat("vm.memory.utilization", "avg30d"),
            "Memoria total GB": bytes_to_gib(item_value("vm.memory.size[total]")),
            "Disco atual %": to_number(item_value("vfs.fs.size[/,pused]")),
            "Disco media 30d %": stat("vfs.fs.size[/,pused]", "avg30d"),
            "Alertas saude": len(relevant),
            "Alertas disaster": len(disasters),
            "Orfaos saude": len(orphan_relevant),
            "Orfaos disaster": len(orphan_disasters),
            "Config issues": None,
            "Score": None,
            "State": None,
            "Resumo do proxy": None,
        })

    return {
        "host_rows": sorted(host_rows, key=lambda row: row["Nome"] or ""),
        "process_rows": sorted(process_rows, key=lambda row: row.get("Media 30d") or -1, reverse=True),
        "proxy_config_rows": sorted(proxy_config_rows, key=lambda row: (row["Host"], row["Key"])),
        "process_config_rows": sorted(process_config_rows, key=lambda row: (row["Host"], row["Processo"])),
        "cache_config_rows": sorted(cache_config_rows, key=lambda row: (row["Host"], row["Cache"])),
        "active_problems": active_problems,
        "orphan_problems": orphan_problems,
        "trigger_by_id": trigger_by_id,
        "trigger_to_host": trigger_to_host,
    }


def add_sheet(wb, title, subtitle, last_col="H"):
    ws = wb.create_sheet(title)
    ws.sheet_view.showGridLines = False
    ws.merge_cells(f"A1:{last_col}1")
    ws["A1"] = title
    ws["A1"].fill = PatternFill("solid", fgColor="1F2937")
    ws["A1"].font = Font(color="FFFFFF", bold=True, size=14)
    ws.merge_cells(f"A2:{last_col}2")
    ws["A2"] = subtitle
    ws["A2"].fill = PatternFill("solid", fgColor="F3F4F6")
    ws["A2"].font = Font(color="374151", size=10)
    return ws


def write_table(ws, start_row, rows, table_name, style="TableStyleMedium2"):
    if not rows:
        return
    headers = list(rows[0].keys())
    for col_idx, header in enumerate(headers, 1):
        ws.cell(start_row, col_idx, header)
    for row_idx, row in enumerate(rows, start_row + 1):
        for col_idx, header in enumerate(headers, 1):
            ws.cell(row_idx, col_idx, row.get(header))
    end_row = start_row + len(rows)
    end_col = len(headers)
    ref = f"A{start_row}:{get_column_letter(end_col)}{end_row}"
    table = Table(displayName=table_name, ref=ref)
    table.tableStyleInfo = TableStyleInfo(name=style, showRowStripes=True, showColumnStripes=False)
    ws.add_table(table)
    ws.freeze_panes = f"A{start_row + 1}"
    for col in ws.columns:
        col_letter = get_column_letter(col[0].column)
        max_len = max(len(str(cell.value)) if cell.value is not None else 0 for cell in col[:80])
        ws.column_dimensions[col_letter].width = min(max(max_len + 2, 10), 55)


def set_date_formats(ws):
    for row in ws.iter_rows():
        for cell in row:
            if isinstance(cell.value, datetime):
                cell.number_format = "yyyy-mm-dd hh:mm"


def apply_readability_layout(wb):
    widths = {
        ("Overview", "L"): 70,
        ("Host Health", "AE"): 75,
        ("Process vs Config", "D"): 72,
        ("Cache vs Config", "K"): 58,
        ("Methodology", "C"): 58,
        ("Methodology", "D"): 58,
        ("Raw Items", "K"): 45,
        ("Proxy Config", "H"): 45,
    }
    for (sheet_name, column), width in widths.items():
        if sheet_name in wb.sheetnames:
            ws = wb[sheet_name]
            ws.column_dimensions[column].width = width
            for cell in ws[column]:
                cell.alignment = Alignment(vertical="top", wrap_text=True)


def process_recommendation_count_formula(row, process_last):
    return (
        f'IF(Config!$B$24="Sim",'
        f'COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{row},\'Process vs Config\'!$C$5:$C${process_last},"Avaliar aumento")+'
        f'COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{row},\'Process vs Config\'!$C$5:$C${process_last},"Avaliar diminuicao"),0)'
    )


def process_recommendation_details_formula(row, process_last):
    process_names = list(dict.fromkeys(PROCESS_CONFIG_MAP.keys()))
    process_parts = [
        f'IF(COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{row},\'Process vs Config\'!$B$5:$B${process_last},"{name}",\'Process vs Config\'!$C$5:$C${process_last},"Avaliar aumento")>0,"{name}: aumentar pollers; ","")&'
        f'IF(COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{row},\'Process vs Config\'!$B$5:$B${process_last},"{name}",\'Process vs Config\'!$C$5:$C${process_last},"Avaliar diminuicao")>0,"{name}: diminuir pollers; ","")'
        for name in process_names
    ]
    return f'IF(Config!$B$24="Sim",{"&".join(process_parts)},"")'


def config_score_details_formula(row, cache_last):
    cache_names = list(dict.fromkeys(cache_name for cache_name, _config_key, _bytes_key, _recommended_key, _candidates in CACHE_CONFIG_MAP))
    cache_parts = [
        f'IF(COUNTIFS(\'Cache vs Config\'!$A$5:$A${cache_last},C{row},\'Cache vs Config\'!$B$5:$B${cache_last},"{name}",\'Cache vs Config\'!$J$5:$J${cache_last},"Avaliar ajuste")>0,"{name} acima do threshold de caches; ","")'
        for name in cache_names
    ]
    return f'IF(Config!$B$21="Sim",{"&".join(cache_parts)},"")'


def process_config_status(row, poller_threshold=75):
    configured = positive_number(row.get("Valor configurado"))
    recommended = positive_number(row.get("Valor recomendado"))
    current = to_number(row.get("Busy atual %")) or 0
    avg30d = to_number(row.get("Busy media 30d %")) or 0
    if configured is None:
        return "Sem mapeamento"
    if current == 0 and avg30d == 0 and configured > 1:
        return "Avaliar diminuicao"
    if current > poller_threshold or avg30d > poller_threshold:
        return "Avaliar aumento"
    if recommended is not None and configured > recommended and avg30d < 50:
        return "Avaliar diminuicao"
    return "OK"


def process_config_action(row, status):
    configured = positive_number(row.get("Valor configurado"))
    recommended = positive_number(row.get("Valor recomendado"))
    current = to_number(row.get("Busy atual %")) or 0
    avg30d = to_number(row.get("Busy media 30d %")) or 0
    if status == "Avaliar aumento":
        return f"Aumentar numero de pollers (configurado={configured:g}, recomendado={recommended:g})" if recommended is not None else f"Aumentar numero de pollers (configurado={configured:g})"
    if status == "Avaliar diminuicao":
        if configured is not None and current == 0 and avg30d == 0 and configured > 1:
            return f"Diminuir numero de pollers para 1 (sem uso atual ou media 30d; configurado={configured:g})"
        return f"Diminuir numero de pollers (configurado={configured:g}, recomendado={recommended:g})" if recommended is not None else f"Diminuir numero de pollers (configurado={configured:g})"
    return ""


def cache_config_status(row, cache_threshold=75):
    current = to_number(row.get("Uso atual %")) or 0
    avg30d = to_number(row.get("Uso media 30d %")) or 0
    return "Avaliar ajuste" if current > cache_threshold or avg30d > cache_threshold else "OK"


def cache_config_action(row, status):
    if status != "Avaliar ajuste":
        return ""
    configured = positive_number(row.get("Valor configurado bytes"))
    recommended = positive_number(row.get("Valor recomendado bytes"))
    if configured is not None and recommended is not None:
        return f"Avaliar aumento de cache (configurado={configured:g} bytes, recomendado={recommended:g} bytes)"
    return "Avaliar aumento de cache"


def build_workbook(data, output_xlsx):
    rows = build_rows(data)
    for row in rows["process_config_rows"]:
        row["Status"] = process_config_status(row)
        row["Acao sugerida"] = process_config_action(row, row["Status"])
    for row in rows["cache_config_rows"]:
        row["Status"] = cache_config_status(row)
        row["Acao sugerida"] = cache_config_action(row, row["Status"])

    wb = Workbook()
    wb.remove(wb.active)
    collected_at = data["collected_at"]

    config = add_sheet(wb, "Config", "Edite os valores na coluna B para recalcular o score e os states.", "D")
    config_rows = [
        {"Parametro": "Versao de corte", "Valor": "7.0.20", "Unidade": "texto", "Uso": "Referencia humana para o assessment."},
        {"Parametro": "Patch minimo", "Valor": 20, "Unidade": "patch", "Uso": "Para Zabbix 7.0.x, versoes com patch menor sao penalizadas."},
        {"Parametro": "Unsupported maximo", "Valor": 0.02, "Unidade": "%", "Uso": "Itens unsupported / total de itens monitorados pelo proxy."},
        {"Parametro": "VPS atual maximo", "Valor": 300, "Unidade": "valores/s", "Uso": "Limite para zabbix[wcache,values] atual."},
        {"Parametro": "CPU atual maxima", "Valor": 85, "Unidade": "%", "Uso": "Utilizacao atual de CPU do host."},
        {"Parametro": "CPU media 30d maxima", "Valor": 75, "Unidade": "%", "Uso": "Media 30d de CPU do host."},
        {"Parametro": "Memoria atual maxima", "Valor": 85, "Unidade": "%", "Uso": "Uso atual de memoria."},
        {"Parametro": "Memoria media 30d maxima", "Valor": 80, "Unidade": "%", "Uso": "Media 30d de memoria."},
        {"Parametro": "Disco atual maximo", "Valor": 85, "Unidade": "%", "Uso": "Uso atual do filesystem selecionado."},
        {"Parametro": "Disco media 30d maximo", "Valor": 80, "Unidade": "%", "Uso": "Media 30d do filesystem selecionado."},
        {"Parametro": "Fila 10m maxima", "Valor": 0, "Unidade": "itens", "Uso": "Itens sem dados por 10 minutos."},
        {"Parametro": "Preproc queue maxima", "Valor": 50, "Unidade": "valores", "Uso": "Fila de preprocessing."},
        {"Parametro": "Considerar Problemas orfaos?", "Valor": "Nao", "Unidade": "Sim/Nao", "Uso": "Se Sim, problems orfaos entram no score."},
        {"Parametro": "Score OK >=", "Valor": 90, "Unidade": "pontos", "Uso": "Classificacao OK."},
        {"Parametro": "Score Atencao >=", "Valor": 70, "Unidade": "pontos", "Uso": "Classificacao Atencao."},
        {"Parametro": "Score Risco >=", "Valor": 40, "Unidade": "pontos", "Uso": "Classificacao Risco."},
        {"Parametro": "Considerar configuracao do Proxy?", "Valor": "Nao", "Unidade": "Sim/Nao", "Uso": "Se Sim, processos/caches acima dos thresholds penalizam o score."},
        {"Parametro": "Threshold pollers", "Valor": 75, "Unidade": "%", "Uso": "Limite de busy atual ou media 30d nos processos mapeados."},
        {"Parametro": "Threshold caches", "Valor": 75, "Unidade": "%", "Uso": "Limite de uso atual ou media 30d dos caches."},
        {"Parametro": "Mostrar recomendacoes Process vs Config no resumo?", "Valor": "Sim", "Unidade": "Sim/Nao", "Uso": "Se Sim, recomendacoes de aumento/diminuicao de pollers aparecem no resumo sem alterar o score."},
    ]
    write_table(config, 4, config_rows, "ConfigTable", "TableStyleMedium4")
    dv = DataValidation(type="list", formula1='"Sim,Nao"')
    config.add_data_validation(dv)
    dv.add(config["B17"])
    dv.add(config["B21"])
    dv.add(config["B24"])

    overview = add_sheet(wb, "Overview", f"Coleta: {collected_at} | Hosts online avaliados: {len(rows['host_rows'])}", "L")
    overview_headers = ["Host", "State", "Score", "Versao", "Unsupported %", "VPS atual", "CPU media 30d %", "Memoria total GB", "Memoria atual %", "Memoria media 30d %", "Disco atual %", "Resumo geral do proxy"]
    overview.append([])
    for col_idx, header in enumerate(overview_headers, 1):
        overview.cell(4, col_idx, header)

    process_summary_names = list(dict.fromkeys(PROCESS_CONFIG_MAP.keys()))
    process_summary_columns = {
        name: get_column_letter(35 + idx)
        for idx, name in enumerate(process_summary_names)
    }
    helper_last_col = get_column_letter(34 + len(process_summary_names))

    host_health = add_sheet(wb, "Host Health", "Score recalculavel com base na aba Config. Proxies offline/desabilitados foram desconsiderados.", helper_last_col)
    write_table(host_health, 4, rows["host_rows"], "HostHealthTableV2")
    helper_headers = {
        "AF": "Resumo base",
        "AG": "Resumo orfaos",
        "AH": "Resumo cache",
    }
    helper_headers.update({
        column: f"Resumo {name}"
        for name, column in process_summary_columns.items()
    })
    for column, header in helper_headers.items():
        host_health[f"{column}4"] = header
        host_health.column_dimensions[column].hidden = True

    process_last = 4 + max(len(rows["process_config_rows"]), 1)
    cache_last = 4 + max(len(rows["cache_config_rows"]), 1)
    first = 5
    last = 4 + len(rows["host_rows"])
    for r in range(first, last + 1):
        host_health[f"AB{r}"] = f'=COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{r},\'Process vs Config\'!$C$5:$C${process_last},"Avaliar aumento")+COUNTIFS(\'Cache vs Config\'!$A$5:$A${cache_last},C{r},\'Cache vs Config\'!$J$5:$J${cache_last},"Avaliar ajuste")'
        host_health[f"AC{r}"] = f'=MAX(0,100-IF(Y{r}>0,50,0)-IF(X{r}>0,20,0)-IF(Config!$B$17="Sim",IF(AA{r}>0,50,0)+IF(Z{r}>0,20,0),0)-IF(Config!$B$21="Sim",IF(AB{r}>0,15,0),0)-IF(F{r}<Config!$B$6,15,0)-IF(L{r}>Config!$B$7,15,0)-IF(M{r}>Config!$B$8,10,0)-IF(P{r}>Config!$B$9,10,0)-IF(Q{r}>Config!$B$10,10,0)-IF(S{r}>Config!$B$11,10,0)-IF(T{r}>Config!$B$12,10,0)-IF(V{r}>Config!$B$13,10,0)-IF(W{r}>Config!$B$14,10,0)-IF(N{r}>Config!$B$15,10,0)-IF(O{r}>Config!$B$16,10,0))'
        host_health[f"AD{r}"] = f'=IF(AC{r}>=Config!$B$18,"OK",IF(AC{r}>=Config!$B$19,"Atencao",IF(AC{r}>=Config!$B$20,"Risco","Critico")))'
        host_health[f"AF{r}"] = f'=IF(Y{r}>0,"Alerta Disaster ativo; ","")&IF(X{r}>0,"Alerta de saude do proxy ativo; ","")&IF(F{r}<Config!$B$6,"Versao abaixo do corte; ","")&IF(L{r}>Config!$B$7,"Itens unsupported acima do limite; ","")&IF(M{r}>Config!$B$8,"VPS atual acima do limite; ","")&IF(P{r}>Config!$B$9,"CPU atual alta; ","")&IF(Q{r}>Config!$B$10,"CPU media 30d alta; ","")&IF(S{r}>Config!$B$11,"Memoria atual alta; ","")&IF(T{r}>Config!$B$12,"Memoria media 30d alta; ","")&IF(V{r}>Config!$B$13,"Disco atual alto; ","")&IF(W{r}>Config!$B$14,"Disco media 30d alta; ","")&IF(N{r}>Config!$B$15,"Fila 10m acima do limite; ","")&IF(O{r}>Config!$B$16,"Preprocessing queue acima do limite; ","")'
        host_health[f"AG{r}"] = f'=IF(Config!$B$17="Sim",IF(AA{r}>0,"Alerta Disaster orfao considerado; ","")&IF(Z{r}>0,"Alerta de saude orfao considerado; ",""),"")'
        cache_parts = [
            f'IF(COUNTIFS(\'Cache vs Config\'!$A$5:$A${cache_last},C{r},\'Cache vs Config\'!$B$5:$B${cache_last},"{cache_name}",\'Cache vs Config\'!$J$5:$J${cache_last},"Avaliar ajuste")>0,"{cache_name} acima do threshold de caches; ","")'
            for cache_name in dict.fromkeys(cache_name for cache_name, *_rest in CACHE_CONFIG_MAP)
        ]
        host_health[f"AH{r}"] = f'=IF(Config!$B$21="Sim",{"&".join(cache_parts)},"")'
        for process_name, column in process_summary_columns.items():
            host_health[f"{column}{r}"] = f'=IF(Config!$B$24="Sim",IF(COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{r},\'Process vs Config\'!$B$5:$B${process_last},"{process_name}",\'Process vs Config\'!$C$5:$C${process_last},"Avaliar aumento")>0,"{process_name}: aumentar pollers; ","")&IF(COUNTIFS(\'Process vs Config\'!$A$5:$A${process_last},C{r},\'Process vs Config\'!$B$5:$B${process_last},"{process_name}",\'Process vs Config\'!$C$5:$C${process_last},"Avaliar diminuicao")>0,"{process_name}: diminuir pollers; ",""),"")'
        summary_cells = ["AF", "AG", "AH", *process_summary_columns.values()]
        summary_join = "&".join(f"{column}{r}" for column in summary_cells)
        host_health[f"AE{r}"] = f'=IF({summary_join}="","Proxy dentro dos parametros configurados",{summary_join})'

    for idx, _host_row in enumerate(rows["host_rows"], 5):
        overview.append([
            f"='Host Health'!C{idx}",
            f"='Host Health'!AD{idx}",
            f"='Host Health'!AC{idx}",
            f"='Host Health'!E{idx}",
            f"='Host Health'!L{idx}",
            f"='Host Health'!M{idx}",
            f"='Host Health'!Q{idx}",
            f"='Host Health'!U{idx}",
            f"='Host Health'!S{idx}",
            f"='Host Health'!T{idx}",
            f"='Host Health'!V{idx}",
            f"='Host Health'!AE{idx}",
        ])
    table = Table(displayName="OverviewAllProxies", ref=f"A4:L{4 + len(rows['host_rows'])}")
    table.tableStyleInfo = TableStyleInfo(name="TableStyleMedium2", showRowStripes=True)
    overview.add_table(table)

    proxy_config = add_sheet(wb, "Proxy Config", "Leituras atuais dos itens de configuracao num.* coletados nos hosts avaliados.", "H")
    write_table(proxy_config, 4, rows["proxy_config_rows"] or [{"Host": "", "Config item": "", "Key": "", "Valor": None, "Unidade": "", "Ultima coleta": None, "Estado": "", "Erro": ""}], "ProxyConfigV1")

    process_config = add_sheet(wb, "Process vs Config", "Compara utilizacao dos processos internos do proxy com parametros coletados.", "I")
    write_table(process_config, 4, rows["process_config_rows"] or [{"Host": "", "Processo": "", "Status": "", "Acao sugerida": "", "Busy atual %": None, "Busy media 30d %": None, "Valor configurado": None, "Valor recomendado": None, "Ultima coleta config": None}], "ProcessVsConfigV1")
    for r in range(5, 5 + max(len(rows["process_config_rows"]), 1)):
        process_config[f"C{r}"] = f'=IF(G{r}="","Sem mapeamento",IF(AND(E{r}=0,F{r}=0,G{r}>1),"Avaliar diminuicao",IF(OR(E{r}>Config!$B$22,F{r}>Config!$B$22),"Avaliar aumento",IF(AND(H{r}<>"",G{r}>H{r},F{r}<50),"Avaliar diminuicao","OK"))))'
        process_config[f"D{r}"] = f'=IF(C{r}="Avaliar aumento","Aumentar numero de pollers (configurado="&G{r}&", recomendado="&H{r}&")",IF(C{r}="Avaliar diminuicao",IF(AND(E{r}=0,F{r}=0,G{r}>1),"Diminuir numero de pollers para 1 (sem uso atual ou media 30d; configurado="&G{r}&")","Diminuir numero de pollers (configurado="&G{r}&", recomendado="&H{r}&")"),""))'

    cache_config = add_sheet(wb, "Cache vs Config", "Compara uso dos caches do proxy com parametros coletados.", "M")
    write_table(cache_config, 4, rows["cache_config_rows"] or [{"Host": "", "Cache": "", "Cache key": "", "Uso atual %": None, "Uso media 30d %": None, "Parametro config": "", "Valor configurado": None, "Valor configurado bytes": None, "Valor recomendado bytes": None, "Status": "", "Acao sugerida": "", "Ultima coleta cache": None, "Ultima coleta config": None}], "CacheVsConfigV1")
    for r in range(5, 5 + max(len(rows["cache_config_rows"]), 1)):
        cache_config[f"J{r}"] = f'=IF(OR(D{r}>Config!$B$23,E{r}>Config!$B$23),"Avaliar ajuste","OK")'
        cache_config[f"K{r}"] = f'=IF(J{r}="Avaliar ajuste",IF(F{r}="","Cache acima do threshold; parametro nao mapeado no template de configuracao",IF(I{r}<>"","Avaliar ajuste de "&F{r}&" (configurado="&G{r}&"; recomendado bytes="&I{r}&")","Avaliar ajuste de "&F{r}&" (configurado="&G{r}&")")),"Dentro dos limites configurados")'

    methodology = add_sheet(wb, "Methodology", "Score focado na saude do proxy, nao na quantidade bruta de problemas dos hosts monitorados por ele.", "D")
    methodology_rows = [
        {"Tema": "Escopo", "Criterio": "Proxies online", "Descricao tecnica simples": "Considera hosts habilitados dentro do host group informado.", "Como interpretar": "Overview representa os proxies avaliaveis no momento da coleta."},
        {"Tema": "Configuracao do proxy", "Criterio": "Config!B21", "Descricao tecnica simples": "Se Sim, Process vs Config e Cache vs Config entram no score.", "Como interpretar": "Com Nao, mantem score sem penalizacao por configuracao."},
        {"Tema": "Configuracao coletada", "Criterio": "Itens num.*", "Descricao tecnica simples": "As leituras de configuracao sao coletadas diretamente dos hosts do grupo, procurando itens com chave num.*.", "Como interpretar": "Dispensa informar o template de configuracao, desde que os itens estejam linkados aos proxies."},
        {"Tema": "Thresholds", "Criterio": "B22/B23", "Descricao tecnica simples": "Pollers e caches usam threshold default de 75%.", "Como interpretar": "Altere conforme a politica operacional."},
        {"Tema": "SO", "Criterio": "Capacidade do host", "Descricao tecnica simples": "CPU, load por core, memoria total, memoria usada e disco raiz sao incluidos para correlacionar carga do proxy com capacidade do sistema operacional.", "Como interpretar": "Memoria total e apoio de capacidade; uso percentual segue como criterio de score."},
        {"Tema": "Disco", "Criterio": "Fallback", "Descricao tecnica simples": data.get("disk_selection_note", "Usa vfs.fs.size[/,pused] quando disponivel."), "Como interpretar": "Hosts sem item percentual de disco permanecem vazios em V/W."},
    ]
    write_table(methodology, 4, methodology_rows, "MethodologyTableV2")

    process_sheet = add_sheet(wb, "Process 30d", "Media e maximo 30d dos processos internos de cada proxy.", "J")
    write_table(process_sheet, 4, rows["process_rows"] or [{"Host": "", "Processo": "", "Key": "", "Busy atual": None, "Media 30d": None, "Maximo 30d": None, "Ultima coleta": None, "Estado": "", "Erro": ""}], "Process30dTable")

    host_by_id = {host["hostid"]: host for host in data["hosts"]}
    active_problem_rows = []
    for problem in rows["active_problems"]:
        host = host_by_id.get(rows["trigger_to_host"].get(problem.get("objectid")), {})
        active_problem_rows.append({
            "Host": host.get("name") or host.get("host") or "",
            "Severidade": severity_name(problem.get("severity")),
            "Sev. num": int(problem.get("severity") or 0),
            "Problema": problem.get("name"),
            "Relevante": "Sim" if is_relevant_problem(problem) else "Nao",
            "Aberto em": excel_dt(problem.get("clock")),
            "Idade": age_text(datetime.fromisoformat(data["collected_at"]), problem.get("clock")),
            "Reconhecido": "Sim" if problem.get("acknowledged") == "1" else "Nao",
            "Event ID": problem.get("eventid"),
            "Trigger ID": problem.get("objectid"),
        })
    active_sheet = add_sheet(wb, "Active Problems", "Problemas ativos validos; problemas orfaos foram movidos para Orphan Problems.", "K")
    write_table(active_sheet, 4, active_problem_rows or [{"Host": "", "Severidade": "", "Sev. num": None, "Problema": "Sem problemas ativos", "Relevante": "", "Aberto em": None, "Idade": "", "Reconhecido": "", "Event ID": "", "Trigger ID": ""}], "ActiveProblemsV2")

    orphan_rows = []
    for problem in rows["orphan_problems"]:
        trigger = rows["trigger_by_id"].get(problem.get("objectid"), {})
        host = (trigger.get("hosts") or [{}])[0]
        orphan_rows.append({
            "Host": host.get("name") or host.get("host") or "",
            "Severidade": severity_name(problem.get("severity")),
            "Sev. num": int(problem.get("severity") or 0),
            "Problema": problem.get("name"),
            "Motivo orfao": orphan_reason(problem, rows["trigger_by_id"]),
            "Trigger status": trigger.get("status") or "",
            "Trigger state": trigger.get("state") or "",
            "Itens associados": "; ".join(f"{item.get('key_') or item.get('name')} [status={item.get('status')}, state={item.get('state')}]" for item in trigger.get("items", []) or []),
            "Aberto em": excel_dt(problem.get("clock")),
            "Idade": age_text(datetime.fromisoformat(data["collected_at"]), problem.get("clock")),
            "Event ID": problem.get("eventid"),
            "Trigger ID": problem.get("objectid"),
        })
    orphan_sheet = add_sheet(wb, "Orphan Problems", "Problems ativos ligados a trigger/item desabilitado ou contexto invalido.", "L")
    write_table(orphan_sheet, 4, orphan_rows or [{"Host": "", "Severidade": "", "Sev. num": None, "Problema": "Sem problemas orfaos identificados", "Motivo orfao": "", "Trigger status": "", "Trigger state": "", "Itens associados": "", "Aberto em": None, "Idade": "", "Event ID": "", "Trigger ID": ""}], "OrphanProblemsV1")

    raw = add_sheet(wb, "Raw Items", "Itens brutos coletados via API para auditoria.", "L")
    raw_rows = []
    host_by_id = {host["hostid"]: host for host in data["hosts"]}
    for item in data.get("items", []):
        trend = data.get("trends30d", {}).get(item["itemid"], {})
        host = host_by_id.get(item["hostid"], {})
        raw_rows.append({
            "Host": host.get("name") or item["hostid"],
            "Item ID": item["itemid"],
            "Nome": item.get("name"),
            "Key": item.get("key_"),
            "Valor": to_number(item.get("lastvalue")) if to_number(item.get("lastvalue")) is not None else item.get("lastvalue"),
            "Unidade": item.get("units") or "",
            "Ultima coleta": excel_dt(item.get("lastclock")),
            "Media 30d": trend.get("avg30d"),
            "Maximo 30d": trend.get("max30d"),
            "Estado": "Normal" if item.get("state") == "0" else "Not supported",
            "Erro": item.get("error") or "",
        })
    write_table(raw, 4, raw_rows, "RawItemsV2")

    for ws in wb.worksheets:
        set_date_formats(ws)
        for row in ws.iter_rows():
            for cell in row:
                cell.font = Font(name="Aptos", size=10, bold=cell.font.bold, color=cell.font.color)
                cell.alignment = Alignment(vertical="top", wrap_text=False)
    apply_readability_layout(wb)
    if hasattr(wb, "calculation"):
        wb.calculation.calcMode = "auto"
        wb.calculation.calcId = 0
        wb.calculation.fullCalcOnLoad = True
        wb.calculation.forceFullCalc = True

    output_xlsx.parent.mkdir(parents=True, exist_ok=True)
    wb.save(output_xlsx)


def validate_xlsx(path):
    with zipfile.ZipFile(path) as archive:
        bad = archive.testzip()
        if bad:
            raise RuntimeError(f"Invalid XLSX zip member: {bad}")
        worksheet_xml = "\n".join(
            archive.read(name).decode("utf-8", errors="ignore")
            for name in archive.namelist()
            if name.startswith("xl/worksheets/sheet")
        )
        markers = ["#REF!", "#DIV/0!", "#VALUE!", "#NAME?", "#N/A"]
        found = [marker for marker in markers if marker in worksheet_xml]
        if found:
            raise RuntimeError(f"Formula error markers found in XLSX: {found}")


def main():
    parser = argparse.ArgumentParser(description="Standalone Zabbix Proxy Health Assessment v3.0.")
    parser.add_argument("--api-url", required=True, help="Base Zabbix URL or full api_jsonrpc.php URL.")
    parser.add_argument("--token", required=True)
    parser.add_argument("--host-group", default="Zabbix/Proxies", help="Host group containing the proxy hosts. Default: Zabbix/Proxies.")
    parser.add_argument("--template-id", help="Optional template ID used as an additional host filter.")
    parser.add_argument("--input-json", help="Optional previously collected JSON to rebuild the workbook without API collection.")
    parser.add_argument("--exclude-host", action="append", default=[], help="Host technical name or visible name to exclude. Can be used more than once.")
    parser.add_argument("--output-xlsx", required=True)
    parser.add_argument("--output-json", help="Optional intermediate JSON path.")
    parser.add_argument("--skip-disk", action="store_true")
    parser.add_argument("--skip-validate", action="store_true")
    args = parser.parse_args()

    zabbix_url = normalize_zabbix_url(args.api_url)
    output_xlsx = Path(args.output_xlsx).resolve()
    output_json = Path(args.output_json).resolve() if args.output_json else output_xlsx.with_suffix(".json")

    print("Zabbix Proxy Health Assessment v3.0 standalone")
    print(f"zabbix_url={zabbix_url}")
    print(f"host_group={args.host_group}")
    if args.template_id:
        print(f"template_id={args.template_id}")
    print(f"output_xlsx={output_xlsx}")

    if args.input_json:
        input_json = Path(args.input_json).resolve()
        print(f"Loading collected data from {input_json}")
        data = json.loads(input_json.read_text(encoding="utf-8"))
        print(f"hosts={len(data.get('hosts', []))} items={len(data.get('items', []))} problems={len(data.get('problems', []))}")
    else:
        print("Connecting with zabbix_utils.ZabbixAPI...")
        api = connect_zabbix(zabbix_url, args.token)

        print("Collecting proxy health data...")
        data = collect_base(api, zabbix_url, args.host_group, args.template_id)
        print(f"hosts={len(data['hosts'])} items={len(data['items'])} problems={len(data['problems'])}")

        print("Collecting proxy configuration data...")
        collect_proxy_config(api, data)
        print(f"config_items={len(data.get('proxy_config_items', []))}")

        if not args.skip_disk:
            print("Collecting disk fallback data...")
            enrich_disk_metrics(api, data)
            disk_count = len([item for item in data["items"] if item.get("key_") == "vfs.fs.size[/,pused]"])
            print(f"disk_selected_hosts={disk_count}")

    removed = filter_data_hosts(data, args.exclude_host)
    if removed:
        print("excluded_hosts=" + ", ".join(host.get("name") or host.get("host") for host in removed))
        print(f"hosts_after_exclusion={len(data.get('hosts', []))}")

    output_json.parent.mkdir(parents=True, exist_ok=True)
    output_json.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"json={output_json}")

    print("Building XLSX...")
    build_workbook(data, output_xlsx)
    if not args.skip_validate:
        validate_xlsx(output_xlsx)
    print(f"xlsx={output_xlsx}")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        sys.exit(1)
