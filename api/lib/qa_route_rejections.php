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
