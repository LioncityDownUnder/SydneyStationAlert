<?php
declare(strict_types=1);
function qa_discover(array $from,array $to,DateTimeImmutable $now):array{
 $start=$now->getTimestamp();$windows=[[0,30],[30,60],[60,120]];$trace=[];$found=[];
 foreach($windows as $window){
  $at=(new DateTimeImmutable('@'.($start+$window[0]*60)))->setTimezone(new DateTimeZone('Australia/Sydney'));
  $params=['depArrMacro'=>'dep','itdDate'=>$at->format('Ymd'),'itdTime'=>$at->format('Hi'),'type_origin'=>'stop','name_origin'=>$from['id'],'type_destination'=>'stop','name_destination'=>$to['id'],'calcNumberOfTrips'=>60,'TfNSWTR'=>'true'];
  $body=timed_upstream('trip',$params,0);$entry=['stage'=>'discovery_window','window'=>implode('-',$window).' min','request'=>$params,'rawResponse'=>$body,'candidates'=>[]];
  foreach(val($body,'journeys',[]) as $index=>$journey){
   if(!is_array($journey))continue;
   $first=null;$segments=val($journey,'legs',[]);
   foreach($segments as $leg){$transport=val($leg,'transportation',[]);if(!mode($transport))continue;$node=val($leg,'origin',[]);$first=['station'=>['id'=>(string)val($node,'id',''),'name'=>(string)val($node,'name',val($node,'disassembledName',''))],'planned'=>val($node,'departureTimePlanned'),'estimated'=>val($node,'departureTimeEstimated'),'line'=>val($transport,'disassembledName',''),'tripIds'=>transportation_trip_ids($transport)];break;}
   $time=$first?(iso_ts($first['estimated'])??iso_ts($first['planned'])):null;$reasons=[];
   if(!$first)$reasons[]='NO_RAIL_LEG';
   elseif(!same_station($first['station'],$from))$reasons[]='ORIGIN_MISMATCH';
   if($time===null)$reasons[]='MISSING_TIME';
   else{if($time<$start+120)$reasons[]='LESS_THAN_2_MINUTES';if($time<$start+$window[0]*60||$time>=$start+$window[1]*60)$reasons[]='OUTSIDE_WINDOW';}
   $reaches=false;
   foreach($segments as $leg){$node=val($leg,'destination',[]);if(is_array($node)&&same_station(['id'=>(string)val($node,'id',''),'name'=>(string)val($node,'name',val($node,'disassembledName',''))],$to)){$reaches=true;break;}}
   if(!$reaches)$reasons[]='DESTINATION_NOT_PRESENT';
   $identity=hash('sha256',($first['station']['id']??'').'|'.$time.'|'.implode(',',($first['tripIds']??[])).'|'.($first['line']??''));
   if(!$reasons&&isset($found[$identity]))$reasons[]='DUPLICATE';
   $entry['candidates'][]=['index'=>$index,'firstDeparture'=>$first,'departureTimestamp'=>$time,'status'=>$reasons?'excluded':'discovered','reasons'=>$reasons?:['AWAITING_VALIDATION'],'identity'=>$identity];
   if(!$reasons)$found[$identity]=['firstDeparture'=>$first,'departureTimestamp'=>$time,'identity'=>$identity,'window'=>$entry['window'],'journey'=>$journey];
  }
  $entry['decision']=$found?'Found candidates; stop expanding after full window':'No candidates; expand window';$trace[]=$entry;if($found)break;
 }
 $ordered=array_values($found);usort($ordered,fn($a,$b)=>$a['departureTimestamp']<=>$b['departureTimestamp']);
 return ['snapshotAt'=>$now->format(DATE_ATOM),'mode'=>'Step 1 QA discovery','minimumLeadSeconds'=>120,'candidateCount'=>count($ordered),'discovered'=>$ordered,'selected'=>null,'trace'=>$trace,'note'=>'Discovery only, not final route validation. TfNSW result caps may limit completeness.'];
}
