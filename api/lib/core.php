<?php
declare(strict_types=1);
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
 $product=val($transport,'product',[]);$class=(int)val($product,'class',-1);
 $name=strtolower((string)(val($product,'name','').' '.val($transport,'name','').' '.val($transport,'disassembledName','')));
 if(str_contains($name,'metro')||str_contains($name,'subway')||$class===2)return 'metro';
 if($class===1||str_contains($name,'train')||str_contains($name,'rail'))return 'train';
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
   $legs[]=['id'=>'leg-'.count($legs),'mode'=>$m,'line'=>(string)val($transport,'disassembledName',val($transport,'number',strtoupper($m))),'origin'=>$originStop,'destination'=>$destStop,'stops'=>$stops,'path'=>$path,'departure'=>$originStop['departure'],'arrival'=>$destStop['arrival'],'platform'=>$originStop['platform']];
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
function route_arrival_ts(array $route):int{$legs=val($route,'legs',[]);if(!is_array($legs)||!$legs)return PHP_INT_MAX;return iso_ts(val($legs[count($legs)-1],'arrival'))??PHP_INT_MAX;}
function route_departure_ts(array $route):int{$legs=val($route,'legs',[]);if(!is_array($legs)||!$legs)return PHP_INT_MAX;return iso_ts(val($legs[0],'departure'))??PHP_INT_MAX;}
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
function source_key():string{
 $key=(string)(getenv('TFNSW_API_KEY')?:'');
 if(!$key){$config=__DIR__.'/../config.local.php';if(is_file($config)){$private=require $config;$key=is_array($private)?(string)($private['TFNSW_API_KEY']??''):'';}}
 return $key;
}
function upstream(string $endpoint,array $params,int $ttl=30):array{
 $key=source_key();if(!$key)fail('CONFIG_MISSING','Live transport data needs a TfNSW API key. Add it on the server, then refresh.',503);
 $base=rtrim((string)(getenv('TFNSW_API_BASE')?:'https://api.transport.nsw.gov.au/v1/tp'),'/');
 if(!str_starts_with($base,'https://api.transport.nsw.gov.au/'))fail('CONFIG_MISSING','Invalid configured API endpoint.',503);
 $params=['outputFormat'=>'rapidJSON','coordOutputFormat'=>'EPSG:4326']+$params;
 $url=$base.'/'.$endpoint.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $cacheDir=sys_get_temp_dir().'/sydney_station_alert_'.substr(hash('sha256',__DIR__),0,8);
 if(!is_dir($cacheDir))@mkdir($cacheDir,0700,true);
 $cache=$cacheDir.'/'.hash('sha256',$url).'.json';
 if(is_file($cache)&&filemtime($cache)>time()-$ttl){$data=json_decode((string)file_get_contents($cache),true);if(is_array($data))return $data;}
 $attempts=2;for($attempt=0;$attempt<$attempts;$attempt++){
  $h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>9,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
  $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
  $json=is_string($body)?json_decode($body,true):null;
  if($status===429)fail('RATE_LIMITED','Transport data is busy. Try again in a minute.',429);
  if($status>=200&&$status<300&&is_array($json)){@file_put_contents($cache,json_encode($json),LOCK_EX);return $json;}
  if($status>=400&&$status<500)break;
  if($attempt===0)usleep(170000);
 }
 fail('UPSTREAM_UNAVAILABLE','TfNSW is temporarily unavailable. Please try refresh shortly.',502);
}
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


