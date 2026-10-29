/** Read-only live Chromium reproduction of the exact user-selected name. */
import {chromium} from 'playwright';
const browser=await chromium.launch({headless:true});
try{
 for(const environment of ['qa','production']){
  const url=environment==='qa'
   ?'https://trains.nytnetwork.work/qatest/'
   :'https://trains.nytnetwork.work/';
  const context=await browser.newContext({timezoneId:'Australia/Sydney'});
  const page=await context.newPage();
  const requests=[],errors=[];
  page.on('pageerror',e=>errors.push(String(e.message).slice(0,220)));
  page.on('request',request=>{
   if(!request.url().includes('action=journey'))return;
   const q=new URL(request.url()).searchParams;
   requests.push({from:q.get('from'),to:q.get('to'),toName:q.get('toName'),
    coreOnly:q.get('coreOnly')});
  });
  page.on('response',response=>{
   if(response.url().includes('action=journey'))requests.push({http:response.status()});
  });
  try{
   await page.goto(url,{waitUntil:'domcontentloaded',timeout:30000});
   await page.locator('#origin').fill('Hurstville');
   await page.getByRole('option',{name:/Hurstville/}).first().click({timeout:18000});
   await page.locator('#destination').fill('Sydney International Airport');
   await page.getByRole('option',{name:/Sydney International Airport/}).first().click({timeout:18000});
   const selections=await page.locator('.selected-station strong').allInnerTexts();
   await page.locator('#set').click();
   await page.waitForFunction(
    ()=>!!document.querySelector('.journeyhead,.no-service'),
    null,{timeout:40000}
   );
   const journey=await page.locator('.journeyhead h1').allInnerTexts();
   const lines=await page.locator('.journeyhead .subtitle').allInnerTexts();
   const failure=await page.locator('.no-service').allInnerTexts();
   console.log(JSON.stringify({type:'live-browser',environment,selections,
    found:journey.length>0,journey,lines,failure:failure.map(s=>s.slice(0,400)),
    requests,errors}));
  }catch(e){
   console.log(JSON.stringify({type:'live-browser',environment,error:String(e.message).slice(0,500),
    requests,errors,excerpt:(await page.locator('body').innerText().catch(()=>'' )).slice(0,320)}));
  }finally{await context.close();}
 }
}finally{await browser.close();}
