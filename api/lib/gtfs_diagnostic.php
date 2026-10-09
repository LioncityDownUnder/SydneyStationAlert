<?php
declare(strict_types=1);
/** QA-only GTFS static timetable investigation. No network fetch or /trip calls. */
function qa_gtfs_rows(ZipArchive $zip,string $name):array{
 $stream=$zip->getStream($name);if(!$stream)return [];
 $header=fgetcsv($stream);if(!$header){fclose($stream);return [];}
 $header=array_map(fn($x)=>trim((string)$x,"\xEF\xBB\xBF \t"),$header);
 $out=[];while(($line=fgetcsv($stream))!==false){
  if(count($line)!==count($header))continue;
  $out[]=array_combine($header,$line);
  if(count($out)>1000000){fclose($stream);return [];}
 }
 fclose($stream);return $out;
}
function qa_gtfs_active_services(ZipArchive $zip,DateTimeImmutable $date):?array{
 $calendar=$zip->locateName('calendar.txt')!==false?qa_gtfs_rows($zip,'calendar.txt'):[];
 $exceptions=$zip->locateName('calendar_dates.txt')!==false?qa_gtfs_rows($zip,'calendar_dates.txt'):[];
 if(!$calendar&&!$exceptions)return null;
 $day=$date->format('Ymd');$weekday=strtolower($date->format('l'));$active=[];
 foreach($calendar as $row){
  if(($row['start_date']??'')<=$day&&($row['end_date']??'')>=$day&&($row[$weekday]??'0')==='1')$active[$row['service_id']??'']=true;
 }
 foreach($exceptions as $row){
  if(($row['date']??'')!==$day)continue;
  if(($row['exception_type']??'')==='1')$active[$row['service_id']??'']=true;
  if(($row['exception_type']??'')==='2')unset($active[$row['service_id']]);
 }
 return $active;
}
function qa_gtfs_live_destination(array $candidate):array{
 $event=$candidate['event']??[];$transport=$event['transportation']??[];
 $paths=[
  ['event.destination', $event['destination']??null],
  ['event.transportation.destination', $transport['destination']??null],
  ['event.transportation.properties.destination', $transport['properties']['destination']??null],
  ['event.transportation.properties.direction', $transport['properties']['direction']??null],
  ['event.transportation.properties.destinationName', $transport['properties']['destinationName']??null],
 ];
 foreach($paths as [$source,$raw]){
  $value=is_string($raw)?trim($raw):'';
  if(is_array($raw))foreach(['name','disassembledName','description','value'] as $field){
   if(isset($raw[$field])&&is_string($raw[$field])&&trim($raw[$field])!==''){$value=trim($raw[$field]);break;}
  }
  if($value!=='')return ['name'=>$value,'source'=>$source];
 }
 return ['name'=>null,'source'=>null];
}
function qa_gtfs_station_key(string $value):string{
 $value=strtolower(clean_station_name($value));
 $value=preg_replace('/\\b(?:station|platform|via)\\b.*$/i','',$value);
 return trim((string)$value);
}
function qa_gtfs_pattern_evidence(array $rows,array $stopMap,string $originName,int $seconds,array $trip,array $first):array{
 $originIndex=null;$originDelta=null;$stopNames=[];
 foreach($rows as $i=>$row){
  $name=(string)($stopMap[$row['stop_id']??'']['stop_name']??'');
  $stopNames[]=$name;
  if(strtolower(clean_station_name($name))!==$originName)continue;
  $parts=explode(':',(string)($row['departure_time']??''));
  if(count($parts)!==3)continue;
  $delta=abs(((int)$parts[0]*3600+(int)$parts[1]*60+(int)$parts[2])-$seconds);
  if($originDelta===null||$delta<$originDelta){$originDelta=$delta;$originIndex=$i;}
 }
 $headsign=trim((string)($trip['trip_headsign']??''));
 $terminus=(string)(end($stopNames)?:'');
 $liveDestination=trim((string)($first['destination']??''));
 $destinationComparable=$liveDestination!==''&&($headsign!==''||$terminus!=='');
 $destinationAgrees=$destinationComparable&&(qa_gtfs_station_key($liveDestination)===qa_gtfs_station_key($terminus)||($headsign!==''&&str_contains(strtolower($headsign),strtolower($liveDestination))));
 return ['originStopIndex'=>$originIndex,'originSequence'=>$originIndex===null?null:($rows[$originIndex]['stop_sequence']??null),
 'originDepartureDifferenceSeconds'=>$originDelta,'originIsLastStop'=>$originIndex===count($rows)-1,
 'scheduledHeadSign'=>$headsign,'scheduledTerminus'=>$terminus,
 'liveDestination'=>$liveDestination?:null,'destinationComparable'=>$destinationComparable,
 'destinationAgrees'=>$destinationComparable?$destinationAgrees:null,
 'onwardStopCount'=>$originIndex===null?0:count($rows)-$originIndex-1,
 'onwardStops'=>$originIndex===null?[]:array_slice($stopNames,$originIndex+1)];
}
function qa_gtfs_probe(array $candidate,array $origin):array{
 $path=(string)(getenv('QA_GTFS_STATIC_ZIP')?:'');
 if($path===''){$config=__DIR__.'/../gtfs-path.local.php';if(is_file($config)){$private=require $config;if(is_string($private))$path=$private;}}
 $result=['mode'=>'Step 2C GTFS timetable investigation','candidate'=>['identity'=>$candidate['identity']??null,'firstDeparture'=>$candidate['firstDeparture']??null],
 'source'=>'local GTFS static ZIP','status'=>'GTFS_NOT_CONFIGURED','matches'=>[],
 'note'=>'Requires QA_GTFS_STATIC_ZIP pointing to a trusted Sydney Trains GTFS ZIP on the QA server. No /trip calls; no automatic download. Exact trip-ID mapping is preferred; station/time candidates are explicitly unverified.'];
 if($path===''||!is_file($path))return $result;
 if(!class_exists('ZipArchive')){$result['status']='ZIP_EXTENSION_UNAVAILABLE';return $result;}
 if(filesize($path)>750000000){$result['status']='GTFS_ZIP_TOO_LARGE';return $result;}
 $zip=new ZipArchive();if($zip->open($path)!==true){$result['status']='GTFS_ZIP_UNREADABLE';return $result;}
 foreach(['trips.txt','stop_times.txt','stops.txt'] as $file)if($zip->locateName($file)===false){$result['status']='GTFS_MISSING_'.$file;$zip->close();return $result;}
 $trips=qa_gtfs_rows($zip,'trips.txt');$stops=qa_gtfs_rows($zip,'stops.txt');
 $routes=$zip->locateName('routes.txt')!==false?qa_gtfs_rows($zip,'routes.txt'):[];
 if(!$trips||!$stops){$result['status']='GTFS_PARSE_OR_SIZE_LIMIT';$zip->close();return $result;}
 $stopMap=[];foreach($stops as $s)$stopMap[$s['stop_id']??'']=$s;
 $first=$candidate['firstDeparture']??[];
 $live=qa_gtfs_live_destination($candidate);
 $first['destination']=$live['name'];
 $result['liveDestinationEvidence']=$live;
 $plannedTs=iso_ts($first['planned']??null);$serviceDate=(new DateTimeImmutable('@'.($plannedTs??(int)($candidate['departureTimestamp']??0))))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $active=qa_gtfs_active_services($zip,$serviceDate);
 $result['serviceDate']=$serviceDate->format('Y-m-d');$result['calendarAvailable']=$active!==null;
 $tripMap=[];foreach($trips as $trip)$tripMap[$trip['trip_id']??'']=$trip;
 $routeMap=[];foreach($routes as $route)$routeMap[$route['route_id']??'']=$route;$ids=array_map('strtolower',$first['tripIds']??[]);
 $exact=[];foreach($trips as $t)if(in_array(strtolower((string)($t['trip_id']??'')),$ids,true))$exact[$t['trip_id']]=$t;
 $originName=strtolower(clean_station_name((string)($origin['name']??'')));
 $ts=(int)($candidate['departureTimestamp']??0);
 $clock=(new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $plannedClock=(new DateTimeImmutable('@'.($plannedTs??$ts)))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $seconds=(int)$plannedClock->format('H')*3600+(int)$plannedClock->format('i')*60+(int)$plannedClock->format('s');
 $stream=$zip->getStream('stop_times.txt');if(!$stream){$zip->close();$result['status']='GTFS_STOP_TIMES_UNREADABLE';return $result;}
 $headers=fgetcsv($stream);$headers=array_map(fn($v)=>trim((string)$v,"\xEF\xBB\xBF \t"),$headers?:[]);
 $matched=[];$possible=[];$scanned=0;
 while(($line=fgetcsv($stream))!==false){
  ++$scanned;
  if(count($line)!==count($headers))continue;
  $v=array_combine($headers,$line);$trip=(string)($v['trip_id']??'');
  if(isset($exact[$trip])){$matched[$trip][]=$v;continue;}
  $stop=$stopMap[$v['stop_id']??'']??[];
  $name=strtolower(clean_station_name((string)($stop['stop_name']??'')));
  if($originName===''||$name!==$originName)continue;
  $departure=explode(':',(string)($v['departure_time']??''));if(count($departure)!==3)continue;
  $sec=(int)$departure[0]*3600+(int)$departure[1]*60+(int)$departure[2];
  if(abs($sec-$seconds)<=120)$possible[$trip]=true;
 }
 fclose($stream);
 if(!$exact&&$possible){
  // Second streaming pass for stop sequences of bounded station/time candidates.
  $possible=array_slice($possible,0,20,true);$stream=$zip->getStream('stop_times.txt');fgetcsv($stream);$n=0;
  while(($line=fgetcsv($stream))!==false){++$n;
   if(count($line)!==count($headers))continue;$v=array_combine($headers,$line);
   if(isset($possible[$v['trip_id']??'']))$matched[$v['trip_id']][]=$v;
  }
  fclose($stream);
 }
 $zip->close();
 $excluded=['inactive'=>0,'routeMismatch'=>0];$valid=[];
 foreach($matched as $id=>$rows){
  $trip=$tripMap[$id]??[];$serviceId=(string)($trip['service_id']??'');
  if($active!==null&&!isset($active[$serviceId])){$excluded['inactive']++;continue;}
  $route=$routeMap[$trip['route_id']??'']??[];
  $routeText=strtoupper(implode(' ',[$route['route_short_name']??'',$route['route_long_name']??'']));
  $line=strtoupper(trim((string)($first['line']??'')));
  $routeVerified=$line!==''&&$routeText!==''&&preg_match('/(?<![A-Z0-9])'.preg_quote($line,'/').'(?![A-Z0-9])/',$routeText)===1;
  if($line!==''&&$routeText!==''&&!$routeVerified){$excluded['routeMismatch']++;continue;}
  usort($rows,fn($a,$b)=>(int)($a['stop_sequence']??0)<=>(int)($b['stop_sequence']??0));
  $originStops=[];foreach($rows as $v){
   $name=strtolower(clean_station_name((string)($stopMap[$v['stop_id']??'']['stop_name']??'')));
   if($name!==$originName)continue;
   $parts=explode(':',(string)($v['departure_time']??''));
   if(count($parts)!==3)continue;
   $sec=(int)$parts[0]*3600+(int)$parts[1]*60+(int)$parts[2];
   if(abs($sec-$seconds)<=120)$originStops[]=$v;
  }
  if(!$originStops)continue;
  $evidence=qa_gtfs_pattern_evidence($rows,$stopMap,$originName,$seconds,$trip,$first);
  if($evidence['originIsLastStop']||$evidence['onwardStopCount']===0)continue;
  if($evidence['destinationComparable']&&$evidence['destinationAgrees']===false){$excluded['destinationMismatch']=($excluded['destinationMismatch']??0)+1;continue;}
  $valid[]=['tripId'=>$id,'evidence'=>$evidence,'confidence'=>isset($exact[$id])?'GTFS_ID_MATCH_CALENDAR_CHECKED':'CALENDAR_ROUTE_TIME_MATCH_UNVERIFIED',
   'serviceId'=>$serviceId,'calendarActive'=>$active!==null,'routeName'=>$routeText,'routeVerified'=>$routeVerified,
   'stops'=>array_map(fn($v)=>['stopId'=>$v['stop_id']??null,'name'=>$stopMap[$v['stop_id']??'']['stop_name']??null,'arrival'=>$v['arrival_time']??null,'departure'=>$v['departure_time']??null,'sequence'=>$v['stop_sequence']??null],$rows)];
 }
 $result['matches']=$valid;$result['excluded']=$excluded;
 $result['identityVerified']=false;
 $result['evidenceStatus']='SCHEDULE_PATTERN_ONLY_LIVE_TRIP_ID_NOT_LINKED';
 $result['status']=!$result['calendarAvailable']?'GTFS_CALENDAR_UNAVAILABLE':(count($valid)===1&&$valid[0]['routeVerified']?'SINGLE_GTFS_SCHEDULE_MATCH_UNVERIFIED':($valid?'GTFS_CANDIDATES_FOUND_UNVERIFIED':'NO_GTFS_MATCH'));
 $result['scannedStopTimeRows']=$scanned;
 $result['note']='QA-only Step 2F extracts advertised destination from departure-event fields where available, compares it with GTFS direction, and retains conservative unverified identity. No /trip calls or commuter routing changes.';
 return $result;
}
