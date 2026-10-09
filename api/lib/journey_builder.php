<?php
declare(strict_types=1);
/** QA-only independent GTFS journey construction. Static schedules, not realtime confirmation. */
function qa_journey_seconds(string $clock):?int{
 if(!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/',$clock,$m))return null;
 return (int)$m[1]*3600+(int)$m[2]*60+(int)$m[3];
}
function qa_journey_station(string $name):string{
 return strtolower(trim((string)preg_replace('/\\s+station(?:,.*)?$/i','',clean_station_name($name))));
}
function qa_journey_build(array $candidate,array $origin,array $destination):array{
 $result=['mode'=>'Step 3 independent GTFS journey construction','status'=>'UNAVAILABLE','routes'=>[],'minimumTransferSeconds'=>180,
 'note'=>'Static scheduled journey candidates only. No /trip calls, realtime connection confirmation or commuter routing changes.'];
 $probe=qa_gtfs_probe($candidate,$origin);$result['firstLegEvidence']=['status'=>$probe['status'],'indexStatus'=>$probe['indexStatus']??null,'matches'=>count($probe['matches']??[])];
 if(($probe['indexStatus']??'')!=='SQLITE_INDEX_USED'){$result['status']='GTFS_INDEX_REQUIRED';return $result;}
 if(count($probe['matches']??[])!==1){$result['status']='FIRST_TRAIN_AMBIGUOUS';return $result;}
 $match=$probe['matches'][0];$stops=$match['stops'];$originIdx=$match['evidence']['originStopIndex']??null;
 if($originIdx===null){$result['status']='ORIGIN_NOT_FOUND';return $result;}
 $target=qa_journey_station($destination['name']??'');
 if($target===''){$result['status']='INVALID_DESTINATION';return $result;}
 $path=(string)(getenv('QA_GTFS_STATIC_ZIP')?:'');
 if($path===''){$cfg=__DIR__.'/../gtfs-path.local.php';if(is_file($cfg))$path=(string)require $cfg;}
 $zip=new ZipArchive();if($zip->open($path)!==true)return $result;
 $date=new DateTimeImmutable($probe['serviceDate'],new DateTimeZone('Australia/Sydney'));
 $active=qa_gtfs_active_services($zip,$date);
 $trips=qa_gtfs_rows($zip,'trips.txt');$tripMap=[];
 foreach($trips as $trip)$tripMap[$trip['trip_id']??'']=$trip;
 $zip->close();
 if($active===null){$result['status']='CALENDAR_UNAVAILABLE';return $result;}
 $db=new SQLite3(dirname($path).'/stop-times.sqlite',SQLITE3_OPEN_READONLY);
 $lookup=$db->prepare('SELECT trip,stop,arrival,departure,seq FROM times WHERE stop=:stop');
 $sequence=$db->prepare('SELECT stop,arrival,departure,seq FROM times WHERE trip=:trip ORDER BY seq');
 $start=qa_journey_seconds((string)($stops[$originIdx]['departure']??''));$found=[];
 $destStopIds=[];$interchanges=[];
 foreach(array_slice($stops,$originIdx+1) as $offset=>$stop){
  $idx=$originIdx+1+$offset;$station=qa_journey_station((string)$stop['name']);
  $arrival=qa_journey_seconds((string)$stop['arrival']);
  if($arrival===null)continue;
  if($station===$target)$found[]=['type'=>'direct','legs'=>[['tripId'=>$match['tripId'],'line'=>$candidate['firstDeparture']['line']??'','from'=>$origin['name'],'to'=>$destination['name'],'departure'=>$stops[$originIdx]['departure'],'arrival'=>$stop['arrival']]],'transfers'=>0,'arrivalSeconds'=>$arrival,'confidence'=>'STATIC_SCHEDULE_UNVERIFIED'];
  if($station!==$target&&count($interchanges)<18)$interchanges[]=['stop'=>$stop,'arrival'=>$arrival,'station'=>$station];
 }
 $seen=[];$checked=0;
 foreach($interchanges as $change){
  $lookup->bindValue(':stop',$change['stop']['stopId'],SQLITE3_TEXT);$rs=$lookup->execute();$departures=[];
  while($row=$rs->fetchArray(SQLITE3_ASSOC)){
   $time=qa_journey_seconds((string)$row['departure']);
   if($time===null||$time<$change['arrival']+180||$time>$change['arrival']+5400)continue;
   if($row['trip']===$match['tripId']||!isset($active[$tripMap[$row['trip']]['service_id']??'']))continue;
   $departures[]=$row;
  }
  $rs->finalize();
  usort($departures,fn($a,$b)=>qa_journey_seconds($a['departure'])<=>qa_journey_seconds($b['departure']));
  foreach(array_slice($departures,0,45) as $board){
   if(++$checked>400)break 2;
   $tripId=$board['trip'];if(isset($seen[$tripId]))continue;$seen[$tripId]=true;
   $sequence->bindValue(':trip',$tripId,SQLITE3_TEXT);$sr=$sequence->execute();
   $boarded=false;$arrival=null;$lastName=null;
   while($leg=$sr->fetchArray(SQLITE3_ASSOC)){
    if(!$boarded){if($leg['stop']===$board['stop']&&(int)$leg['seq']===(int)$board['seq'])$boarded=true;continue;}
    // Destination stop identity is resolved from GTFS stops below.
    $destStopIds[$leg['stop']][]=['trip'=>$tripId,'leg'=>$leg,'board'=>$board,'change'=>$change];
   }
   $sr->finalize();
  }
 }
 // Resolve station names using GTFS stops, preserving platform variants.
 $zip=new ZipArchive();if($zip->open($path)===true){
  $stopRows=qa_gtfs_rows($zip,'stops.txt');$zip->close();$names=[];
  foreach($stopRows as $row)$names[$row['stop_id']??'']=qa_journey_station((string)($row['stop_name']??''));
  foreach($destStopIds as $id=>$possibilities){
   if(($names[$id]??'')!==$target)continue;
   foreach($possibilities as $p){
    $arrival=qa_journey_seconds((string)$p['leg']['arrival']);if($arrival===null)continue;
    $key=$p['trip'].'|'.$p['board']['stop'];if(isset($found[$key]))continue;
    $found[$key]=['type'=>'one_transfer','legs'=>[
     ['tripId'=>$match['tripId'],'line'=>$candidate['firstDeparture']['line']??'','from'=>$origin['name'],'to'=>$p['change']['stop']['name'],'departure'=>$stops[$originIdx]['departure'],'arrival'=>$p['change']['stop']['arrival']],
     ['tripId'=>$p['trip'],'from'=>$p['change']['stop']['name'],'to'=>$destination['name'],'departure'=>$p['board']['departure'],'arrival'=>$p['leg']['arrival']]],
     'transfers'=>1,'transferSeconds'=>qa_journey_seconds($p['board']['departure'])-$p['change']['arrival'],'arrivalSeconds'=>$arrival,'confidence'=>'STATIC_SCHEDULE_UNVERIFIED'];
   }
  }
 }
 $db->close();$found=array_values($found);
 usort($found,fn($a,$b)=>($a['arrivalSeconds']<=>$b['arrivalSeconds'])?:($a['transfers']<=>$b['transfers']));
 $result['routes']=array_slice($found,0,12);$result['status']=$found?'STATIC_JOURNEYS_FOUND_UNVERIFIED':'NO_STATIC_JOURNEY_FOUND';
 $result['checkedConnectingTrips']=$checked;
 $result['note'].=' Only a single independently matched first train is accepted; interchange and destination matches use GTFS stop names. Scheduled times do not account for delays or platform transfer walking.';
 return $result;
}
