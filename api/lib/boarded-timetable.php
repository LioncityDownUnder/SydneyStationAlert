<?php
declare(strict_types=1);

/**
 * SSA-01: an already-boarded service may be refreshed ONLY from an exactly
 * identified TfNSW trip. Search order, line, clock proximity or station
 * names alone must never establish identity.
 */
function boarded_timetable_matches(array $selected,array $candidate):bool{
 $legs=val($selected,'legs',[]);$other=val($candidate,'legs',[]);
 if(!is_array($legs)||!is_array($other)||!$legs||count($legs)!==count($other))return false;
 $anchor=iso_ts(val($selected,'anchor'));
 $departure=iso_ts(val($other[0],'departure'));
 if($anchor===null||$departure===null||abs($departure-$anchor)>2*3600)return false;
 foreach($legs as $i=>$leg){
  if(!is_array($leg)||!is_array($other[$i]))return false;
  $match=$other[$i];
  if(val($leg,'mode')!==val($match,'mode'))return false;
  if((string)val($leg,'originId')!==(string)val(val($match,'origin',[]),'id'))return false;
  if((string)val($leg,'destinationId')!==(string)val(val($match,'destination',[]),'id'))return false;
  $ids=array_values(array_filter(array_map(fn($s)=>strtolower(trim((string)$s)),val($leg,'tripIds',[]))));
  $candidateIds=array_values(array_filter(array_map(fn($s)=>strtolower(trim((string)$s)),val($match,'tripIds',[]))));
  if(!$ids||!$candidateIds||!array_intersect($ids,$candidateIds))return false;
  // A changed stopping pattern is not silently patched into an onboard trip.
  $expected=val($leg,'stopIds',[]);$seen=array_map(fn($s)=>(string)val($s,'id',''),val($match,'stops',[]));
  if(!is_array($expected)||count($expected)<2||array_map('strval',$expected)!==$seen)return false;
 }
 return true;
}

function boarded_timetable_find(array $selected,array $responses,array $origin,array $destination):?array{
 foreach($responses as $response){
  if(!is_array($response))continue;
  foreach(normalized_journeys($response,$origin,$destination) as $candidate){
   if(boarded_timetable_matches($selected,$candidate))return $candidate;
  }
 }
 return null;
}
