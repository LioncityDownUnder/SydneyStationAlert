/**
 * Read-only exploration of TfNSW GTFS stops public ArcGIS service.
 * No credentials or calls to Sydney Station Alert are involved.
 */
const layer='https://portal.data.nsw.gov.au/arcgis/rest/services/Hosted/TfNSW_GTFS_Stops_Public/FeatureServer/0';
async function get(url,params={}){
 const q=new URL(url);
 Object.entries({f:'json',...params}).forEach(([k,v])=>q.searchParams.set(k,String(v)));
 const res=await fetch(q,{signal:AbortSignal.timeout(25000),headers:{Accept:'application/json'}});
 const content=await res.json();if(!res.ok||content.error)throw Error('HTTP '+res.status+' '+JSON.stringify(content.error||'').slice(0,300));
 return content;
}
const metadata=await get(layer);
console.log(JSON.stringify({type:'layer',name:metadata.name,currentVersion:metadata.currentVersion,
 maxRecordCount:metadata.maxRecordCount,fields:metadata.fields?.map(f=>f.name),editingInfo:metadata.editingInfo}));
for(const where of ["location_type = '1'","parent_station IS NOT NULL","location_type IS NULL","1=1"]){
 try{
 const res=await get(layer+'/query',{where,returnCountOnly:'true',returnGeometry:'false'});
 console.log(JSON.stringify({type:'count',where,result:res}));
 const sample=await get(layer+'/query',{where,outFields:'stop_id,stop_name,location_type,parent_station,portal_last_updated,objectid',returnGeometry:'false',resultRecordCount:5});
 console.log(JSON.stringify({type:'sample',where,records:sample.features?.map(f=>f.attributes)||[],exceededTransferLimit:sample.exceededTransferLimit}));
 }catch(e){console.log(JSON.stringify({type:'error',where,message:String(e.message)}));}
}

for(const where of [
 "stop_id LIKE 'PST%'",
 "parent_station LIKE 'PST%'",
 "stop_id LIKE 'S%' AND location_type = '1'",
 "stop_id LIKE 'G%' AND location_type = '1'",
 "stop_name LIKE '%Station%' AND location_type = '1'",
 "stop_id LIKE '2%' AND location_type = '1'",
 "stop_id LIKE 'PST%' AND location_type = '1'"
]){
 try{
  const count=await get(layer+'/query',{where,returnCountOnly:'true',returnGeometry:'false'});
  const sample=await get(layer+'/query',{where,outFields:'stop_id,stop_name,location_type,parent_station,objectid',returnGeometry:'false',resultRecordCount:6});
  console.log(JSON.stringify({type:'filter',where,count:count.count,records:sample.features?.map(f=>f.attributes)}));
 }catch(e){console.log(JSON.stringify({type:'error',where,message:String(e.message)}));}
}
