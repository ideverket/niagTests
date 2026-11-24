// Render and exercise payment navigation without network traffic or a browser.
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const elements=new Map();
function element(selector){if(!elements.has(selector))elements.set(selector,{innerHTML:'',dataset:{page:'exam'},focus(){},addEventListener(type,fn){this[type]=fn;},classList:{toggle(){}},isConnected:true});return elements.get(selector);}
const context=vm.createContext({console,URLSearchParams,document:{querySelector:element,documentElement:{},body:element('body')},localStorage:{getItem(){return null;},setItem(){}},location:{search:'?test=exam',pathname:'/system/index.php',origin:'https://example.test'},window:{scrollTo(){},addEventListener(){}},fetch:()=>new Promise(()=>{}),clearInterval(){},setInterval(){},setTimeout(){},clearTimeout(){}});
vm.runInContext(fs.readFileSync(require('path').join(__dirname,'../assets/app.js'),'utf8'),context);
vm.runInContext(`boot={tests:[{id:'exam',title:{sv:'Test',en:'Test'},description:{sv:'Text',en:'Text'},available:true,paymentReady:true,paymentMode:'stripe_sandbox',questionCount:1,priceEur:12,paidDays:1,paidMaxAttempts:2,paidSeconds:90}]};landing();`,context);
assert(!element('#app').innerHTML.includes('id="pay" class="secondary" disabled'));
assert(element('#app').innerHTML.includes('Stripe-testläge'));
element('#pay').click();assert(element('#app').innerHTML.includes('id="stripe-form"'));
vm.runInContext(`boot.tests[0].paymentReady=false;landing();`,context);
assert(element('#app').innerHTML.includes('id="pay" class="secondary" disabled'));
vm.runInContext(`boot.tests[0].paymentReady=true;boot.tests[0].paymentMode='stripe_live';landing();`,context);
assert(element('#app').innerHTML.includes('Säker kortbetalning'));
vm.runInContext(`boot.tests[0].paymentMode='fake';landing();`,context);
element('#pay').click();assert(element('#app').innerHTML.includes('Simulerad betalning'));
vm.runInContext(`db={admin:'admin@example.test',banks:[],tests:[],settings:{stripeSandbox:{},stripeLive:{},paymentMode:'fake'}};settings('settings');`,context);
assert(element('#app').innerHTML.includes('name="stripeBaseUrl"'));
assert(element('#app').innerHTML.includes('https://example.test/system/payment.php?action=webhook'));
assert(!element('#app').innerHTML.includes('integration återstår'));
console.log('PASS UI: sandbox/live/fake navigation, incomplete configuration, base URL and webhook instructions');
