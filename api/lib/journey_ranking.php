<?php
declare(strict_types=1);
/** QA-only Step 4: evaluate several first trains without calling the /trip planner. */
function qa_journey_rank_departures(array $discovery,array $origin,array $destination):array{
 $candidates=array_slice($discovery['discovered']??[],0,5);
 $out=['mode'=>'Step 4 independent GTFS journey ranking','status'=>'NO_VALID_STATIC_JOURNEY','snapshotAt'=>$discovery['snapshotAt']??null,'candidateCount'=>count($discovery['discovered']??[]),'evaluatedCandidateCount'=>count($candidates),'candidateLimit'=>5,'ranked'=>[],'evaluations'=>[],'rankingPolicy'=>'earliest origin departure, then earliest destination arrival, then fewer transfers','confidence'=>'STATIC_SCHEDULE_UNVERIFIED','note'=>'QA-only timetable comparison. First-leg GTFS matches do not prove realtime train identity. No /trip calls or commuter state changes.'];
 $unique=[];$accepted=0;
 foreach($candidates as $index=>$candidate){
  $built=qa_journey_build($candidate,$origin,$destination);
  $routes=$built['routes']??[];
  $entry=['candidateIndex'=>$index,'originDeparture'=>$candidate['firstDeparture']??null,'status'=>$built['status']??'UNKNOWN','firstLegEvidence'=>$built['firstLegEvidence']??null,'returnedRoutes'=>count($routes),'uniqueJourneyCount'=>$built['uniqueJourneyCount']??count($routes),'hasMoreJourneys'=>$built['hasMoreJourneys']??false,'rejectedConnections'=>$built['rejectedConnections']??null];
  foreach($routes as $route){
   $legs=$route['legs']??[];
   if(!$legs||!isset($legs[0]['departure'])||!isset($legs[count($legs)-1]['arrival']))continue;
   $departure=qa_journey_seconds((string)$legs[0]['departure']);
   $arrival=qa_journey_seconds((string)$legs[count($legs)-1]['arrival']);
   if($departure===null||$arrival===null||$arrival<$departure)continue;
   $accepted++;
   $key=implode('|',array_map(fn($leg)=>(string)($leg['tripId']??'').'@'.(string)($leg['boardingStopId']??''),$legs));
   if(!isset($unique[$key])||$arrival<$unique[$key]['arrivalSeconds'])$unique[$key]=['candidateIndex'=>$index,'departureSeconds'=>$departure,'arrivalSeconds'=>$arrival,'durationSeconds'=>$arrival-$departure]+$route;
  }
  $out['evaluations'][]=$entry;
 }
 $ranked=array_values($unique);
 usort($ranked,fn($a,$b)=>($a['departureSeconds']<=>$b['departureSeconds'])?:($a['arrivalSeconds']<=>$b['arrivalSeconds'])?:($a['transfers']<=>$b['transfers']));
 $out['acceptedJourneyCount']=$accepted;$out['deduplicatedJourneyCount']=$accepted-count($ranked);
 $out['uniqueJourneyCount']=count($ranked);$out['displayedJourneyCount']=min(12,count($ranked));$out['hasMoreJourneys']=count($ranked)>12;
 $out['ranked']=array_slice($ranked,0,12);
 $out['status']=$ranked?'STATIC_JOURNEYS_RANKED_UNVERIFIED':'NO_VALID_STATIC_JOURNEY';
 $out['searchCompleteness']='LIMITED_TO_FIRST_5_DEPARTURES_AND_12_ROUTES_PER_DEPARTURE';
 return $out;
}
