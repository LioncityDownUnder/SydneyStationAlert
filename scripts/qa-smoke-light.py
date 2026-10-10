#!/usr/bin/env python3
"""Bounded low-cost QA verification; extended diagnostics run separately on request."""
import json, os, time, urllib.error, urllib.request
from datetime import datetime, timezone
BASE="https://trains.nytnetwork.work/qatest/api/"
OUT="qa-verification"
os.makedirs(OUT,exist_ok=True)
checks=[]
def check(name, passed, detail):
    checks.append({"test":name,"passed":bool(passed),"detail":detail})
    print(("PASS: " if passed else "FAIL: ")+name+" — "+detail,flush=True)
def fetch(path, attempts=3):
    url=BASE+path
    for i in range(attempts):
        try:
            with urllib.request.urlopen(urllib.request.Request(url,headers={"User-Agent":"SydneyStationAlert-QA-SMOKE","Accept":"application/json"}),timeout=22) as resp:
                return json.load(resp)
        except urllib.error.HTTPError as error:
            if error.code!=429 or i==attempts-1: raise
            delay=min(12,3*(2**i))
            print("WARN: HTTP 429 on "+path.split("?")[0]+"; backoff "+str(delay)+"s, retry "+str(i+2)+"/"+str(attempts),flush=True)
            time.sleep(delay)
health=None
try:
    health=fetch("index.php?action=health")
    d=health.get("data",{})
    check("QA health",d.get("ok") is True and d.get("configured") is True,"Health and TfNSW configuration")
except Exception as e:
    check("QA health",False,type(e).__name__+": "+str(e))
try:
    response=fetch("diagnostics.php?action=probe_active_alerts")
    assessment=response.get("data",{}).get("sydneytrains",{}).get("assessment",{})
    counts=assessment.get("counts",{})
    valid=bool(assessment.get("asOf")) and isinstance(counts.get("total"),int) and isinstance(counts.get("active"),int)
    check("Active alert feed summary",valid,"total="+str(counts.get("total"))+" active="+str(counts.get("active"))+" matched="+str(counts.get("relevantActive")))
    with open(OUT+"/step5aa-alert-summary.json","w") as f:json.dump({"asOf":assessment.get("asOf"),"counts":counts},f,indent=2)
except Exception as e:
    check("Active alert feed summary",False,type(e).__name__+": "+str(e))
with open(OUT+"/summary.json","w") as f:json.dump({"timestamp":datetime.now(timezone.utc).isoformat(),"mode":"lightweight","results":checks},f,indent=2)
with open(OUT+"/summary.md","w") as f:
    f.write("# QA lightweight verification\n\n")
    for item in checks:f.write("- "+("PASS" if item["passed"] else "FAIL")+": "+item["test"]+" — "+item["detail"]+"\n")
raise SystemExit(0 if all(x["passed"] for x in checks) else 1)
