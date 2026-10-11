<?php
declare(strict_types=1);

/**
 * SSA-02: choose only independently normalized rail-only itineraries leaving
 * the interchange no earlier than the commuter's validated earliest boarding
 * time. Never extrapolate a service time or convert replacement buses to trains.
 */
function safe_onward_options(array $bodies,array $origin,array $destination,int $notBefore,int $end,int $limit=3):array{
 $found=[];
 foreach($bodies as $body){
  if(!is_array($body))continue;
  foreach(normalized_journeys($body,$origin,$destination) as $route){
   $departure=route_departure_ts($route);
   if($departure<$notBefore||$departure>$end||!qa_rail_chronology_valid($route))continue;
   $first=$route['legs'][0]??[];
   $ids=array_values(array_filter(array_map('strval',$first['tripIds']??[])));
   $key=$ids?'id:'.implode('|',array_map('strtolower',$ids)):'departure:'.$departure.':'.(string)($first['line']??'');
   if(!isset($found[$key])||route_arrival_ts($route)<route_arrival_ts($found[$key]))$found[$key]=$route;
  }
 }
 $routes=array_values($found);
 usort($routes,static fn($a,$b)=>[route_departure_ts($a),route_arrival_ts($a)]<=>[route_departure_ts($b),route_arrival_ts($b)]);
 return array_slice($routes,0,max(1,min(5,$limit)));
}
