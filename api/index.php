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
 // Production crowding lookup matches only identifiers from the selected service.
 if($action==='crowding'){
  $encoded=(string)($_GET['legs']??'');
  if($encoded===''||strlen($encoded)>3000)fail('BAD_REQUEST','Invalid crowding request.');
  $legs=json_decode($encoded,true);
  if(!is_array($legs)||!array_is_list($legs)||count($legs)<1||count($legs)>6)fail('BAD_REQUEST','Invalid crowding legs.');
  $normalized=[];
  foreach($legs as $i=>$leg){
   if(!is_array($leg)||!in_array($leg['mode']??null,['train','metro'],true))fail('BAD_REQUEST','Invalid rail mode.');
   $ids=$leg['tripIds']??null;
   if(!is_array($ids)||!array_is_list($ids)||count($ids)>8)fail('BAD_REQUEST','Invalid trip identifier list.');
   $valid=[];
   foreach($ids as $id){
    if(!is_string($id)&&!is_int($id))fail('BAD_REQUEST','Invalid trip identifier.');
    $text=(string)$id;
    if(strlen($text)>150||trim($text)===''||preg_match('/[\\x00-\\x1F\\x7F]/',$text))fail('BAD_REQUEST','Invalid trip identifier.');
    $valid[]=$text;
   }
   $normalized[]=['id'=>'leg-'.$i,'mode'=>$leg['mode'],'tripIds'=>$valid];
  }
  echo json_encode(['data'=>journey_crowding_status(['legs'=>$normalized])],JSON_INVALID_UTF8_SUBSTITUTE);exit;
 }
 // QA-only list of independently normalized rail journeys near boarding time.
 if($action==='boarding-options'){
  if(!str_contains((string)($_SERVER['SCRIPT_NAME']??''),'/qatest/api/'))fail('NOT_FOUND','Unavailable',404);
  $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
  if(!preg_match('/^[\\w:-]{3,50}$/',$from)||!preg_match('/^[\\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Choose two valid stations.');
  $fromName=trim((string)($_GET['fromName']??$from));$toName=trim((string)($_GET['toName']??$to));
  if($fromName===''||mb_strlen($fromName)>100)$fromName=$from;
  if($toName===''||mb_strlen($toName)>100)$toName=$to;
  require_once __DIR__.'/lib/qa_route_rejections.php';
  $origin=['id'=>$from,'name'=>$fromName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
  $destination=['id'=>$to,'name'=>$toName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
  $now=new DateTimeImmutable('now',new DateTimeZone('Australia/Sydney'));$ts=$now->getTimestamp();
  $params=[];
  foreach([-90,-45,0] as $offset){
   $probe=$now->modify(($offset<0?'':'+' ).$offset.' minutes');
   $params[]=qa_rail_filter_params(['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>30,'TfNSWTR'=>'true']);
  }
  $found=[];
  foreach(timed_parallel_trip($params,15) as $body){
   if(!is_array($body))continue;
   foreach(normalized_journeys($body,$origin,$destination) as $candidate){
    $departure=route_departure_ts($candidate);
    if($departure<$ts-5400||$departure>$ts+600||!qa_rail_chronology_valid($candidate))continue;
    $first=$candidate['legs'][0];$ids=array_values(array_filter(array_map('strval',$first['tripIds']??[])));
    // No two timetable entries may be treated as the same service just because their line matches.
    $key=$ids?'id:'.implode('|',array_map('strtolower',$ids)):'time:'.$departure.':'.($first['line']??'').':'.($first['origin']['id']??'');
    if(!isset($found[$key]))$found[$key]=$candidate;
   }
  }
  $options=array_values($found);usort($options,fn($a,$b)=>route_departure_ts($b)<=>route_departure_ts($a));
  echo json_encode(['data'=>array_slice($options,0,8)],JSON_INVALID_UTF8_SUBSTITUTE);exit;
 }
 if($action==='journey'){
  $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');if(!preg_match('/^[\w:-]{3,50}$/',$from)||!preg_match('/^[\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Choose two different valid stations.');
  $fromName=trim((string)($_GET['fromName']??''));$toName=trim((string)($_GET['toName']??''));
  if($fromName===''||mb_strlen($fromName)>100||preg_match('/[\x00-\x1F\x7F]/u',$fromName))$fromName=$from;
  if($toName===''||mb_strlen($toName)>100||preg_match('/[\x00-\x1F\x7F]/u',$toName))$toName=$to;
  journey_perf_begin($from,$to,(string)($_GET['coreOnly']??'')==='1');
  $originSeed=['id'=>$from,'name'=>$fromName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
  $destinationSeed=['id'=>$to,'name'=>$toName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
  $now=new DateTimeImmutable('now',new DateTimeZone('Australia/Sydney'));
  $coreOnly=(string)($_GET['coreOnly']??'')==='1';
  // QA-only budget: prevent disrupted rail searches from traversing every 24-hour probe.
  $qaBoundedSearch=$coreOnly&&str_contains((string)($_SERVER['SCRIPT_NAME']??''),'/qatest/api/');
  $qaSearchDeadline=$qaBoundedSearch?microtime(true)+22.0:INF;
  // Explicit opt-in on QA only; production and ordinary QA requests follow the legacy path.
  $qaRailPilot=$qaBoundedSearch;
  if($qaRailPilot)require_once __DIR__.'/lib/qa_route_rejections.php';
  $qaRailLatestDeparture=$qaRailPilot?$now->getTimestamp()+4*3600:PHP_INT_MAX;
  $qaRouteWithinWindow=static function(?array $candidate)use($qaRailPilot,$now,$qaRailLatestDeparture):?array{
   if(!$qaRailPilot||$candidate===null)return $candidate;
   $departure=route_departure_ts($candidate);
   return $departure>=$now->getTimestamp()&&$departure<=$qaRailLatestDeparture?$candidate:null;
  };
  $qaSearchBudgetExceeded=false;
  $tripCount=$coreOnly?22:30;
  $fallbackTripCount=$coreOnly?8:16;
  $route=null;
  $searchTimes=journey_search_probes($now);
  journey_perf_phase('initial_parallel_search');
  if($coreOnly&&count($searchTimes)>=6){
   $fastProbes=array_slice($searchTimes,0,$qaRailPilot?7:6);$fastParams=[];
   foreach($fastProbes as $probe)$fastParams[]=['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$tripCount,'TfNSWTR'=>'true'];
   if($qaRailPilot)$fastParams=array_map('qa_rail_filter_params',$fastParams);
   $fastBodies=timed_parallel_trip($fastParams,25);
   foreach($fastBodies as $i=>$body){if(!is_array($body))continue;$candidate=$qaRailPilot?qa_rail_route_in_window($body,$originSeed,$destinationSeed,$now->getTimestamp(),$qaRailLatestDeparture):timed_normalized_journey($body,$originSeed,$destinationSeed);$candidate=$qaRouteWithinWindow($candidate);$route=better_route($route,$candidate);}
   if(!$route&&!$qaRailPilot){
    journey_perf_phase('parallel_fallback');
    $fallbackMeta=[];$firstParams=[];
    foreach($fastBodies as $i=>$body){if(!is_array($body))continue;$probe=$fastProbes[$i];$candidates=timed_transfer_candidates(null,$body,$originSeed,$destinationSeed,1);if(!$candidates)continue;$transfer=$candidates[0];$fallbackMeta[]=['probe'=>$probe,'transfer'=>$transfer];$firstParams[]=['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$transfer['id'],'calcNumberOfTrips'=>$fallbackTripCount,'TfNSWTR'=>'true'];}
    if($firstParams){$firstBodies=timed_parallel_trip($firstParams,25);
     $secondParams=[];$secondMeta=[];
     foreach($firstBodies as $i=>$fb){
      if(!is_array($fb)||!isset($fallbackMeta[$i]))continue;
      $transfer=$fallbackMeta[$i]['transfer'];
      $firstRoute=timed_normalized_journey($fb,$originSeed,$transfer);
      if(!$firstRoute)continue;
      $arrival=route_arrival_ts($firstRoute);if($arrival===PHP_INT_MAX)continue;
      $onward=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
      $secondMeta[]=['first'=>$firstRoute,'transfer'=>$transfer];
      $secondParams[]=['depArrMacro'=>'dep','itdDate'=>$onward->format('Ymd'),'itdTime'=>$onward->format('Hi'),'type_origin'=>'stop','name_origin'=>$transfer['id'],'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$fallbackTripCount,'TfNSWTR'=>'true'];
     }
     // Batch onward TfNSW calls: do not wait on each alternative interchange serially.
     if($secondParams){
      $secondBodies=timed_parallel_trip($secondParams,25);
      foreach($secondBodies as $i=>$secondBody){
       if(!is_array($secondBody))continue;
       $meta=$secondMeta[$i];$secondRoute=timed_normalized_journey($secondBody,$meta['transfer'],$destinationSeed);
       if(!$secondRoute)continue;
       $route=better_route($route,stitch_routes($meta['first'],$secondRoute,$originSeed,$destinationSeed));
      }
     }
    }
   }
  }
  if($qaRailPilot&&!headers_sent())header('X-QA-Rail-Pilot: enabled');
  $qaSearchDiagnostics=['initial_route_found'=>$route!==null,'additional_probes'=>0,'additional_route_probe'=>0,'additional_prefetch_count'=>0,'interchange_checks'=>0,'time_budget_exceeded'=>0];
  journey_perf_phase('additional_search');
  $remainingSearchTimes=$qaRailPilot?[]:(($coreOnly&&count($searchTimes)>=6)?array_slice($searchTimes,6):$searchTimes);
  // The initial batch has already verified a train-only route. Avoid an extra
  // TfNSW lookup on core-only requests; retain full search for non-core requests.
  if($coreOnly&&$route)$remainingSearchTimes=[];
  // Prefetch the next two probes together only when the initial batch found no route.
  // Keep later probes sequential to limit unnecessary upstream requests.
  $prefetchedBodies=[];
  if($coreOnly&&!$route&&count($remainingSearchTimes)>=2){
   $prefetchParams=[];
   foreach(array_slice($remainingSearchTimes,0,2) as $probe){
    $prefetchParams[]=['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$tripCount,'TfNSWTR'=>'true'];
   }
   $qaSearchDiagnostics['additional_prefetch_count']=count($prefetchParams);
   $prefetchedBodies=timed_parallel_trip($prefetchParams,25);
  }
  foreach($remainingSearchTimes as $probeIndex=>$probe){
   if($qaBoundedSearch&&microtime(true)>=$qaSearchDeadline){$qaSearchBudgetExceeded=true;break;}
   $qaSearchDiagnostics['additional_probes']++;
   $body=array_key_exists($probeIndex,$prefetchedBodies)&&is_array($prefetchedBodies[$probeIndex])
    ?$prefetchedBodies[$probeIndex]
    :timed_upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$tripCount,'TfNSWTR'=>'true'],25);
   $route=better_route($route,timed_normalized_journey($body,$originSeed,$destinationSeed));
   $needsInterchangeCheck=!$route;
   if($needsInterchangeCheck)$qaSearchDiagnostics['interchange_checks']++;
   $candidateList=$needsInterchangeCheck?timed_transfer_candidates($route,$body,$originSeed,$destinationSeed,1):[];
   foreach($candidateList as $transfer){
    if($qaBoundedSearch&&microtime(true)>=$qaSearchDeadline){$qaSearchBudgetExceeded=true;break;}
    $firstBody=timed_upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$transfer['id'],'calcNumberOfTrips'=>$fallbackTripCount,'TfNSWTR'=>'true'],25);
    $firstRoute=timed_normalized_journey($firstBody,$originSeed,$transfer);if(!$firstRoute)continue;
    $arrival=route_arrival_ts($firstRoute);if($arrival===PHP_INT_MAX)continue;
    $onward=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
    
    $secondBody=timed_upstream('trip',['depArrMacro'=>'dep','itdDate'=>$onward->format('Ymd'),'itdTime'=>$onward->format('Hi'),'type_origin'=>'stop','name_origin'=>$transfer['id'],'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$fallbackTripCount,'TfNSWTR'=>'true'],25);
    $secondRoute=timed_normalized_journey($secondBody,$transfer,$destinationSeed);if(!$secondRoute)continue;
    $route=better_route($route,stitch_routes($firstRoute,$secondRoute,$originSeed,$destinationSeed));
   }
   if($route){$qaSearchDiagnostics['additional_route_probe']=$probeIndex+1;break;}
  }
  if($qaRailPilot)$route=$qaRouteWithinWindow($route);
  if(!$route){
   $qaSearchDiagnostics['time_budget_exceeded']=$qaSearchBudgetExceeded?1:0;
   if($qaBoundedSearch){journey_perf_qa_header();journey_search_qa_diagnostics_header($qaSearchDiagnostics);}
   $message=$qaSearchBudgetExceeded?'No train-only journey was verified within the QA search time limit. Other services may be available; check official TfNSW information or retry.':'No verified train-only journey could be found from '.$fromName.' to '.$toName.' in the next 24 hours. Train services may still be running; please retry or check TripView.';
   if($qaRailPilot&&!$qaSearchBudgetExceeded){$message='No train-only journey departing '.$fromName.' for '.$toName.' could be verified in the next 4 hours. This does not prove trains are not running. Check TfNSW service alerts and Trip Planner for alternative travel options.';}
   fail($qaSearchBudgetExceeded?'SEARCH_TIME_LIMIT':'NO_ROUTE',$message,$qaSearchBudgetExceeded?503:404);
  }
  if($coreOnly){
   journey_perf_phase('response');
   $route['serviceStatus']=['level'=>'unavailable','hasMaterialChange'=>false,'updatedAt'=>gmdate('c'),'alerts'=>[],'revalidationAttempted'=>false,'replacementFound'=>false];
   $route['crowding']=['available'=>false,'level'=>'unknown','updatedAt'=>gmdate('c'),'legs'=>[]];
   $route['nextDepartures']=[];
   journey_perf_qa_header();
   journey_search_qa_diagnostics_header($qaSearchDiagnostics);
  echo json_encode(['data'=>$route],JSON_INVALID_UTF8_SUBSTITUTE);exit;
  }
  journey_perf_phase('enrichment');
  $alertBody=fetch_current_service_alerts($now);
  if(is_array($alertBody)){
   $initialStatus=journey_service_status($route,$alertBody);
   $initialRouteId=(string)$route['id'];
   if($initialStatus['hasMaterialChange']===true){
    $replacement=null;
    foreach($searchTimes as $probe){
     $body=timed_upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$tripCount,'TfNSWTR'=>'true'],25);
     $replacement=better_route($replacement,best_unaffected_route(normalized_journeys($body,$originSeed,$destinationSeed),$alertBody));
     $needsInterchangeCheck=!$replacement;
     foreach($needsInterchangeCheck?timed_transfer_candidates($replacement,$body,$originSeed,$destinationSeed,1):[] as $transfer){
      $firstBody=timed_upstream('trip',['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$transfer['id'],'calcNumberOfTrips'=>$fallbackTripCount,'TfNSWTR'=>'true'],25);
      $firstRoute=best_unaffected_route(normalized_journeys($firstBody,$originSeed,$transfer),$alertBody);if(!$firstRoute)continue;
      $arrival=route_arrival_ts($firstRoute);if($arrival===PHP_INT_MAX)continue;
      $onward=(new DateTimeImmutable('@'.($arrival+120)))->setTimezone(new DateTimeZone('Australia/Sydney'));
      
      $secondBody=timed_upstream('trip',['depArrMacro'=>'dep','itdDate'=>$onward->format('Ymd'),'itdTime'=>$onward->format('Hi'),'type_origin'=>'stop','name_origin'=>$transfer['id'],'type_destination'=>'stop','name_destination'=>$to,'calcNumberOfTrips'=>$fallbackTripCount,'TfNSWTR'=>'true'],25);
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
  journey_perf_phase('response');
  journey_perf_qa_header();
  journey_search_qa_diagnostics_header($qaSearchDiagnostics);
  echo json_encode(['data'=>$route],JSON_INVALID_UTF8_SUBSTITUTE);exit;
 }
 fail('BAD_REQUEST','Unknown API action.',404);
} catch(Throwable $e){error_log('Sydney Station Alert: '.$e->getMessage());fail('UPSTREAM_UNAVAILABLE','Transport data could not be loaded. Try again shortly.',502);}
