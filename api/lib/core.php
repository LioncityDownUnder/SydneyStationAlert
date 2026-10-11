<?php
declare(strict_types=1);

require_once __DIR__.'/journey-performance.php';

function fail(string $code,string $message,int $status=400):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>['code'=>$code,'message'=>$message]]);exit;}
function val(array $a,string $k,mixed $default=null):mixed{return $a[$k]??$default;}
function point(mixed $node):?array{
 if(!is_array($node))return null;
 $c=val($node,'coord',val($node,'coordinates'));
 if(is_array($c)){
  if(array_is_list($c)&&count($c)>=2){
   $a=(float)$c[0];$b=(float)$c[1];
   if(abs($a)<=90&&abs($b)>90&&abs($b)<=180)return ['lat'=>$a,'lon'=>$b];
   if(abs($a)>90&&abs($a)<=180&&abs($b)<=90)return ['lat'=>$b,'lon'=>$a];
   if(abs($a)<=180&&abs($b)<=90)return ['lat'=>$b,'lon'=>$a];
  }
  if(isset($c['latitude'],$c['longitude']))return ['lat'=>(float)$c['latitude'],'lon'=>(float)$c['longitude']];
 }
 if(isset($node['lat'],$node['lon']))return ['lat'=>(float)$node['lat'],'lon'=>(float)$node['lon']];
 return null;
}
function mode(mixed $transport):?string{
 if(!is_array($transport))return null;
 $product=val($transport,'product',[]);
 if(!is_array($product))return null;
 $class=val($product,'class',null);
 if($class!==null&&is_numeric($class)){
  return match((int)$class){1=>'train',2=>'metro',default=>null};
 }
 // Only use explicit rail labels when TfNSW omits the product class.
 $name=strtolower(trim((string)val($product,'name','')));
 if(str_contains($name,'metro'))return 'metro';
 if(str_contains($name,'sydney trains')||$name==='train')return 'train';
 return null;
}
function location_rail_mode(mixed $raw):?string{
 if(!is_array($raw))return null;
 $values=[];foreach(['productClasses','modes'] as $field){$v=val($raw,$field,[]);if(is_array($v))$values=array_merge($values,$v);}
 foreach($values as $v){
  if(is_array($v)){$v=val($v,'id',val($v,'class',val($v,'name','')));}
  $text=strtolower(trim((string)$v));
  if($text==='1'||$text==='train'||str_contains($text,'heavy rail'))return 'train';
  if($text==='2'||$text==='metro'||str_contains($text,'sydney metro')||str_contains($text,'subway'))return 'metro';
 }
 return null;
}
function platform_from_name(mixed $raw):?string{
 if(!is_array($raw))return null;
 $name=(string)val($raw,'disassembledName',val($raw,'name',''));
 if(preg_match('/(?:^|[,\s])(?:platform|plat\.?|pl\.?)[\s:]*([0-9]+[a-z]?)(?:\b|$)/i',$name,$m))return strtoupper($m[1]);
 return null;
}
function normalize_platform(mixed $value):?string{
 if($value===null)return null;
 $p=trim((string)$value);if($p==='')return null;
 $p=preg_replace('/^platform\s*/i','',$p) ?? $p;
 $p=strtoupper(trim($p));
 if(preg_match('/^[0-9]+[A-Z]?$/',$p))return $p;
 if(preg_match('/^[A-Z]{2,8}([0-9]+[A-Z]?)$/',$p,$m))return $m[1];
 if(preg_match('/^P([0-9]+[A-Z]?)$/',$p,$m))return $m[1];
 return null;
}
function clean_station_name(string $name):string{
 $name=trim($name);
 $name=preg_replace('/\s*,?\s*(?:platform|plat\.?|pl\.?)[\s:]*[0-9]+[a-z]?\s*$/i','',$name) ?: $name;
 $name=preg_replace('/\s+(Station|Railway Station)\s*$/i','',$name) ?: $name;
 return trim($name," \t\n\r\0\x0B,");
}
function station(mixed $raw,?string $forcedMode=null):?array{
 if(!is_array($raw))return null;
 $coords=point($raw);$name=clean_station_name((string)val($raw,'disassembledName',val($raw,'name','')));$id=(string)val($raw,'id',val($raw,'stateless',''));
 if(!$coords||!$id||!$name)return null;
 $stationMode=$forcedMode??location_rail_mode($raw)??(str_contains(strtolower($name),'metro')?'metro':'train');
 return ['id'=>$id,'name'=>$name,'mode'=>$stationMode]+$coords;
}
function stop(mixed $raw,?string $transportMode=null):?array{
 $s=station($raw,$transportMode);if(!$s)return null;
 $props=val($raw,'properties',[]);
 $platform=platform_from_name($raw);
 if(!$platform)$platform=normalize_platform(val($props,'platform',val($raw,'platform')));
 return $s+['platform'=>$platform,'arrival'=>val($raw,'arrivalTimeEstimated',val($raw,'arrivalTimePlanned')),'departure'=>val($raw,'departureTimeEstimated',val($raw,'departureTimePlanned'))];
}
function parse_path(mixed $raw):array{
 $path=val($raw,'pathCoordinates',val($raw,'coords',val($raw,'coordinates',[])));
 if(is_string($path)){$decoded=json_decode($path,true);$path=is_array($decoded)?$decoded:[];}
 if(!is_array($path))return [];
 $points=[];foreach($path as $v){$p=point(['coord'=>$v]);if($p)$points[]=$p;}
 return $points;
}
function journey_endpoint_station(mixed $raw,array $fallback):array{
 $s=station($raw,(string)val($fallback,'mode','train'));
 if($s)return $s;
 return $fallback;
}
function transport_label(mixed $transport):string{
 if(!is_array($transport))return '';
 $product=val($transport,'product',[]);
 return strtolower(trim((string)(val($product,'name','').' '.val($transport,'name','').' '.val($transport,'disassembledName',''))));
}
function is_transfer_walk(mixed $transport):bool{
 if(!is_array($transport))return false;
 $product=val($transport,'product',[]);
 $class=(int)val(is_array($product)?$product:[],'class',-1);
 if($class===100)return true;
 $label=transport_label($transport);
 return str_contains($label,'walk')||str_contains($label,'footpath')||str_contains($label,'walking')||str_contains($label,'fussweg')||str_contains($label,'fußweg')||str_contains($label,'pedestrian');
}
function same_station(array $a,array $b):bool{
 if((string)val($a,'id','')!==''&&(string)val($a,'id','')===(string)val($b,'id',''))return true;
 $an=strtolower(clean_station_name((string)val($a,'name','')));$bn=strtolower(clean_station_name((string)val($b,'name','')));
 return $an!==''&&$an===$bn;
}
function iso_ts(mixed $value):?int{
 if(!is_string($value)||trim($value)==='')return null;
 $ts=strtotime($value);return $ts===false?null:$ts;
}
function sequence_stop(mixed $raw,?string $transportMode=null):?array{
 if(!is_array($raw))return null;
 $node=val($raw,'stopPoint',val($raw,'location',$raw));
 if(!is_array($node))return null;
 $s=stop($node,$transportMode);if(!$s)return null;
 $outerProps=val($raw,'properties',[]);if(!is_array($outerProps))$outerProps=[];
 $outerPlatform=platform_from_name($raw)??normalize_platform(val($outerProps,'platform',val($raw,'platform')));
 if($outerPlatform)$s['platform']=$outerPlatform;
 $arrival=val($raw,'arrivalTimeEstimated',val($raw,'arrivalTimePlanned',$s['arrival']??null));
 $departure=val($raw,'departureTimeEstimated',val($raw,'departureTimePlanned',$s['departure']??null));
 $s['arrival']=$arrival;$s['departure']=$departure;
 return $s;
}
function append_unique_station(array &$list,array $station):void{
 if(!$list){$list[]=$station;return;}
 $last=$list[count($list)-1];
 if(same_station($last,$station)){
  foreach(['platform','arrival','departure'] as $k){if(empty($last[$k])&&!empty($station[$k]))$last[$k]=$station[$k];}
  $list[count($list)-1]=$last;return;
 }
 $list[]=$station;
}
function leg_stop_sequence(array $leg,string $transportMode,array $originStop,array $destStop):array{
 $sequence=val($leg,'stopSequence',[]);$stops=[];
 if(is_array($sequence)){foreach($sequence as $raw){$s=sequence_stop($raw,$transportMode);if($s)append_unique_station($stops,$s);}}
 if(!$stops||!same_station($stops[0],$originStop))array_unshift($stops,$originStop);
 else {foreach(['platform','departure'] as $k){if(empty($stops[0][$k])&&!empty($originStop[$k]))$stops[0][$k]=$originStop[$k];}}
 $last=$stops[count($stops)-1];
 if(!same_station($last,$destStop))$stops[]=$destStop;
 else {foreach(['platform','arrival'] as $k){if(empty($last[$k])&&!empty($destStop[$k]))$last[$k]=$destStop[$k];}$stops[count($stops)-1]=$last;}
 return $stops;
}
function transportation_trip_ids(array $transport):array{
 $out=[];
 $properties=val($transport,'properties',[]);if(!is_array($properties))$properties=[];
 foreach(['RealtimeTripId','realtimeTripId','AVMSTripID','avmsTripId','gtfsTripId','tripId','serviceId','tripCode','trainNumber'] as $key){
  $v=val($properties,$key,'');
  if(is_string($v)||is_int($v)){ $v=strtolower(trim((string)$v));if($v!=='')$out[$v]=true; }
 }
 return array_keys($out);
}
function normalized_journeys(array $body,array $origin,array $destination):array{
 $journeys=val($body,'journeys',[]);if(!is_array($journeys))return [];
 $candidates=[];
 foreach($journeys as $journeyIndex=>$j){
  $rawLegs=val($j,'legs',[]);if(!is_array($rawLegs)||!$rawLegs)continue;
  $legs=[];$all=[];$hasNonRail=false;
  foreach($rawLegs as $leg){
   if(!is_array($leg))continue;
   $transport=val($leg,'transportation',[]);$m=mode($transport);
   if(!$m){if(is_transfer_walk($transport))continue;$hasNonRail=true;break;}
   $originStop=stop(val($leg,'origin'),$m);$destStop=stop(val($leg,'destination'),$m);
   if(!$originStop||!$destStop)continue;
   $stops=leg_stop_sequence($leg,$m,$originStop,$destStop);
   $path=parse_path(val($leg,'path',[]));if(!$path)$path=parse_path($leg);
   $legs[]=['id'=>'leg-'.count($legs),'mode'=>$m,'line'=>(string)val($transport,'disassembledName',val($transport,'number',strtoupper($m))),'tripIds'=>transportation_trip_ids($transport),'origin'=>$originStop,'destination'=>$destStop,'stops'=>$stops,'path'=>$path,'departure'=>$originStop['departure'],'arrival'=>$destStop['arrival'],'platform'=>$originStop['platform']];
   foreach($stops as $s)append_unique_station($all,$s);
  }
  if($hasNonRail||!$legs)continue;
  $railStart=$legs[0]['origin'];$railEnd=$legs[count($legs)-1]['destination'];
  if(!same_station($railStart,$origin)||!same_station($railEnd,$destination))continue;
  $transfers=[];
  for($i=1;$i<count($legs);$i++){
   $arrive=$legs[$i-1]['destination'];$depart=$legs[$i]['origin'];$s=$depart;
   $transfers[]=['id'=>$s['id'],'name'=>$s['name'],'mode'=>$s['mode'],'lat'=>$s['lat'],'lon'=>$s['lon'],'arrivalPlatform'=>$arrive['platform']??null,'departurePlatform'=>$depart['platform']??null];
  }
  $departure=iso_ts($legs[0]['departure']);$arrival=iso_ts($legs[count($legs)-1]['arrival']);
  $duration=($departure!==null&&$arrival!==null&&$arrival>=$departure)?$arrival-$departure:PHP_INT_MAX;
  $candidates[]=['arrival'=>$arrival??PHP_INT_MAX,'departure'=>$departure??PHP_INT_MAX,'duration'=>$duration,'transfers'=>count($transfers),'index'=>$journeyIndex,'route'=>[
   'id'=>hash('sha256',$origin['id'].'|'.$destination['id'].'|'.json_encode($legs)),
   'origin'=>$origin,'destination'=>$destination,'legs'=>$legs,'stops'=>$all,'transfers'=>$transfers,
   'omittedNonRail'=>false,'railStartsAtOrigin'=>true,'railEndsAtDestination'=>true,
   'railStart'=>$railStart,'railEnd'=>$railEnd,'fetchedAt'=>gmdate('c'),'source'=>'live','nextDepartures'=>[]
  ]];
 }
 usort($candidates,function($a,$b){return [$a['arrival'],$a['transfers'],$a['duration'],$a['departure'],$a['index']] <=> [$b['arrival'],$b['transfers'],$b['duration'],$b['departure'],$b['index']];});
 return array_map(fn($c)=>$c['route'],$candidates);
}
function normalized_journey(array $body,array $origin,array $destination):?array{$routes=normalized_journeys($body,$origin,$destination);return $routes[0]??null;}
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
require_once __DIR__.'/upstream-transport.php';

