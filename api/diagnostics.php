<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__.'/lib/core.php';
// Diagnostic endpoint must never operate outside the isolated QA deployment.
if(!str_starts_with((string)($_SERVER['SCRIPT_NAME']??''),'/qatest/api/')){http_response_code(404);echo json_encode(['error'=>['code'=>'NOT_FOUND','message'=>'QA diagnostics only']]);exit;}

if(($_SERVER['REQUEST_METHOD']??'')!=='GET')fail('BAD_REQUEST','GET only',405);
if(($_GET['action']??'')==='stations'){
 $q=trim((string)($_GET['q']??''));if(mb_strlen($q)<2||mb_strlen($q)>70)fail('BAD_REQUEST','Enter 2-70 characters');
 echo json_encode(['data'=>station_search($q)],JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if(in_array(($_GET['action']??''),['discover','stops','service','gtfs','build','rank_static','validate','rank'],true)){
 require __DIR__.'/lib/discovery.php';
 if(in_array(($_GET['action']??''),['validate','rank'],true))require __DIR__.'/lib/validation.php';
 $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
 if(!preg_match('/^[\\w:-]{3,50}$/',$from)||!preg_match('/^[\\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Select two different stations');
 $origin=['id'=>$from,'name'=>substr((string)($_GET['fromName']??$from),0,100)];
 $destination=['id'=>$to,'name'=>substr((string)($_GET['toName']??$to),0,100)];
 try{$data=qa_discover($origin,$destination,new DateTimeImmutable('now',new DateTimeZone('Australia/Sydney')));if(($_GET['action']??'')==='gtfs'){
 $index=filter_var($_GET['candidate']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>24]]);
 if($index===false)fail('BAD_REQUEST','Candidate index must be 0–24');
 $candidate=val($data,'discovered',[])[$index]??null;
 if(!$candidate)fail('BAD_REQUEST','Candidate unavailable in current discovery snapshot');
 require __DIR__.'/lib/gtfs_diagnostic.php';
 $data=qa_gtfs_probe($candidate,$origin);
 }
 if(($_GET['action']??'')==='build'){
  $index=filter_var($_GET['candidate']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>24]]);
  if($index===false)fail('BAD_REQUEST','Candidate index must be 0–24');
  $candidate=val($data,'discovered',[])[$index]??null;
  if(!$candidate)fail('BAD_REQUEST','Candidate unavailable');
  require __DIR__.'/lib/gtfs_diagnostic.php';
  require __DIR__.'/lib/journey_builder.php';
  $data=qa_journey_build($candidate,$origin,$destination);
 }
 if(($_GET['action']??'')==='rank_static'){
  require __DIR__.'/lib/gtfs_diagnostic.php';
  require __DIR__.'/lib/journey_builder.php';
  require __DIR__.'/lib/journey_ranking.php';
  $data=qa_journey_rank_departures($data,$origin,$destination);
 }
 if(($_GET['action']??'')==='service'){
 $index=filter_var($_GET['candidate']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>24]]);
 if($index===false)fail('BAD_REQUEST','Candidate index must be 0–24');
 $candidate=val($data,'discovered',[])[$index]??null;
 if(!$candidate)fail('BAD_REQUEST','Candidate unavailable in current discovery snapshot; refresh Step 2');
 $first=val($candidate,'firstDeparture',[]);$ts=(int)val($candidate,'departureTimestamp',0);
 $at=(new DateTimeImmutable('@'.max(0,$ts-120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $params=['type_dm'=>'stop','name_dm'=>$from,'mode'=>'direct','itdDate'=>$at->format('Ymd'),'itdTime'=>$at->format('Hi'),'limit'=>100,'TfNSWDM'=>'true','includeCompleteStopSeq'=>1,'excludedMeans'=>'checkbox','exclMOT_4'=>1,'exclMOT_5'=>1,'exclMOT_7'=>1,'exclMOT_9'=>1,'exclMOT_11'=>1];
 $started=microtime(true);$response=timed_upstream('departure_mon',$params,0);$events=val($response,'stopEvents',[]);$matches=[];
 foreach(is_array($events)?$events:[] as $event){
  if(!is_array($event))continue;
  $transport=val($event,'transportation',[]);$ids=transportation_trip_ids($transport);
  $sameId=(bool)array_intersect($ids,val($first,'tripIds',[]));
  $time=iso_ts(val($event,'departureTimeEstimated'))??iso_ts(val($event,'departureTimePlanned'));
  $sameTime=$time!==null&&abs($time-$ts)<=120;
  $sameLine=(string)val($transport,'disassembledName',val($transport,'number',''))===(string)val($first,'line','');
  if(!$sameId&&!($sameTime&&$sameLine))continue;
  $seq=val($event,'stopSequence',[]);
  $matches[]=['tripIdMatch'=>$sameId,'timeAndLineMatch'=>$sameTime&&$sameLine,'tripIds'=>$ids,'sequenceCount'=>is_array($seq)?count($seq):0,'stopSequence'=>$seq,'destination'=>val($event,'destination'),'rawEvent'=>$event];
 }
 $hasStops=false;foreach($matches as $m)if($m['sequenceCount']>1)$hasStops=true;
 $data=['mode'=>'Step 2B service lookup probe','snapshotAt'=>gmdate('c'),'candidate'=>$candidate,'candidateIndex'=>$index,'matchedEvents'=>$matches,
 'status'=>!$matches?'SERVICE_NOT_FOUND_IN_PROBE':($hasStops?'STOP_SEQUENCE_PRESENT_UNVERIFIED':'STOP_SEQUENCE_NOT_EXPOSED'),
 'request'=>$params,'rawResponse'=>$response,'elapsedMs'=>(int)((microtime(true)-$started)*1000),
 'note'=>'One-service exploratory departure-monitor probe with includeCompleteStopSeq=1. Parameter support is unverified; this is NOT a documented trip-ID lookup and does not use /trip. If absent, GTFS static stop_times plus realtime trip updates are the next data source.'];
 }
 if(($_GET['action']??'')==='stops'){
 $rows=[];$started=microtime(true);
 foreach(array_slice(val($data,'discovered',[]),0,25) as $candidate){
  $event=val($candidate,'event',[]);$transport=val($event,'transportation',[]);
  $rawSequence=val($event,'stopSequence',[]);
  $destination=val($event,'destination',null);
  $properties=val($transport,'properties',[]);
  $rows[]=['identity'=>val($candidate,'identity'),'firstDeparture'=>val($candidate,'firstDeparture'),
   'destination'=>$destination,'transportationProperties'=>$properties,
   'eventKeys'=>array_keys(is_array($event)?$event:[]),
   'stopSequence'=>is_array($rawSequence)?$rawSequence:[],
   'status'=>is_array($rawSequence)&&count($rawSequence)>1?'SEQUENCE_PRESENT_UNVERIFIED':'STOPPING_PATTERN_UNAVAILABLE',
   'reason'=>'Departure-monitor event inspection only; no service-level lookup performed',
   'rawEvent'=>$event];
 }
 $data=['mode'=>'Step 2 stopping-pattern investigation','snapshotAt'=>$data['snapshotAt'],
 'candidateCount'=>$data['candidateCount'],'inspectedCount'=>count($rows),
 'elapsedMs'=>(int)((microtime(true)-$started)*1000),
 'note'=>'Read-only departure-monitor inspection. A missing sequence does not prove the service has no stops. No journey-planner request is made.',
 'results'=>$rows,'discovery'=>$data];}
 if(in_array(($_GET['action']??''),['validate','rank'],true)){$data=qa_validate_departures($data,$origin,$destination);if(($_GET['action']??'')==='rank')$data['mode']='Step 3 QA ranking';}echo json_encode(['data'=>$data],JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);exit;}
 catch(Throwable $e){error_log('QA discovery: '.$e->getMessage());fail('UPSTREAM_UNAVAILABLE','Discovery failed; retry shortly',502);}
}
if(($_GET['action']??'')!=='trace')fail('BAD_REQUEST','Unknown action',404);
$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
if(!preg_match('/^[\\w:-]{3,50}$/',$from)||!preg_match('/^[\\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Select two different stations');
$fromName=substr(trim((string)($_GET['fromName']??$from)),0,100);
$toName=substr(trim((string)($_GET['toName']??$to)),0,100);
$origin=['id'=>$from,'name'=>$fromName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
$dest=['id'=>$to,'name'=>$toName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
$now=new DateTimeImmutable('now',new DateTimeZone('Australia/Sydney'));
$probes=array_slice(journey_search_probes($now),0,6);
$params=[];foreach($probes as $t)$params[]=['depArrMacro'=>'dep','itdDate'=>$t->format('Ymd'),'itdTime'=>$t->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>22,'TfNSWTR'=>'true'];
function diagnostic_candidate(array $j,array $origin,array $dest):array{
 $body=['journeys'=>[$j]];$accepted=normalized_journeys($body,$origin,$dest);
 $legs=val($j,'legs',[]);$reasons=[];$segments=[];
 foreach(is_array($legs)?$legs:[] as $leg){
  if(!is_array($leg))continue;
  $transport=val($leg,'transportation',[]);
  $mode=mode($transport);$walk=is_transfer_walk($transport);
  $o=val($leg,'origin',[]);$d=val($leg,'destination',[]);
  $segments[]=['mode'=>$mode??($walk?'walk':'unsupported'),'line'=>val($transport,'disassembledName',val($transport,'number',val($transport,'name',''))),'from'=>val($o,'name',''),'to'=>val($d,'name',''),'departure'=>val($o,'departureTimeEstimated',val($o,'departureTimePlanned')),'arrival'=>val($d,'arrivalTimeEstimated',val($d,'arrivalTimePlanned')),'tripIds'=>transportation_trip_ids($transport)];
  if(!$mode&&!$walk)$reasons[]='Contains unsupported transport mode';
 }
 if(!$legs)$reasons[]='No journey legs';
 if(!$accepted){
  if(!$reasons)$reasons[]='Failed normalization: rail endpoints, station identity, or usable rail legs did not match requested route';
 }else $reasons[]='Accepted as complete train/metro journey';
 return ['accepted'=>(bool)$accepted,'reasons'=>array_values(array_unique($reasons)),'segments'=>$segments,'rank'=>$accepted?route_rank($accepted[0]):null,'normalized'=>$accepted[0]??null];
}
try{
 $start=microtime(true);$bodies=timed_parallel_trip($params,25);$logs=[];$best=null;$candidateCount=0;
 foreach($bodies as $i=>$body){
  $entry=['stage'=>'initial_parallel_search','probe'=>$i+1,'requestedTime'=>$probes[$i]->format(DATE_ATOM),'request'=>$params[$i],'responseReceived'=>is_array($body),'rawResponse'=>$body,'candidates'=>[]];
  $journeys=is_array($body)?val($body,'journeys',[]):[];
  foreach(is_array($journeys)?$journeys:[] as $index=>$j){
   if(!is_array($j))continue;
   $candidate=diagnostic_candidate($j,$origin,$dest);$candidate['upstreamIndex']=$index;$entry['candidates'][]=$candidate;$candidateCount++;
  }
  $route=is_array($body)?timed_normalized_journey($body,$origin,$dest):null;
  $entry['bestValidForProbe']=$route?['id'=>$route['id'],'rank'=>route_rank($route)]:null;
  $before=$best; $best=better_route($best,$route);
  $entry['decision']=$route===null?'No valid complete journey in this probe':($before===null?'Initial candidate selected':($best===$before?'Existing candidate ranked better or equal':'Candidate replaced previous selection'));
  $logs[]=$entry;
 }
 if(!$best){
  $fallbackMeta=[];$firstParams=[];
  foreach($bodies as $i=>$body){
   if(!is_array($body))continue;
   $candidates=timed_transfer_candidates(null,$body,$origin,$dest,1);
   $logs[]=['stage'=>'fallback_transfer_discovery','probe'=>$i+1,'candidates'=>$candidates,'decision'=>$candidates?'Try highest-priority interchange':'No candidate interchange'];
   if(!$candidates)continue;
   $transfer=$candidates[0];$fallbackMeta[]=['probe'=>$i,'transfer'=>$transfer];
   $firstParams[]=['depArrMacro'=>'dep','itdDate'=>$probes[$i]->format('Ymd'),'itdTime'=>$probes[$i]->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$transfer['id'],'calcNumberOfTrips'=>8,'TfNSWTR'=>'true'];
  }
  if($firstParams){
   $firstBodies=timed_parallel_trip($firstParams,25);$secondParams=[];$secondMeta=[];
   foreach($firstBodies as $i=>$body){
    $meta=$fallbackMeta[$i];$first=is_array($body)?timed_normalized_journey($body,$origin,$meta['transfer']):null;
    $logs[]=['stage'=>'fallback_first_leg','request'=>$firstParams[$i],'rawResponse'=>$body,'selected'=>$first,'decision'=>$first?'First leg accepted':'No valid first leg'];
    if(!$first)continue;
    $arrival=route_arrival_ts($first);if($arrival===PHP_INT_MAX)continue;
    $onward=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
    $secondMeta[]=['first'=>$first,'transfer'=>$meta['transfer']];
    $secondParams[]=['depArrMacro'=>'dep','itdDate'=>$onward->format('Ymd'),'itdTime'=>$onward->format('Hi'),'type_origin'=>'stop','name_origin'=>$meta['transfer']['id'],'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>8,'TfNSWTR'=>'true'];
   }
   if($secondParams){
    $secondBodies=timed_parallel_trip($secondParams,25);
    foreach($secondBodies as $i=>$body){
     $meta=$secondMeta[$i];$second=is_array($body)?timed_normalized_journey($body,$meta['transfer'],$dest):null;
     $stitched=$second?stitch_routes($meta['first'],$second,$origin,$dest):null;
     $before=$best;$best=better_route($best,$stitched);
     $logs[]=['stage'=>'fallback_onward_leg','request'=>$secondParams[$i],'rawResponse'=>$body,'selected'=>$second,'stitched'=>$stitched,'decision'=>$stitched?'Valid stitched journey compared':'Onward or interchange validation failed'];
    }
   }
  }
 }
 $logs[]=['stage'=>'search_stop','decision'=>$best?'Core fast path stops once an initial-batch or fallback route is found; later probes are not searched':'Core path would continue with later time probes (not executed by this diagnostic page)'];
 echo json_encode(['data'=>['snapshotAt'=>$now->format(DATE_ATOM),'origin'=>$origin,'destination'=>$dest,'mode'=>'QA core-only fast-path diagnostic','note'=>'Runs the six initial probes and their conditional parallel fallback. It does not execute later sequential probes, enrichment, or modify journey state. Raw upstream responses are included where returned.','ranking'=>'earliest arrival, then fewest transfers, then shortest duration, then earlier departure','candidateCount'=>$candidateCount,'elapsedMs'=>(int)((microtime(true)-$start)*1000),'selected'=>$best,'selectedRank'=>$best?route_rank($best):null,'trace'=>$logs]],JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
}catch(Throwable $e){error_log('QA route diagnostic: '.$e->getMessage());fail('UPSTREAM_UNAVAILABLE','Diagnostic search failed; please retry',502);}
