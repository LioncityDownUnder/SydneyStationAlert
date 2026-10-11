<?php
declare(strict_types=1);

/** Rail journey search probes, route ranking and interchange stitching. */
function best_connecting_route(array $firstRoutes,array $secondRoutes,array $origin,array $destination,int $earliestDeparture):?array{
 $best=null;
 foreach(array_slice($firstRoutes,0,12) as $first){
  $depart=route_departure_ts($first);if($depart<$earliestDeparture||$depart===PHP_INT_MAX)continue;
  foreach(array_slice($secondRoutes,0,16) as $second){
   $candidate=stitch_routes($first,$second,$origin,$destination);
   if($candidate)$best=better_route($best,$candidate);
  }
 }
 return $best;
}
function route_arrival_ts(array $route):int{$legs=val($route,'legs',[]);if(!is_array($legs)||!$legs)return PHP_INT_MAX;return iso_ts(val($legs[count($legs)-1],'arrival'))??PHP_INT_MAX;}
function route_departure_ts(array $route):int{$legs=val($route,'legs',[]);if(!is_array($legs)||!$legs)return PHP_INT_MAX;return iso_ts(val($legs[0],'departure'))??PHP_INT_MAX;}
function journey_search_probes(DateTimeImmutable $now):array{
 // Search the next 24 hours from now, including the following calendar day.
 // Keep the established bounded probe offsets and parallel fast path.
 $times=[$now];
 foreach([30,60,90,120,180,240,300,360,480,600,720,840,960,1080,1200,1320,1440] as $minutes)$times[]=$now->modify('+'.$minutes.' minutes');
 return $times;
}
function earliest_future_route(array $body,array $origin,array $destination,int $notBefore):?array{
 $best=null;
 foreach(normalized_journeys($body,$origin,$destination) as $route){
  if(route_departure_ts($route)<$notBefore)continue;
  $best=better_route($best,$route);
 }
 return $best;
}
function route_rank(array $route):array{
 $arrival=route_arrival_ts($route);$departure=route_departure_ts($route);
 $duration=($arrival!==PHP_INT_MAX&&$departure!==PHP_INT_MAX&&$arrival>=$departure)?$arrival-$departure:PHP_INT_MAX;
 $transfers=max(0,count(val($route,'legs',[]))-1);
 return [$arrival,$transfers,$duration,$departure];
}
function better_route(?array $current,?array $candidate):?array{if(!$candidate)return $current;if(!$current)return $candidate;return route_rank($candidate)<route_rank($current)?$candidate:$current;}
function rail_transfer_candidates(array $body,array $origin,array $destination,int $limit=8):array{
 $nodes=[];$journeys=val($body,'journeys',[]);if(!is_array($journeys))return [];
 foreach($journeys as $j){
  $legs=val($j,'legs',[]);if(!is_array($legs))continue;
  foreach($legs as $leg){
   if(!is_array($leg))continue;$transport=val($leg,'transportation',[]);$m=mode($transport);if(!$m)continue;
   $line=strtolower(trim((string)val($transport,'disassembledName',val($transport,'number',$m))));
   $items=[];$o=stop(val($leg,'origin'),$m);$d=stop(val($leg,'destination'),$m);if($o)$items[]=['s'=>$o,'boundary'=>true];
   $seq=val($leg,'stopSequence',[]);if(is_array($seq))foreach($seq as $raw){$s=sequence_stop($raw,$m);if($s)$items[]=['s'=>$s,'boundary'=>false];}
   if($d)$items[]=['s'=>$d,'boundary'=>true];
   foreach($items as $item){
    $st=$item['s'];if(same_station($st,$origin)||same_station($st,$destination))continue;
    $key=strtolower(clean_station_name((string)$st['name']));if($key==='')continue;
    if(!isset($nodes[$key]))$nodes[$key]=['station'=>$st,'lines'=>[],'boundary'=>0,'seen'=>0];
    $nodes[$key]['seen']++;if($item['boundary'])$nodes[$key]['boundary']++;if($line!=='')$nodes[$key]['lines'][$line]=true;
   }
  }
 }
 $ranked=[];foreach($nodes as $node){$lineCount=count($node['lines']);$score=($lineCount>1?200+50*$lineCount:0)+40*$node['boundary']+$node['seen'];$ranked[]=['score'=>$score,'station'=>$node['station']];}
 usort($ranked,fn($a,$b)=>$b['score']<=>$a['score'] ?: strnatcasecmp((string)$a['station']['name'],(string)$b['station']['name']));
 return array_map(fn($x)=>$x['station'],array_slice($ranked,0,$limit));
}
function prioritized_transfer_candidates(?array $route,array $body,array $origin,array $destination,int $limit=2):array{
 $out=[];$seen=[];
 if(is_array($route)){
  foreach(val($route,'transfers',[]) as $transfer){
   if(!is_array($transfer))continue;
   $name=strtolower(clean_station_name((string)val($transfer,'name','')));$id=strtolower(trim((string)val($transfer,'id','')));
   $key=$id!==''?$id:$name;if($key===''||isset($seen[$key]))continue;
   $seen[$key]=true;$out[]=['id'=>(string)val($transfer,'id',''),'name'=>(string)val($transfer,'name',''),'mode'=>(string)val($transfer,'mode','train'),'lat'=>(float)val($transfer,'lat',0.0),'lon'=>(float)val($transfer,'lon',0.0)];
   if(count($out)>=$limit)return $out;
  }
 }
 foreach(rail_transfer_candidates($body,$origin,$destination,$limit*2) as $transfer){
  $name=strtolower(clean_station_name((string)val($transfer,'name','')));$id=strtolower(trim((string)val($transfer,'id','')));
  $key=$id!==''?$id:$name;if($key===''||isset($seen[$key]))continue;
  $seen[$key]=true;$out[]=$transfer;if(count($out)>=$limit)break;
 }
 return $out;
}
function stitch_routes(array $first,array $second,array $origin,array $destination):?array{
 $legs1=val($first,'legs',[]);$legs2=val($second,'legs',[]);if(!is_array($legs1)||!$legs1||!is_array($legs2)||!$legs2)return null;
 $end1=$legs1[count($legs1)-1]['destination'];$start2=$legs2[0]['origin'];if(!same_station($end1,$start2))return null;
 $arrive=iso_ts($legs1[count($legs1)-1]['arrival']);$depart=iso_ts($legs2[0]['departure']);
 if($arrive!==null&&$depart!==null&&$depart<$arrive+120)return null;
 $legs=array_merge($legs1,$legs2);$all=[];foreach($legs as $leg)foreach(val($leg,'stops',[]) as $s)append_unique_station($all,$s);
 $transfers=[];for($i=1;$i<count($legs);$i++){
  $a=$legs[$i-1]['destination'];$d=$legs[$i]['origin'];if(!same_station($a,$d))return null;
  $transfers[]=['id'=>$d['id'],'name'=>$d['name'],'mode'=>$d['mode'],'lat'=>$d['lat'],'lon'=>$d['lon'],'arrivalPlatform'=>$a['platform']??null,'departurePlatform'=>$d['platform']??null];
 }
 return ['id'=>hash('sha256',$origin['id'].'|'.$destination['id'].'|'.json_encode($legs)),'origin'=>$origin,'destination'=>$destination,'legs'=>$legs,'stops'=>$all,'transfers'=>$transfers,'omittedNonRail'=>false,'railStartsAtOrigin'=>true,'railEndsAtDestination'=>true,'railStart'=>$legs[0]['origin'],'railEnd'=>$legs[count($legs)-1]['destination'],'fetchedAt'=>gmdate('c'),'source'=>'validated','nextDepartures'=>[]];
}
