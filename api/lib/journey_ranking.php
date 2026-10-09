<?php
declare(strict_types=1);
/** QA-only Step 4B: compare the best complete static journey for each origin departure. */
function qa_journey_rank_departures(array $discovery,array $origin,array $destination):array{
 $all=$discovery['discovered']??[];
 $limit=10;
 $candidates=array_slice($all,0,$limit);
 $out=['mode'=>'Step 4 independent GTFS journey ranking','version'=>'4B','status'=>'NO_VALID_STATIC_JOURNEY',
  'snapshotAt'=>$discovery['snapshotAt']??null,'candidateCount'=>count($all),
  'evaluatedCandidateCount'=>count($candidates),'candidateLimit'=>$limit,
  'ranked'=>[],'evaluations'=>[],
  'rankingPolicy'=>'earliest origin departure, then earliest destination arrival, then fewer transfers',
  'confidence'=>'STATIC_SCHEDULE_UNVERIFIED',
  'note'=>'QA-only static timetable comparison; GTFS matches do not confirm live train identity. No /trip calls or commuter state changes.'];
 $accepted=0;$bestByDeparture=[];$truncated=false;
 foreach($candidates as $index=>$candidate){
  $built=qa_journey_build($candidate,$origin,$destination);
  $routes=$built['routes']??[];
  $truncated=$truncated||!empty($built['hasMoreJourneys']);
  $entry=['candidateIndex'=>$index,'originDeparture'=>$candidate['firstDeparture']??null,
   'status'=>$built['status']??'UNKNOWN','firstLegEvidence'=>$built['firstLegEvidence']??null,
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
   $item=['candidateIndex'=>$index,'departureSeconds'=>$departure,'arrivalSeconds'=>$arrival,'durationSeconds'=>$arrival-$departure]+$route;
   if($best===null||$arrival<$best['arrivalSeconds']||($arrival===$best['arrivalSeconds']&&$item['transfers']<$best['transfers']))$best=$item;
  }
  if($best!==null){
   $bestByDeparture[]=$best;
   $entry['bestJourney']=['departure'=>$best['legs'][0]['departure'],
    'arrival'=>$best['legs'][count($best['legs'])-1]['arrival'],
    'transfers'=>$best['transfers'],'arrivalSeconds'=>$best['arrivalSeconds']];
  }
  $out['evaluations'][]=$entry;
 }
 usort($bestByDeparture,fn($a,$b)=>($a['departureSeconds']<=>$b['departureSeconds'])?:($a['arrivalSeconds']<=>$b['arrivalSeconds'])?:($a['transfers']<=>$b['transfers'])?:($a['candidateIndex']<=>$b['candidateIndex']));
 // A later departure can be faster overall; flag the earlier journey, without changing departure-first order.
 $count=count($bestByDeparture);
 for($i=0;$i<$count;$i++){
  $later=[];
  for($j=$i+1;$j<$count;$j++){
   if($bestByDeparture[$j]['departureSeconds']>$bestByDeparture[$i]['departureSeconds']&&$bestByDeparture[$j]['arrivalSeconds']<$bestByDeparture[$i]['arrivalSeconds']){
    $later[]=['candidateIndex'=>$bestByDeparture[$j]['candidateIndex'],
     'departure'=>$bestByDeparture[$j]['legs'][0]['departure'],
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
 $out['laterDepartureArrivesEarlierCount']=count(array_filter($bestByDeparture,fn($r)=>$r['laterDepartureArrivesEarlier']));
 $out['status']=$count?'STATIC_JOURNEYS_RANKED_UNVERIFIED':'NO_VALID_STATIC_JOURNEY';
 $out['searchCompleteness']=['evaluatedAllDiscoveredDepartures'=>count($all)<=count($candidates),
  'perDepartureRoutesTruncated'=>$truncated,
  'note'=>'Best journey selected from at most 12 routes returned per origin departure; not guaranteed globally optimal.'];
 return $out;
}
