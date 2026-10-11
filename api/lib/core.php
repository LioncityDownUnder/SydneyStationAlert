<?php
declare(strict_types=1);

require_once __DIR__.'/journey-performance.php';

function fail(string $code,string $message,int $status=400):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>['code'=>$code,'message'=>$message]]);exit;}
function val(array $a,string $k,mixed $default=null):mixed{return $a[$k]??$default;}
require_once __DIR__.'/rail-normalization.php';
require_once __DIR__.'/boarded-timetable.php';

require_once __DIR__.'/journey-routing.php';

require_once __DIR__.'/upstream-transport.php';

require_once __DIR__.'/station-discovery.php';

require_once __DIR__.'/service-alerts.php';

require_once __DIR__.'/gtfs-crowding.php';
