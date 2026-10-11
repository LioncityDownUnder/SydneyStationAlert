<?php
require __DIR__.'/../api/lib/core.php';
function assertit($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$origin=['id'=>'101','name'=>'Central','lat'=>-33.883,'lon'=>151.206,'mode'=>'train'];$dest=['id'=>'103','name'=>'Town Hall','lat'=>-33.873,'lon'=>151.207,'mode'=>'train'];
$fixtures=['success','transfer','mixed','no-route','partial','missing-platform'];foreach($fixtures as $key){$body=json_decode(file_get_contents(__DIR__.'/fixtures/'.$key.'.json'),true);$fixtureDest=$key==='transfer'?['id'=>'105','name'=>'Barangaroo','lat'=>-33.862,'lon'=>151.202,'mode'=>'metro']:$dest;$result=normalized_journey($body,$origin,$fixtureDest);if(in_array($key,['no-route','partial','mixed'])){assertit($result===null,"$key is rejected safely");continue;}assertit($result!==null,"$key normalized");if($key==='success'){assertit(count($result['stops'])===3,'intermediate stops retained');assertit(count($result['legs'][0]['path'])===3,'rail geometry retained');assertit($result['legs'][0]['platform']==='4','platform parsed');assertit($result['stops'][1]['arrival']==='2026-10-07T14:24:00+11:00','estimated intermediate arrival is preferred');assertit($result['stops'][1]['departure']==='2026-10-07T14:24:30+11:00','intermediate departure is retained');assertit($result['stops'][2]['arrival']==='2026-10-07T14:26:00+11:00','destination arrival is retained');}if($key==='transfer')assertit(count($result['transfers'])===1&&count($result['legs'])===2,'transfer legs separate');if($key==='missing-platform')assertit($result['legs'][0]['platform']===null,'missing platform fallback is possible');}
assertit(mode(['product'=>['class'=>5],'name'=>'Bus'])===null,'bus is not a rail mode');

$alertBody=json_decode(file_get_contents(__DIR__.'/fixtures/service-alerts.json'),true);
$alertRoute=[
 'stops'=>[
  ['id'=>'200060','name'=>'Central','mode'=>'train','lat'=>-33.884,'lon'=>151.206],
  ['id'=>'220810','name'=>'Wolli Creek','mode'=>'train','lat'=>-33.928,'lon'=>151.154],
  ['id'=>'222020','name'=>'Hurstville','mode'=>'train','lat'=>-33.967,'lon'=>151.102]
 ],
 'legs'=>[['id'=>'leg-0','line'=>'T4 Eastern Suburbs & Illawarra Line','tripIds'=>['selected-live-trip','232']]]
];
$status=journey_service_status($alertRoute,$alertBody);
assertit(count($status['alerts'])===2,'journey filters unrelated bus and other-line service alerts');
assertit($status['level']==='major','material stopping-pattern alert is major');
assertit($status['hasMaterialChange']===true,'material service change triggers revalidation flag');
assertit($status['alerts'][0]['materialChange']===true,'not-stopping alert is marked material');
assertit(!in_array('alert-t4-other-trip-major',array_column($status['alerts'],'id'),true),'same-line material alert for another trip is excluded');
assertit(in_array('selected-live-trip',$status['alerts'][0]['affectedTrips'],true),'exact realtime trip id is retained for matching');

assertit($status['alerts'][0]['affectedLines'][0] !== '970','bus line is not exposed as a rail disruption');
assertit(!in_array('T1 North Shore & Western Line',$status['alerts'][0]['affectedLines'],true),'other rail lines do not match merely through a shared station');


$affectedRoute=$alertRoute;
$affectedRoute['id']='affected-route';
$affectedRoute['legs'][0]['tripIds']=['selected-live-trip','232'];
$clearRoute=$alertRoute;
$clearRoute['id']='clear-route';
$clearRoute['legs'][0]['tripIds']=['different-live-trip','999'];
$affectedRoute['legs'][0]['arrival']='2026-10-08T03:00:00Z';
$clearRoute['legs'][0]['arrival']='2026-10-08T03:05:00Z';
assertit(route_has_material_service_change($affectedRoute,$alertBody)===true,'selected trip is recognized as materially disrupted');
assertit(route_has_material_service_change($clearRoute,$alertBody)===true,'different fixture trip with its own material alert is also recognized');
$thirdRoute=$alertRoute;
$thirdRoute['id']='third-route';
$thirdRoute['legs'][0]['tripIds']=['unaffected-live-trip','555'];
$thirdRoute['legs'][0]['arrival']='2026-10-08T03:10:00Z';
$replacement=best_unaffected_route([$affectedRoute,$clearRoute,$thirdRoute],$alertBody);
assertit($replacement!==null&&$replacement['id']==='third-route','revalidation selects the earliest unaffected route');


function test_pb_varint(int $value):string{
 $out='';do{$byte=$value&0x7f;$value>>=7;if($value>0)$byte|=0x80;$out.=chr($byte);}while($value>0);return $out;
}
function test_pb_int(int $field,int $value):string{return test_pb_varint(($field<<3)|0).test_pb_varint($value);}
function test_pb_msg(int $field,string $value):string{return test_pb_varint(($field<<3)|2).test_pb_varint(strlen($value)).$value;}
function test_pb_string(int $field,string $value):string{return test_pb_msg($field,$value);}

$tripMsg=test_pb_string(1,'3001.nsw-2-T4.1.TA.1432.sj2');
$vehicleDescriptor=test_pb_string(1,'train-123');
$carriage1=test_pb_string(1,'D1234').test_pb_int(2,1).test_pb_int(3,1).test_pb_int(4,1).test_pb_int(5,2).test_pb_int(6,1);
$carriage2=test_pb_string(1,'N5678').test_pb_int(2,2).test_pb_int(3,3).test_pb_int(4,0).test_pb_int(5,0).test_pb_int(6,0);
$vehicleMsg=test_pb_msg(1,$tripMsg).test_pb_int(5,1760000000).test_pb_string(7,'2000344').test_pb_msg(8,$vehicleDescriptor).test_pb_int(9,2).test_pb_msg(1007,$carriage1).test_pb_msg(1007,$carriage2);
$entityMsg=test_pb_string(1,'entity-1').test_pb_msg(4,$vehicleMsg);
$feedMsg=test_pb_msg(2,$entityMsg);
$parsedVehicles=parse_vehicle_positions_feed($feedMsg);
$parsedVehicle=$parsedVehicles['3001.nsw-2-t4.1.ta.1432.sj2']??null;
assertit(is_array($parsedVehicle),'GTFS realtime vehicle feed parser extracts trip');
assertit($parsedVehicle['vehicleId']==='train-123','vehicle id is retained');
assertit($parsedVehicle['level']==='moderate','whole-train occupancy is normalized');
assertit(count($parsedVehicle['carriages'])===2,'carriage occupancy records are retained');
assertit($parsedVehicle['carriages'][0]['level']==='quiet'&&$parsedVehicle['carriages'][0]['quietCarriage']===true,'quiet carriage occupancy is normalized');
assertit($parsedVehicle['carriages'][1]['level']==='busy','standing-room carriage is normalized as busy');
assertit(crowding_level_from_occupancy(4)==='very_busy'&&crowding_level_from_occupancy(7)==='unknown','occupancy enum mapping is conservative');


$trainUnknown=[
 'trip-a'=>['tripId'=>'trip-a','vehicleId'=>'train-a','level'=>'unknown','timestamp'=>1760000000,'carriages'=>[
  ['name'=>'1','position'=>1,'level'=>'unknown','quietCarriage'=>false,'toilet'=>'unknown','luggageRack'=>false]
 ]]
];
$metroKnown=[
 'trip-b'=>['tripId'=>'trip-b','vehicleId'=>'metro-b','level'=>'busy','timestamp'=>1760000001,'carriages'=>[
  ['name'=>'1','position'=>1,'level'=>'busy','quietCarriage'=>false,'toilet'=>'none','luggageRack'=>false]
 ]]
];
$transferCrowdingRoute=[
 'legs'=>[
  ['id'=>'leg-a','mode'=>'train','tripIds'=>['trip-a']],
  ['id'=>'leg-b','mode'=>'metro','tripIds'=>['trip-b']]
 ]
];
$combinedCrowding=journey_crowding_from_feeds($transferCrowdingRoute,['train'=>$trainUnknown,'metro'=>$metroKnown]);
assertit($combinedCrowding['available']===true,'known crowding on one transfer leg makes journey crowding available');
assertit($combinedCrowding['level']==='busy','journey crowding uses the busiest known leg');
assertit($combinedCrowding['legs'][0]['vehicleMatched']===true&&$combinedCrowding['legs'][0]['level']==='unknown','matched vehicle with unknown occupancy stays unknown');
assertit($combinedCrowding['legs'][1]['vehicleMatched']===true&&$combinedCrowding['legs'][1]['level']==='busy','known Metro occupancy is retained on transfer leg');

$unknownOnly=journey_crowding_from_feeds(['legs'=>[['id'=>'leg-a','mode'=>'train','tripIds'=>['trip-a']]]],['train'=>$trainUnknown]);
assertit($unknownOnly['available']===false&&$unknownOnly['level']==='unknown','matched vehicle without occupancy does not claim crowding is available');

$mismatch=journey_crowding_from_feeds(['legs'=>[['id'=>'leg-x','mode'=>'train','tripIds'=>['missing-trip']]]],['train'=>$trainUnknown]);
assertit($mismatch['available']===false&&$mismatch['legs'][0]['vehicleMatched']===false,'trip-id mismatch fails open without crowding data');


$routeWithKnownTransfer=[
 'transfers'=>[
  ['id'=>'220810','name'=>'Wolli Creek','mode'=>'train','lat'=>-33.928,'lon'=>151.154]
 ]
];
$transferBody=[
 'journeys'=>[
  ['legs'=>[
   ['transportation'=>['product'=>['class'=>1],'disassembledName'=>'T4'],'origin'=>['id'=>'222020','name'=>'Hurstville','coord'=>[-33.967,151.102]],'destination'=>['id'=>'220810','name'=>'Wolli Creek','coord'=>[-33.928,151.154]]],
   ['transportation'=>['product'=>['class'=>1],'disassembledName'=>'T8'],'origin'=>['id'=>'220810','name'=>'Wolli Creek','coord'=>[-33.928,151.154]],'destination'=>['id'=>'221810','name'=>'Padstow','coord'=>[-33.953,151.031]]]
  ]]
 ]
];
$prioritized=prioritized_transfer_candidates($routeWithKnownTransfer,$transferBody,
 ['id'=>'222020','name'=>'Hurstville','mode'=>'train','lat'=>-33.967,'lon'=>151.102],
 ['id'=>'221810','name'=>'Padstow','mode'=>'train','lat'=>-33.953,'lon'=>151.031],1);
assertit(count($prioritized)<=1,'transfer validation fanout is capped');
assertit(($prioritized[0]['name']??'')==='Wolli Creek','selected route transfer is validated first');

$GLOBALS['journey_perf']=['start'=>microtime(true),'phase'=>'setup','phaseStart'=>microtime(true),'durations'=>[],'counts'=>[],'coreOnly'=>true,'route'=>'test'];
$value=journey_perf_track('normalize',fn()=>42);
journey_perf_phase('initial_parallel_search');
assertit($value===42,'timed callback returns its original value');
assertit(($GLOBALS['journey_perf']['counts']['normalize']??0)===1,'performance tracker records phase calls');
assertit(isset($GLOBALS['journey_perf']['durations']['phase_setup']),'performance tracker measures stage durations');
unset($GLOBALS['journey_perf']);

$transferPoint=['id'=>'220810','name'=>'Wolli Creek','lat'=>-33.928,'lon'=>151.154,'mode'=>'train','platform'=>'3','arrival'=>'2026-10-08T22:48:00+11:00'];
$transferBoard=$transferPoint;$transferBoard['platform']='2';$transferBoard['arrival']=null;$transferBoard['departure']='2026-10-08T23:05:00+11:00';
$originLate=['id'=>'222020','name'=>'Hurstville','lat'=>-33.967,'lon'=>151.102,'mode'=>'train','platform'=>'3','departure'=>'2026-10-08T22:36:00+11:00'];
$destinationLate=['id'=>'221000','name'=>'Padstow','lat'=>-33.953,'lon'=>151.032,'mode'=>'train','platform'=>'2','arrival'=>'2026-10-08T23:25:00+11:00'];
$firstLate=['legs'=>[['origin'=>$originLate,'destination'=>$transferPoint,'arrival'=>$transferPoint['arrival'],'departure'=>$originLate['departure'],'stops'=>[$originLate,$transferPoint]]]];
$secondLate=['legs'=>[['origin'=>$transferBoard,'destination'=>$destinationLate,'arrival'=>$destinationLate['arrival'],'departure'=>$transferBoard['departure'],'stops'=>[$transferBoard,$destinationLate]]]];
$stitchedLate=stitch_routes($firstLate,$secondLate,$originLate,$destinationLate);
assertit($stitchedLate!==null&&count($stitchedLate['transfers'])===1,'late T4 to T8 connection with 17-minute interchange remains valid');
assertit($stitchedLate['transfers'][0]['arrivalPlatform']==='3'&&$stitchedLate['transfers'][0]['departurePlatform']==='2','interchange preserves separate arrival and boarding platforms');

$earlyFirst=$firstLate;
$earlyFirst['legs'][0]['departure']='2026-10-08T22:36:00+11:00';
$earlySecond=$secondLate;
$earlySecond['legs'][0]['departure']='2026-10-08T23:05:00+11:00';
$laterFirst=$firstLate;
$laterFirst['legs'][0]['departure']='2026-10-08T22:51:00+11:00';
$laterFirst['legs'][0]['arrival']='2026-10-08T23:06:00+11:00';
$laterSecond=$secondLate;
$laterSecond['legs'][0]['departure']='2026-10-08T23:20:00+11:00';
$laterSecond['legs'][0]['arrival']='2026-10-08T23:40:00+11:00';
$foundLate=best_connecting_route([$earlyFirst,$laterFirst],[$earlySecond,$laterSecond],$originLate,$destinationLate,strtotime('2026-10-08T22:42:00+11:00'));
assertit($foundLate!==null,'later train is found even when first candidate has departed');
assertit($foundLate['legs'][0]['departure']==='2026-10-08T22:51:00+11:00'&&$foundLate['legs'][1]['departure']==='2026-10-08T23:20:00+11:00','late interchange uses a valid subsequent T8 service');

$overnightFirst=$firstLate;
$overnightFirst['legs'][0]['departure']='2026-10-08T23:55:00+11:00';
$overnightFirst['legs'][0]['arrival']='2026-10-09T00:10:00+11:00';
$overnightNext=$secondLate;
$overnightNext['legs'][0]['departure']='2026-10-09T00:24:00+11:00';
$overnightNext['legs'][0]['arrival']='2026-10-09T00:44:00+11:00';
$overnight=best_connecting_route([$overnightFirst],[$overnightNext],$originLate,$destinationLate,strtotime('2026-10-08T23:45:00+11:00'));
assertit($overnight!==null,'train-only interchange after midnight is valid');
assertit(route_arrival_ts($overnight)>strtotime('2026-10-09T00:40:00+11:00'),'overnight arrival is not cut off at calendar midnight');

$lateProbeStart=new DateTimeImmutable('2026-10-08T23:42:00+11:00');
$lateProbes=journey_search_probes($lateProbeStart);
assertit(count($lateProbes)===18,'overnight search keeps the bounded probe count');
assertit($lateProbes[0]->format('Y-m-d H:i')==='2026-10-08 23:42','overnight search begins at the requested current time');
assertit($lateProbes[1]->format('Y-m-d H:i')==='2026-10-09 00:12','overnight search probes the following date');
assertit($lateProbes[4]->format('Y-m-d H:i')==='2026-10-09 01:42','overnight search retains five probes for the parallel fast path');
assertit($lateProbes[count($lateProbes)-1]->format('Y-m-d H:i')==='2026-10-09 23:42','overnight search ends within a rolling 24-hour horizon');

require_once __DIR__.'/../api/lib/qa_route_rejections.php';
$qaOrigin=['id'=>'101','name'=>'Central','lat'=>-33.883,'lon'=>151.206,'mode'=>'train'];
$qaDest=['id'=>'103','name'=>'Town Hall','lat'=>-33.873,'lon'=>151.207,'mode'=>'train'];
$qaMixed=json_decode(file_get_contents(__DIR__.'/fixtures/mixed.json'),true);
$qaReject=qa_route_rejection_summary($qaMixed,$qaOrigin,$qaDest);
assertit(($qaReject['rawJourneys']??0)>0,'Step 5AD rejection probe counts raw journeys');
assertit(($qaReject['railOnlyNormalized']??-1)===0,'Step 5AD rejection probe does not accept mixed-mode journeys');
assertit(array_sum($qaReject['reasons'])===$qaReject['sampledJourneys'],'Step 5AD counts each sampled journey once');

$qaDetails=$qaReject['journeyLegDetails']??[];
assertit(count($qaDetails)===1,'Step 5AE includes one bounded fixture journey classification');
assertit(($qaDetails[0]['reason']??'')==='NON_RAIL_LEG','Step 5AE preserves strict rail-only rejection');
assertit(($qaDetails[0]['legs'][0]['kind']??'')==='non_rail'&&($qaDetails[0]['legs'][0]['transportClass']??'')==='5','Step 5AE identifies bus leg without exposing journey payload');
assertit(($qaDetails[0]['legs'][1]['kind']??'')==='train','Step 5AE distinguishes following rail leg');
$qaWalk=['journeys'=>[['legs'=>[['transportation'=>['product'=>['class'=>100],'name'=>'Walk']]]]]];
$qaWalkDetails=qa_route_rejection_summary($qaWalk,$qaOrigin,$qaDest)['journeyLegDetails'][0]['legs']??[];
assertit(($qaWalkDetails[0]['kind']??'')==='walk','Step 5AE does not classify walking interchange as non-rail transport');

$baseFilter=['name_origin'=>'222010','name_destination'=>'221110','calcNumberOfTrips'=>22];
$qaFiltered=qa_rail_filter_params($baseFilter);
assertit(($qaFiltered['name_origin']??'')==='222010'&&($qaFiltered['name_destination']??'')==='221110','Step 5AF filter preserves station pair');
assertit(($qaFiltered['excludedMeans']??'')==='checkbox'&&($qaFiltered['exclMOT_5']??0)===1,'Step 5AF filter requests exclusion of bus class');
assertit(!isset($qaFiltered['exclMOT_1'])&&!isset($qaFiltered['exclMOT_2']),'Step 5AF does not exclude train or metro');
$comparison=qa_rail_filter_comparison($qaMixed,$qaMixed,$qaOrigin,$qaDest);
assertit(($comparison['baseline']['summary']['rawJourneys']??0)===1&&($comparison['railFiltered']['summary']['railOnlyNormalized']??-1)===0,'Step 5AF reports counts without falsely accepting mixed fixture');
assertit(($comparison['railFilterConfirmed']??null)===false,'Step 5AF does not claim that TfNSW applied the filter');
assertit(!isset($comparison['baseline']['summary']['journeyLegDetails']),'Step 5AF comparison excludes raw per-leg journey evidence');

$qaRailResult=qa_rail_search_result($qaMixed,$qaOrigin,$qaDest);
assertit(($qaRailResult['status']??'')==='NO_RAIL_CANDIDATE_IN_RESPONSE','Step 5AG refuses mixed-mode route even with filter request');
assertit(($qaRailResult['railOnlyNormalized']??-1)===0,'Step 5AG strictly normalizes candidates');
assertit(!isset($qaRailResult['journeyLegDetails'])&&!isset($qaRailResult['journeys']),'Step 5AG response does not expose raw journeys');
$qaMissingResult=qa_rail_search_result(null,$qaOrigin,$qaDest);
assertit(($qaMissingResult['status']??'')==='UPSTREAM_UNAVAILABLE','Step 5AG distinguishes upstream failure from unavailable trains');
$qaRailFixture=json_decode(file_get_contents(__DIR__.'/fixtures/success.json'),true);
$qaGoodResult=qa_rail_search_result($qaRailFixture,$qaOrigin,$qaDest);
assertit(($qaGoodResult['status']??'')==='RAIL_CANDIDATE_UNVERIFIED'&&$qaGoodResult['railOnlyNormalized']>0,'Step 5AG keeps valid train-only candidate explicitly unverified');

$windowOrigin=['id'=>'101','name'=>'Central','lat'=>-33.883,'lon'=>151.206,'mode'=>'train'];
$windowDest=['id'=>'103','name'=>'Town Hall','lat'=>-33.873,'lon'=>151.207,'mode'=>'train'];
$windowRaw=json_decode(file_get_contents(__DIR__.'/fixtures/success.json'),true);
$baseTime=strtotime('2026-10-07T14:00:00+11:00');
$windowRoute=qa_rail_route_in_window($windowRaw,$windowOrigin,$windowDest,$baseTime,$baseTime+14400);
assertit($windowRoute!==null,'Step 5AJ accepts chronological train journey within four hours');
assertit(qa_rail_route_in_window($windowRaw,$windowOrigin,$windowDest,$baseTime-18000,$baseTime-3600)===null,'Step 5AJ rejects departures more than four hours ahead');
$invalidTimeline=['legs'=>[['mode'=>'train','departure'=>'2026-10-07T14:00:00+11:00','arrival'=>'2026-10-07T14:10:00+11:00'],['mode'=>'train','departure'=>'2026-10-07T14:09:00+11:00','arrival'=>'2026-10-07T14:20:00+11:00']]];
assertit(!qa_rail_chronology_valid($invalidTimeline),'Step 5AJ rejects impossible one-minute interchange');

$counts=qa_window_rejection_counts($windowRaw,$windowOrigin,$windowDest,$baseTime);
assertit(($counts['eligible']??0)===1,'Step 5AK categorizes eligible four-hour candidate');
$lateCounts=qa_window_rejection_counts($windowRaw,$windowOrigin,$windowDest,$baseTime-18000);
assertit(($lateCounts['beyond_four_hours']??0)===1,'Step 5AK categorizes departure beyond four hours');

 
// Crowding matching remains exact and never substitutes another service.
$crowdingRoute=['legs'=>[['id'=>'leg-0','mode'=>'metro','tripIds'=>['selected-trip']]]];
$unmatched=journey_crowding_from_feeds($crowdingRoute,['metro'=>['other-trip'=>['level'=>'busy','carriages'=>[]]]]);
assertit(($unmatched['available']??true)===false,'Selected crowding never matches a different trip');
$matched=journey_crowding_from_feeds($crowdingRoute,['metro'=>['selected-trip'=>['level'=>'moderate','carriages'=>[['level'=>'moderate']],'timestamp'=>time()]]]);
assertit(($matched['available']??false)===true,'Selected crowding retains exact trip matching');

$cacheDir=upstream_cache_directory();
assertit($cacheDir===sys_get_temp_dir().'/sydney_station_alert_'.substr(hash('sha256',dirname(__DIR__).'/api/lib'),0,8),'Shared cache directory retains original path');
assertit(is_dir($cacheDir),'Shared cache directory exists');
assertit($cacheDir===upstream_cache_directory(),'Shared cache directory is stable across calls');
