/**
 * Read-only browser reproduction of Hurstville → International Airport on
 * QA and production. No user accounts, stored trips or boarding.
 */
import {chromium} from 'playwright';
const browser=await chromium.launch({headless:true});
for(const environment of ['qa','production']){
 const url=environment==='qa'
  ?'https://trains.nytnetwork.work/qatest/'
  :'https://trains.nytnetwork.work/';
 const context=await browser.newContext({timezoneId:'Australia/Sydney'});
 const page=await context.newPage();
 const journeyRequests=[],errors=[];
 page.on('pageerror',e=>errors.push(String(e.message).slice(0,250)));
 page.on('request',r=>{
  if(!r.url().includes('/api/index.php?')||!r.url().includes('action=journey'))return;
  const q=new URL(r.url()).searchParams;
  journeyRequests.push({from:q.get('from'),to:q.get('to'),coreOnly:q.get('coreOnly')});
 });
 page.on('response',r=>{
  if(!r.url().includes('/api/index.php?')||!r.url().includes('action=journey'))return;
  journeyRequests.push({http:r.status()});
 });
 try{
  await page.goto(url,{waitUntil:'domcontentloaded',timeout:30000});
  await page.locator('#origin').fill('Hurstville');
  await page.getByRole('option',{name:/Hurstville/}).first().click({timeout:20000});
  await page.locator('#destination').fill('International Airport');
  await page.getByRole('option',{name:/International Airport/}).first().click({timeout:20000});
  const selected=await page.locator('.selected-station strong').allInnerTexts();
  await page.locator('#set').click();
  await page.waitForFunction(
   ()=>!!document.querySelector('.journeyhead,.no-service'),
   null,{timeout:40000}
  );
  const journey=await page.locator('.journeyhead h1').allInnerTexts();
  const noService=await page.locator('.no-service').allInnerTexts();
  const legs=await page.locator('.journeyhead .subtitle').allInnerTexts();
  console.log(JSON.stringify({type:'browser',environment,selected,
   result:journey.length?'JOURNEY_FOUND':'NO_ROUTE_UI',journey,legs,
   noService:noService.map(s=>s.slice(0,500)),journeyRequests,errors}));
 }catch(e){
  console.log(JSON.stringify({type:'browser',environment,result:'TEST_ERROR',
    error:String(e.message).slice(0,500),journeyRequests,errors,
    excerpt:(await page.locator('body').innerText().catch(()=>'' )).slice(0,450)}));
 }
 await context.close();
}
await browser.close();
