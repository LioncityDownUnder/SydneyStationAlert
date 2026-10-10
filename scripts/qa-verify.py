#!/usr/bin/env python3
"""Read-only QA diagnostics smoke verification. No credentials or private URLs."""
import json, os, sys, urllib.parse, urllib.request
from collections import Counter
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
    # Step 5I: independently inspect departure-monitor discovery without changing route selection.
    discovery=get("discover",**{"from":a["id"],"to":b["id"],"fromName":a["name"],"toName":b["name"]})
    windows=[]
    for w in discovery.get("trace",[]):
        reasons=Counter(reason for c in w.get("candidates",[]) for reason in c.get("reasons",[]) if c.get("status")=="excluded")
        modes=dict(Counter(str((c.get("firstDeparture") or {}).get("mode")) for c in w.get("candidates",[])))
        windows.append({"window":w.get("window"),"returnedCount":w.get("returnedCount"),"parsedCandidates":len(w.get("candidates",[])),"modes":modes,"exclusionReasons":dict(reasons),"decision":w.get("decision"),"upstreamResponseType":type(w.get("rawResponse")).__name__,"upstreamKeys":list(w.get("rawResponse",{}).keys()) if isinstance(w.get("rawResponse"),dict) else []})
    evidence={"snapshotAt":discovery.get("snapshotAt"),"candidateCount":discovery.get("candidateCount"),"windows":windows}
    # Step 5J: summarize TfNSW messages, resolved stops, and nonsecret request parameters.
    for i, window in enumerate(discovery.get("trace",[])):
        raw=window.get("rawResponse")
        if not isinstance(raw,dict): continue
        evidence["windows"][i]["systemMessages"]=raw.get("systemMessages",[])
        locations=raw.get("locations",[])
        evidence["windows"][i]["locations"]=locations[:5] if isinstance(locations,list) else locations
        allowed=("type_dm","name_dm","mode","itdDate","itdTime","limit","TfNSWDM","excludedMeans","exclMOT_4","exclMOT_5","exclMOT_7","exclMOT_9","exclMOT_11")
        evidence["windows"][i]["request"]={k:v for k,v in window.get("request",{}).items() if k in allowed}
    # Step 5K baseline: identify the transport-filter settings used in each request.
    for w in evidence["windows"]:
        request=w.get("request",{})
        w["filterSummary"]={"excludedMeans":request.get("excludedMeans"),"excludedModeIds":sorted(k for k in request if k.startswith("exclMOT_"))}
    print("STEP 5J RESPONSE: "+json.dumps(evidence["windows"],ensure_ascii=False,default=str),flush=True)
    with open(OUT+"/step5i-departure-monitor.json","w") as f:json.dump(evidence,f,indent=2)
    print("STEP 5I DEPARTURE MONITOR: "+json.dumps(evidence),flush=True)
    check("Step 5I discovery evidence",len(windows)>0,str({"windows":len(windows),"rawCounts":[w["returnedCount"] for w in windows],"eligible":discovery.get("candidateCount")}))
    data=get("compare",**{"from":a["id"],"to":b["id"],"fromName":a["name"],"toName":b["name"]})
    with open(OUT+"/step5-comparison.json","w") as f:json.dump(data,f,indent=2)
    independent=data.get("independent",{})
    check("Step 5 response version",data.get("version")=="5G",str(data.get("version")))
    # Step 5H: explain empty independent shortlists from per-departure GTFS matching.
    evaluations=independent.get("evaluations",[])
    from collections import Counter
    status_counts=dict(Counter(str(e.get("status","UNKNOWN")) for e in evaluations))
    first_leg_counts=dict(Counter(str((e.get("firstLegEvidence") or {}).get("status","UNKNOWN")) for e in evaluations))
    investigation={"snapshotAt":data.get("snapshotAt"),"discoveredCandidates":independent.get("candidateCount"),"evaluatedCandidates":independent.get("evaluatedCandidateCount"),"candidateLimit":independent.get("candidateLimit"),"evaluationStatuses":status_counts,"firstLegStatuses":first_leg_counts,"evaluations":[{"candidateIndex":e.get("candidateIndex"),"status":e.get("status"),"firstLegEvidence":e.get("firstLegEvidence"),"returnedRoutes":e.get("returnedRoutes"),"rejectedConnections":e.get("rejectedConnections")} for e in evaluations]}
    with open(OUT+"/step5h-investigation.json","w") as f:json.dump(investigation,f,indent=2)
    print("STEP 5H DISCOVERY: "+json.dumps(investigation),flush=True)
    check("Step 5H candidate accounting",isinstance(independent.get("candidateCount"),int) and len(evaluations)==independent.get("evaluatedCandidateCount") and independent.get("evaluatedCandidateCount",0)<=independent.get("candidateLimit",10),str({"discovered":independent.get("candidateCount"),"evaluated":len(evaluations),"statuses":status_counts}))
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
    rejected=[p for p in data.get("legacyProbes",[]) if (p.get("rejectionReasons") if isinstance(p.get("rejectionReasons"),dict) else {}).get("UNSUPPORTED_TRANSPORT",0)>0]
    check("Unsupported transport class evidence",not rejected or all(p.get("unsupportedTransportSamples") for p in rejected),str([p.get("unsupportedTransportSamples",[]) for p in rejected]))
    rows=data.get("comparisons",[])
    check("Step 5E comparison classifications",all(row.get("matchStatus") in ("NO_LEGACY_CANDIDATE","DEPARTURE_NOT_COMPARABLE","ROUTE_STRUCTURE_DIFFERS","TIME_ALIGNED_STRUCTURE_MATCH") and "departureDifferenceSeconds" in row and "arrivalDifferenceSeconds" in row and "sameTransferStations" in row for row in rows),str([row.get("matchStatus") for row in rows]))
    check("Step 5E rejects distant legacy departures",all(row.get("nearestLegacyIndex") is None or row.get("nearestLegacyDepartureGapSeconds",999999)<=300 for row in rows),str([(row.get("matchStatus"),row.get("nearestLegacyDepartureGapSeconds")) for row in rows]))
    check("Step 5E bounded probes",1<=data.get("legacyProbeCount",0)<=3,str(data.get("legacyProbeCount")))
    check("Step 5F unmatched departure diagnostics",all(isinstance(row.get("departureDiagnostics"),dict) and row["departureDiagnostics"].get("diagnosis") in ("NO_RAIL_ONLY_LEGACY_REFERENCE","DEPARTURE_WINDOW_MISMATCH","WITHIN_FIVE_MINUTES_REVIEW_ROUTE") and isinstance(row["departureDiagnostics"].get("independentLegs"),list) for row in rows),str([(row.get("departureDiagnostics",{}).get("diagnosis"),row.get("departureDiagnostics",{}).get("closestLegacyGapSeconds")) for row in rows]))
    diagnoses=("NO_TIME_ALIGNED_LEGACY","TRANSFER_COUNT_DIFFERS","STATION_LABEL_FORMAT_DIFFERS","TRANSFER_STATIONS_DIFFER","TRANSFER_STRUCTURE_ALIGNED")
    check("Step 5G structural evidence",all(isinstance(row.get("structureEvidence"),dict) and row["structureEvidence"].get("diagnosis") in diagnoses and isinstance(row["structureEvidence"].get("independentLegs"),list) and isinstance(row["structureEvidence"].get("normalizedIndependentTransfers"),list) for row in rows),str([(row.get("structureEvidence",{}).get("diagnosis"),row.get("structureEvidence",{}).get("independentLegCount"),row.get("structureEvidence",{}).get("legacyLegCount")) for row in rows]))
    # Surface structural disagreements in the CI log for daytime review, without asserting train identity.
    for i,row in enumerate(rows,1):
        evidence=row.get("structureEvidence",{})
        print("STRUCTURE REVIEW "+str(i)+": "+json.dumps({
            "matchStatus":row.get("matchStatus"),
            "departureGapSeconds":row.get("nearestLegacyDepartureGapSeconds"),
            "diagnosis":evidence.get("diagnosis"),
            "independentLegCount":evidence.get("independentLegCount"),
            "legacyLegCount":evidence.get("legacyLegCount"),
            "independentTransfers":evidence.get("independentTransferStations"),
            "legacyTransfers":evidence.get("legacyTransferStations"),
            "normalizedIndependentTransfers":evidence.get("normalizedIndependentTransfers"),
            "normalizedLegacyTransfers":evidence.get("normalizedLegacyTransfers"),
            "legacyLines":evidence.get("legacyLines")
        },ensure_ascii=False),flush=True)
    check("Comparison rows match shortlist",len(data.get("comparisons",[]))==len(independent.get("ranked",[])),str(len(data.get("comparisons",[])))+" rows")
except Exception as e:
    check("QA diagnostic execution",False,type(e).__name__+": "+str(e))
with open(OUT+"/summary.json","w") as f:json.dump({"timestamp":datetime.now(timezone.utc).isoformat(),"results":results},f,indent=2)
with open(OUT+"/summary.md","w") as f:
    f.write("# QA diagnostic verification\n\n| Test | Result | Detail |\n|---|---|---|\n")
    for r in results:f.write("| "+r["test"]+" | "+("PASS" if r["passed"] else ("WARN" if r["advisory"] else "FAIL"))+" | "+r["detail"].replace("|","/").replace("\n"," ")+" |\n")
sys.exit(0 if all(r["passed"] or r["advisory"] for r in results) else 1)
