const $=s=>document.querySelector(s), v=id=>document.getElementById(id).value, day=864e5, now=Date.now();
const fmt=n=>'UGX '+Math.round(n).toLocaleString('en-UG');
const iso=t=>new Date(t).toISOString().slice(0,10);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const S={members:[],loans:[],tx:[],audit:[]};
const roles={'Accountant':['Dashboard','Savings','Loans','Members'],'Teller':['Savings','Members'],'Loans officer':['Loans','Members'],'Manager':['Dashboard','Loans','Members','Audit'],'Member':['My account'],'Guest':['Dashboard','Savings','Loans','Members','Audit']};
const PW='sacco123';
let user=null,role='Guest',view='Dashboard',msg='',showLogin=false,loginFor='';
const DEMO={'Teller':'teller1','Loans officer':'officer1','Manager':'manager1','Member':'nakato'};
const mem=id=>S.members.find(m=>m.id===id), mname=id=>esc(mem(id).name);
const total=l=>l.amt*(1+l.rate*l.months/12), inst=l=>total(l)/l.months, bal=l=>total(l)-l.paid;
const late=l=>l.st==='approved'&&bal(l)>0&&Date.parse(l.due)<now?Math.floor((now-Date.parse(l.due))/day):0;
// Talks to api/api.php, which reads and writes the MySQL database.
async function api(action,data={}){
 try{const r=await fetch('api/api.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({action,...data})});
  const t=await r.text();let j;try{j=JSON.parse(t)}catch(e){j={ok:false,error:'The server did not reply correctly (HTTP '+r.status+'). Reply was: '+t.replace(/<[^>]*>/g,' ').trim().slice(0,300)+' | Open the app via http://localhost/sacco-mis/ not by double-clicking index.html.'}}
  return j}catch(e){return{ok:false,error:'Cannot reach the server. Start Apache and MySQL in XAMPP and open http://localhost/sacco-mis/'}}}
function apply(j){const f=$('#dbstatus');if(f)f.innerHTML='Data source: <b>MySQL database (live)</b> - '+j.members.length+' members in the database';
 S.members=j.members;S.loans=j.loans;S.tx=j.tx;S.audit=j.audit;
 if(j.user){user=j.user;role=j.user.role}else{user=null;role='Guest'}}
async function act(action,data){const j=await api(action,data);
 if(!j.ok)return note(esc(j.error||'Something went wrong.'),1);
 apply(j);note(esc(j.message||'Done.'))}
const note=(t,bad)=>{msg=`<p class="msg ${bad?'bad':'ok'}">${t}</p>`;render()};
const opts=()=>S.members.map(m=>`<option value="${m.id}">${m.id} ${esc(m.name)}</option>`).join('');
const rows=(h,r)=>`<div class="wrap"><table><tr>${h.map(x=>`<th>${x}</th>`).join('')}</tr>${r.join('')||'<tr><td colspan="9">Nothing recorded yet.</td></tr>'}</table></div>`;
const txTable=l=>rows(['Date','Member','Type','Amount'],l.slice(0,8).map(t=>`<tr><td>${t.d}</td><td>${mname(t.m)}</td><td>${t.type}</td><td>${fmt(t.a)}</td></tr>`));

const V={
 Dashboard(){
  const act=S.loans.filter(l=>l.st==='approved'&&bal(l)>0), out=act.reduce((s,l)=>s+bal(l),0),
   lateL=act.filter(l=>late(l)>0), par=out?lateL.reduce((s,l)=>s+bal(l),0)/out*100:0,
   cur=act.filter(l=>!late(l)).reduce((s,l)=>s+bal(l),0), a1=lateL.filter(l=>late(l)<=30).reduce((s,l)=>s+bal(l),0), a2=out-cur-a1,
   p=x=>out?x/out*100:0;
  return `<h2>Management dashboard</h2><div class="grid">
  <div class="card"><b>${S.members.length}</b><span>Members</span></div>
  <div class="card"><b>${fmt(S.members.reduce((s,m)=>s+m.sav,0))}</b><span>Total member savings</span></div>
  <div class="card"><b>${fmt(out)}</b><span>Loans outstanding</span></div>
  <div class="card"><b class="${par>10?'bad':'ok'}">${par.toFixed(1)}%</b><span>Portfolio at risk</span></div>
  <div class="card"><b>${S.loans.filter(l=>l.st==='pending').length}</b><span>Applications awaiting approval</span></div></div>
  <h3>Loan balance by days overdue</h3>
  <div class="bar"><i style="width:${p(cur)}%;background:var(--ok)"></i><i style="width:${p(a1)}%;background:var(--acc)"></i><i style="width:${p(a2)}%;background:var(--bad)"></i></div>
  <p class="note">Current ${fmt(cur)} | 1 to 30 days ${fmt(a1)} | Over 30 days ${fmt(a2)}</p>
  <h3>Accounts needing follow-up</h3>${rows(['Loan','Member','Balance','Days overdue'],lateL.sort((a,b)=>late(b)-late(a)).map(l=>`<tr><td>${l.id}</td><td>${mname(l.m)}</td><td>${fmt(bal(l))}</td><td class="bad">${late(l)}</td></tr>`))}`},
 Savings(){const can=role==='Teller';return `<h2>Savings counter</h2>${can?`<div class="card"><div class="row"><div><label for="sm">Member</label><select id="sm">${opts()}</select></div>
  <div><label for="sa">Amount (UGX)</label><input id="sa" type="number" min="0" step="1000"></div></div>
  <button class="btn" onclick="saving(1)">Record deposit</button><button class="btn alt" onclick="saving(-1)">Record withdrawal</button></div>`:''}
  <h3>Member balances</h3>${rows(['Member','Savings'],S.members.map(m=>`<tr><td>${m.id} ${esc(m.name)}</td><td>${fmt(m.sav)}</td></tr>`))}
  <h3>Recent transactions</h3>${txTable(S.tx)}`},
 Members(){const can=role==='Teller'||role==='Manager';
  return `<h2>Members</h2>${can?`<div class="card"><div class="row"><div><label for="nn">Full name</label><input id="nn"></div><div><label for="np">Phone</label><input id="np"></div><div><label for="nv">Village</label><input id="nv"></div></div><button class="btn" onclick="register()">Register member</button></div>`:''}
  <h3>Register</h3>${rows(['ID','Name','Phone','Village','Savings'],S.members.map(m=>`<tr><td>${m.id}</td><td>${esc(m.name)}</td><td>${esc(m.phone)}</td><td>${esc(m.village)}</td><td>${fmt(m.sav)}</td></tr>`))}`},
 Loans(){const lo=role==='Loans officer', mg=role==='Manager', ap=S.loans.filter(l=>l.st==='approved'&&bal(l)>0);
  return `<h2>Loans</h2>${lo?`<div class="card"><h3 style="margin-top:0">New application</h3><div class="row"><div><label for="lm">Member</label><select id="lm">${opts()}</select></div>
  <div><label for="la">Amount (UGX)</label><input id="la" type="number" min="0"></div><div><label for="lt">Months</label><input id="lt" type="number" min="1" max="24" value="6" style="width:80px"></div>
  <div><label for="lp">Purpose</label><input id="lp"></div></div><button class="btn" onclick="applyLoan()">Submit application</button><p class="note">Policy: a loan cannot exceed 3 times the member's savings. Interest is 12% a year, flat.</p></div>
  <div class="card" style="margin-top:12px"><h3 style="margin-top:0">Record repayment</h3><div class="row"><div><label for="rl">Loan</label><select id="rl">${ap.map(l=>`<option value="${l.id}">${l.id} ${mname(l.m)} (balance ${fmt(bal(l))})</option>`).join('')}</select></div>
  <div><label for="ra">Amount (UGX)</label><input id="ra" type="number" min="0"></div><div><label for="rc">Channel</label><select id="rc"><option>Cash</option><option>MTN MoMo</option><option>Airtel Money</option></select></div><div><label for="rr">Mobile money ref</label><input id="rr"></div></div>
  <button class="btn" onclick="repay()">Record repayment</button></div>`:''}
  <h3>Loan register</h3>${rows(['Loan','Member','Purpose','Amount','Instalment','Balance','Status','Overdue',''],S.loans.map(l=>`<tr><td>${l.id}</td><td>${mname(l.m)}</td><td>${esc(l.purpose)}</td><td>${fmt(l.amt)}</td><td>${fmt(inst(l))}</td><td>${fmt(bal(l))}</td><td><span class="tag">${l.st}</span></td><td class="${late(l)?'bad':''}">${late(l)?late(l)+' days':'-'}</td><td>${mg&&l.st==='pending'?`<button class="btn sm" onclick="decide('${l.id}',1)">Approve</button><button class="btn sm alt" onclick="decide('${l.id}',0)">Reject</button>`:''}</td></tr>`))}`},
 Audit(){return `<h2>Audit trail</h2>${rows(['Action'],S.audit.map(a=>`<tr><td>${esc(a)}</td></tr>`))}`},
 'My account'(){const id=user.mid, m=mem(id), ml=S.loans.filter(l=>l.m===id);
  return `<h2>My account: ${esc(m.name)}</h2><div class="grid"><div class="card"><b>${fmt(m.sav)}</b><span>Savings balance</span></div><div class="card"><b>${fmt(ml.filter(l=>l.st==='approved').reduce((s,l)=>s+bal(l),0))}</b><span>Loan balance</span></div></div>
  <h3>My loans</h3>${rows(['Loan','Purpose','Balance','Next instalment due','Instalment'],ml.map(l=>`<tr><td>${l.id}</td><td>${esc(l.purpose)}</td><td>${fmt(bal(l))}</td><td class="${late(l)?'bad':''}">${l.due||'-'}${late(l)?' (overdue)':''}</td><td>${fmt(inst(l))}</td></tr>`))}
  <h3>My transactions</h3>${txTable(S.tx.filter(t=>t.m===id))}`}
};
function saving(sign){act('saving',{member:v('sm'),amount:v('sa'),type:sign>0?'deposit':'withdrawal'})}
function register(){act('register',{name:v('nn'),phone:v('np'),village:v('nv')})}
function applyLoan(){act('applyLoan',{member:v('lm'),amount:v('la'),months:v('lt'),purpose:v('lp')})}
function decide(id,ok){act('decide',{loan:id,approve:!!ok})}
function repay(){const l=v('rl');if(!l)return note('No active loans to repay.',1);
 act('repay',{loan:l,amount:v('ra'),channel:v('rc'),reference:v('rr')})}
function go(x){view=x;render()}
function openLogin(r){loginFor=r||'';showLogin=true;msg='';render()}
function backToPage(){showLogin=false;msg='';render()}
async function signIn(){const u=v('lu').trim(),p=v('lp2');
 const j=await api('login',{username:u,password:p});
 if(!j.ok){msg=`<p class="msg bad">${esc(j.error||'Login failed.')}</p>`;return render()}
 if(loginFor&&j.user.role!==loginFor){await api('logout');msg=`<p class="msg bad">That account is not a ${esc(loginFor)}. Use the matching username.</p>`;return render()}
 apply(j);view=roles[role][0];showLogin=false;msg='';render()}
async function signOut(){await api('logout');user=null;role='Guest';view='Dashboard';showLogin=false;msg='';await load()}
async function load(){const j=await api('state');if(!j.ok){$('#main').innerHTML=`<p class="msg bad">${esc(j.error)}</p>`;return}
 apply(j);if(user&&!roles[role].includes(view))view=roles[role][0];render()}
function login(){return `<div class="card login"><h2>${loginFor?'Log in as '+esc(loginFor):'Log in'}</h2>${msg}
 <label for="lu">Username${loginFor==='Member'||!loginFor?' or Member ID':''}</label><input id="lu" value="${loginFor?DEMO[loginFor]:''}" autocomplete="username" onkeydown="if(event.key==='Enter')signIn()">
 <label for="lp2">Password</label><input id="lp2" type="password" autocomplete="current-password" onkeydown="if(event.key==='Enter')signIn()">
 <button class="btn" onclick="signIn()">Log in</button><button class="btn alt" onclick="backToPage()">Back to page</button>
 <p class="note">Prototype password: <b>${PW}</b></p></div>`}
function render(){
 if(showLogin){$('#who').innerHTML='';$('#nav').innerHTML='';$('#main').innerHTML=login();msg='';return}
 if(!user){
  $('#who').innerHTML='<span class="lg">Log in as</span> '+Object.keys(DEMO).map(r=>`<button class="btn alt so" onclick="openLogin('${r}')">${r}</button>`).join(' ');
  $('#nav').innerHTML=roles.Guest.map(x=>`<button class="${x===view?'on':''}" onclick="go('${x}')">${x}</button>`).join('');
  $('#main').innerHTML='<p class="msg ok">You are viewing the page without logging in (read-only). Use a "Log in as" button above to record transactions.</p>'+V[view]();msg='';return}
 $('#who').innerHTML=`<span>${esc(user.name)} (${esc(role)})</span> <button class="btn alt so" onclick="signOut()">Sign out</button>`;
 $('#nav').innerHTML=roles[role].map(x=>`<button class="${x===view?'on':''}" onclick="go('${x}')">${x}</button>`).join('');
 $('#main').innerHTML=msg+V[view]();msg='';
}
load();