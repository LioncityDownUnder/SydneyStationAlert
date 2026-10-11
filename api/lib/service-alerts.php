<?php
declare(strict_types=1);

/** TfNSW service alert normalization, trip-scoped matching and safe reroute ranking. */
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
function alert_line_tokens(string $value):array{
 $text=strtolower(trim(preg_replace('/\s+/',' ',$value) ?? $value));if($text==='')return [];
 $tokens=[$text=>true];
 if(preg_match_all('/\b(?:t|m)\d+\b/i',$value,$matches)){
  foreach($matches[0] as $code)$tokens[strtolower($code)]=true;
 }
 $clean=preg_replace('/^(?:sydney trains|intercity trains|sydney metro) network\s+/i','',$value) ?? $value;
 $clean=strtolower(trim(preg_replace('/\s+/',' ',$clean) ?? $clean));
 if($clean!=='')$tokens[$clean]=true;
 return array_keys($tokens);
}
function alert_station_match_name(string $value):string{
 $first=trim(explode(',',$value,2)[0]);
 return strtolower(clean_station_name($first));
}
function alert_scope_values(mixed $items,array $keys):array{
 $out=[];
 if(!is_array($items))return [];
 foreach($items as $item){
  if(is_string($item)||is_int($item)){ $v=trim((string)$item);if($v!=='')$out[$v]=true;continue; }
  if(!is_array($item))continue;
  foreach($keys as $key){
   $v=val($item,$key,'');
   if(is_string($v)||is_int($v)){ $v=trim((string)$v);if($v!=='')$out[$v]=true; }
  }
 }
 return array_keys($out);
}
function alert_affected_scope(array $raw):array{
 $affected=val($raw,'affected',[]);if(!is_array($affected))$affected=[];
 $lineScoped=false;$railScoped=false;$lines=[];$stations=[];$trips=[];

 $lineItems=val($affected,'lines',[]);
 if(is_array($lineItems)&&$lineItems){
  $lineScoped=true;
  foreach($lineItems as $line){
   if(!is_array($line))continue;
   $product=val($line,'product',[]);if(!is_array($product))$product=[];
   $class=(int)val($product,'class',-1);
   if($class!==1&&$class!==2)continue;
   $railScoped=true;
   foreach(['id','name','number','description'] as $key){
    $v=trim((string)val($line,$key,''));
    if($v!=='')$lines[$v]=true;
   }
   $lineTrips=val($line,'trips',[]);
   foreach(alert_scope_values($lineTrips,['id','tripId','realtimeTripId','RealtimeTripId','AVMSTripID','gtfsTripId','tripCode','trainNumber','name','number']) as $v)$trips[strtolower((string)$v)]=true;
  }
 }

 $stopItems=val($affected,'stops',val($affected,'stopPoints',[]));
 foreach(alert_scope_values($stopItems,['id','stateless','name','disassembledName']) as $v)$stations[$v]=true;

 // Backward-compatible fixture/older payload support without recursive harvesting.
 if(!$lineScoped){
  $legacyLines=val($raw,'affectedLines',[]);
  if(is_array($legacyLines)&&$legacyLines){
   $lineScoped=true;$railScoped=true;
   foreach(alert_scope_values($legacyLines,['id','name','number']) as $v)$lines[$v]=true;
  }
 }
 $legacyStops=val($raw,'affectedStops',[]);
 foreach(alert_scope_values($legacyStops,['id','name','disassembledName']) as $v)$stations[$v]=true;

 return [
  'lineScoped'=>$lineScoped,
  'railScoped'=>$railScoped,
  'lines'=>array_keys($lines),
  'stations'=>array_keys($stations),
  'trips'=>array_keys($trips)
 ];
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
 $timestamps=val($raw,'timestamps',[]);if(!is_array($timestamps))$timestamps=[];
 $starts=alert_text_field($timestamps,['validFrom','startTime','start','from','publicationStart']);
 $ends=alert_text_field($timestamps,['validTo','endTime','end','to','publicationEnd']);
 $scope=alert_affected_scope($raw);
 return [
  'id'=>$id,
  'severity'=>alert_severity($raw,$title,$description),
  'title'=>$title,
  'description'=>$description,
  'affectedLines'=>$scope['lines'],
  'affectedStations'=>$scope['stations'],
  'affectedTrips'=>$scope['trips'],
  'startsAt'=>$starts!==''?$starts:null,
  'endsAt'=>$ends!==''?$ends:null,
  'materialChange'=>alert_material_change($title,$description),
  '_lineScoped'=>$scope['lineScoped'],
  '_railScoped'=>$scope['railScoped']
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
 $stationIds=[];$stationNames=[];$lineTokens=[];$tripIds=[];
 foreach(val($route,'stops',[]) as $stop){
  if(!is_array($stop))continue;
  $id=strtolower(trim((string)val($stop,'id','')));if($id!=='')$stationIds[$id]=true;
  $name=alert_station_match_name((string)val($stop,'name',''));if($name!=='')$stationNames[$name]=true;
 }
 foreach(val($route,'legs',[]) as $leg){
  if(!is_array($leg))continue;
  foreach(alert_line_tokens((string)val($leg,'line','')) as $token)$lineTokens[$token]=true;
  $legTripIds=val($leg,'tripIds',[]);if(is_array($legTripIds))foreach($legTripIds as $id){$id=strtolower(trim((string)$id));if($id!=='')$tripIds[$id]=true;}
  foreach(['tripId','trip','serviceId'] as $key){
   $id=strtolower(trim((string)val($leg,$key,'')));if($id!=='')$tripIds[$id]=true;
  }
 }
 return ['stationIds'=>array_keys($stationIds),'stationNames'=>array_keys($stationNames),'lineTokens'=>array_keys($lineTokens),'tripIds'=>array_keys($tripIds)];
}
function alert_line_matches(array $alert,array $terms):bool{
 $journey=array_fill_keys($terms['lineTokens'],true);
 foreach($alert['affectedLines'] as $line){
  foreach(alert_line_tokens((string)$line) as $token){if(isset($journey[$token]))return true;}
 }
 return false;
}
function alert_station_matches(array $alert,array $terms):bool{
 $ids=array_fill_keys($terms['stationIds'],true);$names=array_fill_keys($terms['stationNames'],true);
 foreach($alert['affectedStations'] as $station){
  $raw=strtolower(trim((string)$station));if($raw!==''&&isset($ids[$raw]))return true;
  $name=alert_station_match_name((string)$station);if($name!==''&&isset($names[$name]))return true;
 }
 return false;
}
function alert_trip_matches(array $alert,array $terms):bool{
 if(!$terms['tripIds'])return false;
 $ids=array_fill_keys($terms['tripIds'],true);
 foreach($alert['affectedTrips'] as $trip){$v=strtolower(trim((string)$trip));if($v!==''&&isset($ids[$v]))return true;}
 return false;
}
function alert_matches_terms(array $alert,array $terms):bool{
 if(($alert['_lineScoped']??false)===true){
  if(($alert['_railScoped']??false)!==true)return false;
  $tripScoped=!empty($alert['affectedTrips']);
  if(($alert['materialChange']??false)===true&&$tripScoped)return alert_trip_matches($alert,$terms);
  return alert_line_matches($alert,$terms)||alert_trip_matches($alert,$terms);
 }
 if(alert_station_matches($alert,$terms)||alert_trip_matches($alert,$terms))return true;

 // Conservative fallback only when TfNSW supplies no structured affected line.
 $text=strtolower($alert['title'].' '.$alert['description']);
 foreach($terms['lineTokens'] as $token){
  if(preg_match('/^(?:t|m)\d+$/',$token)&&preg_match('/\b'.preg_quote($token,'/').'\b/i',$text))return true;
 }
 foreach($terms['stationNames'] as $name){if(strlen($name)>=5&&str_contains($text,$name))return true;}
 return false;
}
function journey_service_status(array $route,array $alertBody):array{
 $terms=journey_alert_terms($route);$alerts=[];
 foreach(normalized_service_alerts($alertBody) as $alert){
  if(!alert_matches_terms($alert,$terms))continue;
  unset($alert['_lineScoped'],$alert['_railScoped']);
  $alerts[]=$alert;
 }
 usort($alerts,function($a,$b){$rank=['major'=>0,'warning'=>1,'info'=>2];return ($rank[$a['severity']]??3)<=>($rank[$b['severity']]??3);});
 $level='ok';foreach($alerts as $alert){if($alert['severity']==='major'){$level='major';break;}if($alert['severity']==='warning')$level='warning';elseif($level==='ok')$level='info';}
 return ['level'=>$level,'hasMaterialChange'=>(bool)array_filter($alerts,fn($a)=>$a['materialChange']===true),'updatedAt'=>gmdate('c'),'alerts'=>$alerts];
}
function route_has_material_service_change(array $route,array $alertBody):bool{
 return journey_service_status($route,$alertBody)['hasMaterialChange']===true;
}
function best_unaffected_route(array $routes,array $alertBody):?array{
 $best=null;
 foreach($routes as $route){
  if(!is_array($route)||route_has_material_service_change($route,$alertBody))continue;
  $best=better_route($best,$route);
 }
 return $best;
}

function fetch_current_service_alerts(DateTimeImmutable $when):?array{
 return upstream_optional('add_info',['filterDateValid'=>$when->format('d-m-Y'),'filterPublicationStatus'=>'current'],60);
}
