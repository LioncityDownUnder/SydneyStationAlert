<?php
declare(strict_types=1);

/**
 * TfNSW transport configuration and primary JSON request/cache helpers.
 * Function signatures, credential lookup, endpoint restrictions, TTLs and
 * error responses are preserved from api/lib/core.php.
 */
function source_key():string{
 $key=(string)(getenv('TFNSW_API_KEY')?:'');
 if(!$key){$config=__DIR__.'/../config.local.php';if(is_file($config)){$private=require $config;$key=is_array($private)?(string)($private['TFNSW_API_KEY']??''):'';}}
 return $key;
}
/**
 * Shared ephemeral TfNSW response-cache directory.
 * Retains the existing stable path and per-endpoint cache TTLs.
 */
function upstream_cache_directory():string{
 $dir=sys_get_temp_dir().'/sydney_station_alert_'.substr(hash('sha256',__DIR__),0,8);
 if(!is_dir($dir))@mkdir($dir,0700,true);
 return $dir;
}
function upstream_parallel_trip(array $paramsList,int $ttl=25):array{
 $key=source_key();if(!$key)return array_fill(0,count($paramsList),null);
 $base=rtrim((string)(getenv('TFNSW_API_BASE')?:'https://api.transport.nsw.gov.au/v1/tp'),'/');if(!str_starts_with($base,'https://api.transport.nsw.gov.au/'))return array_fill(0,count($paramsList),null);
 $cacheDir=upstream_cache_directory();
 $results=array_fill(0,count($paramsList),null);$mh=curl_multi_init();$pending=[];
 foreach($paramsList as $i=>$params){
  $params=['outputFormat'=>'rapidJSON','coordOutputFormat'=>'EPSG:4326']+$params;$url=$base.'/trip?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);$cache=$cacheDir.'/'.hash('sha256',$url).'.json';
  if(is_file($cache)&&filemtime($cache)>time()-$ttl){$data=json_decode((string)file_get_contents($cache),true);if(is_array($data)){$results[$i]=$data;continue;}}
  $h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>7,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);curl_multi_add_handle($mh,$h);$pending[]=['index'=>$i,'handle'=>$h,'cache'=>$cache];
 }
 if($pending){do{$status=curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,0.5);}while($running&&$status===CURLM_OK);}
 foreach($pending as $item){$h=$item['handle'];$body=curl_multi_getcontent($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$json=is_string($body)?json_decode($body,true):null;if($status>=200&&$status<300&&is_array($json)){$results[$item['index']]=$json;@file_put_contents($item['cache'],json_encode($json),LOCK_EX);}curl_multi_remove_handle($mh,$h);curl_close($h);}curl_multi_close($mh);return $results;
}
function upstream(string $endpoint,array $params,int $ttl=30):array{
 $key=source_key();if(!$key)fail('CONFIG_MISSING','Live transport data needs a TfNSW API key. Add it on the server, then refresh.',503);
 $base=rtrim((string)(getenv('TFNSW_API_BASE')?:'https://api.transport.nsw.gov.au/v1/tp'),'/');
 if(!str_starts_with($base,'https://api.transport.nsw.gov.au/'))fail('CONFIG_MISSING','Invalid configured API endpoint.',503);
 $params=['outputFormat'=>'rapidJSON','coordOutputFormat'=>'EPSG:4326']+$params;
 $url=$base.'/'.$endpoint.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $cacheDir=upstream_cache_directory();

 $cache=$cacheDir.'/'.hash('sha256',$url).'.json';
 if(is_file($cache)&&filemtime($cache)>time()-$ttl){$data=json_decode((string)file_get_contents($cache),true);if(is_array($data))return $data;}
 $attempts=2;for($attempt=0;$attempt<$attempts;$attempt++){
  $h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>9,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
  $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
  $json=is_string($body)?json_decode($body,true):null;
  if($status===429)fail('RATE_LIMITED','Transport data is busy. Try again in a minute.',429);
  if($status>=200&&$status<300&&is_array($json)){@file_put_contents($cache,json_encode($json),LOCK_EX);return $json;}
  if($status>=400&&$status<500)break;
  if($attempt===0)usleep(170000);
 }
 fail('UPSTREAM_UNAVAILABLE','TfNSW is temporarily unavailable. Please try refresh shortly.',502);
}

/**
 * Optional TfNSW JSON and GTFS-Realtime vehicle HTTP requests.
 * These preserve their original safe null-on-failure semantics.
 */
function upstream_binary_optional(string $url,int $ttl=15):?string{
 $key=source_key();if($key==='')return null;
 if(!preg_match('#^https://api\.transport\.nsw\.gov\.au/v2/gtfs/vehiclepos/(?:sydneytrains|metro)$#',$url))return null;
 $cacheDir=upstream_cache_directory();

 $cache=$cacheDir.'/'.hash('sha256',$url).'.bin';
 if(is_file($cache)&&filemtime($cache)>time()-$ttl){$cached=file_get_contents($cache);if(is_string($cached)&&$cached!=='')return $cached;}
 $h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/x-protobuf'],CURLOPT_FOLLOWLOCATION=>false]);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 if($status>=200&&$status<300&&is_string($body)&&$body!==''){@file_put_contents($cache,$body,LOCK_EX);return $body;}
 error_log('Sydney Station Alert optional vehicle feed failed: '.$status);return null;
}

function upstream_optional(string $endpoint,array $params,int $ttl=60):?array{
 $key=source_key();if($key==='')return null;
 $base=rtrim((string)(getenv('TFNSW_API_BASE')?:'https://api.transport.nsw.gov.au/v1/tp'),'/');
 if(!str_starts_with($base,'https://api.transport.nsw.gov.au/'))return null;
 $params=['outputFormat'=>'rapidJSON','coordOutputFormat'=>'EPSG:4326']+$params;
 $url=$base.'/'.$endpoint.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $cacheDir=upstream_cache_directory();

 $cache=$cacheDir.'/'.hash('sha256',$url).'.json';
 if(is_file($cache)&&filemtime($cache)>time()-$ttl){
  $data=json_decode((string)file_get_contents($cache),true);if(is_array($data))return $data;
 }
 $h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 $json=is_string($body)?json_decode($body,true):null;
 if($status>=200&&$status<300&&is_array($json)){@file_put_contents($cache,json_encode($json),LOCK_EX);return $json;}
 error_log('Sydney Station Alert optional TfNSW endpoint failed: '.$endpoint.' status '.$status);
 return null;
}
