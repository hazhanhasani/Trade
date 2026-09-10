(()=>{
  'use strict';
  const root=document.documentElement;
  const stored=localStorage.getItem('trade-theme');
  const preferred=stored||((window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');
  root.dataset.theme=preferred;

  function updateThemeButton(){
    document.querySelectorAll('[data-theme-toggle]').forEach(btn=>{
      const dark=root.dataset.theme==='dark';
      btn.textContent=dark?'☀ روشن':'☾ تاریک';
      btn.setAttribute('aria-label',dark?'فعال کردن تم روشن':'فعال کردن تم تاریک');
      btn.setAttribute('title',dark?'تم روشن':'تم تاریک');
    });
  }
  function toggleTheme(){root.dataset.theme=root.dataset.theme==='dark'?'light':'dark';localStorage.setItem('trade-theme',root.dataset.theme);updateThemeButton();}

  let iranClockFormatter=null,iranDateTimeFormatter=null;
  try{
    iranClockFormatter=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',weekday:'long',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
    iranDateTimeFormatter=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
  }catch(_){iranClockFormatter=null;iranDateTimeFormatter=null;}
  function updateClocks(){const now=new Date(),text=iranClockFormatter?iranClockFormatter.format(now):now.toLocaleString('fa-IR',{timeZone:'Asia/Tehran'});document.querySelectorAll('[data-iran-clock]').forEach(el=>{el.textContent=text;el.setAttribute('datetime',now.toISOString());});}
  function iranDateTimeFromUtc(value){
    if(!iranDateTimeFormatter)return value;
    const raw=String(value).trim();
    const iso=/Z$|[+-]\d\d:\d\d$/.test(raw)?raw:raw.replace(' ','T')+'Z';
    const d=new Date(iso);return Number.isNaN(d.getTime())?value:iranDateTimeFormatter.format(d);
  }
  function localizeVisibleDates(scope=document.body){
    if(!scope||!iranDateTimeFormatter)return;
    const walker=document.createTreeWalker(scope,NodeFilter.SHOW_TEXT,{acceptNode(node){const p=node.parentElement;if(!p||p.closest('script,style,code,pre,textarea,input,[data-no-date-localize]'))return NodeFilter.FILTER_REJECT;return /20\d{2}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(node.nodeValue||'')?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;}});
    const nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);
    nodes.forEach(node=>{node.nodeValue=(node.nodeValue||'').replace(/20\d{2}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?/g,m=>iranDateTimeFromUtc(m));});
  }

  function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  async function json(url,options={}){const response=await fetch(url,{cache:'no-store',credentials:'same-origin',...options});let body;try{body=await response.json();}catch(_){throw new Error(`HTTP ${response.status}: پاسخ JSON معتبر نیست`);}if(!response.ok||body?.ok===false)throw new Error(body?.message||body?.error||`HTTP ${response.status}`);return body;}
  function poll(fn,interval=5000,{immediate=true,visibleOnly=true}={}){let stopped=false,busy=false,timer=null;const tick=async()=>{if(stopped||busy)return;if(visibleOnly&&document.hidden)return;busy=true;try{await fn();}catch(e){console.warn('[Trade live]',e);}finally{busy=false;}};if(immediate)tick();timer=setInterval(tick,Math.max(1500,interval));const onVisible=()=>{if(!document.hidden)tick();};document.addEventListener('visibilitychange',onVisible);return()=>{stopped=true;clearInterval(timer);document.removeEventListener('visibilitychange',onVisible);};}
  function pulse(el){if(!el)return;el.classList.add('changed');setTimeout(()=>el.classList.remove('changed'),180);}
  function setText(selector,value){const el=typeof selector==='string'?document.querySelector(selector):selector;if(!el)return;const next=String(value??'—');if(el.textContent!==next){el.textContent=next;pulse(el);}}
  function formatNumber(value,max=2){const n=Number(value);if(!Number.isFinite(n))return'—';return new Intl.NumberFormat('fa-IR',{maximumFractionDigits:max}).format(n);}
  function formatBytes(value){const n=Number(value);if(!Number.isFinite(n)||n<0)return'—';const units=['B','KB','MB','GB','TB'];let i=0,v=n;while(v>=1024&&i<units.length-1){v/=1024;i++;}return`${formatNumber(v,i===0?0:2)} ${units[i]}`;}
  function iranFromPayload(p){return p?.jalali_human||p?.jalali_datetime||p?.jalali_datetime_minute||'—';}

  document.addEventListener('click',e=>{const btn=e.target.closest('[data-theme-toggle]');if(btn)toggleTheme();});
  document.addEventListener('DOMContentLoaded',()=>{
    updateThemeButton();updateClocks();localizeVisibleDates(document.body);
    const observer=new MutationObserver(mutations=>{for(const m of mutations)for(const n of m.addedNodes)if(n.nodeType===Node.ELEMENT_NODE)localizeVisibleDates(n);else if(n.nodeType===Node.TEXT_NODE&&n.parentElement)localizeVisibleDates(n.parentElement);});
    observer.observe(document.body,{childList:true,subtree:true});
  });
  updateThemeButton();updateClocks();setInterval(updateClocks,1000);
  window.TradeUI={json,poll,escapeHtml,setText,formatNumber,formatBytes,iranFromPayload,iranDateTimeFromUtc,localizeVisibleDates,toggleTheme};
})();