function alert_text_value(mixed $value):string{
 if(is_string($value))return trim(strip_tags($value));
 if(!is_array($value))return '';
 foreach(['text','content','value','name','title','subtitle','heading','description','message'] as $key){
  if(isset($value[$key])){
   $text=alert_text_value($value[$key]);
   if($text!=='')return $text;
  }
 }
 foreach($value as $item){
  $text=alert_text_value($item);
  if($text!=='')return $text;
 }
 return '';
}
function alert_text_field(array $alert,array $keys):string{
 foreach($keys as $key){
  if(array_key_exists($key,$alert)){
   $text=alert_text_value($alert[$key]);
   if($text!=='')return $text;
  }
 }
 return '';
}
function alert_scalar_values(mixed $value,array &$out):void{
 if(is_string($value)||is_int($value)||is_float($value)){
  $text=trim((string)$value);
  if($text!=='')$out[]=$text;
  return;
 }
 if(!is_array($value))return;
 foreach($value as $item)alert_scalar_values($item,$out);
}
function alert_entity_values(array $alert,array $needles):array{
 $out=[];
 $walk=function(mixed $node,string $parent='') use (&$walk,&$out,$needles):void{
  if(!is_array($node))return;
  foreach($node as $key=>$value){
   $name=strtolower((string)$key);
   $matched=false;
   foreach($needles as $needle){if(str_contains($name,$needle)){$matched=true;break;}}
   if($matched){$values=[];alert_scalar_values($value,$values);foreach($values as $v)$out[$v]=true;}
   if(is_array($value))$walk($value,$name);
  }
 };
 $walk($alert);
 return array_keys($out);
}
function alert_severity(array $alert,string $title,string $description):string{
 $raw=strtolower(alert_text_field($alert,['severity','priority','type','status','level','messageType','messageTypeText']));
 $text=strtolower($title.' '.$description.' '.$raw);
 if(preg_match('/\\b(severe|major|critical|suspend(?:ed|sion)?|cancel(?:led|lation)?|closed|closure|no trains|service stopped|not stopping|skip(?:ping|s)?)\\b/',$text))return 'major';
 if(preg_match('/\b(delay|delays|delayed|disruption|disrupted|reduced|changed|altered|incident|warning|maintenance)\b/',$text))return 'warning';
 return 'info';
}
function alert_material_change(string $title,string $description):bool{
 $text=strtolower($title.' '.$description);
 return (bool)preg_match('/\b(cancel(?:led|lation)?|not stopping|skip(?:ping|s)?|changed stopping pattern|terminat(?:e|es|ing|ed) early|platform change|changed platform|service suspended|services suspended|line closed|station closed)\b/',$text);
}
function current_service_alerts(array $body):array{
 $infos=val($body,'infos',[]);
 if(!is_array($infos))return [];
 $current=val($infos,'current',$infos);
 if(!is_array($current))return [];
 if(!array_is_list($current)){
  $list=[];
  foreach($current as $value){
   if(is_array($value)&&array_is_list($value))$list=array_merge($list,$value);
   elseif(is_array($value))$list[]=$value;
  }
  return $list;
 }
 return $current;
}
function normalize_service_alert(array $raw,int $index=0):array{
 $title=alert_text_field($raw,['title','heading','subject','summary','subtitle','name']);
 $description=alert_text_field($raw,['description','content','body','message','detail','text']);
 if($description===$title)$description='';
 if($title==='')$title=$description!==''?$description:'Service information';
 $id=(string)val($raw,'id',val($raw,'infoId',val($raw,'identifier','alert-'.$index)));
 $starts=alert_text_field($raw,['validFrom','startTime','start','from']);
 $ends=alert_text_field($raw,['validTo','endTime','end','to']);
 return [
  'id'=>$id,
  'severity'=>alert_severity($raw,$title,$description),
  'title'=>$title,
  'description'=>$description,
  'affectedLines'=>alert_entity_values($raw,['line','route']),
  'affectedStations'=>alert_entity_values($raw,['stop','station']),
  'affectedTrips'=>alert_entity_values($raw,['trip','journey']),
  'startsAt'=>$starts!==''?$starts:null,
  'endsAt'=>$ends!==''?$ends:null,
  'materialChange'=>alert_material_change($title,$description)
 ];
}
function normalized_service_alerts(array $body):array{
 $out=[];$seen=[];
 foreach(current_service_alerts($body) as $i=>$raw){
  if(!is_array($raw))continue;
  $alert=normalize_service_alert($raw,(int)$i);
  $fingerprint=strtolower($alert['id'].'|'.$alert['title'].'|'.$alert['description']);
  if(isset($seen[$fingerprint]))continue;
  $seen[$fingerprint]=true;$out[]=$alert;
 }
 return $out;
}
function journey_alert_terms(array $route):array{
 $stationIds=[];$stationNames=[];$lines=[];$tripIds=[];
 foreach(val($route,'stops',[]) as $stop){
  if(!is_array($stop))continue;
  $id=strtolower(trim((string)val($stop,'id','')));if($id!=='')$stationIds[$id]=true;
  $name=strtolower(clean_station_name((string)val($stop,'name','')));if($name!=='')$stationNames[$name]=true;
 }
 foreach(val($route,'legs',[]) as $leg){
  if(!is_array($leg))continue;
  $line=strtolower(trim((string)val($leg,'line','')));if($line!=='')$lines[$line]=true;
  foreach(['tripId','trip','serviceId','id'] as $key){
   $id=strtolower(trim((string)val($leg,$key,'')));if($id!=='')$tripIds[$id]=true;
  }
 }
 return ['stationIds'=>array_keys($stationIds),'stationNames'=>array_keys($stationNames),'lines'=>array_keys($lines),'tripIds'=>array_keys($tripIds)];
}
function alert_matches_terms(array $alert,array $terms):bool{
 $haystack=strtolower($alert['title'].' '.$alert['description'].' '.implode(' ',$alert['affectedLines']).' '.implode(' ',$alert['affectedStations']).' '.implode(' ',$alert['affectedTrips']));
 foreach(array_merge($terms['stationIds'],$terms['stationNames'],$terms['lines'],$terms['tripIds']) as $term){
  if((string)$term!==''&&str_contains($haystack,strtolower((string)$term)))return true;
 }
 return false;
}
function journey_service_status(array $route,array $alertBody):array{
 $terms=journey_alert_terms($route);$alerts=[];
 foreach(normalized_service_alerts($alertBody) as $alert){if(alert_matches_terms($alert,$terms))$alerts[]=$alert;}
 usort($alerts,function($a,$b){$rank=['major'=>0,'warning'=>1,'info'=>2];return ($rank[$a['severity']]??3)<=>($rank[$b['severity']]??3);});
 $level='ok';foreach($alerts as $alert){if($alert['severity']==='major'){$level='major';break;}if($alert['severity']==='warning')$level='warning';elseif($level==='ok')$level='info';}
 return ['level'=>$level,'hasMaterialChange'=>(bool)array_filter($alerts,fn($a)=>$a['materialChange']===true),'updatedAt'=>gmdate('c'),'alerts'=>$alerts];
}
function upstream_optional(string $endpoint,array $params,int $ttl=60):?array{
 $key=source_key();if($key==='')return null;
 $base=rtrim((string)(getenv('TFNSW_API_BASE')?:'https://api.transport.nsw.gov.au/v1/tp'),'/');
 if(!str_starts_with($base,'https://api.transport.nsw.gov.au/'))return null;
 $params=['outputFormat'=>'rapidJSON','coordOutputFormat'=>'EPSG:4326']+$params;
 $url=$base.'/'.$endpoint.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $cacheDir=sys_get_temp_dir().'/sydney_station_alert_'.substr(hash('sha256',__DIR__),0,8);
 if(!is_dir($cacheDir))@mkdir($cacheDir,0700,true);
 $cache=$cacheDir.'/'.hash('sha256',$url).'.json';
 if(is_file($cache)&&filemtime($cache)>time()-$ttl){
  $data=json_decode((string)file_get_contents($cache),true);if(is_array($data))return $data;
 }
 $h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>7,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 $json=is_string($body)?json_decode($body,true):null;
 if($status>=200&&$status<300&&is_array($json)){@file_put_contents($cache,json_encode($json),LOCK_EX);return $json;}
 error_log('Sydney Station Alert optional TfNSW endpoint failed: '.$endpoint.' status '.$status);
 return null;
}
function fetch_current_service_alerts(DateTimeImmutable $when):?array{
 return upstream_optional('add_info',['filterDateValid'=>$when->format('d-m-Y'),'filterPublicationStatus'=>'current'],60);
}
