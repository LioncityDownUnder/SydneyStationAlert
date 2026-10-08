<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
require __DIR__.'/lib/core.php';
if($_SERVER['REQUEST_METHOD']!=='GET')fail('BAD_REQUEST','Only GET requests are accepted.',405);
$action=(string)($_GET['action']??'');
try {
 if($action==='health'){echo json_encode(['data'=>['ok'=>true,'configured'=>source_key()!=='']]);exit;}
 if($action==='station-index'){$data=station_index();echo json_encode(['data'=>$data],JSON_INVALID_UTF8_SUBSTITUTE);exit;}
 if($action==='stations'){$q=trim((string)($_GET['q']??''));if(mb_strlen($q)<2||mb_strlen($q)>70)fail('BAD_REQUEST','Enter at least two station-name characters.');$data=station_search($q);echo json_encode(['data'=>$data]);exit;}
 if($action==='nearby'){$lat=filter_var($_GET['lat']??null,FILTER_VALIDATE_FLOAT);$lon=filter_var($_GET['lon']??null,FILTER_VALIDATE_FLOAT);if($lat===false||$lon===false||$lat< -38||$lat> -31||$lon<149||$lon>153)fail('BAD_REQUEST','Location is outside the supported NSW area.');
  $body=upstream('coord',['coord'=>(int)round($lon*1000000).':'.(int)round($lat*1000000).':EPSG:4326','type_1'=>'stop','radius_1'=>3000,'inclFilter'=>1],90);
  $found=[];foreach(val($body,'locations',[]) as $loc){$railMode=location_rail_mode($loc);if(!$railMode)continue;$s=station($loc,$railMode);if($s){$distance=2*6371000*asin(min(1,sqrt(sin(deg2rad(($s['lat']-$lat)/2))**2+cos(deg2rad($lat))*cos(deg2rad($s['lat']))*sin(deg2rad(($s['lon']-$lon)/2))**2)));if($distance<=3000)$found[]=$s;}}echo json_encode(['data'=>$found]);exit;
 }
 if($action==='journey'){
  $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');if(!preg_match('/^[\w:-]{3,50}$/',$from)||!preg_match('/^[\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Choose two different valid stations.');
  $fromName=trim((string)($_GET['fromName']??''));$toName=trim((string)($_GET['toName']??''));
  if($fromName===''||mb_strlen($fromName)>100||preg_match('/[\x00-\x1F\x7F]/u',$fromName))$fromName=$from;
  if($toName===''||mb_strlen($toName)>100||preg_match('/[\x00-\x1F\x7F]/u',$toName))$toName=$to;
  $originSeed=['id'=>$from,'name'=>$fromName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
  $destinationSeed=['id'=>$to,'name'=>$toName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
  $now=new DateTimeImmutable('now',new DateTimeZone('Australia/Sydney'));
  $coreOnly=(string)($_GET['coreOnly']??'')==='1';
  $tripCount=$coreOnly?10:30;
  $route=null;
  $searchTimes=[$now];
  $offsets=[30,60,90,120,180,240,300,360,480,600,720,840,960,1080,1200,1320,1440];
  foreach($offsets as $minutes){
   $probe=$now->modify('+'.$minutes.' minutes');
   if($probe->format('Ymd')!==$now->format('Ymd'))break;
   $searchTimes[]=$probe;
  }
  foreach($searchTimes as $probe){
   $body=upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$tripCount,'TfNSWTR'=>'true'],25);
   $route=better_route($route,normalized_journey($body,$originSeed,$destinationSeed));
   $needsInterchangeCheck=!$route;
   foreach($needsInterchangeCheck?prioritized_transfer_candidates($route,$body,$originSeed,$destinationSeed,1):[] as $transfer){
    $firstBody=upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$transfer['id'],'calcNumberOfTrips'=>16,'TfNSWTR'=>'true'],25);
    $firstRoute=normalized_journey($firstBody,$originSeed,$transfer);if(!$firstRoute)continue;
    $arrival=route_arrival_ts($firstRoute);if($arrival===PHP_INT_MAX)continue;
    $onward=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
    if($onward->format('Ymd')!==$now->format('Ymd'))continue;
    $secondBody=upstream('trip',['depArrMacro'=>'dep','itdDate'=>$onward->format('Ymd'),'itdTime'=>$onward->format('Hi'),'type_origin'=>'stop','name_origin'=>$transfer['id'],'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>16,'TfNSWTR'=>'true'],25);
    $secondRoute=normalized_journey($secondBody,$transfer,$destinationSeed);if(!$secondRoute)continue;
    $route=better_route($route,stitch_routes($firstRoute,$secondRoute,$originSeed,$destinationSeed));
   }
   if($route)break;
  }
  if(!$route)fail('NO_RAIL_TODAY','No more train services are available today from '.$fromName.' to '.$toName.'.',404);
  if($coreOnly){
   $route['serviceStatus']=['level'=>'unavailable','hasMaterialChange'=>false,'updatedAt'=>gmdate('c'),'alerts'=>[],'revalidationAttempted'=>false,'replacementFound'=>false];
   $route['crowding']=['available'=>false,'level'=>'unknown','updatedAt'=>gmdate('c'),'legs'=>[]];
   $route['nextDepartures']=[];
   echo json_encode(['data'=>$route],JSON_INVALID_UTF8_SUBSTITUTE);exit;
  }
  $alertBody=fetch_current_service_alerts($now);
  if(is_array($alertBody)){
   $initialStatus=journey_service_status($route,$alertBody);
   $initialRouteId=(string)$route['id'];
   if($initialStatus['hasMaterialChange']===true){
    $replacement=null;
    foreach($searchTimes as $probe){
     $body=upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$tripCount,'TfNSWTR'=>'true'],25);
     $replacement=better_route($replacement,best_unaffected_route(normalized_journeys($body,$originSeed,$destinationSeed),$alertBody));
     $needsInterchangeCheck=!$replacement;
     foreach($needsInterchangeCheck?prioritized_transfer_candidates($replacement,$body,$originSeed,$destinationSeed,1):[] as $transfer){
      $firstBody=upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$transfer['id'],'calcNumberOfTrips'=>16,'TfNSWTR'=>'true'],25);
      $firstRoute=best_unaffected_route(normalized_journeys($firstBody,$originSeed,$transfer),$alertBody);if(!$firstRoute)continue;
      $arrival=route_arrival_ts($firstRoute);if($arrival===PHP_INT_MAX)continue;
      $onward=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
      if($onward->format('Ymd')!==$now->format('Ymd'))continue;
      $secondBody=upstream('trip',['depArrMacro'=>'dep','itdDate'=>$onward->format('Ymd'),'itdTime'=>$onward->format('Hi'),'type_origin'=>'stop','name_origin'=>$transfer['id'],'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>16,'TfNSWTR'=>'true'],25);
      $secondRoute=best_unaffected_route(normalized_journeys($secondBody,$transfer,$destinationSeed),$alertBody);if(!$secondRoute)continue;
      $stitched=stitch_routes($firstRoute,$secondRoute,$originSeed,$destinationSeed);
      if($stitched&&!route_has_material_service_change($stitched,$alertBody))$replacement=better_route($replacement,$stitched);
     }
     if($replacement)break;
    }
    if($replacement){
     $replacement['source']='validated';
     $route=$replacement;
     $route['serviceStatus']=journey_service_status($route,$alertBody);
     $route['serviceStatus']['revalidationAttempted']=true;
     $route['serviceStatus']['replacementFound']=true;
     $route['serviceStatus']['originalJourneyId']=$initialRouteId;
    }else{
     $route['serviceStatus']=$initialStatus;
     $route['serviceStatus']['revalidationAttempted']=true;
     $route['serviceStatus']['replacementFound']=false;
     $route['serviceStatus']['originalJourneyId']=$initialRouteId;
    }
   }else{
    $route['serviceStatus']=$initialStatus;
    $route['serviceStatus']['revalidationAttempted']=false;
    $route['serviceStatus']['replacementFound']=false;
   }
  }else{
   $route['serviceStatus']=['level'=>'unavailable','hasMaterialChange'=>false,'updatedAt'=>gmdate('c'),'alerts'=>[],'revalidationAttempted'=>false,'replacementFound'=>false];
  }
  $route['crowding']=journey_crowding_status($route);
  $first=$route['legs'][0];$dep=upstream('departure_mon',['mode'=>'direct','type_dm'=>'stop','name_dm'=>$first['origin']['id'],'depArrMacro'=>'dep','itdDate'=>$now->format('Ymd'),'itdTime'=>$now->format('Hi'),'TfNSWDM'=>'true'],25);
  $times=[];foreach(val($dep,'stopEvents',[]) as $evt){if(!mode(val($evt,'transportation',[])))continue;$t=val($evt,'departureTimeEstimated',val($evt,'departureTimePlanned'));if(is_string($t)&&strtotime($t)!==false)$times[]=$t;if(count($times)>=2)break;}$route['nextDepartures']=$times;
  echo json_encode(['data'=>$route],JSON_INVALID_UTF8_SUBSTITUTE);exit;
 }
 fail('BAD_REQUEST','Unknown API action.',404);
} catch(Throwable $e){error_log('Sydney Station Alert: '.$e->getMessage());fail('UPSTREAM_UNAVAILABLE','Transport data could not be loaded. Try again shortly.',502);}
