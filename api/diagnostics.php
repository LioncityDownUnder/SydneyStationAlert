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
if(($_GET['action']??'')==='discover'){
 require __DIR__.'/lib/discovery.php';
 $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
 if(!preg_match('/^[\\w:-]{3,50}$/',$from)||!preg_match('/^[\\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Select two different stations');
 $origin=['id'=>$from,'name'=>substr((string)($_GET['fromName']??$from),0,100)];
 $destination=['id'=>$to,'name'=>substr((string)($_GET['toName']??$to),0,100)];
 try{$data=qa_discover($origin,$destination,new DateTimeImmutable('now',new DateTimeZone('Australia/Sydney')));echo json_encode(['data'=>$data],JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);exit;}
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