function normalize_station_locations(array $locations):array{
 $results=[];foreach($locations as $loc){
  if(!is_array($loc))continue;
  $type=strtolower((string)val($loc,'type','stop'));if($type!=='stop')continue;
  $railMode=location_rail_mode($loc);if(!$railMode)continue;
  $s=station($loc,$railMode);if($s)$results[$s['id']]=$s;
 }
 return array_values($results);
}
function station_index():array{
 $results=[];
 foreach(['Station','Metro','Railway'] as $term){
  $body=upstream('stop_finder',['type_sf'=>'any','name_sf'=>$term,'anyMaxSizeHitList'=>1000,'TfNSWSF'=>'true'],43200);
  $locations=val($body,'locations',[]);if(!is_array($locations))continue;
  foreach(normalize_station_locations($locations) as $s)$results[$s['id']]=$s;
 }
 uasort($results,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));
 return array_values($results);
}
function station_search(string $q):array{
 $requests=[
  ['odvSugMacro'=>1,'name_sf'=>$q,'anyMaxSizeHitList'=>20,'TfNSWSF'=>'true'],
  ['type_sf'=>'any','name_sf'=>$q,'anyMaxSizeHitList'=>20,'TfNSWSF'=>'true'],
 ];
 $locations=[];
 foreach($requests as $params){
  $body=upstream('stop_finder',$params,120);
  $candidate=val($body,'locations',[]);
  if(is_array($candidate)&&count($candidate)>0){$locations=$candidate;break;}
 }
 return normalize_station_locations($locations);
}
function lookup_station(string $id):?array{
 $body=upstream('stop_finder',['type_sf'=>'stop','name_sf'=>$id,'anyMaxSizeHitList'=>10,'TfNSWSF'=>'true'],600);
 foreach(val($body,'locations',[]) as $loc){$railMode=location_rail_mode($loc);if(!$railMode)continue;$s=station($loc,$railMode);if($s&&$s['id']===$id)return $s;}
 return null;
}


