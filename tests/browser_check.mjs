// Optional browser check. Install once: npm i puppeteer-core @sparticuz/chromium ; then: node tests/browser_check.mjs
import chromium from '@sparticuz/chromium';
import puppeteer from 'puppeteer-core';
const b = await puppeteer.launch({args: chromium.args, executablePath: await chromium.executablePath(), headless: 'shell'});
const p = await b.newPage(); await p.setViewport({width:1440,height:900});
const errs=[]; p.on('pageerror',e=>errs.push(String(e)));
await p.goto(''+(process.env.TK_URL||'http://127.0.0.1:8080')+'/',{waitUntil:'load'}); await new Promise(r=>setTimeout(r,600));
const info = ()=>p.evaluate(()=>({sy:Math.round(scrollY), ps:[...document.querySelectorAll('.tk-st')].map(s=>s.style.getPropertyValue('--p')||'0'), tops:[...document.querySelectorAll('.tk-st')].map(s=>Math.round(s.getBoundingClientRect().top)), counts:[...document.querySelectorAll('[data-count]')].map(e=>e.textContent), track:[...document.querySelectorAll('.tk-marquee-track')].map(t=>t.style.transform)}));
console.log('top', JSON.stringify(await info()));
for (const y of [450, 900, 1300, 1700]) { await p.evaluate(y=>scrollTo(0,y),y); await new Promise(r=>setTimeout(r,400)); console.log(y, JSON.stringify(await info())); if(y===450) await p.screenshot({path:'/tmp/fx-450.png'}); if(y===1300) await p.screenshot({path:'/tmp/fx-1300.png'});}
// count up: scroll to stats and sample
const maxY = await p.evaluate(()=>document.documentElement.scrollHeight);
await p.evaluate(()=>document.querySelector('.tk-orange').scrollIntoView()); 
const samples=[]; for(let i=0;i<8;i++){ samples.push((await info()).counts.join('|')); await new Promise(r=>setTimeout(r,250)); }
console.log('count samples', samples);
await p.screenshot({path:'/tmp/fx-stats.png'});
// reverse
await p.evaluate(()=>scrollTo(0,450)); await new Promise(r=>setTimeout(r,400)); console.log('reverse450', JSON.stringify((await info()).ps));
await p.evaluate(y=>scrollTo(0,y),maxY); await new Promise(r=>setTimeout(r,500)); await p.screenshot({path:'/tmp/fx-bottom.png'});
console.log('footer visible:', await p.evaluate(()=>document.querySelector('.tk-footer').getBoundingClientRect().bottom <= innerHeight+2), 'errors', errs);
await b.close();
