<?php
declare(strict_types=1);
// Read-only, one-off timetable sample. Runs on existing production host with the
// already-configured TfNSW credential; never prints a credential or raw payload.
require_once getcwd().'/api/lib/core.php';
$key=source_key();
if($key===''){fwrite(STDERR,"Configured transport key unavailable\n");exit(2);}
$tz=new DateTimeZone('Australia/Sydney');
function local_time(mixed $s,DateTimeZone $tz):?string{
 if(!is_string($s)||$s==='')return null;
 try{return (new DateTimeImmutable($s))->setTimezone($tz)->format('Y-m-d H:i:s T');}
 catch(Throwable){return null;}
}
function little_stop(array $s,DateTimeZone $tz):array{
 return ['id'=>$s['id']??null,'name'=>$s['name']??null,'platform'=>$s['platform']??null,
  'arrival'=>local_time($s['arrival']??null,$tz),'departure'=>local_time($s['departure']??null,$tz)];
}
$stations=[
 'Circular Quay'=>['id'=>'200020','name'=>'Circular Quay','lat'=>0.0,'lon'=>0.0,'mode'=>'train'],
 'International Airport'=>['id'=>'202030','name'=>'International Airport','lat'=>0.0,'lon'=>0.0,'mode'=>'train'],
 'Padstow'=>['id'=>'221110','name'=>'Padstow','lat'=>0.0,'lon'=>0.0,'mode'=>'train']
];
$checks=[
 ['Circular Quay','International Airport','20261012','1200'],
 ['International Airport','Padstow','20261012','1230'],
 ['International Airport','Circular Quay','20261012','1300'],
 ['Circular Quay','Padstow','20261012','1200']
];
$ok=0;
foreach($checks as [$from,$to,$date,$clock]){
 $params=['outputFormat'=>'rapidJSON','coordOutputFormat'=>'EPSG:4326','depArrMacro'=>'dep',
  'itdDate'=>$date,'itdTime'=>$clock,'type_origin'=>'stop','name_origin'=>$stations[$from]['id'],
  'type_destination'=>'stop','name_destination'=>$stations[$to]['id'],'calcNumberOfTrips'=>12,'TfNSWTR'=>'true'];
 $url='https://api.transport.nsw.gov.au/v1/tp/trip?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $h=curl_init($url);
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>24,CURLOPT_CONNECTTIMEOUT=>5,
  CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$err=curl_error($h);curl_close($h);
 $json=is_string($body)?json_decode($body,true):null;
 $out=['request'=>['from'=>$from,'to'=>$to,'date'=>$date,'time'=>$clock,'zone'=>'Australia/Sydney'],
  'http'=>$status,'error'=>$err?:null,'rawJourneyCount'=>is_array($json['journeys']??null)?count($json['journeys']):null,
  'normalizedRailOnlyCount'=>0,'sampleRailRoutes'=>[],'rawLegHints'=>[]];
 if($status===200&&is_array($json)){
  ++$ok;
  $routes=normalized_journeys($json,$stations[$from],$stations[$to]);
  $out['normalizedRailOnlyCount']=count($routes);
  foreach(array_slice($routes,0,2) as $route){
   $legs=[];foreach(($route['legs']??[]) as $leg){
    $legs[]=['line'=>$leg['line']??null,'mode'=>$leg['mode']??null,
     'origin'=>little_stop($leg['origin'],$tz),'destination'=>little_stop($leg['destination'],$tz)];
   }
   $stops=array_map(fn($stop)=>little_stop($stop,$tz),$route['stops']??[]);
   $out['sampleRailRoutes'][]=['legs'=>$legs,'stopCount'=>count($stops),'stops'=>$stops,
    'airportStops'=>array_values(array_filter($stops,fn($s)=>preg_match('/airport|^platform\s*\d+/i',(string)($s['name']??''))||in_array((string)($s['id']??''),['202091','202092','2020101','2020102'],true)))];
  }
  foreach(array_slice($json['journeys']??[],0,2) as $journey){
   $summ=[];
   foreach(($journey['legs']??[]) as $leg){
    if(!is_array($leg))continue;
    $transport=$leg['transportation']??[];
    $class=$transport['product']['class']??null;
    $stopHints=[];
    foreach(($leg['stopSequence']??[]) as $item){
     $stopNode=$item['stopPoint']??$item['location']??$item;
     $stopId=(string)($stopNode['id']??'');
     $stopName=(string)($stopNode['disassembledName']??$stopNode['name']??'');
     if(in_array($stopId,['202091','202092','2020101','2020102'],true)||preg_match('/airport|^platform\s*\d+/i',$stopName))
       $stopHints[]=['id'=>$stopId,'name'=>$stopName,'normalizedName'=>station($stopNode,'train')['name']??null,
       'parentName'=>$stopNode['parent']['name']??null,'parentId'=>$stopNode['parent']['id']??null,
       'outerName'=>$item['name']??null];
    }
    $summ[]=['class'=>$class,'line'=>$transport['disassembledName']??null,
     'from'=>$leg['origin']['name']??null,'to'=>$leg['destination']['name']??null,
     'rawDepart'=>local_time($leg['origin']['departureTimePlanned']??null,$tz),
     'rawArrive'=>local_time($leg['destination']['arrivalTimePlanned']??null,$tz),
     'normalizedFrom'=>stop($leg['origin'],'train')['name']??null,
     'normalizedTo'=>stop($leg['destination'],'train')['name']??null,
     'stopSequenceSize'=>is_array($leg['stopSequence']??null)?count($leg['stopSequence']):null,
     'airportStopHints'=>$stopHints];
   }
   $out['rawLegHints'][]=$summ;
  }
 }
 echo json_encode($out,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES)."\n";
}
if(!$ok)exit(1);
