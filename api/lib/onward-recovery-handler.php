<?php
declare(strict_types=1);

/** SSA-02: bounded provider lookup, anchored to expected interchange arrival. */
function onward_recovery_endpoint():never{
 $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
 if(!preg_match('/^[\\w:-]{3,50}$/',$from)||!preg_match('/^[\\w:-]{3,50}$/',$to)||$from===$to)fail('BAD_REQUEST','Invalid interchange stations.');
 $fromName=trim((string)($_GET['fromName']??$from));$toName=trim((string)($_GET['toName']??$to));
 if($fromName===''||mb_strlen($fromName)>100||preg_match('/[\\x00-\\x1F\\x7F]/u',$fromName))$fromName=$from;
 if($toName===''||mb_strlen($toName)>100||preg_match('/[\\x00-\\x1F\\x7F]/u',$toName))$toName=$to;
 $at=iso_ts((string)($_GET['notBefore']??''));
 if($at===null||$at<time()-60||$at>time()+4*3600)fail('BAD_REQUEST','Interchange departure time is outside the supported search window.');
 require_once __DIR__.'/qa_route_rejections.php';
 $origin=['id'=>$from,'name'=>$fromName,'mode'=>'train','lat'=>0.0,'lon'=>0.0];
 $destination=['id'=>$to,'name'=>$toName,'mode'=>'train','lat'=>0.0,'lon'=>0.0];
 $base=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Australia/Sydney'));
 $params=[];
 foreach([-10,10,30,60] as $offset){
  $probe=$base->modify(($offset<0?'':'+').$offset.' minutes');
  $params[]=qa_rail_filter_params(['depArrMacro'=>'dep','itdDate'=>$probe->format('Ymd'),'itdTime'=>$probe->format('Hi'),
   'type_origin'=>'stop','name_origin'=>$from,'type_destination'=>'stop','name_destination'=>$to,
   'calcNumberOfTrips'=>30,'TfNSWTR'=>'true']);
 }
 $options=safe_onward_options(timed_parallel_trip($params,25),$origin,$destination,$at,$at+4*3600,3);
 echo json_encode(['data'=>['options'=>$options,'checkedAt'=>gmdate('c')]],JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
