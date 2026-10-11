<?php
declare(strict_types=1);

/** GTFS-Realtime protobuf decoding and strictly trip-identified occupancy matching. */
function pb_varint(string $data,int &$offset):?int{
 $value=0;$shift=0;$length=strlen($data);
 while($offset<$length&&$shift<=63){
  $byte=ord($data[$offset++]);$value|=(($byte&0x7f)<<$shift);
  if(($byte&0x80)===0)return $value;
  $shift+=7;
 }
 return null;
}
function pb_fields(string $data):array{
 $fields=[];$offset=0;$length=strlen($data);
 while($offset<$length){
  $key=pb_varint($data,$offset);if($key===null)break;
  $number=$key>>3;$wire=$key&7;if($number<=0)break;
  if($wire===0){$value=pb_varint($data,$offset);if($value===null)break;$fields[]=['number'=>$number,'wire'=>0,'value'=>$value];continue;}
  if($wire===1){if($offset+8>$length)break;$fields[]=['number'=>$number,'wire'=>1,'value'=>substr($data,$offset,8)];$offset+=8;continue;}
  if($wire===2){$size=pb_varint($data,$offset);if($size===null||$size<0||$offset+$size>$length)break;$fields[]=['number'=>$number,'wire'=>2,'value'=>substr($data,$offset,$size)];$offset+=$size;continue;}
  if($wire===5){if($offset+4>$length)break;$fields[]=['number'=>$number,'wire'=>5,'value'=>substr($data,$offset,4)];$offset+=4;continue;}
  break;
 }
 return $fields;
}
function pb_values(array $fields,int $number,?int $wire=null):array{
 $out=[];foreach($fields as $field){if($field['number']!==$number)continue;if($wire!==null&&$field['wire']!==$wire)continue;$out[]=$field['value'];}return $out;
}
function pb_first_string(array $fields,int $number):?string{
 foreach($fields as $field){if($field['number']===$number&&$field['wire']===2)return (string)$field['value'];}
 return null;
}
function pb_first_int(array $fields,int $number):?int{
 foreach($fields as $field){if($field['number']===$number&&$field['wire']===0)return (int)$field['value'];}
 return null;
}
function crowding_level_from_occupancy(?int $status):string{
 return match($status){0,1=>'quiet',2=>'moderate',3=>'busy',4,5,6=>'very_busy',default=>'unknown'};
}
function crowding_rank(string $level):int{
 return match($level){'quiet'=>0,'moderate'=>1,'busy'=>2,'very_busy'=>3,default=>-1};
}
function carriage_to_crowding(string $message):array{
 $fields=pb_fields($message);$status=pb_first_int($fields,3);$toilet=pb_first_int($fields,5);
 return [
  'name'=>pb_first_string($fields,1),
  'position'=>pb_first_int($fields,2),
  'level'=>crowding_level_from_occupancy($status),
  'quietCarriage'=>pb_first_int($fields,4)===1,
  'toilet'=>match($toilet){0=>'none',1=>'normal',2=>'accessible',default=>'unknown'},
  'luggageRack'=>pb_first_int($fields,6)===1
 ];
}
function parse_vehicle_positions_feed(string $data):array{
 $feed=pb_fields($data);$vehicles=[];
 foreach(pb_values($feed,2,2) as $entityMessage){
  $entity=pb_fields((string)$entityMessage);$vehicleMessage=pb_first_string($entity,4);if($vehicleMessage===null)continue;
  $vehicle=pb_fields($vehicleMessage);$tripMessage=pb_first_string($vehicle,1);if($tripMessage===null)continue;
  $trip=pb_fields($tripMessage);$tripId=strtolower(trim((string)(pb_first_string($trip,1)??'')));if($tripId==='')continue;
  $descriptorMessage=pb_first_string($vehicle,8);$vehicleId=null;
  if($descriptorMessage!==null){$descriptor=pb_fields($descriptorMessage);$vehicleId=pb_first_string($descriptor,1)??pb_first_string($descriptor,2);}
  $timestamp=pb_first_int($vehicle,5)??0;$wholeLevel=crowding_level_from_occupancy(pb_first_int($vehicle,9));
  $carriages=[];$maxRank=-1;
  foreach(pb_values($vehicle,1007,2) as $carriageMessage){
   $carriage=carriage_to_crowding((string)$carriageMessage);$carriages[]=$carriage;$maxRank=max($maxRank,crowding_rank($carriage['level']));
  }
  usort($carriages,fn($a,$b)=>(($a['position']??PHP_INT_MAX)<=>($b['position']??PHP_INT_MAX)));
  $level=$wholeLevel;
  if($level==='unknown'&&$maxRank>=0)$level=['quiet','moderate','busy','very_busy'][$maxRank];
  $record=['tripId'=>$tripId,'vehicleId'=>$vehicleId,'level'=>$level,'timestamp'=>$timestamp,'stopId'=>pb_first_string($vehicle,7),'carriages'=>$carriages];
  if(!isset($vehicles[$tripId])||$timestamp>($vehicles[$tripId]['timestamp']??0))$vehicles[$tripId]=$record;
 }
 return $vehicles;
}
function journey_crowding_from_feeds(array $route,array $feeds):array{
 $legs=[];$available=false;$overall='unknown';$overallRank=-1;$latest=0;
 foreach(val($route,'legs',[]) as $leg){
  if(!is_array($leg))continue;$mode=(string)val($leg,'mode','');$match=null;$matchedTrip=null;
  $tripIds=val($leg,'tripIds',[]);if(!is_array($tripIds))$tripIds=[];
  foreach($tripIds as $tripId){$key=strtolower(trim((string)$tripId));if($key!==''&&isset($feeds[$mode][$key])){$match=$feeds[$mode][$key];$matchedTrip=$key;break;}}
  $level=$match['level']??'unknown';$rank=crowding_rank($level);
  if($match){$latest=max($latest,(int)($match['timestamp']??0));if($rank>=0)$available=true;if($rank>$overallRank){$overallRank=$rank;$overall=$level;}}
  $legs[]=[
   'legId'=>(string)val($leg,'id',''),
   'mode'=>$mode,
   'vehicleMatched'=>$match!==null,
   'tripId'=>$matchedTrip,
   'vehicleId'=>$match['vehicleId']??null,
   'level'=>$level,
   'updatedAt'=>isset($match['timestamp'])&&$match['timestamp']>0?gmdate('c',(int)$match['timestamp']):null,
   'carriages'=>$match['carriages']??[]
  ];
 }
 return ['available'=>$available,'level'=>$overall,'updatedAt'=>$latest>0?gmdate('c',$latest):gmdate('c'),'legs'=>$legs];
}
function journey_crowding_status(array $route):array{
 $modes=[];foreach(val($route,'legs',[]) as $leg){if(is_array($leg))$modes[(string)val($leg,'mode','')]=true;}
 $feeds=[];
 if(isset($modes['train'])){$body=upstream_binary_optional('https://api.transport.nsw.gov.au/v2/gtfs/vehiclepos/sydneytrains',15);if(is_string($body))$feeds['train']=parse_vehicle_positions_feed($body);}
 if(isset($modes['metro'])){$body=upstream_binary_optional('https://api.transport.nsw.gov.au/v2/gtfs/vehiclepos/metro',15);if(is_string($body))$feeds['metro']=parse_vehicle_positions_feed($body);}
 return journey_crowding_from_feeds($route,$feeds);
}
