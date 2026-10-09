#!/usr/bin/env python3
"""Read-only QA diagnostics smoke verification. No credentials or private URLs."""
import json, os, sys, urllib.parse, urllib.request
from datetime import datetime, timezone

BASE="https://trains.nytnetwork.work/qatest/api/"
OUT="qa-verification"
os.makedirs(OUT,exist_ok=True)
results=[]
def check(name, ok, detail, advisory=False):
    results.append({"test":name,"passed":bool(ok),"advisory":advisory,"detail":detail})
    print(("PASS" if ok else ("WARN" if advisory else "FAIL"))+": "+name+" — "+detail,flush=True)
def get(action, **params):
    url=BASE+"diagnostics.php?"+urllib.parse.urlencode({"action":action,**params})
    with urllib.request.urlopen(urllib.request.Request(url,headers={"User-Agent":"SydneyStationAlert-QA-CI"}),timeout=160) as resp:
        return json.load(resp)["data"]
def station(name):
    data=get("stations",q=name)
    exact=next((s for s in data if s["name"].lower()==name.lower()),None)
    if not exact: exact=next((s for s in data if s["name"].lower().startswith(name.lower()+" station")),None)
    if not exact: raise ValueError("No exact station match: "+name)
    return exact
try:
    a,b=station("Hurstville"),station("Padstow")
    data=get("compare",**{"from":a["id"],"to":b["id"],"fromName":a["name"],"toName":b["name"]})
    with open(OUT+"/step5-comparison.json","w") as f:json.dump(data,f,indent=2)
    independent=data.get("independent",{})
    check("Step 5 response version",data.get("version")=="5D",str(data.get("version")))
    check("Independent engine returns shortlist",len(independent.get("ranked",[]))>0,str(len(independent.get("ranked",[])))+" journeys",advisory=True)
    complete=independent.get("searchCompleteness",{})
    check("All discovered departures evaluated",complete.get("evaluatedAllDiscoveredDepartures") is True,str(complete))
    check("No truncated per-departure routes",complete.get("perDepartureRoutesTruncated") is False,str(complete.get("perDepartureRoutesTruncated")))
    legacy=data.get("legacyJourneyCount",0)
    # Upstream legacy planner may legitimately return zero; preserve evidence and flag as blocked, not a passing comparison.
    check("Legacy comparison request completed",data.get("legacyStatus") in ("LEGACY_JOURNEYS_AVAILABLE","LEGACY_NO_USABLE_JOURNEYS"),str(data.get("legacyStatus")))
    if legacy == 0:
        check("Legacy route comparison inconclusive",False,"0 normalized journeys; raw="+str(data.get("legacyRawJourneyCount"))+"; investigate upstream response and normalization",advisory=True)
    check("Legacy probe diagnostics available",len(data.get("legacyProbes",[]))>=1,str(data.get("legacyProbes",[])))
    rejected=[p for p in data.get("legacyProbes",[]) if p.get("rejectionReasons",{}).get("UNSUPPORTED_TRANSPORT",0)>0]
    check("Unsupported transport class evidence",not rejected or all(p.get("unsupportedTransportSamples") for p in rejected),str([p.get("unsupportedTransportSamples",[]) for p in rejected]))
    rows=data.get("comparisons",[])
    check("Step 5D comparison classifications",all(row.get("matchStatus") in ("NO_LEGACY_CANDIDATE","DEPARTURE_NOT_COMPARABLE","ROUTE_STRUCTURE_DIFFERS","TIME_ALIGNED_STRUCTURE_MATCH") and "departureDifferenceSeconds" in row and "arrivalDifferenceSeconds" in row and "sameTransferStations" in row for row in rows),str([row.get("matchStatus") for row in rows]))
    check("Comparison rows match shortlist",len(data.get("comparisons",[]))==len(independent.get("ranked",[])),str(len(data.get("comparisons",[])))+" rows")
except Exception as e:
    check("QA diagnostic execution",False,type(e).__name__+": "+str(e))
with open(OUT+"/summary.json","w") as f:json.dump({"timestamp":datetime.now(timezone.utc).isoformat(),"results":results},f,indent=2)
with open(OUT+"/summary.md","w") as f:
    f.write("# QA diagnostic verification\n\n| Test | Result | Detail |\n|---|---|---|\n")
    for r in results:f.write("| "+r["test"]+" | "+("PASS" if r["passed"] else ("WARN" if r["advisory"] else "FAIL"))+" | "+r["detail"].replace("|","/").replace("\n"," ")+" |\n")
sys.exit(0 if all(r["passed"] or r["advisory"] for r in results) else 1)
