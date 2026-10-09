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
 $journeys=[];$trace=[];
 foreach($requests as $params){
  $body=timed_upstream('trip',$params,0);
  $raw=val($body,'journeys',[]);
  $trace[]=['stage'=>'validation_probe','request'=>$params,'returnedCount'=>is_array($raw)?count($raw):0,'rawResponse'=>$body];
  foreach(is_array($raw)?$raw:[] as $j)if(is_array($j))$journeys[]=$j;
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
  foreach($journeys as $j){
   $firstLeg=null;
   foreach(val($j,'legs',[]) as $leg){if(is_array($leg)&&mode(val($leg,'transportation',[]))){$firstLeg=$leg;break;}}
   if(!$firstLeg)continue;
   $node=val($firstLeg,'origin',[]);
   $departure=iso_ts(val($node,'departureTimeEstimated'))??iso_ts(val($node,'departureTimePlanned'));
   $transport=val($firstLeg,'transportation',[]);
   $otherIds=array_map('strval',transportation_trip_ids($transport));
   $idMatch=$ids&&$otherIds&&array_intersect($ids,$otherIds);
   $timeMatch=$departure!==null&&abs($departure-$time)<=120;
   $lineMatch=(string)val($transport,'disassembledName','')===(string)($first['line']??'');
   if(!$idMatch&&!($timeMatch&&$lineMatch))continue;
   $valid=normalized_journeys(['journeys'=>[$j]],$origin,$destination);
   if(!$valid){$failed++;continue;}
   $route=$valid[0];$matches[]=['route'=>$route,'rank'=>route_rank($route),'matchMethod'=>$idMatch?'TRIP_ID':'TIME_AND_LINE'];
  }
  usort($matches,fn($a,$b)=>($a['matchMethod']==='TRIP_ID'?0:1)<=>($b['matchMethod']==='TRIP_ID'?0:1) ?: ($a['rank']<=>$b['rank']));
  $results[]=['identity'=>$item['identity'],'firstDeparture'=>$first,'departureTimestamp'=>$time,'window'=>$item['window'],'status'=>$matches?'VALID':'UNRESOLVED','reason'=>$matches?'COMPLETE_RAIL_JOURNEY_FOUND':($failed?'MATCHED_SERVICE_FAILED_VALIDATION':'NO_MATCHING_JOURNEY_RETURNED'),'matchingJourneys'=>count($matches),'bestJourney'=>$matches[0]??null,'matchMethods'=>array_values(array_unique(array_column($matches,'matchMethod')))];
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
 return ['mode'=>'Step 2 QA validation','snapshotAt'=>$discovery['snapshotAt'],'origin'=>$from,'destination'=>$to,'candidateCount'=>count($items),'physicalServiceCount'=>count($services),'services'=>$services,'results'=>$results,'duplicateReview'=>$duplicateReview,'trace'=>$trace,'discovery'=>$discovery,'note'=>'Unmatched departures remain UNRESOLVED. Short cross-platform connections are flagged heuristically; transfer feasibility and independent interchange search are not yet verified.'];
}
