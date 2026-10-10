import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const base = process.env.QA_BROWSER_URL || 'http://127.0.0.1:8765/qatest/';
const browser = await chromium.launch({headless:true});
const stations = [
 {id:'222010',name:'Hurstville',mode:'train',lat:-33.967,lon:151.103},
 {id:'216610',name:'Casula',mode:'train',lat:-33.95,lon:150.91}
];
async function scenario(mode) {
 const page=await browser.newPage();
 let searches=0, notices=0;
 await page.route('**/api/index.php?*',async route=>{
  const query=new URL(route.request().url()).searchParams;
  const action=query.get('action');
  if(action==='journey'){
   searches++;
   return route.fulfill({status:404,contentType:'application/json',body:JSON.stringify({error:{code:'NO_ROUTE',message:'No verified train-only journey found'}})});
  }
  const data=action==='station-index'?stations:action==='stations'?stations.filter(s=>s.name.toLowerCase().includes((query.get('q')||'').toLowerCase())):[];
  return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data})});
 });
 await page.route('**/api/diagnostics.php?*',async route=>{
  notices++;
  if(mode==='unavailable')return route.fulfill({status:503,body:'Unavailable'});
  return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data:{
   sydneytrains:{assessment:{matchingAlerts:[
    {title:'Active T4 trackwork',description:'Change at Central for buses.',periodStatus:'WITHIN_ACTIVE_PERIOD'},
    {title:'Future T4 work',description:'Not yet active.',periodStatus:'OUTSIDE_ACTIVE_PERIOD'}
   ]}}
  }})});
 });
 await page.goto(base,{waitUntil:'domcontentloaded'});
 await page.locator('#origin').fill('Hurstville');
 await page.getByRole('option',{name:/Hurstville/}).click();
 await page.locator('#destination').fill('Casula');
 await page.getByRole('option',{name:/Casula/}).click();
 await page.locator('#set').click();
 await page.getByText('JOURNEY NOT FOUND').waitFor({timeout:12000});
 if(mode==='active'){
  await page.getByText('Active T4 trackwork').waitFor();
  assert.equal(await page.getByText('Future T4 work').count(),0,'Inactive notice must not show');
 } else {
  await page.getByText('Disruption information is unavailable.').waitFor();
 }
 assert.equal(searches,1);
 await page.locator('#retry-no-route').click();
 await page.getByText('JOURNEY NOT FOUND').waitFor();
 await page.waitForFunction(()=>document.querySelector('#retry-no-route')!==null);
 assert.equal(searches,2,'Retry must issue new journey request');
 assert.ok(notices>=2,'Retry must refresh disruption information');
 console.log('PASS browser: '+mode+' notice, NO_ROUTE, retry');
 await page.close();
}
try {
 await scenario('active');
 await scenario('unavailable');
} finally {await browser.close();}
