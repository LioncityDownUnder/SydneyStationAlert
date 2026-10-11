<?php
declare(strict_types=1);

/** TfNSW rail station search, lookup and station-index discovery. */
function normalize_station_locations(array $locations):array{
 $results=[];foreach($locations as $loc){
  if(!is_array($loc))continue;
  $type=strtolower((string)val($loc,'type','stop'));if($type!=='stop')continue;
  $railMode=location_rail_mode($loc);if(!$railMode)continue;
  $s=station($loc,$railMode);if($s)$results[$s['id']]=$s;
 }
 return array_values($results);
}
function station_index():array{
 $results=[];
 foreach(['Station','Metro','Railway'] as $term){
  $body=upstream('stop_finder',['type_sf'=>'any','name_sf'=>$term,'anyMaxSizeHitList'=>1000,'TfNSWSF'=>'true'],43200);
  $locations=val($body,'locations',[]);if(!is_array($locations))continue;
  foreach(normalize_station_locations($locations) as $s)$results[$s['id']]=$s;
 }
 uasort($results,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));
 return array_values($results);
}
function station_search(string $q):array{
 $requests=[
  ['odvSugMacro'=>1,'name_sf'=>$q,'anyMaxSizeHitList'=>20,'TfNSWSF'=>'true'],
  ['type_sf'=>'any','name_sf'=>$q,'anyMaxSizeHitList'=>20,'TfNSWSF'=>'true'],
 ];
 $locations=[];
 foreach($requests as $params){
  $body=upstream('stop_finder',$params,120);
  $candidate=val($body,'locations',[]);
  if(is_array($candidate)&&count($candidate)>0){$locations=$candidate;break;}
 }
 return normalize_station_locations($locations);
}
function lookup_station(string $id):?array{
 $body=upstream('stop_finder',['type_sf'=>'stop','name_sf'=>$id,'anyMaxSizeHitList'=>10,'TfNSWSF'=>'true'],600);
 foreach(val($body,'locations',[]) as $loc){$railMode=location_rail_mode($loc);if(!$railMode)continue;$s=station($loc,$railMode);if($s&&$s['id']===$id)return $s;}
 return null;
}
