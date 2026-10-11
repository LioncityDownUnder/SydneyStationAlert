<?php
declare(strict_types=1);

/** TfNSW stop, rail-mode, platform, path and journey normalization. */
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
/** TfNSW platform stopPoint names can contain ONLY ", Platform 2".
 * Prefer the actual parent/standalone station name, never a guessed nearby stop.
 */
function platform_only_station_name(string $name):bool{
 return preg_match('/^(?:platform|plat\\.?|pl\\.?)\\s*:?\\s*[0-9]+[a-z]?$/i',trim($name))===1;
}
function station_name_without_platform_location(string $name):string{
 // e.g. "International Airport Station, Platform 2, Mascot" -> "International Airport".
 $base=preg_replace('/,\\s*(?:platform|plat\\.?|pl\\.?)\\s*:?\\s*[0-9]+[a-z]?(?:\\s*,\\s*[^,]+)?\\s*$/i','',$name);
 return clean_station_name($base??$name);
}
function station(mixed $raw,?string $forcedMode=null):?array{
 if(!is_array($raw))return null;
 $coords=point($raw);$name=clean_station_name((string)val($raw,'disassembledName',val($raw,'name','')));$id=(string)val($raw,'id',val($raw,'stateless',''));
 if($name===''||platform_only_station_name($name)){
  $parent=val($raw,'parent',[]);
  $alternatives=[
   is_array($parent)?(string)val($parent,'name',''):'',
   (string)val($raw,'name','')
  ];
  foreach($alternatives as $source){
   $candidate=station_name_without_platform_location($source);
   if($candidate!==''&&!platform_only_station_name($candidate)){$name=$candidate;break;}
  }
 }
 if(!$coords||!$id||!$name)return null;
 $stationMode=$forcedMode??location_rail_mode($raw)??(str_contains(strtolower($name),'metro')?'metro':'train');
 return ['id'=>$id,'name'=>$name,'mode'=>$stationMode]+$coords;
}
function stop(mixed $raw,?string $transportMode=null):?array{
 $s=station($raw,$transportMode);if(!$s)return null;
 $props=val($raw,'properties',[]);
 $platform=platform_from_name($raw);
 if(!$platform)$platform=normalize_platform(val($props,'platform',val($raw,'platform')));
 return $s+['platform'=>$platform,'arrival'=>val($raw,'arrivalTimeEstimated',val($raw,'arrivalTimePlanned')),'departure'=>val($raw,'departureTimeEstimated',val($raw,'departureTimePlanned')),'plannedArrival'=>val($raw,'arrivalTimePlanned'),'plannedDeparture'=>val($raw,'departureTimePlanned')];
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
 // "Platform 2" is not a station identity: never collapse unrelated stop IDs
 // or treat a platform-only label as the requested origin/destination.
 if($an===''||platform_only_station_name($an)||platform_only_station_name($bn))return false;
 return $an===$bn;
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
 $s['plannedArrival']=val($raw,'arrivalTimePlanned',$s['plannedArrival']??null);
 $s['plannedDeparture']=val($raw,'departureTimePlanned',$s['plannedDeparture']??null);
 return $s;
}
function append_unique_station(array &$list,array $station):void{
 if(!$list){$list[]=$station;return;}
 $last=$list[count($list)-1];
 if(same_station($last,$station)){
  foreach(['platform','arrival','departure','plannedArrival','plannedDeparture'] as $k){if(empty($last[$k])&&!empty($station[$k]))$last[$k]=$station[$k];}
  $list[count($list)-1]=$last;return;
 }
 $list[]=$station;
}
function leg_stop_sequence(array $leg,string $transportMode,array $originStop,array $destStop):array{
 $sequence=val($leg,'stopSequence',[]);$stops=[];
 if(is_array($sequence)){foreach($sequence as $raw){$s=sequence_stop($raw,$transportMode);if($s)append_unique_station($stops,$s);}}
 if(!$stops||!same_station($stops[0],$originStop))array_unshift($stops,$originStop);
 else {foreach(['platform','departure','plannedDeparture'] as $k){if(empty($stops[0][$k])&&!empty($originStop[$k]))$stops[0][$k]=$originStop[$k];}}
 $last=$stops[count($stops)-1];
 if(!same_station($last,$destStop))$stops[]=$destStop;
 else {foreach(['platform','arrival','plannedArrival'] as $k){if(empty($last[$k])&&!empty($destStop[$k]))$last[$k]=$destStop[$k];}$stops[count($stops)-1]=$last;}
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
