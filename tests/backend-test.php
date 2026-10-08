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
