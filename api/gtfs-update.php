<?php
declare(strict_types=1);
// QA-only CLI updater. Never accessible through HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/lib/core.php';
$key=source_key();
if($key===''){fwrite(STDERR,"GTFS: missing server API key\n");exit(2);}
$dir=(string)(getenv('HOME')?:'');
if($dir===''||!is_dir($dir)){fwrite(STDERR,"GTFS: HOME unavailable\n");exit(2);}
$dir.='/.sydney-station-alert-qa/gtfs';
if(!is_dir($dir)&&!mkdir($dir,0700,true)){fwrite(STDERR,"GTFS: cannot create private directory\n");exit(2);}
$path=$dir.'/complete.zip';$url='https://api.transport.nsw.gov.au/v1/publictransport/timetables/complete/gtfs';
$head=curl_init($url);
curl_setopt_array($head,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>40,CURLOPT_FOLLOWLOCATION=>false]);
curl_exec($head);$headStatus=(int)curl_getinfo($head,CURLINFO_RESPONSE_CODE);curl_close($head);
if($headStatus===401||$headStatus===403){fwrite(STDERR,"GTFS: existing key not authorized (HTTP $headStatus)\n");exit(3);}
if(is_file($path)&&filesize($path)>1000&&$headStatus===200){
 // HEAD is advisory only; periodically refresh even when unchanged.
 if(filemtime($path)>time()-86400){file_put_contents(__DIR__.'/gtfs-path.local.php','<?php return '.var_export($path,true).';');echo "GTFS: cached private archive available\n";exit;}
}
$tmp=tempnam($dir,'gtfs-');if($tmp===false)exit(2);
$fp=fopen($tmp,'wb');if(!$fp)exit(2);
$ch=curl_init($url);
curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_HTTPHEADER=>['Authorization: apikey '.$key],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXFILESIZE=>120000000]);
$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);fclose($fp);
if(!$ok||$status!==200){unlink($tmp);fwrite(STDERR,"GTFS: download failed HTTP $status ($err)\n");exit(3);}
if(filesize($tmp)<1000||filesize($tmp)>120000000||!class_exists('ZipArchive')){unlink($tmp);fwrite(STDERR,"GTFS: invalid size or ZIP extension unavailable\n");exit(4);}
$zip=new ZipArchive();if($zip->open($tmp)!==true){unlink($tmp);fwrite(STDERR,"GTFS: invalid ZIP\n");exit(4);}
foreach(['trips.txt','stops.txt','stop_times.txt','calendar.txt','calendar_dates.txt'] as $file){
 if(in_array($file,['calendar.txt','calendar_dates.txt'],true))continue;
 if($zip->locateName($file)===false){$zip->close();unlink($tmp);fwrite(STDERR,"GTFS: missing $file\n");exit(4);}
}
$zip->close();chmod($tmp,0600);
if(!rename($tmp,$path)){unlink($tmp);exit(4);}
file_put_contents(__DIR__.'/gtfs-path.local.php','<?php return '.var_export($path,true).';');
chmod(__DIR__.'/gtfs-path.local.php',0600);
echo "GTFS: validated archive installed in private QA directory\n";
