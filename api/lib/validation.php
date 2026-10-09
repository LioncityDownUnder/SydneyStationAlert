<?php
declare(strict_types=1);
function qa_validate_departures(array $discovery,array $from,array $to):array{
 $items=$discovery['discovered']??[];
 $origin=['id'=>$from['id'],'name'=>$from['name'],'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
 $destination=['id'=>$to['id'],'name'=>$to['name'],'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
 $requests=[];$seen=[];
 foreach($items as $item){
  $time=$item['departureTimestamp']??null;
  if(!is_int($time))continue;
  $bucket=(int)(floor($time/900)*900);
  if(isset($seen[$bucket]))continue;
  $seen[$bucket]=true;
  $at=(new DateTimeImmutable('@'.max(0,$bucket-120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
  $requests[]=['depArrMacro'=>'dep','itdDate'=>$at->format('Ymd'),'itdTime'=>$at->format('Hi'),'type_origin'=>'stop','name_origin'=>$from['id'],'type_destination'=>'stop','name_destination'=>$to['id'],'calcNumberOfTrips'=>60,'TfNSWTR'=>'true','excludedMeans'=>'checkbox','exclMOT_4'=>1,'exclMOT_5'=>1,'exclMOT_7'=>1,'exclMOT_9'=>1,'exclMOT_11'=>1];
 }
 $journeys=[];$trace=[];$journeySources=[];
 foreach($requests as $params){
  $body=timed_upstream('trip',$params,0);
  $raw=val($body,'journeys',[]);
  $trace[]=['stage'=>'validation_probe','request'=>$params,'returnedCount'=>is_array($raw)?count($raw):0,'rawResponse'=>$body];
  foreach(is_array($raw)?$raw:[] as $index=>$j)if(is_array($j)){$journeys[]=$j;$journeySources[]=['probeIndex'=>count($trace)-1,'journeyIndex'=>$index,'requestedDate'=>$params['itdDate'],'requestedTime'=>$params['itdTime']];}
 }
 $sameMinute=[];
 foreach($items as $item){
  $minute=(int)floor(($item['departureTimestamp']??0)/60);
  $key=$minute.'|'.(string)($item['firstDeparture']['line']??'');
  $sameMinute[$key][]=$item;
 }
 $duplicateReview=[];
 foreach($sameMinute as $group){
  if(count($group)<2)continue;
  $members=[];
  foreach($group as $item){
   $event=$item['event']??[];$transport=val($event,'transportation',[]);
   $location=val($event,'location',[]);
   $members[]=['identity'=>$item['identity'],'departure'=>$item['firstDeparture'],'location'=>$location,'transportation'=>$transport,'destination'=>val($event,'destination',null),'properties'=>val($event,'properties',null)];
  }
  $platforms=array_values(array_unique(array_filter(array_map(fn($x)=>(string)val($x['location'],'id',''),$members))));
  $ids=array_map(fn($x)=>implode(',',array_map('strval',$x['departure']['tripIds']??[])),$members);
  $duplicateReview[]=['minute'=>$group[0]['departureTimestamp'],'line'=>$group[0]['firstDeparture']['line'],'candidateCount'=>count($group),'platformIds'=>$platforms,'tripIdSets'=>$ids,'assessment'=>'REVIEW_REQUIRED','reason'=>'Same-minute same-line departures: distinct IDs alone cannot establish distinct physical trains. Compare platform, direction and raw service records.','members'=>$members];
 }
 $results=[];
 foreach($items as $item){
  $first=$item['firstDeparture'];$time=$item['departureTimestamp'];
  $ids=array_map('strval',$first['tripIds']??[]);$matches=[];$failed=0;
  $diagnostic=['journeysReturned'=>count($journeys),'firstRailLegFound'=>0,'tripIdMatches'=>0,'plannedTimeMatches'=>0,'estimatedTimeMatches'=>0,'fallbackTimeMatches'=>0,'lineMatches'=>0,'firstLegMatches'=>0,'failedNormalization'=>0,'noRailFirstLeg'=>0];
  $diagnostic['candidate']=['planned'=>$first['planned']??null,'estimated'=>$first['estimated']??null,'line'=>$first['line']??null,'tripIds'=>$ids];
  $diagnostic['returnedFirstLegs']=[];
  foreach($journeys as $journeyIndex=>$j){
   $firstLeg=null;
   foreach(val($j,'legs',[]) as $leg){if(is_array($leg)&&mode(val($leg,'transportation',[]))){$firstLeg=$leg;break;}}
   if(!$firstLeg){$diagnostic['noRailFirstLeg']++;$diagnostic['returnedFirstLegs'][]=['source'=>$journeySources[$journeyIndex]??null,'reason'=>'NO_RAIL_LEG'];continue;}
   $diagnostic['firstRailLegFound']++;
   $node=val($firstLeg,'origin',[]);
   $journeyPlanned=iso_ts(val($node,'departureTimePlanned'));
   $journeyEstimated=iso_ts(val($node,'departureTimeEstimated'));
   $candidatePlanned=iso_ts($first['planned']??null);
   $candidateEstimated=iso_ts($first['estimated']??null);
   $transport=val($firstLeg,'transportation',[]);
   $otherIds=array_map('strval',transportation_trip_ids($transport));
   $idMatch=(bool)($ids&&$otherIds&&array_intersect($ids,$otherIds));
   // Compare like-for-like times: a delayed 14:21 service can depart at 14:25.
   // Never widen the tolerance to encompass neighbouring physical trains.
   $plannedMatch=$candidatePlanned!==null&&$journeyPlanned!==null&&abs($candidatePlanned-$journeyPlanned)<=120;
   $estimatedMatch=$candidateEstimated!==null&&$journeyEstimated!==null&&abs($candidateEstimated-$journeyEstimated)<=120;
   $fallbackMatch=($candidateEstimated===null||$journeyEstimated===null)
    &&($candidatePlanned===null||$journeyPlanned===null)
    &&(($journeyEstimated??$journeyPlanned)!==null)
    &&abs(($journeyEstimated??$journeyPlanned)-$time)<=120;
   $lineMatch=(string)val($transport,'disassembledName','')===(string)($first['line']??'');
   if($idMatch)$diagnostic['tripIdMatches']++;
   if($lineMatch)$diagnostic['lineMatches']++;
   if($lineMatch&&$plannedMatch)$diagnostic['plannedTimeMatches']++;
   if($lineMatch&&$estimatedMatch)$diagnostic['estimatedTimeMatches']++;
   if($lineMatch&&$fallbackMatch)$diagnostic['fallbackTimeMatches']++;
   $diagnostic['returnedFirstLegs'][]=['source'=>$journeySources[$journeyIndex]??null,'planned'=>val($node,'departureTimePlanned'),'estimated'=>val($node,'departureTimeEstimated'),'line'=>val($transport,'disassembledName',''),'tripIds'=>$otherIds,'tripIdMatch'=>$idMatch,'lineMatch'=>$lineMatch,'plannedTimeDifferenceSeconds'=>($candidatePlanned!==null&&$journeyPlanned!==null)?$journeyPlanned-$candidatePlanned:null,'estimatedTimeDifferenceSeconds'=>($candidateEstimated!==null&&$journeyEstimated!==null)?$journeyEstimated-$candidateEstimated:null,'acceptedFirstLeg'=>$idMatch||($lineMatch&&($plannedMatch||$estimatedMatch||$fallbackMatch))];
   if(!$idMatch&&!($lineMatch&&($plannedMatch||$estimatedMatch||$fallbackMatch)))continue;
   $diagnostic['firstLegMatches']++;
   $matchMethod=$idMatch?'TRIP_ID':($plannedMatch?'PLANNED_TIME_AND_LINE':($estimatedMatch?'ESTIMATED_TIME_AND_LINE':'TIME_AND_LINE'));
   $valid=normalized_journeys(['journeys'=>[$j]],$origin,$destination);
   if(!$valid){$failed++;$diagnostic['failedNormalization']++;continue;}
   $route=$valid[0];$matches[]=['route'=>$route,'rank'=>route_rank($route),'matchMethod'=>$matchMethod];
  }
  // QA-only bounded recovery: seek the exact departure on a shorter origin-to-interchange journey.
  // Never invent a first leg or treat a neighbouring train as the candidate.
  if(!$matches){
   $diagnostic['recovery']=['status'=>'NOT_FOUND','attempts'=>[]];
   $interchanges=[];
   foreach($journeys as $j){
    foreach(val($j,'legs',[]) as $leg){
     if(!is_array($leg)||!mode(val($leg,'transportation',[])))continue;
     $node=val($leg,'destination',[]);
     $id=(string)val($node,'id','');
     if($id!==''&&$id!==(string)$from['id']&&$id!==(string)$to['id'])$interchanges[$id]=['id'=>$id,'name'=>(string)val($node,'name',val($node,'disassembledName',$id)),'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
    }
   }
   $interchanges=array_slice(array_values($interchanges),0,2);
   foreach($interchanges as $interchange){
    $at=(new DateTimeImmutable('@'.max(0,$time-120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
    $params=['depArrMacro'=>'dep','itdDate'=>$at->format('Ymd'),'itdTime'=>$at->format('Hi'),'type_origin'=>'stop','name_origin'=>$from['id'],'type_destination'=>'stop','name_destination'=>$interchange['id'],'calcNumberOfTrips'=>30,'TfNSWTR'=>'true','excludedMeans'=>'checkbox','exclMOT_4'=>1,'exclMOT_5'=>1,'exclMOT_7'=>1,'exclMOT_9'=>1,'exclMOT_11'=>1];
    $body=timed_upstream('trip',$params,0);
    $raw=val($body,'journeys',[]);
    $attempt=['interchange'=>$interchange,'firstLegJourneys'=>is_array($raw)?count($raw):0,'matchedFirstLegs'=>0,'validFirstRoutes'=>0,'validOnwardRoutes'=>0,'stitchedRoutes'=>0];
    foreach(is_array($raw)?$raw:[] as $j){
     if(!is_array($j))continue;
     $rail=null;
     foreach(val($j,'legs',[]) as $leg)if(is_array($leg)&&mode(val($leg,'transportation',[]))){$rail=$leg;break;}
     if(!$rail)continue;
     $tr=val($rail,'transportation',[]);$node=val($rail,'origin',[]);
     $otherIds=array_map('strval',transportation_trip_ids($tr));
     $idMatch=(bool)($ids&&$otherIds&&array_intersect($ids,$otherIds));
     $lineMatch=(string)val($tr,'disassembledName','')===(string)($first['line']??'');
     $planned=iso_ts(val($node,'departureTimePlanned'));$estimated=iso_ts(val($node,'departureTimeEstimated'));
     $cp=iso_ts($first['planned']??null);$ce=iso_ts($first['estimated']??null);
     $timeMatch=($cp!==null&&$planned!==null&&abs($cp-$planned)<=120)||($ce!==null&&$estimated!==null&&abs($ce-$estimated)<=120);
     if(!$idMatch&&!($lineMatch&&$timeMatch))continue;
     $attempt['matchedFirstLegs']++;
     $firstRoutes=normalized_journeys(['journeys'=>[$j]],$origin,$interchange);
     foreach($firstRoutes as $firstRoute){
      $attempt['validFirstRoutes']++;
      $legs=$firstRoute['legs']??[];
      if(count($legs)!==1)continue; // Only an exact single-train first segment is recovered.
      $arrival=route_arrival_ts($firstRoute);
      if($arrival===PHP_INT_MAX)continue;
      $onwardAt=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
      $onwardParams=$params;
      $onwardParams['itdDate']=$onwardAt->format('Ymd');$onwardParams['itdTime']=$onwardAt->format('Hi');
      $onwardParams['name_origin']=$interchange['id'];$onwardParams['name_destination']=$to['id'];
      $onward=timed_upstream('trip',$onwardParams,0);
      $onwardRoutes=normalized_journeys($onward,$interchange,$destination);
      $attempt['validOnwardRoutes']+=count($onwardRoutes);
      foreach(array_slice($onwardRoutes,0,12) as $next){
       $stitched=stitch_routes($firstRoute,$next,$origin,$destination);
       if(!$stitched)continue;
       $attempt['stitchedRoutes']++;
       $matches[]=['route'=>$stitched,'rank'=>route_rank($stitched),'matchMethod'=>'RECOVERED_CONNECTION'];
      }
     }
    }
    $diagnostic['recovery']['attempts'][]=$attempt;
    if($matches){$diagnostic['recovery']['status']='RECOVERED';break;}
   }
   if(!$interchanges)$diagnostic['recovery']['status']='NO_INTERCHANGE_CANDIDATES';
  }
  usort($matches,fn($a,$b)=>($a['matchMethod']==='TRIP_ID'?0:1)<=>($b['matchMethod']==='TRIP_ID'?0:1) ?: ($a['rank']<=>$b['rank']));
  $results[]=['identity'=>$item['identity'],'firstDeparture'=>$first,'departureTimestamp'=>$time,'window'=>$item['window'],'status'=>$matches?'VALID':'UNRESOLVED','reason'=>$matches?(($matches[0]['matchMethod']??'')==='RECOVERED_CONNECTION'?'RECOVERED_CONNECTION':'COMPLETE_RAIL_JOURNEY_FOUND'):($failed?'MATCHED_SERVICE_FAILED_VALIDATION':'NO_MATCHING_JOURNEY_RETURNED'),'matchingJourneys'=>count($matches),'matchDiagnostics'=>$diagnostic,'bestJourney'=>$matches[0]??null,'matchMethods'=>array_values(array_unique(array_column($matches,'matchMethod')))];
 }
 // Consolidate candidates only when their validated first-leg physical trip ID agrees.
 // Unresolved records remain visible and are never silently removed.
 $groups=[];$candidateEvidence=[];
 foreach($results as $i=>$result){
  $best=$result['bestJourney']??null;
  $leg=$best['route']['legs'][0]??null;
  $physicalId=is_array($leg)?(string)($leg['tripIds'][0]??''):'';
  $key=$physicalId!==''?'trip|'.$physicalId.'|'.($leg['departure']??''):'candidate|'.$result['identity'];
  $groups[$key][]=$i;
 }
 $services=[];
 foreach($groups as $indices){
  $members=array_map(fn($i)=>$results[$i],$indices);
  usort($members,fn($a,$b)=>(in_array('TRIP_ID',$a['matchMethods']??[],true)?0:1)<=>(in_array('TRIP_ID',$b['matchMethods']??[],true)?0:1) ?: (($a['bestJourney']['rank']??[])<=>($b['bestJourney']['rank']??[])));
  $chosen=$members[0];$route=$chosen['bestJourney']['route']??null;$risks=[];
  if(is_array($route)){
   $legs=$route['legs']??[];
   for($i=1;$i<count($legs);$i++){
    $prev=$legs[$i-1];$next=$legs[$i];
    $arr=iso_ts($prev['arrival']??null);$dep=iso_ts($next['departure']??null);
    $arrPlatform=(string)($prev['destination']['platform']??'');
    $depPlatform=(string)($next['origin']['platform']??'');
    if($arr!==null&&$dep!==null){
     $minutes=($dep-$arr)/60;
     if($minutes<0||($minutes<5&&$arrPlatform!==''&&$depPlatform!==''&&$arrPlatform!==$depPlatform)){
      $risks[]=['station'=>$next['origin']['name']??'','minutes'=>$minutes,'arrivalPlatform'=>$arrPlatform,'departurePlatform'=>$depPlatform,'reason'=>$minutes<0?'NEGATIVE_TRANSFER_TIME':'SHORT_PLATFORM_CHANGE','assessment'=>'RISKY_UNVERIFIED'];
     }
    }
   }
  }
  $services[]=['identity'=>$chosen['identity'],'firstDeparture'=>$chosen['firstDeparture'],'departureTimestamp'=>$chosen['departureTimestamp'],'status'=>$chosen['status'],'reason'=>$chosen['reason'],'bestJourney'=>$chosen['bestJourney'],'matchMethod'=>$chosen['bestJourney']['matchMethod']??null,'sourceCandidateCount'=>count($indices),'sourceCandidateIds'=>array_column($members,'identity'),'connectionRisks'=>$risks,'connectionAssessment'=>$risks?'RISKY_UNVERIFIED':'NOT_FLAGGED','evidence'=>$members];
 }
 usort($services,fn($a,$b)=>$a['departureTimestamp']<=>$b['departureTimestamp']); 
 // Step 3 QA-only: rank unique complete journeys; do not silently promote unresolved services.
 $ranked=[];
 foreach($services as $service){
  if($service['status']!=='VALID'||!is_array($service['bestJourney']['route']??null))continue;
  $route=$service['bestJourney']['route'];$legs=$route['legs']??[];
  $departure=iso_ts($route['railStart']['departure']??null)??iso_ts($legs[0]['departure']??null);
  $arrival=iso_ts($route['railEnd']['arrival']??null)??iso_ts($legs[count($legs)-1]['arrival']??null);
  if($departure===null||$arrival===null||$arrival<$departure)continue;
  $transfers=max(0,count($legs)-1);$riskCount=count($service['connectionRisks']);
  // Arrival first among non-flagged journeys, then transfers, duration, departure.
  // Flagged journeys remain visible but cannot displace a non-flagged option.
  $ranked[]=['identity'=>$service['identity'],'firstDeparture'=>$service['firstDeparture'],'arrival'=>gmdate('c',$arrival),'durationMinutes'=>round(($arrival-$departure)/60,1),'transfers'=>$transfers,'riskCount'=>$riskCount,'connectionRisks'=>$service['connectionRisks'],'matchMethod'=>$service['matchMethod'],'sourceCandidateCount'=>$service['sourceCandidateCount'],'route'=>$route,'sortKey'=>[$riskCount>0?1:0,$arrival,$transfers,$arrival-$departure,$departure]];
 }
 usort($ranked,fn($a,$b)=>$a['sortKey']<=>$b['sortKey']);
 foreach($ranked as $i=>&$entry){$entry['position']=$i+1;$entry['selectionReason']=$entry['riskCount']?'RISK_FLAGGED_REVIEW_REQUIRED':'NO_SHORT_PLATFORM_CHANGE_FLAGGED';unset($entry['sortKey']);}unset($entry);

 return ['mode'=>'Step 2 QA validation','snapshotAt'=>$discovery['snapshotAt'],'origin'=>$from,'destination'=>$to,'candidateCount'=>count($items),'physicalServiceCount'=>count($services),'services'=>$services,'ranking'=>['policy'=>'Unflagged complete routes first; then earliest arrival, fewest transfers, shortest duration, earlier departure. Risk flagged routes remain visible.','ranked'=>$ranked,'selected'=>$ranked[0]??null,'unresolvedCount'=>count(array_filter($services,fn($x)=>$x['status']!=='VALID'))],'results'=>$results,'duplicateReview'=>$duplicateReview,'trace'=>$trace,'discovery'=>$discovery,'note'=>'Unmatched departures remain UNRESOLVED. Short cross-platform connections are flagged heuristically; QA recovery attempts up to two interchange candidates, requiring exact first-train matching, a normalized single-train first segment and a stitched onward route with at least two minutes transfer time; other connections may remain unresolved.'];
}
