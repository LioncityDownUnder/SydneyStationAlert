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
  usort($matches,fn($a,$b)=>$a['rank']<=>$b['rank']);
  $results[]=['identity'=>$item['identity'],'firstDeparture'=>$first,'departureTimestamp'=>$time,'window'=>$item['window'],'status'=>$matches?'VALID':'UNRESOLVED','reason'=>$matches?'COMPLETE_RAIL_JOURNEY_FOUND':($failed?'MATCHED_SERVICE_FAILED_VALIDATION':'NO_MATCHING_JOURNEY_RETURNED'),'matchingJourneys'=>count($matches),'bestJourney'=>$matches[0]??null];
 }
 return ['mode'=>'Step 2 QA validation','snapshotAt'=>$discovery['snapshotAt'],'origin'=>$from,'destination'=>$to,'candidateCount'=>count($items),'results'=>$results,'duplicateReview'=>$duplicateReview,'trace'=>$trace,'discovery'=>$discovery,'note'=>'Unmatched departures remain UNRESOLVED. Connection risk and independent interchange search are not yet evaluated.'];
}
