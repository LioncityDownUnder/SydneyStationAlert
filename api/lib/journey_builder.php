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
 $stopRows=qa_gtfs_rows($zip,'stops.txt');$stopNames=[];$stopLabels=[];$stationStopIds=[];
 foreach($stopRows as $stop){$id=(string)($stop['stop_id']??'');$key=qa_journey_station((string)($stop['stop_name']??''));if($id!==''&&$key!==''){$stopNames[$id]=$key;$stopLabels[$id]=(string)($stop['stop_name']??'');$stationStopIds[$key][]=$id;}}
 $routes=qa_gtfs_rows($zip,'routes.txt');$railRoutes=[];
 foreach($routes as $route){if(in_array((string)($route['route_type']??''),['2','401','402'],true))$railRoutes[(string)($route['route_id']??'')]=true;}
 $trips=qa_gtfs_rows($zip,'trips.txt');$tripMap=[];
 foreach($trips as $trip)$tripMap[$trip['trip_id']??'']=$trip;
 $zip->close();
 if($active===null){$result['status']='CALENDAR_UNAVAILABLE';return $result;}
 $db=new SQLite3(dirname($path).'/stop-times.sqlite',SQLITE3_OPEN_READONLY);
 $start=qa_journey_seconds((string)($stops[$originIdx]['departure']??''));$found=[];$rejections=['insufficientTransferTime'=>0,'inactiveService'=>0,'noOnwardDestination'=>0,'outsideConnectionWindow'=>0];
 $interchanges=[];
 foreach(array_slice($stops,$originIdx+1) as $stop){
  $station=qa_journey_station((string)($stop['name']??''));
  $arrival=qa_journey_seconds((string)($stop['arrival']??''));
  if($arrival===null)continue;
  if($station===$target)$found[]=['type'=>'direct','legs'=>[['tripId'=>$match['tripId'],'line'=>$candidate['firstDeparture']['line']??'','from'=>$origin['name'],'to'=>$destination['name'],'departure'=>$stops[$originIdx]['departure'],'arrival'=>$stop['arrival'],'boardingStopId'=>$stops[$originIdx]['stopId'],'alightingStopId'=>$stop['stopId']]],'transfers'=>0,'arrivalSeconds'=>$arrival,'confidence'=>'STATIC_SCHEDULE_UNVERIFIED'];
  if($station!==$target)$interchanges[]=['stop'=>$stop,'arrival'=>$arrival,'station'=>$station];
 }
 $result['interchangeStationsConsidered']=count($interchanges);
 $result['destinationStopIds']=count($stationStopIds[$target]??[]);
 $result['railRouteTypePolicy']=['2','401','402'];
 $result['transferPolicy']=['samePlatformSeconds'=>180,'otherPlatformSeconds'=>420,'centralOtherPlatformSeconds'=>900,'note'=>'Conservative QA heuristics only; no platform walking or accessibility data'];
 if(empty($stationStopIds[$target])){$result['status']='DESTINATION_NOT_IN_GTFS';$db->close();return $result;}
 $destIds=array_values(array_unique($stationStopIds[$target]));
 $destPlaceholders=implode(',',array_fill(0,count($destIds),'?'));
 $query=$db->prepare("SELECT b.trip,b.stop AS boardStop,b.departure AS boardDeparture,b.seq AS boardSeq,d.stop AS destinationStop,d.arrival AS destinationArrival,d.seq AS destinationSeq FROM times b JOIN times d ON b.trip=d.trip AND d.seq>b.seq WHERE b.stop=? AND d.stop IN ($destPlaceholders) AND d.seq=(SELECT MIN(d2.seq) FROM times d2 WHERE d2.trip=b.trip AND d2.seq>b.seq AND d2.stop IN ($destPlaceholders))");
 $checked=0;$seen=[];$rejections['nonRailService']=0;
 foreach($interchanges as $change){
  $stationIds=array_values(array_unique($stationStopIds[$change['station']]??[]));
  foreach($stationIds as $platformId){
   $query->bindValue(1,$platformId,SQLITE3_TEXT);
   foreach($destIds as $i=>$id){$query->bindValue($i+2,$id,SQLITE3_TEXT);$query->bindValue(count($destIds)+$i+2,$id,SQLITE3_TEXT);}
   $rs=$query->execute();
   while($row=$rs->fetchArray(SQLITE3_ASSOC)){
    ++$checked;
    $tripId=$row['trip'];$key=$tripId.'|'.$platformId.'|'.$change['stop']['stopId'];
    if(isset($seen[$key])||$tripId===$match['tripId'])continue;$seen[$key]=true;
    if(!isset($railRoutes[(string)($tripMap[$tripId]['route_id']??'')])){$rejections['nonRailService']++;continue;}
    if(!isset($active[$tripMap[$tripId]['service_id']??''])){$rejections['inactiveService']++;continue;}
    $dep=qa_journey_seconds((string)$row['boardDeparture']);$arr=qa_journey_seconds((string)$row['destinationArrival']);
    if($dep===null||$arr===null||$arr<$dep){$rejections['noOnwardDestination']++;continue;}
    if($dep>$change['arrival']+5400){$rejections['outsideConnectionWindow']++;continue;}
    $samePlatform=$platformId===(string)$change['stop']['stopId'];
    $minimum=$samePlatform?180:($change['station']==='central'?900:420);
    $wait=$dep-$change['arrival'];
    if($wait<$minimum){$rejections['insufficientTransferTime']++;continue;}
    $found[]=['type'=>'one_transfer','legs'=>[
     ['tripId'=>$match['tripId'],'line'=>$candidate['firstDeparture']['line']??'','from'=>$origin['name'],'to'=>$change['stop']['name'],'departure'=>$stops[$originIdx]['departure'],'arrival'=>$change['stop']['arrival'],'boardingStopId'=>$stops[$originIdx]['stopId'],'alightingStopId'=>$change['stop']['stopId']],
     ['tripId'=>$tripId,'from'=>$stopLabels[$platformId]??$change['stop']['name'],'to'=>$destination['name'],'departure'=>$row['boardDeparture'],'arrival'=>$row['destinationArrival'],'boardingStopId'=>$platformId,'boardingPlatformName'=>$stopLabels[$platformId]??null,'alightingStopId'=>$row['destinationStop']]],
     'transfers'=>1,'transferSeconds'=>$wait,'minimumTransferSeconds'=>$minimum,'arrivalSeconds'=>$arr,'confidence'=>'STATIC_SCHEDULE_UNVERIFIED'];
   }
   $rs->finalize();
  }
 }
 $result['rejectedConnections']=$rejections;
 $result['matchingDestinationEvents']=count($found);
 $db->close();
 // Multiple GTFS platform records can describe the same train at one interchange.
 // Retain the earliest feasible boarding event for each connecting trip and interchange station.
 $unique=[];
 foreach($found as $route){
  if($route['type']==='direct'){$key='direct|'.$route['legs'][0]['tripId'].'|'.$route['legs'][0]['alightingStopId'];}
  else{$key='transfer|'.$route['legs'][1]['tripId'].'|'.qa_journey_station((string)$route['legs'][0]['to']);}
  if(!isset($unique[$key])){$unique[$key]=$route;continue;}
  $old=$unique[$key];
  if($route['arrivalSeconds']<$old['arrivalSeconds']||($route['arrivalSeconds']===$old['arrivalSeconds']&&qa_journey_seconds((string)$route['legs'][1]['departure'])<qa_journey_seconds((string)$old['legs'][1]['departure'])))$unique[$key]=$route;
 }
 $result['deduplicatedConnections']=count($found)-count($unique);
 $found=array_values($unique);$result['uniqueJourneyCount']=count($found);
 usort($found,fn($a,$b)=>qa_journey_seconds((string)$a['legs'][0]['departure'])<=>qa_journey_seconds((string)$b['legs'][0]['departure']) ?: ($a['arrivalSeconds']<=>$b['arrivalSeconds']) ?: ($a['transfers']<=>$b['transfers']));
 $result['routes']=array_slice($found,0,12);$result['displayedJourneyCount']=count($result['routes']);$result['hasMoreJourneys']=count($found)>count($result['routes']);$result['status']=$found?'STATIC_JOURNEYS_FOUND_UNVERIFIED':'NO_STATIC_JOURNEY_FOUND';
 $result['checkedConnectingTrips']=$checked;
 $result['rankingPolicy']='earliest departure, then earliest arrival';
 $result['note'].=' Step 3D reports the connecting boarding platform as the second leg origin, removes duplicate platform variants, and distinguishes accepted versus displayed journeys. Rail-only connection filtering uses GTFS route_type 2/401/402. Step 3B uses destination-aware indexed searches and identifies the connecting GTFS stop ID. Transfer buffers are provisional conservative heuristics, not validated walking times. Live delays are not applied.';
 return $result;
}
