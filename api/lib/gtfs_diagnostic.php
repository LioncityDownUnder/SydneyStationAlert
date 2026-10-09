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
  if(count($out)>250000){fclose($stream);return [];}
 }
 fclose($stream);return $out;
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
 if(!$trips||!$stops){$result['status']='GTFS_PARSE_OR_SIZE_LIMIT';$zip->close();return $result;}
 $stopMap=[];foreach($stops as $s)$stopMap[$s['stop_id']??'']=$s;
 $first=$candidate['firstDeparture']??[];$ids=array_map('strtolower',$first['tripIds']??[]);
 $exact=[];foreach($trips as $t)if(in_array(strtolower((string)($t['trip_id']??'')),$ids,true))$exact[$t['trip_id']]=$t;
 $originName=strtolower(clean_station_name((string)($origin['name']??'')));
 $ts=(int)($candidate['departureTimestamp']??0);
 $clock=(new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $seconds=(int)$clock->format('H')*3600+(int)$clock->format('i')*60+(int)$clock->format('s');
 $stream=$zip->getStream('stop_times.txt');if(!$stream){$zip->close();$result['status']='GTFS_STOP_TIMES_UNREADABLE';return $result;}
 $headers=fgetcsv($stream);$headers=array_map(fn($v)=>trim((string)$v,"\xEF\xBB\xBF \t"),$headers?:[]);
 $matched=[];$possible=[];$scanned=0;
 while(($line=fgetcsv($stream))!==false){
  if(++$scanned>1500000)break;
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
  while(($line=fgetcsv($stream))!==false&&++$n<=1500000){
   if(count($line)!==count($headers))continue;$v=array_combine($headers,$line);
   if(isset($possible[$v['trip_id']??'']))$matched[$v['trip_id']][]=$v;
  }
  fclose($stream);
 }
 $zip->close();
 foreach($matched as $id=>$rows){
  usort($rows,fn($a,$b)=>(int)($a['stop_sequence']??0)<=>(int)($b['stop_sequence']??0));
  $result['matches'][]=['tripId'=>$id,'confidence'=>isset($exact[$id])?'EXACT_GTFS_TRIP_ID':'STATION_TIME_ONLY_UNVERIFIED',
   'stops'=>array_map(fn($v)=>['stopId'=>$v['stop_id']??null,'name'=>$stopMap[$v['stop_id']??'']['stop_name']??null,'arrival'=>$v['arrival_time']??null,'departure'=>$v['departure_time']??null,'sequence'=>$v['stop_sequence']??null],$rows)];
 }
 $result['status']=$scanned>1500000?'GTFS_SCAN_LIMIT':($result['matches']?'GTFS_CANDIDATES_FOUND_UNVERIFIED':'NO_GTFS_MATCH');
 $result['scannedStopTimeRows']=$scanned;
 $result['note'].=' Static service-calendar validity, feed freshness, station identity and realtime identity must still be verified before using any result for routing.';
 return $result;
}
