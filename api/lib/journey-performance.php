<?php
declare(strict_types=1);

/**
 * Journey-only latency diagnostics. Never logs client IPs, API keys, station
 * names, request URLs or response payloads. One compact record per slow request.
 * The destination/origin pair is represented by a non-reversible daily hash.
 */
function journey_perf_begin(string $from,string $to,bool $coreOnly):void{
 $GLOBALS['journey_perf']=[
  'start'=>microtime(true),'phase'=>'setup','phaseStart'=>microtime(true),
  'durations'=>[],'counts'=>[],'coreOnly'=>$coreOnly,
  'route'=>substr(hash_hmac('sha256',$from.'|'.$to,gmdate('Y-m-d')),0,12)
 ];
 register_shutdown_function('journey_perf_finish');
}
function journey_perf_track(string $phase,callable $callback):mixed{
 if(!isset($GLOBALS['journey_perf']))return $callback();
 $start=microtime(true);
 try{return $callback();}
 finally{
  $p=&$GLOBALS['journey_perf'];
  $p['durations'][$phase]=($p['durations'][$phase]??0)+(microtime(true)-$start);
  $p['counts'][$phase]=($p['counts'][$phase]??0)+1;
 }
}
function journey_perf_phase(string $phase):void{
 if(!isset($GLOBALS['journey_perf']))return;
 $p=&$GLOBALS['journey_perf'];$now=microtime(true);
 $prev=$p['phase'];
 $p['durations']['phase_'.$prev]=($p['durations']['phase_'.$prev]??0)+($now-$p['phaseStart']);
 $p['phase']=$phase;$p['phaseStart']=$now;
}
function journey_search_qa_diagnostics_header(array $diagnostics):void{
 if(headers_sent()||!str_contains((string)($_SERVER['SCRIPT_NAME']??''),'/qatest/api/'))return;
 $fields=['initial_route_found','additional_probes','additional_route_probe','additional_prefetch_count','interchange_checks','time_budget_exceeded'];
 $parts=[];
 foreach($fields as $field){
  $value=$diagnostics[$field]??0;
  $parts[]=$field.'='.($value===true?'1':($value===false?'0':(int)$value));
 }
 header('X-QA-Journey-Search: '.implode('; ',$parts));
}
function journey_perf_qa_header():void{
 if(!isset($GLOBALS['journey_perf'])||headers_sent())return;
 if(!str_contains((string)($_SERVER['SCRIPT_NAME']??''),'/qatest/api/'))return;
 journey_perf_phase('response');
 $p=$GLOBALS['journey_perf'];
 $durations=$p['durations'];
 $durations['total']=microtime(true)-$p['start'];
 $parts=[];
 foreach(['total','phase_initial_parallel_search','phase_parallel_fallback','phase_additional_search','phase_enrichment','parallel_trip','upstream_trip','normalize','transfer_candidates'] as $name){
  if(isset($durations[$name]))$parts[]=$name.';dur='.round($durations[$name]*1000);
 }
 header('Server-Timing: '.implode(', ',$parts));
}
function journey_perf_finish():void{
 if(!isset($GLOBALS['journey_perf']))return;
 journey_perf_phase('complete');
 $p=$GLOBALS['journey_perf'];unset($GLOBALS['journey_perf']);
 $total=microtime(true)-$p['start'];
 if($total<5.0)return;
 $timings=[];foreach($p['durations'] as $k=>$v)$timings[$k]=(int)round($v*1000);
 $record=['time'=>gmdate('c'),'route_hash'=>$p['route'],'core_only'=>$p['coreOnly'],
  'total_ms'=>(int)round($total*1000),'status'=>http_response_code(),
  'durations_ms'=>$timings,'counts'=>$p['counts']];
 $dir=sys_get_temp_dir().'/sydney_station_alert_perf_'.substr(hash('sha256',__DIR__),0,8);
 if(!is_dir($dir))@mkdir($dir,0700,true);
 if(!is_dir($dir))return;
 // Keep diagnostic data outside the web root and retain it for at most 7 days.
 foreach(glob($dir.'/slow-*.jsonl')?:[] as $file){if(filemtime($file)<time()-7*86400)@unlink($file);}
 @file_put_contents($dir.'/slow-'.gmdate('Y-m-d').'.jsonl',json_encode($record)."\n",FILE_APPEND|LOCK_EX);
}
function timed_upstream(string $endpoint,array $params,int $ttl=30):array{
 return journey_perf_track('upstream_'.$endpoint,fn()=>upstream($endpoint,$params,$ttl));
}
function timed_parallel_trip(array $paramsList,int $ttl=25):array{
 return journey_perf_track('parallel_trip',fn()=>upstream_parallel_trip($paramsList,$ttl));
}
function timed_normalized_journey(array $body,array $origin,array $destination):?array{
 return journey_perf_track('normalize',fn()=>normalized_journey($body,$origin,$destination));
}
function timed_transfer_candidates(?array $route,array $body,array $origin,array $destination,int $limit=2):array{
 return journey_perf_track('transfer_candidates',fn()=>prioritized_transfer_candidates($route,$body,$origin,$destination,$limit));
}
