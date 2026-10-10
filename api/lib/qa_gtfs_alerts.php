<?php
declare(strict_types=1);
/** Bounded wire-format decoder for QA GTFS-RT ServiceAlert fields; no protobuf dependency. */
function qa_pb_varint(string $s,int &$i):?int{
 $v=0;$shift=0;$n=strlen($s);
 for($j=0;$j<10&&$i<$n;$j++){
  $b=ord($s[$i++]);if($shift<63)$v|=(($b&127)<<$shift);
  if(($b&128)===0)return $v;
  $shift+=7;
 }
 return null;
}
function qa_pb_fields(string $s,int $cap=20000):array{
 $out=[];$i=0;$n=strlen($s);$count=0;
 while($i<$n&&$count++<$cap){
  $tag=qa_pb_varint($s,$i);if($tag===null||$tag===0)break;
  $field=$tag>>3;$wire=$tag&7;
  if($wire===0){$value=qa_pb_varint($s,$i);if($value===null)break;}
  elseif($wire===2){$size=qa_pb_varint($s,$i);if($size===null||$size<0||$size>$n-$i)break;$value=substr($s,$i,$size);$i+=$size;}
  elseif($wire===1){if($i+8>$n)break;$i+=8;continue;}
  elseif($wire===5){if($i+4>$n)break;$i+=4;continue;}
  else break;
  $out[$field][]=[$wire,$value];
 }
 return $out;
}
function qa_pb_messages(array $fields,int $field,int $limit=1500):array{
 $out=[];foreach($fields[$field]??[] as [$wire,$value]){if($wire!==2)continue;$out[]=qa_pb_fields($value);if(count($out)>=$limit)break;}return $out;
}
function qa_pb_string(array $fields,int $field):string{
 foreach($fields[$field]??[] as [$wire,$value])if($wire===2)return substr($value,0,1000);return '';
}
function qa_pb_text(array $fields,int $field):string{
 foreach(qa_pb_messages($fields,$field,4) as $translated){
  foreach(qa_pb_messages($translated,1,10) as $translation){
   $text=qa_pb_string($translation,1);if($text!=='')return $text;
  }
 }
 return '';
}
function qa_gtfs_alert_evidence(string $raw,int $limit=2000):array{
 $root=qa_pb_fields($raw);$total=0;$matched=[];$examples=[];$causes=[];$effects=[];
 foreach(qa_pb_messages($root,2,$limit) as $entity){
  $alerts=qa_pb_messages($entity,5,2);if(!$alerts)continue;
  $total++;
  foreach($alerts as $alert){
   $header=qa_pb_text($alert,10);$description=qa_pb_text($alert,11);
   $selectors=[];
   foreach(qa_pb_messages($alert,5,40) as $selector){
    $selectors[]=['routeId'=>qa_pb_string($selector,2),'stopId'=>qa_pb_string($selector,5)];
   }
   $cause=$alert[6][0][1]??null;$effect=$alert[7][0][1]??null;
   if(is_int($cause))$causes[$cause]=($causes[$cause]??0)+1;
   if(is_int($effect))$effects[$effect]=($effects[$effect]??0)+1;
   $record=['id'=>qa_pb_string($entity,1),'header'=>substr($header,0,230),'description'=>substr($description,0,420),'selectors'=>array_slice($selectors,0,10),'cause'=>$cause,'effect'=>$effect];
   $haystack=strtolower($header.' '.$description.' '.json_encode($selectors));
   if(str_contains($haystack,'hurstville')||preg_match('/\\bt4\\b/i',$haystack)||str_contains($haystack,'replacement')){if(count($matched)<15)$matched[]=$record;}
   elseif(count($examples)<3)$examples[]=['header'=>$record['header'],'selectors'=>array_slice($selectors,0,3)];
  }
 }
 return ['decodedEntities'=>$total,'matchedCount'=>count($matched),'matchedSamples'=>$matched,'otherSamples'=>$examples,'causes'=>$causes,'effects'=>$effects,'note'=>'Text or raw selector match only; route IDs must be normalized and alerts checked for active dates before commuter use.'];
}
