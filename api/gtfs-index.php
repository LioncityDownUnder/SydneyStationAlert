<?php
declare(strict_types=1);
/** QA-only private SQLite stop_times index. CLI only, no network or web access. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$path=(string)(getenv('QA_GTFS_STATIC_ZIP')?:'');
if($path===''){$cfg=__DIR__.'/gtfs-path.local.php';if(is_file($cfg))$path=(string)require $cfg;}
if(!is_file($path)||!class_exists('SQLite3')||!class_exists('ZipArchive')){fwrite(STDERR,"GTFS index: ZIP or SQLite3 unavailable\n");exit(2);}
$target=dirname($path).'/stop-times.sqlite';
if(is_file($target)&&filemtime($target)>=filemtime($path)){echo "GTFS index: current\n";exit;}
$zip=new ZipArchive();if($zip->open($path)!==true)exit(2);
$stream=$zip->getStream('stop_times.txt');if(!$stream){$zip->close();exit(2);}
$tmp=$target.'.building';@unlink($tmp);
$db=new SQLite3($tmp);$db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; CREATE TABLE times(trip TEXT,stop TEXT,arrival TEXT,departure TEXT,seq INTEGER);');
$head=fgetcsv($stream);$head=array_map(fn($x)=>trim((string)$x,"\xEF\xBB\xBF \t"),$head?:[]);
foreach(['trip_id','stop_id','arrival_time','departure_time','stop_sequence'] as $col)if(!in_array($col,$head,true)){fclose($stream);$zip->close();$db->close();@unlink($tmp);exit(2);}
$insert=$db->prepare('INSERT INTO times VALUES(:trip,:stop,:arrival,:departure,:seq)');
$db->exec('BEGIN');$count=0;
while(($row=fgetcsv($stream))!==false){
 if(count($row)!==count($head))continue;$v=array_combine($head,$row);
 $insert->bindValue(':trip',$v['trip_id'],SQLITE3_TEXT);$insert->bindValue(':stop',$v['stop_id'],SQLITE3_TEXT);
 $insert->bindValue(':arrival',$v['arrival_time'],SQLITE3_TEXT);$insert->bindValue(':departure',$v['departure_time'],SQLITE3_TEXT);
 $insert->bindValue(':seq',(int)$v['stop_sequence'],SQLITE3_INTEGER);$insert->execute();
 if(++$count%50000===0){$db->exec('COMMIT; BEGIN');}
}
$db->exec('COMMIT; CREATE INDEX idx_trip ON times(trip); CREATE INDEX idx_stop ON times(stop);');
fclose($stream);$zip->close();$db->close();chmod($tmp,0600);
if(!rename($tmp,$target)){@unlink($tmp);exit(2);}
echo "GTFS index: ready ($count rows)\n";