require_once __DIR__.'/service-alerts.php';

function pb_varint(string $data,int &$offset):?int{
 $value=0;$shift=0;$length=strlen($data);
 while($offset<$length&&$shift<=63){
  $byte=ord($data[$offset++]);$value|=(($byte&0x7f)<<$shift);
  if(($byte&0x80)===0)return $value;
  $shift+=7;
 }
 return null;
}
function pb_fields(string $data):array{
 $fields=[];$offset=0;$length=strlen($data);
 while($offset<$length){
  $key=pb_varint($data,$offset);if($key===null)break;
  $number=$key>>3;$wire=$key&7;if($number<=0)break;
  if($wire===0){$value=pb_varint($data,$offset);if($value===null)break;$fields[]=['number'=>$number,'wire'=>0,'value'=>$value];continue;}
  if($wire===1){if($offset+8>$length)break;$fields[]=['number'=>$number,'wire'=>1,'value'=>substr($data,$offset,8)];$offset+=8;continue;}
  if($wire===2){$size=pb_varint($data,$offset);if($size===null||$size<0||$offset+$size>$length)break;$fields[]=['number'=>$number,'wire'=>2,'value'=>substr($data,$offset,$size)];$offset+=$size;continue;}
  if($wire===5){if($offset+4>$length)break;$fields[]=['number'=>$number,'wire'=>5,'value'=>substr($data,$offset,4)];$offset+=4;continue;}
  break;
 }
 return $fields;
}
function pb_values(array $fields,int $number,?int $wire=null):array{
 $out=[];foreach($fields as $field){if($field['number']!==$number)continue;if($wire!==null&&$field['wire']!==$wire)continue;$out[]=$field['value'];}return $out;
}
function pb_first_string(array $fields,int $number):?string{
 foreach($fields as $field){if($field['number']===$number&&$field['wire']===2)return (string)$field['value'];}
 return null;
}
function pb_first_int(array $fields,int $number):?int{
 foreach($fields as $field){if($field['number']===$number&&$field['wire']===0)return (int)$field['value'];}
 return null;
}
function crowding_level_from_occupancy(?int $status):string{
 return match($status){0,1=>'quiet',2=>'moderate',3=>'busy',4,5,6=>'very_busy',default=>'unknown'};
}
function crowding_rank(string $level):int{
 return match($level){'quiet'=>0,'moderate'=>1,'busy'=>2,'very_busy'=>3,default=>-1};
}
function carriage_to_crowding(string $message):array{
 $fields=pb_fields($message);$status=pb_first_int($fields,3);$toilet=pb_first_int($fields,5);
 return [
  'name'=>pb_first_string($fields,1),
  'position'=>pb_first_int($fields,2),
  'level'=>crowding_level_from_occupancy($status),
  'quietCarriage'=>pb_first_int($fields,4)===1,
  'toilet'=>match($toilet){0=>'none',1=>'normal',2=>'accessible',default=>'unknown'},
  'luggageRack'=>pb_first_int($fields,6)===1
 ];
}
function parse_vehicle_positions_feed(string $data):array{
 $feed=pb_fields($data);$vehicles=[];
 foreach(pb_values($feed,2,2) as $entityMessage){
  $entity=pb_fields((string)$entityMessage);$vehicleMessage=pb_first_string($entity,4);if($vehicleMessage===null)continue;
  $vehicle=pb_fields($vehicleMessage);$tripMessage=pb_first_string($vehicle,1);if($tripMessage===null)continue;
  $trip=pb_fields($tripMessage);$tripId=strtolower(trim((string)(pb_first_string($trip,1)??'')));if($tripId==='')continue;
  $descriptorMessage=pb_first_string($vehicle,8);$vehicleId=null;
  if($descriptorMessage!==null){$descriptor=pb_fields($descriptorMessage);$vehicleId=pb_first_string($descriptor,1)??pb_first_string($descriptor,2);}
  $timestamp=pb_first_int($vehicle,5)??0;$wholeLevel=crowding_level_from_occupancy(pb_first_int($vehicle,9));
  $carriages=[];$maxRank=-1;
  foreach(pb_values($vehicle,1007,2) as $carriageMessage){
   $carriage=carriage_to_crowding((string)$carriageMessage);$carriages[]=$carriage;$maxRank=max($maxRank,crowding_rank($carriage['level']));
  }
  usort($carriages,fn($a,$b)=>(($a['position']??PHP_INT_MAX)<=>($b['position']??PHP_INT_MAX)));
  $level=$wholeLevel;
  if($level==='unknown'&&$maxRank>=0)$level=['quiet','moderate','busy','very_busy'][$maxRank];
  $record=['tripId'=>$tripId,'vehicleId'=>$vehicleId,'level'=>$level,'timestamp'=>$timestamp,'stopId'=>pb_first_string($vehicle,7),'carriages'=>$carriages];
  if(!isset($vehicles[$tripId])||$timestamp>($vehicles[$tripId]['timestamp']??0))$vehicles[$tripId]=$record;
 }
 return $vehicles;
}
function journey_crowding_from_feeds(array $route,array $feeds):array{
 $legs=[];$available=false;$overall='unknown';$overallRank=-1;$latest=0;
 foreach(val($route,'legs',[]) as $leg){
  if(!is_array($leg))continue;$mode=(string)val($leg,'mode','');$match=null;$matchedTrip=null;
  $tripIds=val($leg,'tripIds',[]);if(!is_array($tripIds))$tripIds=[];
  foreach($tripIds as $tripId){$key=strtolower(trim((string)$tripId));if($key!==''&&isset($feeds[$mode][$key])){$match=$feeds[$mode][$key];$matchedTrip=$key;break;}}
  $level=$match['level']??'unknown';$rank=crowding_rank($level);
  if($match){$latest=max($latest,(int)($match['timestamp']??0));if($rank>=0)$available=true;if($rank>$overallRank){$overallRank=$rank;$overall=$level;}}
  $legs[]=[
   'legId'=>(string)val($leg,'id',''),
   'mode'=>$mode,
   'vehicleMatched'=>$match!==null,
   'tripId'=>$matchedTrip,
   'vehicleId'=>$match['vehicleId']??null,
   'level'=>$level,
   'updatedAt'=>isset($match['timestamp'])&&$match['timestamp']>0?gmdate('c',(int)$match['timestamp']):null,
   'carriages'=>$match['carriages']??[]
  ];
 }
 return ['available'=>$available,'level'=>$overall,'updatedAt'=>$latest>0?gmdate('c',$latest):gmdate('c'),'legs'=>$legs];
}
function journey_crowding_status(array $route):array{
 $modes=[];foreach(val($route,'legs',[]) as $leg){if(is_array($leg))$modes[(string)val($leg,'mode','')]=true;}
 $feeds=[];
 if(isset($modes['train'])){$body=upstream_binary_optional('https://api.transport.nsw.gov.au/v2/gtfs/vehiclepos/sydneytrains',15);if(is_string($body))$feeds['train']=parse_vehicle_positions_feed($body);}
 if(isset($modes['metro'])){$body=upstream_binary_optional('https://api.transport.nsw.gov.au/v2/gtfs/vehiclepos/metro',15);if(is_string($body))$feeds['metro']=parse_vehicle_positions_feed($body);}
 return journey_crowding_from_feeds($route,$feeds);
}
