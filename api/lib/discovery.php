<?php
declare(strict_types=1);
/** Step 1: station departures only. Destination is intentionally not a filter. */
function qa_discover(array $from,array $to,DateTimeImmutable $now):array{
 $snapshot=$now->getTimestamp();$windows=[[0,30],[30,60],[60,120]];$trace=[];$found=[];
 foreach($windows as $window){
  $start=$snapshot+$window[0]*60;$end=$snapshot+$window[1]*60;
  $at=(new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone('Australia/Sydney'));
  $params=['type_dm'=>'stop','name_dm'=>$from['id'],'mode'=>'direct','itdDate'=>$at->format('Ymd'),'itdTime'=>$at->format('Hi'),'limit'=>100,'TfNSWDM'=>'true','excludedMeans'=>'checkbox','exclMOT_4'=>1,'exclMOT_5'=>1,'exclMOT_7'=>1,'exclMOT_9'=>1,'exclMOT_11'=>1];
  $body=timed_upstream('departure_mon',$params,0);
  $events=val($body,'stopEvents',[]);
  $entry=['stage'=>'discovery_window','window'=>implode('-',$window).' min','request'=>$params,'rawResponse'=>$body,'candidates'=>[],'returnedCount'=>is_array($events)?count($events):0];
  foreach(is_array($events)?$events:[] as $index=>$event){
   if(!is_array($event))continue;
   $transport=val($event,'transportation',[]);
   $m=mode($transport);
   $loc=val($event,'location',[]);
   if(!is_array($loc))$loc=[];
   $planned=val($event,'departureTimePlanned',val($loc,'departureTimePlanned'));
   $estimated=val($event,'departureTimeEstimated',val($loc,'departureTimeEstimated'));
   $time=iso_ts($estimated)??iso_ts($planned);
   $first=['station'=>['id'=>(string)val($loc,'id',''),'name'=>(string)val($loc,'name',val($loc,'disassembledName',''))],'planned'=>$planned,'estimated'=>$estimated,'line'=>val($transport,'disassembledName',val($transport,'number','')),'tripIds'=>transportation_trip_ids($transport),'mode'=>$m];
   $reasons=[];
   if(!$m)$reasons[]='NOT_TRAIN_OR_METRO';
   if($time===null)$reasons[]='MISSING_DEPARTURE_TIME';
   else{
    if($time<$snapshot)$reasons[]='ALREADY_DEPARTED';
    if($time<$start||$time>=$end)$reasons[]='OUTSIDE_WINDOW';
   }
   // Departure-monitor locations may be platform IDs, not the parent station ID.
   // The requested stop ID is authoritative; platform names are not used to reject.
   $identity=hash('sha256',implode('|',[$time,(string)$first['line'],implode(',',array_map('strval',$first['tripIds'])),(string)val($transport,'number','')]));
   if(!$reasons&&isset($found[$identity]))$reasons[]='DUPLICATE_DEPARTURE';
   $entry['candidates'][]=['index'=>$index,'firstDeparture'=>$first,'departureTimestamp'=>$time,'status'=>$reasons?'excluded':'discovered','boardingWarning'=>($time!==null&&$time>=$snapshot&&$time<$snapshot+120)?'DEPARTING_SOON':null,'reasons'=>$reasons?:['AWAITING_DESTINATION_VALIDATION'],'identity'=>$identity];
   if(!$reasons)$found[$identity]=['firstDeparture'=>$first,'departureTimestamp'=>$time,'identity'=>$identity,'window'=>$entry['window'],'event'=>$event];
  }
  $entry['decision']=$found?'Found eligible departures; destination feasibility deferred to Step 2':'No eligible departures; expand search window';
  $trace[]=$entry;
  if($found)break;
 }
 $ordered=array_values($found);usort($ordered,fn($a,$b)=>$a['departureTimestamp']<=>$b['departureTimestamp']);
 return ['snapshotAt'=>$now->format(DATE_ATOM),'mode'=>'Step 1 QA discovery','minimumLeadSeconds'=>0,'boardingWarningThresholdSeconds'=>120,'candidateCount'=>count($ordered),'discovered'=>$ordered,'selected'=>null,'trace'=>$trace,'note'=>'Origin station departures only. Destination '.$to['name'].' is not used to reject trains. Step 2 must establish onward route feasibility. TfNSW stop-event limits may constrain completeness.'];
}
