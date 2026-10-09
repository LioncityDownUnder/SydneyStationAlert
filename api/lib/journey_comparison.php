<?php
declare(strict_types=1);
/** QA-only Step 5: independent static routes compared with a bounded legacy /trip snapshot. */
function qa_compare_engines(array $discovery,array $origin,array $destination,DateTimeImmutable $now):array{
 $independent=qa_journey_rank_departures($discovery,$origin,$destination);
 $params=['depArrMacro'=>'dep','itdDate'=>$now->format('Ymd'),'itdTime'=>$now->format('Hi'),
  'type_origin'=>'stop','name_origin'=>$origin['id'],'type_destination'=>'stop','name_destination'=>$destination['id'],
  'calcNumberOfTrips'=>30,'TfNSWTR'=>'true'];
 $started=microtime(true);$legacyError=null;$legacy=[];$rawLegacyCount=0;$legacyResponseKeys=[];$probes=[];$probeTimes=[];$seenProbeMinutes=[];
 foreach($independent['ranked'] as $candidate){
  $seconds=(int)($candidate['effectiveDepartureSeconds']??0);
  $candidateTime=$now->setTime(0,0)->modify('+'.$seconds.' seconds')->modify('-10 minutes');
  $minute=$candidateTime->format('YmdHi');
  if(!isset($seenProbeMinutes[$minute])){$seenProbeMinutes[$minute]=true;$probeTimes[]=$candidateTime;}
  if(count($probeTimes)>=3)break;
 }
 if(!$probeTimes)$probeTimes=array_slice(journey_search_probes($now),0,3);
 foreach($probeTimes as $probeIndex=>$probeTime){
  $request=$params;$request['itdDate']=$probeTime->format('Ymd');$request['itdTime']=$probeTime->format('Hi');
  $probe=['probe'=>$probeIndex+1,'requestedAt'=>$probeTime->format(DATE_ATOM),'status'=>'PENDING','rawCount'=>null,'normalizedCount'=>0,'rejectionReasons'=>[],'unsupportedTransportSamples'=>[]];
  try{
   $body=timed_upstream('trip',$request,25);
   $keys=is_array($body)?array_keys($body):[];
   $legacyResponseKeys=array_values(array_unique(array_merge($legacyResponseKeys,$keys)));
   $rawJourneys=val($body,'journeys',[]);
   $rawJourneys=is_array($rawJourneys)?$rawJourneys:[];
   $probe['rawCount']=count($rawJourneys);$rawLegacyCount+=count($rawJourneys);
   $accepted=normalized_journeys($body,$origin+['lat'=>0.0,'lon'=>0.0,'mode'=>'train'],$destination+['lat'=>0.0,'lon'=>0.0,'mode'=>'train']);
   $probe['normalizedCount']=count($accepted);
   foreach($rawJourneys as $j){
    $single=normalized_journeys(['journeys'=>[$j]],$origin+['lat'=>0.0,'lon'=>0.0,'mode'=>'train'],$destination+['lat'=>0.0,'lon'=>0.0,'mode'=>'train']);
    if($single)continue;
    $legs=val($j,'legs',[]);
    $reason='ENDPOINT_OR_RAIL_MISMATCH';
    if(!is_array($legs)||!$legs)$reason='NO_LEGS';
    else{
     foreach($legs as $leg){
      $transport=val($leg,'transportation',[]);
      if(!mode($transport)&&!is_transfer_walk($transport)){
       $reason='UNSUPPORTED_TRANSPORT';
       $product=val($transport,'product',[]);
       $sample=['productClass'=>val($product,'class',null),'productName'=>substr((string)val($product,'name',''),0,70),'transportName'=>substr((string)val($transport,'name',''),0,70),'disassembledName'=>substr((string)val($transport,'disassembledName',''),0,70),'transportType'=>substr((string)val($transport,'type',''),0,40)];
       $signature=json_encode($sample);
       $seen=array_map('json_encode',$probe['unsupportedTransportSamples']);
       if(!in_array($signature,$seen,true)&&count($seen)<8)$probe['unsupportedTransportSamples'][]=$sample;
       break;
      }
     }
    }
    $probe['rejectionReasons'][$reason]=($probe['rejectionReasons'][$reason]??0)+1;
   }
   $probe['status']=$probe['rawCount']===0?'UPSTREAM_EMPTY':($probe['normalizedCount']===0?'NORMALIZATION_REJECTED':'NORMALIZED');
   $legacy=array_merge($legacy,$accepted);
  }catch(Throwable $e){
   $probe['status']='UPSTREAM_ERROR';$legacyError='One or more legacy /trip probes failed';
   error_log('QA Step 5 legacy probe '.($probeIndex+1).': '.$e->getMessage());
  }
  $probes[]=$probe;
  // Keep probing the bounded departure windows to improve comparison coverage.
 }
 $summary=[];
 foreach($legacy as $route){
  $legs=$route['legs']??[];if(!$legs)continue;
  $first=$legs[0];$last=$legs[count($legs)-1];
  $dep=iso_ts($first['departure']??null);$arr=iso_ts($last['arrival']??null);
  if($dep===null||$arr===null)continue;
  $transfers=[];for($i=0;$i<count($legs)-1;$i++)$transfers[]=(string)($legs[$i]['destination']['name']??'');
  $summary[]=['departure'=>$first['departure'],'arrival'=>$last['arrival'],'departureTimestamp'=>$dep,'arrivalTimestamp'=>$arr,
   'transfers'=>count($legs)-1,'transferStations'=>$transfers,'lines'=>array_map(fn($l)=>(string)($l['line']??''),$legs)];
 }
 usort($summary,fn($a,$b)=>$a['departureTimestamp']<=>$b['departureTimestamp']);
 $comparisons=[];
 foreach($independent['ranked'] as $route){
  $staticDeparture=(int)$route['effectiveDepartureSeconds'];$staticArrival=(int)$route['arrivalSeconds'];
  $dayStart=$now->setTime(0,0)->getTimestamp();
  $indDep=$dayStart+$staticDeparture;$indArr=$dayStart+$staticArrival;
  $nearest=null;$distance=PHP_INT_MAX;
  $transferStations=[];
  foreach(array_slice($route['legs'],0,-1) as $leg)$transferStations[]=(string)($leg['to']??'');
  $normalizedTransfers=array_map('strtolower',array_map('trim',$transferStations));
  $bestScore=PHP_INT_MAX;
  foreach($summary as $i=>$candidate){
   $gap=abs($candidate['departureTimestamp']-$indDep);
   $sameStructure=$route['transfers']===$candidate['transfers']&&$normalizedTransfers===array_map('strtolower',array_map('trim',$candidate['transferStations']));
   $score=$gap+($sameStructure?0:86400);
   if($gap<=300&&$score<$bestScore){$nearest=$i;$distance=$gap;$bestScore=$score;}
  }
  $closestLegacyIndex=null;$closestLegacyGap=null;
  foreach($summary as $i=>$candidate){
   $gap=abs($candidate['departureTimestamp']-$indDep);
   if($closestLegacyGap===null||$gap<$closestLegacyGap){$closestLegacyGap=$gap;$closestLegacyIndex=$i;}
  }
  $closestLegacy=$closestLegacyIndex===null?null:$summary[$closestLegacyIndex];
  $plannedTs=iso_ts($route['plannedOriginDeparture']??null);
  $estimatedTs=iso_ts($route['estimatedOriginDeparture']??null);
  $diagnostic=['plannedOriginDeparture'=>$route['plannedOriginDeparture']??null,
   'estimatedOriginDeparture'=>$route['estimatedOriginDeparture']??null,
   'originDelaySeconds'=>$route['originDelaySeconds']??null,
   'plannedVsEstimatedSeconds'=>($plannedTs===null||$estimatedTs===null)?null:$estimatedTs-$plannedTs,
   'closestLegacyIndex'=>$closestLegacyIndex,
   'closestLegacyDeparture'=>$closestLegacy['departure']??null,
   'closestLegacyGapSeconds'=>$closestLegacyGap,
   'closestLegacyTransferStations'=>$closestLegacy['transferStations']??null,
   'closestLegacyLines'=>$closestLegacy['lines']??null,
   'independentLegs'=>array_map(static fn($leg)=>['from'=>$leg['from']??null,'to'=>$leg['to']??null,'departure'=>$leg['departure']??null,'arrival'=>$leg['arrival']??null],$route['legs']),
   'diagnosis'=>$closestLegacy===null?'NO_RAIL_ONLY_LEGACY_REFERENCE':($closestLegacyGap>300?'DEPARTURE_WINDOW_MISMATCH':'WITHIN_FIVE_MINUTES_REVIEW_ROUTE')];
  $legacyMatch=$nearest!==null?$summary[$nearest]:null;
  $sameStations=$legacyMatch===null?null:array_map('strtolower',array_map('trim',$transferStations))===array_map('strtolower',array_map('trim',$legacyMatch['transferStations']));
  $matchStatus=$legacyMatch===null?'NO_LEGACY_CANDIDATE':($distance>300?'DEPARTURE_NOT_COMPARABLE':(($route['transfers']!==$legacyMatch['transfers']||!$sameStations)?'ROUTE_STRUCTURE_DIFFERS':'TIME_ALIGNED_STRUCTURE_MATCH'));
  $comparisons[]=['departureDiagnostics'=>$diagnostic,'matchStatus'=>$matchStatus,'sameTransferStations'=>$sameStations,'departureDifferenceSeconds'=>$legacyMatch===null?null:$indDep-$legacyMatch['departureTimestamp'],'arrivalDifferenceSeconds'=>$legacyMatch===null?null:$indArr-$legacyMatch['arrivalTimestamp'],'candidateIndex'=>$route['candidateIndex'],'independentEstimatedDeparture'=>$route['estimatedOriginDeparture']??null,
   'independentStaticArrival'=>$route['legs'][count($route['legs'])-1]['arrival']??null,
   'independentTransferStations'=>$transferStations,'independentTransfers'=>$route['transfers'],
   'connectionRisk'=>$route['connectionRisk'],'nearestLegacyIndex'=>$nearest,
   'nearestLegacyDepartureGapSeconds'=>$nearest===null?null:$distance,
   'legacyArrivalDifferenceSeconds'=>$legacyMatch===null?null:$indArr-$legacyMatch['arrivalTimestamp'],
   'legacyTransferStations'=>$legacyMatch['transferStations']??null,
   'legacyTransfers'=>$legacyMatch['transfers']??null,
   'similarDepartureWithinFiveMinutes'=>$nearest!==null&&$distance<=300,
   'sameTransferCount'=>$legacyMatch!==null&&$legacyMatch['transfers']===$route['transfers']];
 }
 return ['mode'=>'Step 5 independent versus legacy comparison','version'=>'5F',
  'snapshotAt'=>$discovery['snapshotAt']??$now->format(DATE_ATOM),
  'comparisonPolicy'=>'Bounded legacy probes near independent departures; only compare departures within five minutes, preferring identical transfer sequence and count. Time proximity does not prove train identity',
  'legacyStatus'=>count($legacy)>0?'LEGACY_JOURNEYS_AVAILABLE':($legacyError!==null?'LEGACY_PARTIAL_OR_UNAVAILABLE':'LEGACY_NO_USABLE_JOURNEYS'),
  'legacyError'=>$legacyError,'legacyElapsedMs'=>(int)((microtime(true)-$started)*1000),
  'legacyRequest'=>['date'=>$params['itdDate'],'time'=>$params['itdTime'],'requestedTrips'=>30],
  'legacyProbes'=>$probes,'legacyProbeCount'=>count($probes),
  'legacyJourneys'=>$summary,'legacyJourneyCount'=>count($summary),
  'legacyRawJourneyCount'=>$rawLegacyCount,'legacyResponseKeys'=>$legacyResponseKeys,
  'legacyNormalizationRejectedCount'=>max(0,$rawLegacyCount-count($legacy)),
  'independent'=>$independent,'comparisons'=>$comparisons,
  'note'=>'Step 5F reports closest unmatched rail-only legacy departure and realtime versus planned origin timing without claiming train identity. Step 5E uses independent-departure-aligned legacy probes and rejects out-of-window comparisons. Step 5C inspects unsupported legacy transport classes without relaxing rail-only journey acceptance. QA-only up to three bounded /trip probes for legacy reference, not the full production fallback/probe search. Independent GTFS identity and downstream arrival remain unverified. No commuter state changes.'];
}
