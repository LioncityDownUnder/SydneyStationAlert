<?php
declare(strict_types=1);
require getcwd().'/api/lib/core.php';
$routes=[['Hurstville','Kogarah'],['Hurstville','Padstow'],['Chatswood','Gadigal']];
date_default_timezone_set('Australia/Sydney');
foreach($routes as [$fromName,$toName]){
 $from=station_search($fromName);$to=station_search($toName);
 $find=function($items,$name){foreach($items as $s){if(strcasecmp($s['name']??'',$name)===0)return $s;}return $items[0]??null;};
 $o=$find($from,$fromName);$d=$find($to,$toName);
 $out=['route'=>$fromName.' -> '.$toName,'stationsFound'=>(bool)$o&&(bool)$d];
 if(!$o||!$d){echo json_encode($out)."\n";continue;}
 $p=['depArrMacro'=>'dep','itdDate'=>date('Ymd'),'itdTime'=>date('Hi'),'type_origin'=>'stop','name_origin'=>$o['id'],'type_destination'=>'stop','name_destination'=>$d['id'],'calcNumberOfTrips'=>22,'TfNSWTR'=>'true'];
 $start=microtime(true);
 $b=upstream('trip',$p,0);
 $out['ms']=(int)round((microtime(true)-$start)*1000);
 $out['journeys']=count($b['journeys']??[]);
 $out['systemMessages']=array_map(fn($x)=>['module'=>$x['module']??null,'code'=>$x['code']??null],array_slice($b['systemMessages']??[],0,4));
 $out['normalized']=count(normalized_journeys($b,$o,$d));
 $reasons=['nonRail'=>0,'unrecognizedTransport'=>0,'missingStop'=>0,'endpointMismatch'=>0];
 foreach($b['journeys']??[] as $j){
  $legs=$j['legs']??[];$rail=[];$nonRail=false;
  foreach($legs as $leg){
   $t=$leg['transportation']??[];$m=mode($t);
   if(!$m){if(is_transfer_walk($t))continue;$nonRail=true;$reasons['unrecognizedTransport']++;break;}
   $a=stop($leg['origin']??null,$m);$z=stop($leg['destination']??null,$m);
   if(!$a||!$z){$reasons['missingStop']++;continue;}
   $rail[]=[$a,$z];
  }
  if($nonRail){$reasons['nonRail']++;continue;}
  if($rail&&(!same_station($rail[0][0],$o)||!same_station($rail[count($rail)-1][1],$d)))$reasons['endpointMismatch']++;
 }
 $out['rejectIndicators']=$reasons;
 echo json_encode($out,JSON_INVALID_UTF8_SUBSTITUTE)."\n";
}
