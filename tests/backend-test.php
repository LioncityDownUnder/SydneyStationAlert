<?php
require __DIR__.'/../api/lib/core.php';
function assertit($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$origin=['id'=>'101','name'=>'Central','lat'=>-33.883,'lon'=>151.206,'mode'=>'train'];$dest=['id'=>'103','name'=>'Town Hall','lat'=>-33.873,'lon'=>151.207,'mode'=>'train'];
$fixtures=['success','transfer','mixed','no-route','partial','missing-platform'];foreach($fixtures as $key){$body=json_decode(file_get_contents(__DIR__.'/fixtures/'.$key.'.json'),true);$fixtureDest=$key==='transfer'?['id'=>'105','name'=>'Barangaroo','lat'=>-33.862,'lon'=>151.202,'mode'=>'metro']:$dest;$result=normalized_journey($body,$origin,$fixtureDest);if(in_array($key,['no-route','partial','mixed'])){assertit($result===null,"$key is rejected safely");continue;}assertit($result!==null,"$key normalized");if($key==='success'){assertit(count($result['stops'])===3,'intermediate stops retained');assertit(count($result['legs'][0]['path'])===3,'rail geometry retained');assertit($result['legs'][0]['platform']==='4','platform parsed');}if($key==='transfer')assertit(count($result['transfers'])===1&&count($result['legs'])===2,'transfer legs separate');if($key==='missing-platform')assertit($result['legs'][0]['platform']===null,'missing platform fallback is possible');}
assertit(mode(['product'=>['class'=>5],'name'=>'Bus'])===null,'bus is not a rail mode');

$alertBody=json_decode(file_get_contents(__DIR__.'/fixtures/service-alerts.json'),true);
$alertRoute=[
 'stops'=>[
  ['id'=>'200060','name'=>'Central','mode'=>'train','lat'=>-33.884,'lon'=>151.206],
  ['id'=>'220810','name'=>'Wolli Creek','mode'=>'train','lat'=>-33.928,'lon'=>151.154],
  ['id'=>'222020','name'=>'Hurstville','mode'=>'train','lat'=>-33.967,'lon'=>151.102]
 ],
 'legs'=>[['id'=>'leg-0','line'=>'T4 Eastern Suburbs & Illawarra Line']]
];
$status=journey_service_status($alertRoute,$alertBody);
assertit(count($status['alerts'])===2,'journey filters unrelated service alerts');
assertit($status['level']==='major','material stopping-pattern alert is major');
assertit($status['hasMaterialChange']===true,'material service change triggers revalidation flag');
assertit($status['alerts'][0]['materialChange']===true,'not-stopping alert is marked material');
