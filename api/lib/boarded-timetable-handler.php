<?php
declare(strict_types=1);

/**
 * A bounded timetable check for a commuter-confirmed TfNSW service.
 * Returns explicit "unavailable" when no exact same-trip rail journey exists.
 */
function boarded_timetable_endpoint():never{
 $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
 if(!preg_match('/^[\\w:-]{3,50}$/',$from)||!preg_match('/^[\\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Invalid selected service stations.');
 $fromName=trim((string)($_GET['fromName']??$from));$toName=trim((string)($_GET['toName']??$to));
 if($fromName===''||mb_strlen($fromName)>100||preg_match('/[\\x00-\\x1F\\x7F]/u',$fromName))$fromName=$from;
 if($toName===''||mb_strlen($toName)>100||preg_match('/[\\x00-\\x1F\\x7F]/u',$toName))$toName=$to;
 $encoded=(string)($_GET['selected']??'');
 if($encoded===''||strlen($encoded)>4500)fail('BAD_REQUEST','Invalid selected service.');
 $selected=json_decode($encoded,true);
 $legs=is_array($selected)?val($selected,'legs',[]):[];
 $anchor=is_array($selected)?iso_ts(val($selected,'anchor')):null;
 if(!is_array($legs)||!array_is_list($legs)||count($legs)<1||count($legs)>6
   ||$anchor===null||$anchor<time()-12*3600||$anchor>time()+4*3600)fail('BAD_REQUEST','Selected service outside the live lookup window.');
 foreach($legs as $leg){
  if(!is_array($leg)||!in_array(val($leg,'mode'),['train','metro'],true))fail('BAD_REQUEST','Invalid selected rail service.');
  foreach(['originId','destinationId'] as $key)if(!preg_match('/^[\\w:-]{3,50}$/',(string)val($leg,$key,'')))fail('BAD_REQUEST','Invalid selected station.');
  $ids=val($leg,'tripIds',[]);$stops=val($leg,'stopIds',[]);
  if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>8
   ||!is_array($stops)||!array_is_list($stops)||count($stops)<2||count($stops)>100)fail('BAD_REQUEST','Selected service needs verified trip and stop identifiers.');
  foreach($ids as $id)if((!is_string($id)&&!is_int($id))||strlen((string)$id)>150
    ||trim((string)$id)===''||preg_match('/[\\x00-\\x1F\\x7F]/',(string)$id))fail('BAD_REQUEST','Invalid trip identifier.');
  foreach($stops as $id)if((!is_string($id)&&!is_int($id))||!preg_match('/^[\\w:-]{3,50}$/',(string)$id))fail('BAD_REQUEST','Invalid selected stop.');
 }
 if((string)val($legs[0],'originId')!==$from||(string)val($legs[count($legs)-1],'destinationId')!==$to)fail('BAD_REQUEST','Selected service endpoints do not match.');
 $origin=['id'=>$from,'name'=>$fromName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
 $destination=['id'=>$to,'name'=>$toName,'lat'=>0.0,'lon'=>0.0,'mode'=>'train'];
 $base=(new DateTimeImmutable('@'.$anchor))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $params=[];
 foreach([-35,-10,15] as $offset){
  $probe=$base->modify(($offset<0?'':'+' ).$offset.' minutes');
  $params[]=['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),
    'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,
    'calcNumberOfTrips'=>30,'TfNSWTR'=>'true'];
 }
 $candidate=boarded_timetable_find($selected,timed_parallel_trip($params,25),$origin,$destination);
 echo json_encode(['data'=>['status'=>$candidate?'matched':'unavailable','journey'=>$candidate,
   'checkedAt'=>gmdate('c')]],JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
