<?php
declare(strict_types=1);
/** QA-only Step 5: independent static routes compared with a bounded legacy /trip snapshot. */
function qa_compare_engines(array $discovery,array $origin,array $destination,DateTimeImmutable $now):array{
 $independent=qa_journey_rank_departures($discovery,$origin,$destination);
 $params=['depArrMacro'=>'dep','itdDate'=>$now->format('Ymd'),'itdTime'=>$now->format('Hi'),
  'type_origin'=>'stop','name_origin'=>$origin['id'],'type_destination'=>'stop','name_destination'=>$destination['id'],
  'calcNumberOfTrips'=>30,'TfNSWTR'=>'true'];
 $started=microtime(true);$legacyError=null;$legacy=[];$rawLegacyCount=null;$legacyResponseKeys=[];
 try{
  $body=timed_upstream('trip',$params,25);
  $legacyResponseKeys=is_array($body)?array_keys($body):[];
  $rawJourneys=is_array($body)?val($body,'journeys',[]):[];
  $rawLegacyCount=is_array($rawJourneys)?count($rawJourneys):null;
  $legacy=normalized_journeys($body,
   $origin+['lat'=>0.0,'lon'=>0.0,'mode'=>'train'],
   $destination+['lat'=>0.0,'lon'=>0.0,'mode'=>'train']);
 }catch(Throwable $e){$legacyError='Legacy /trip request failed';error_log('QA Step 5 legacy: '.$e->getMessage());}
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
  foreach($summary as $i=>$candidate){
   $gap=abs($candidate['departureTimestamp']-$indDep);
   if($gap<$distance){$nearest=$i;$distance=$gap;}
  }
  $legacyMatch=$nearest!==null?$summary[$nearest]:null;
  $transferStations=[];
  foreach(array_slice($route['legs'],0,-1) as $leg)$transferStations[]=(string)($leg['to']??'');
  $comparisons[]=['candidateIndex'=>$route['candidateIndex'],'independentEstimatedDeparture'=>$route['estimatedOriginDeparture']??null,
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
 return ['mode'=>'Step 5 independent versus legacy comparison','version'=>'5A',
  'snapshotAt'=>$discovery['snapshotAt']??$now->format(DATE_ATOM),
  'comparisonPolicy'=>'Closest legacy departure by time; proximity does not prove same train identity or equivalent route',
  'legacyStatus'=>$legacyError===null?'LEGACY_RESPONSE_RECEIVED':'LEGACY_UNAVAILABLE',
  'legacyError'=>$legacyError,'legacyElapsedMs'=>(int)((microtime(true)-$started)*1000),
  'legacyRequest'=>['date'=>$params['itdDate'],'time'=>$params['itdTime'],'requestedTrips'=>30],
  'legacyJourneys'=>$summary,'legacyJourneyCount'=>count($summary),
  'legacyRawJourneyCount'=>$rawLegacyCount,'legacyResponseKeys'=>$legacyResponseKeys,
  'legacyNormalizationRejectedCount'=>$rawLegacyCount===null?null:max(0,$rawLegacyCount-count($legacy)),
  'independent'=>$independent,'comparisons'=>$comparisons,
  'note'=>'QA-only one bounded /trip call for legacy reference, not the full production fallback/probe search. Independent GTFS identity and downstream arrival remain unverified. No commuter state changes.'];
}
