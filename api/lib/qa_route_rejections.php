<?php
declare(strict_types=1);
// Aggregate why journey candidates cannot be used for rail-only routing.
function qa_route_rejection_summary(array $body,array $origin,array $destination):array{
 $journeys=val($body,'journeys',[]);
 if(!is_array($journeys))$journeys=[];
 $reasons=[];$classes=[];$journeyLegDetails=[];
 foreach(array_slice($journeys,0,30) as $journeyIndex=>$j){
  $legs=val(is_array($j)?$j:[],'legs',[]);
  $reason='MISSING_LEGS';
  if(is_array($legs)&&count($legs)>0){
   $nonRail=false;$rail=[];$legDetails=[];
   foreach(array_slice($legs,0,12) as $legIndex=>$leg){
    if(!is_array($leg))continue;
    $t=val($leg,'transportation',[]);
    $p=val(is_array($t)?$t:[],'product',[]);
    $class=(string)val(is_array($p)?$p:[],'class','unknown');
    $classes[$class]=($classes[$class]??0)+1;
    $m=mode($t);
    $kind=$m??(is_transfer_walk($t)?'walk':'non_rail');
    $legDetails[]=['legIndex'=>$legIndex,'kind'=>$kind,'transportClass'=>$class];
    if(!$m){if($kind!=='walk')$nonRail=true;continue;}
    $o=stop(val($leg,'origin'),$m);$d=stop(val($leg,'destination'),$m);
    if($o&&$d)$rail[]=['origin'=>$o,'destination'=>$d];
   }
   if($nonRail)$reason='NON_RAIL_LEG';
   elseif(!$rail)$reason='NO_USABLE_RAIL_LEGS';
   elseif(!same_station($rail[0]['origin'],$origin))$reason='ORIGIN_MISMATCH';
   elseif(!same_station($rail[count($rail)-1]['destination'],$destination))$reason='DESTINATION_MISMATCH';
   else $reason='RAIL_CANDIDATE';
   $journeyLegDetails[]=['journeyIndex'=>$journeyIndex,'reason'=>$reason,'totalLegs'=>count($legs),'legs'=>$legDetails];
  }
  $reasons[$reason]=($reasons[$reason]??0)+1;
 }
 return ['rawJourneys'=>count($journeys),'sampledJourneys'=>min(30,count($journeys)),'railOnlyNormalized'=>count(normalized_journeys($body,$origin,$destination)),'reasons'=>$reasons,'transportClasses'=>$classes,'journeyLegDetails'=>$journeyLegDetails];
}

/** QA-only comparative request parameters; do not use in commuter routing. */
function qa_rail_filter_params(array $base):array{
 return array_merge($base,['excludedMeans'=>'checkbox','exclMOT_4'=>1,'exclMOT_5'=>1,'exclMOT_7'=>1,'exclMOT_9'=>1,'exclMOT_11'=>1]);
}
function qa_rail_filter_comparison(?array $baseline,?array $filtered,array $origin,array $destination):array{
 $summarize=static function(?array $body)use($origin,$destination):array{
  if($body===null)return ['upstreamResponseReceived'=>false,'summary'=>null];
  $s=qa_route_rejection_summary($body,$origin,$destination);
  // Only safe aggregate counts are needed; avoid raw service IDs or journey payloads.
  return ['upstreamResponseReceived'=>true,'summary'=>[
   'rawJourneys'=>$s['rawJourneys'],
   'sampledJourneys'=>$s['sampledJourneys'],
   'railOnlyNormalized'=>$s['railOnlyNormalized'],
   'reasons'=>$s['reasons'],
   'transportClasses'=>$s['transportClasses']
  ]];
 };
 return ['baseline'=>$summarize($baseline),'railFiltered'=>$summarize($filtered),
  'railFilterConfirmed'=>false,
  'note'=>'Request excludes non-rail product classes, but actual provider filter compliance and service availability are not independently verified.'];
}

/** Step 5AG: result metadata only, never returns raw journey or changes commuter routing. */
function qa_rail_search_result(?array $body,array $origin,array $destination):array{
 if($body===null)return ['upstreamResponseReceived'=>false,'railOnlyNormalized'=>0,'rawJourneys'=>0,'reasons'=>[],'status'=>'UPSTREAM_UNAVAILABLE'];
 $s=qa_route_rejection_summary($body,$origin,$destination);
 return ['upstreamResponseReceived'=>true,'rawJourneys'=>$s['rawJourneys'],
  'railOnlyNormalized'=>$s['railOnlyNormalized'],'reasons'=>$s['reasons'],
  'status'=>$s['railOnlyNormalized']>0?'RAIL_CANDIDATE_UNVERIFIED':'NO_RAIL_CANDIDATE_IN_RESPONSE'];
}
