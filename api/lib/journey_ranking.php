<?php
declare(strict_types=1);
/** QA-only Step 4B: compare the best complete static journey for each origin departure. */
function qa_journey_rank_departures(array $discovery,array $origin,array $destination):array{
 $all=$discovery['discovered']??[];
 $limit=10;
 $candidates=array_slice($all,0,$limit);
 $out=['mode'=>'Step 4 independent GTFS journey ranking','version'=>'4C','status'=>'NO_VALID_STATIC_JOURNEY',
  'snapshotAt'=>$discovery['snapshotAt']??null,'candidateCount'=>count($all),
  'evaluatedCandidateCount'=>count($candidates),'candidateLimit'=>$limit,
  'ranked'=>[],'evaluations'=>[],
  'rankingPolicy'=>'earliest estimated origin departure (when available), then static scheduled arrival, then fewer transfers; transfer risks flagged',
  'confidence'=>'STATIC_SCHEDULE_UNVERIFIED',
  'note'=>'QA-only estimated-origin ordering with static GTFS downstream times. Delay is projected unchanged to the interchange only for connection-risk screening; this is not a realtime prediction or confirmation. No /trip calls or commuter state changes.'];
 $accepted=0;$bestByDeparture=[];$truncated=false;
 foreach($candidates as $index=>$candidate){
  $built=qa_journey_build($candidate,$origin,$destination);
  $routes=$built['routes']??[];
  $truncated=$truncated||!empty($built['hasMoreJourneys']);
  $first=$candidate['firstDeparture']??[];
  $plannedTs=isset($first['planned'])?iso_ts($first['planned']):null;
  $estimatedTs=isset($first['estimated'])?iso_ts($first['estimated']):null;
  $delaySeconds=($plannedTs!==null&&$estimatedTs!==null)?$estimatedTs-$plannedTs:null;
  $departureTs=$estimatedTs??$plannedTs;
  $entry=['candidateIndex'=>$index,'originDeparture'=>$candidate['firstDeparture']??null,
   'plannedDeparture'=>$first['planned']??null,'estimatedDeparture'=>$first['estimated']??null,'originDelaySeconds'=>$delaySeconds,'status'=>$built['status']??'UNKNOWN','firstLegEvidence'=>$built['firstLegEvidence']??null,
   'returnedRoutes'=>count($routes),'uniqueJourneyCount'=>$built['uniqueJourneyCount']??count($routes),
   'hasMoreJourneys'=>$built['hasMoreJourneys']??false,
   'rejectedConnections'=>$built['rejectedConnections']??null,'bestJourney'=>null];
  $best=null;
  foreach($routes as $route){
   $legs=$route['legs']??[];
   if(!$legs||!isset($legs[0]['departure'],$legs[count($legs)-1]['arrival']))continue;
   $departure=qa_journey_seconds((string)$legs[0]['departure']);
   $arrival=qa_journey_seconds((string)$legs[count($legs)-1]['arrival']);
   if($departure===null||$arrival===null||$arrival<$departure)continue;
   ++$accepted;
   $effectiveDeparture=$departureTs!==null?(int)($departureTs-(new DateTimeImmutable('@'.$departureTs))->setTimezone(new DateTimeZone('Australia/Sydney'))->setTime(0,0)->getTimestamp()):$departure;
   // Compare estimated origin departure only; downstream times remain GTFS static.
   $transfer=$route['transferSeconds']??null;
   $projectedSlack=($transfer!==null&&$delaySeconds!==null)?$transfer-$delaySeconds-($route['minimumTransferSeconds']??180):null;
   $risk=$transfer===null?'DIRECT_NO_CONNECTION':($delaySeconds===null?'DELAY_UNKNOWN':($projectedSlack<0?'POTENTIAL_MISSED_CONNECTION':($delaySeconds>=120?'DELAY_AFFECTED_UNVERIFIED':'STATIC_CONNECTION_UNVERIFIED')));
   $item=['candidateIndex'=>$index,'departureSeconds'=>$departure,'effectiveDepartureSeconds'=>$effectiveDeparture,
    'plannedOriginDeparture'=>$first['planned']??null,'estimatedOriginDeparture'=>$first['estimated']??null,
    'originDelaySeconds'=>$delaySeconds,'delayMeaningful'=>$delaySeconds!==null&&abs($delaySeconds)>=120,
    'connectionRisk'=>$risk,'projectedTransferSlackSeconds'=>$projectedSlack,
    'arrivalSeconds'=>$arrival,'durationSeconds'=>$arrival-$departure]+$route;
   if($best===null||($best['connectionRisk']==='POTENTIAL_MISSED_CONNECTION'&&$risk!=='POTENTIAL_MISSED_CONNECTION')||(($best['connectionRisk']==='POTENTIAL_MISSED_CONNECTION')===($risk==='POTENTIAL_MISSED_CONNECTION')&&($arrival<$best['arrivalSeconds']||($arrival===$best['arrivalSeconds']&&$item['transfers']<$best['transfers']))))$best=$item;
  }
  if($best!==null){
   $bestByDeparture[]=$best;
   $entry['bestJourney']=['departure'=>$best['legs'][0]['departure'],
    'arrival'=>$best['legs'][count($best['legs'])-1]['arrival'],
    'transfers'=>$best['transfers'],'originDelaySeconds'=>$delaySeconds,'connectionRisk'=>$best['connectionRisk'],'arrivalSeconds'=>$best['arrivalSeconds']];
  }
  $out['evaluations'][]=$entry;
 }
 usort($bestByDeparture,fn($a,$b)=>($a['effectiveDepartureSeconds']<=>$b['effectiveDepartureSeconds'])?:($a['arrivalSeconds']<=>$b['arrivalSeconds'])?:($a['transfers']<=>$b['transfers'])?:($a['candidateIndex']<=>$b['candidateIndex']));
 // A later departure can be faster overall; flag the earlier journey, without changing departure-first order.
 $count=count($bestByDeparture);
 for($i=0;$i<$count;$i++){
  $later=[];
  for($j=$i+1;$j<$count;$j++){
   if($bestByDeparture[$j]['effectiveDepartureSeconds']>$bestByDeparture[$i]['effectiveDepartureSeconds']&&$bestByDeparture[$j]['arrivalSeconds']<$bestByDeparture[$i]['arrivalSeconds']){
    $later[]=['candidateIndex'=>$bestByDeparture[$j]['candidateIndex'],
     'departure'=>$bestByDeparture[$j]['estimatedOriginDeparture']??$bestByDeparture[$j]['legs'][0]['departure'],
     'arrival'=>$bestByDeparture[$j]['legs'][count($bestByDeparture[$j]['legs'])-1]['arrival']];
   }
  }
  $bestByDeparture[$i]['laterDepartureArrivesEarlier']=count($later)>0;
  $bestByDeparture[$i]['fasterLaterDepartures']=$later;
 }
 $out['acceptedJourneyCount']=$accepted;
 $out['uniqueJourneyCount']=$count;
 $out['shortlistJourneyCount']=$count;
 $out['suppressedSlowerAlternatives']=$accepted-$count;
 $out['displayedJourneyCount']=$count;
 $out['hasMoreJourneys']=false;
 $out['ranked']=$bestByDeparture;
 $out['delayAffectedJourneyCount']=count(array_filter($bestByDeparture,fn($r)=>$r['delayMeaningful']));
 $out['potentialMissedConnectionCount']=count(array_filter($bestByDeparture,fn($r)=>$r['connectionRisk']==='POTENTIAL_MISSED_CONNECTION'));
 $out['laterDepartureArrivesEarlierCount']=count(array_filter($bestByDeparture,fn($r)=>$r['laterDepartureArrivesEarlier']));
 $out['status']=$count?'STATIC_JOURNEYS_RANKED_UNVERIFIED':'NO_VALID_STATIC_JOURNEY';
 $out['searchCompleteness']=['evaluatedAllDiscoveredDepartures'=>count($all)<=count($candidates),
  'perDepartureRoutesTruncated'=>$truncated,
  'note'=>'Best journey selected from at most 12 routes returned per origin departure; not guaranteed globally optimal.'];
 return $out;
}
