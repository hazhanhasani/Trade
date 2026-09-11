from pathlib import Path


def main() -> None:
    nav = Path('backend/public/admin/_nav.php')
    text = nav.read_text()
    text = text.replace('/admin/assets/cockpit-ui.css?v=6', '/admin/assets/cockpit-ui.css?v=7')
    text = text.replace('/admin/assets/cockpit.js?v=6', '/admin/assets/cockpit.js?v=7')
    anchor = '    <link rel="stylesheet" href="/admin/assets/cockpit-ui.css?v=7">\n    <script defer src="/admin/assets/cockpit.js?v=7"></script>'
    block = r'''    <style id="trade-theme-prepaint">html:not([data-theme-ready="true"]) body{visibility:hidden!important}html:not(.theme-animated) *,html:not(.theme-animated) *::before,html:not(.theme-animated) *::after{transition:none!important}</style>
    <script id="trade-theme-bootstrap">
    (()=>{const root=document.documentElement;let stored=null;try{stored=localStorage.getItem('trade-theme')}catch(_){}const theme=(stored==='dark'||stored==='light')?stored:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');root.dataset.theme=theme;const meta=document.querySelector('meta[name="theme-color"]');if(meta)meta.setAttribute('content',theme==='dark'?'#0d111b':'#f3f5f9');})();
    </script>
    <link rel="stylesheet" href="/admin/assets/cockpit-ui.css?v=7">
    <script>document.documentElement.dataset.themeReady='true';requestAnimationFrame(()=>requestAnimationFrame(()=>document.documentElement.classList.add('theme-animated')));</script>
    <script defer src="/admin/assets/cockpit.js?v=7"></script>'''
    if 'id="trade-theme-bootstrap"' not in text:
        if anchor not in text:
            raise SystemExit('theme bootstrap anchor missing')
        text = text.replace(anchor, block, 1)
    nav.write_text(text)

    ui = Path('backend/public/admin/assets/cockpit-ui.css')
    css = ui.read_text()
    css = css.replace('--live-glow:0 0 0 4px rgba(11,150,109,.10);', '--live-glow:0 0 0 4px rgba(11,150,109,.10);\n  --panel:var(--surface);--border:var(--line);')
    css = css.replace(
        'body{font-family:var(--trade-font)!important;transition:background-color .18s ease,color .18s ease}',
        'body{font-family:var(--trade-font)!important}html.theme-animated body{transition:background-color .18s ease,color .18s ease}',
    )
    marker = '/* Trade Dark Contrast v7 */'
    if marker not in css:
        css += r'''

/* Trade Dark Contrast v7 */
html[data-theme="dark"] .badge{background:var(--surface2);color:var(--muted);border-color:var(--line)}
html[data-theme="dark"] .badge.good{background:var(--greenSoft);color:var(--green)}
html[data-theme="dark"] .badge.bad{background:var(--redSoft);color:var(--red)}
html[data-theme="dark"] .badge.warn{background:var(--amberSoft);color:var(--amber)}
html[data-theme="dark"] .badge.info{background:var(--blueSoft);color:var(--blue)}
html[data-theme="dark"] .notice.good{background:var(--greenSoft);color:var(--green)}
html[data-theme="dark"] .notice.bad{background:var(--redSoft);color:var(--red)}
html[data-theme="dark"] .notice.info{background:var(--blueSoft);color:var(--blue)}
html[data-theme="dark"] .btn.soft{background:var(--purpleSoft);color:#c4baff;border-color:#524985}
html[data-theme="dark"] .mobile-nav-grid,html[data-theme="dark"] .mobile-nav-grid a,html[data-theme="dark"] .mobile-nav-menu summary{background:var(--surface);border-color:var(--line);color:var(--ink)}
html[data-theme="dark"] .mobile-nav-grid a{background:var(--surface2)}
html[data-theme="dark"] .mobile-nav-grid a.active{background:var(--purpleSoft);border-color:#524985;color:#c4baff}
html[data-theme="dark"] .mobile-nav-grid a.logout{background:var(--redSoft);border-color:#543038;color:#ff9c9c}
html[data-theme="dark"] .cc-mini{background:var(--surface2)!important;border-color:var(--line)!important;color:var(--ink)!important}
html[data-theme="dark"] .cc-position{border-bottom-color:var(--line)!important}
html[data-theme="dark"] .cc-danger{background:var(--redSoft)!important;border-color:#65343d!important;color:var(--ink)!important}
html[data-theme="dark"] .cc-radar section{border:1px solid var(--line);color:var(--ink)!important}
html[data-theme="dark"] .cc-radar .excellent{background:var(--greenSoft)!important}
html[data-theme="dark"] .cc-radar .goodradar{background:var(--blueSoft)!important}
html[data-theme="dark"] .cc-radar .watch{background:var(--amberSoft)!important}
html[data-theme="dark"] .cc-radar .avoid{background:var(--redSoft)!important}
html[data-theme="dark"] .cc-cell{color:var(--ink)!important}
html[data-theme="dark"] .theme-toggle,html[data-theme="dark"] .trade-clock{background:var(--surface);border-color:var(--line);color:var(--ink)}
'''
    ui.write_text(css)

    js = Path('backend/public/admin/assets/cockpit.js')
    script = js.read_text()
    old_theme = '''  const root=document.documentElement;
  const stored=localStorage.getItem('trade-theme');
  const preferred=stored||((window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');
  root.dataset.theme=preferred;

  function updateThemeButton(){
    document.querySelectorAll('[data-theme-toggle]').forEach(btn=>{
      const dark=root.dataset.theme==='dark';btn.textContent=dark?'☀ روشن':'☾ تاریک';btn.setAttribute('aria-label',dark?'فعال کردن تم روشن':'فعال کردن تم تاریک');btn.setAttribute('title',dark?'تم روشن':'تم تاریک');
    });
  }
  function toggleTheme(){root.dataset.theme=root.dataset.theme==='dark'?'light':'dark';localStorage.setItem('trade-theme',root.dataset.theme);updateThemeButton();}
'''
    new_theme = '''  const root=document.documentElement;
  if(root.dataset.theme!=='dark'&&root.dataset.theme!=='light'){
    let stored=null;try{stored=localStorage.getItem('trade-theme');}catch(_){}
    root.dataset.theme=(stored==='dark'||stored==='light')?stored:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');
  }
  root.dataset.themeReady='true';

  function updateThemeChrome(){const dark=root.dataset.theme==='dark';const meta=document.querySelector('meta[name="theme-color"]');if(meta)meta.setAttribute('content',dark?'#0d111b':'#f3f5f9');}
  function updateThemeButton(){
    updateThemeChrome();
    document.querySelectorAll('[data-theme-toggle]').forEach(btn=>{
      const dark=root.dataset.theme==='dark';btn.textContent=dark?'☀ روشن':'☾ تاریک';btn.setAttribute('aria-label',dark?'فعال کردن تم روشن':'فعال کردن تم تاریک');btn.setAttribute('title',dark?'تم روشن':'تم تاریک');
    });
  }
  function toggleTheme(){root.classList.add('theme-animated');root.dataset.theme=root.dataset.theme==='dark'?'light':'dark';try{localStorage.setItem('trade-theme',root.dataset.theme);}catch(_){}updateThemeButton();}
'''
    if old_theme not in script:
        raise SystemExit('cockpit theme block anchor missing')
    script = script.replace(old_theme, new_theme, 1)

    old_stats = '''  function statCard(label){return [...document.querySelectorAll('.stat-card')].find(c=>(c.querySelector(':scope > span')?.textContent||'').trim()===label);}
  function setStat(label,value,small=null){const card=statCard(label);if(!card)return;setText(card.querySelector(':scope > b'),value);if(small!==null)setText(card.querySelector(':scope > small'),small);}
  function setHero(prefix,value){const pill=[...document.querySelectorAll('.hero-pill')].find(x=>(x.textContent||'').trim().startsWith(prefix));if(pill)setText(pill,value);}
  function liveDashboard(d){
    const status=d.status||{},nb=status.exchanges?.nobitex||{},perf=nb.performance||{},cap=nb.portfolio_capacity||{},intel=d.intelligence||{};
    const unit=perf.display_unit==='TOMAN'?'تومان':(perf.display_unit||perf.quote_asset||'IRT');
    setStat('PnL امروز Nobitex',formatNumber(perf.today_realized_pnl,2),unit);
    setStat('PnL کل Nobitex',formatNumber(perf.total_realized_pnl,2),unit);
    setStat('Win Rate',formatNumber(perf.win_rate_percent,1)+'٪','معاملات بسته‌شده');
    const active=Number(cap.active_positions??nb.active_position_count??0),max=Number(cap.max_positions??0);setStat('پوزیشن فعال',max>0?`${formatNumber(active,0)}/${formatNumber(max,0)}`:formatNumber(active,0),`${formatNumber(cap.remaining_position_slots??0,0)} اسلات آزاد`);
    setStat('سفارش امروز',formatNumber(d.order_count_today??0,0),'روز جاری ایران');
    setStat('Pending',`${formatNumber(cap.pending_orders??0,0)}/${formatNumber(cap.max_pending_orders??0,0)}`,`Watchdog ${formatNumber(cap.pending_timeout_seconds??60,0)}s`);
    setStat('Risk Multiplier',formatNumber(Number(intel.position_size_multiplier??1)*100,0)+'٪','Portfolio Intelligence');
    setStat('Android Devices',formatNumber(d.device_count??0,0),'اتصال فعال');
    setHero('Nobitex Bot',`Nobitex Bot ${nb.bot_enabled?'ON':'OFF'}`);setHero('Live',`Live ${nb.live_execution_enabled?'ON':'OFF'}`);setHero('Cron',`Cron ${status.cron_health?.healthy?'HEALTHY':'CHECK'}`);setHero('Kill Switch',`Kill Switch ${status.kill_switch?'ON':'OFF'}`);
    const footer=document.querySelector('.admin-footer');if(footer)footer.title='آخرین بروزرسانی زنده: '+iranFromPayload(d.time_iran);
  }
  function startPageSpecificLive(){
    const path=location.pathname.replace(/\\/+$/,'/');
    if((path==='/admin/'||path==='/admin/index.php')&&document.querySelector('.admin-shell'))poll(async()=>{const j=await json('/admin/live-dashboard.php');liveDashboard(j.data);},4000);
  }
'''
    new_stats = '''  function statCard(labels){const wanted=(Array.isArray(labels)?labels:[labels]).map(x=>String(x).trim());return [...document.querySelectorAll('.stat-card')].find(c=>wanted.includes((c.querySelector(':scope > span')?.textContent||'').trim()));}
  function updateStatCard(labels,newLabel,value,small=null){const card=statCard(labels);if(!card)return;setText(card.querySelector(':scope > span'),newLabel);setText(card.querySelector(':scope > b'),value);if(small!==null)setText(card.querySelector(':scope > small'),small);card.classList.add('live-truth-card');}
  function setHero(prefix,value){const pill=[...document.querySelectorAll('.hero-pill')].find(x=>(x.textContent||'').trim().startsWith(prefix));if(pill)setText(pill,value);}
  function prepareLiveDashboard(){
    updateStatCard(['PnL امروز Nobitex','ارزش کیف پول نوبیتکس'],'ارزش کیف پول نوبیتکس','…','در حال همگام‌سازی با کیف پول واقعی');
    updateStatCard(['PnL کل Nobitex','نقد قابل معامله نوبیتکس'],'نقد قابل معامله نوبیتکس','…','در حال دریافت موجودی آزاد');
    updateStatCard(['Win Rate','PnL امروز ربات'],'PnL امروز ربات','…','فقط معاملات بسته‌شده Trade');
    updateStatCard(['پوزیشن فعال','پوزیشن فعال ربات'],'پوزیشن فعال ربات','…','در حال تطبیق ظرفیت');
    updateStatCard(['سفارش امروز','سفارش امروز Trade'],'سفارش امروز Trade','…','Order Ledger • روز جاری ایران');
    updateStatCard('Pending','Pending','…','در حال تطبیق سفارش‌های معلق');
    updateStatCard(['Risk Multiplier','اکسپوژر ربات'],'اکسپوژر ربات','…','نسبت پوزیشن‌های Trade به کل کیف پول');
    updateStatCard(['Android Devices','Win Rate ربات'],'Win Rate ربات','…','معاملات بسته‌شده Trade');
  }
  function liveDashboard(d){
    const status=d.status||{},nb=status.exchanges?.nobitex||{},perf=nb.performance||{},perfIrt=perf.by_quote?.IRT||((perf.quote_asset==='IRT'||perf.display_unit==='TOMAN')?perf:{}),cap=nb.portfolio_capacity||{},wallet=d.portfolio_truth||{};
    const assets=Array.isArray(wallet.wallet_assets)?wallet.wallet_assets:[];
    const cashRow=assets.find(x=>['RLS','IRT'].includes(String(x?.asset||'').toUpperCase()));
    const walletTotal=Number(wallet.wallet_total_toman),cashAvailable=Number(cashRow?.available_value_toman??wallet.cash_by_quote?.IRT),exposure=Number(wallet.exposure_percent);
    const cacheAge=Number(wallet.cache_age_seconds??0),cacheState=String(wallet.cache_state||wallet.status||'unknown');
    const walletReady=Number.isFinite(walletTotal)&&walletTotal>=0;
    const cashReady=Number.isFinite(cashAvailable)&&cashAvailable>=0;
    updateStatCard(['PnL امروز Nobitex','ارزش کیف پول نوبیتکس'],'ارزش کیف پول نوبیتکس',walletReady?formatNumber(walletTotal,0):'—',walletReady?`تومان • ${cacheState} • ${formatNumber(cacheAge,0)}s`:'داده معتبر کیف پول در دسترس نیست');
    updateStatCard(['PnL کل Nobitex','نقد قابل معامله نوبیتکس'],'نقد قابل معامله نوبیتکس',cashReady?formatNumber(cashAvailable,0):'—','تومان • موجودی آزاد RLS/IRT در نوبیتکس');
    updateStatCard(['Win Rate','PnL امروز ربات'],'PnL امروز ربات',formatNumber(perfIrt.today_realized_pnl??0,0),'تومان • PnL تحقق‌یافته دفتر معاملات Trade');
    const active=Number(cap.active_positions??nb.active_position_count??0),max=Number(cap.max_positions??0),remaining=Number(cap.remaining_position_slots??Math.max(0,max-active));
    updateStatCard(['پوزیشن فعال','پوزیشن فعال ربات'],'پوزیشن فعال ربات',max>0?`${formatNumber(active,0)}/${formatNumber(max,0)}`:formatNumber(active,0),max>0&&active>=max?'ظرفیت تکمیل؛ خرید جدید تا آزاد شدن اسلات متوقف است':`${formatNumber(remaining,0)} اسلات آزاد`);
    updateStatCard(['سفارش امروز','سفارش امروز Trade'],'سفارش امروز Trade',formatNumber(d.order_count_today??0,0),'Order Ledger • روز جاری ایران');
    updateStatCard('Pending','Pending',`${formatNumber(cap.pending_orders??0,0)}/${formatNumber(cap.max_pending_orders??0,0)}`,`Watchdog ${formatNumber(cap.pending_timeout_seconds??60,0)}s`);
    updateStatCard(['Risk Multiplier','اکسپوژر ربات'],'اکسپوژر ربات',Number.isFinite(exposure)?formatNumber(exposure,1)+'٪':'—','بر مبنای ارزش کامل کیف پول نوبیتکس');
    updateStatCard(['Android Devices','Win Rate ربات'],'Win Rate ربات',formatNumber(perfIrt.win_rate_percent??0,1)+'٪',`${formatNumber(perfIrt.closed_positions??0,0)} معامله بسته‌شده`);
    setHero('Nobitex Bot',`Nobitex Bot ${nb.bot_enabled?'ON':'OFF'}`);setHero('Live',`Live ${nb.live_execution_enabled?'ON':'OFF'}`);setHero('Cron',`Cron ${status.cron_health?.healthy?'HEALTHY':'CHECK'}`);setHero('Kill Switch',`Kill Switch ${status.kill_switch?'ON':'OFF'}`);
    const footer=document.querySelector('.admin-footer');if(footer)footer.title='آخرین بروزرسانی داشبورد: '+iranFromPayload(d.time_iran)+' • Nobitex '+cacheState+' '+formatNumber(cacheAge,0)+'s';
    document.documentElement.dataset.dashboardTruth='ready';
  }
  function startPageSpecificLive(){
    const path=location.pathname.replace(/\\/+$/,'/');
    if((path==='/admin/'||path==='/admin/index.php')&&document.querySelector('.admin-shell')){prepareLiveDashboard();poll(async()=>{const j=await json('/admin/live-dashboard.php');liveDashboard(j.data);},4000);}
  }
'''
    if old_stats not in script:
        raise SystemExit('cockpit dashboard block anchor missing')
    js.write_text(script.replace(old_stats, new_stats, 1))

    live = Path('backend/public/admin/live-dashboard.php')
    php = live.read_text()
    if 'NobitexPortfolioSnapshotCache' not in php:
        php = php.replace(
            'use Trade\\Trading\\NobitexPortfolioIntelligence;\n',
            'use Trade\\Trading\\NobitexPortfolioIntelligence;\nuse Trade\\Trading\\NobitexPortfolioSnapshotCache;\n',
        )
        php = php.replace(
            "    $pdo=Database::connection();$status=(new BotController())->status();$intelligence=[];\n    try{$intelligence=(new NobitexPortfolioIntelligence())->snapshot($pdo);}catch(Throwable){}\n",
            "    $pdo=Database::connection();$status=(new BotController())->status();$intelligence=[];$portfolioTruth=[];\n    try{$intelligence=(new NobitexPortfolioIntelligence())->snapshot($pdo);}catch(Throwable){}\n    try{$portfolioTruth=(new NobitexPortfolioSnapshotCache())->snapshot($pdo);}catch(Throwable $e){$portfolioTruth=['status'=>'deferred','reason'=>'valuation_failed','message'=>mb_substr($e->getMessage(),0,240)];}\n",
        )
        php = php.replace(
            "        'intelligence'=>$intelligence,\n",
            "        'intelligence'=>$intelligence,\n        'portfolio_truth'=>$portfolioTruth,\n",
        )
    live.write_text(php)

    bale = Path('backend/src/Observability/BaleSystemAlert.php')
    b = bale.read_text()
    old = '''        $hash = substr(hash('sha256', $fingerprint), 0, 40);
        // The channel is intentionally verbose. Keep only a tiny 5-second guard
        // against accidental recursive storms; otherwise every occurrence is kept.
        if ($this->recentlyQueued($pdo, $hash, 5)) return;
'''
    new = '''        $hash = substr(hash('sha256', $fingerprint), 0, 40);
        // Identical technical fingerprints are still persisted by ErrorReporter,
        // but Bale is a human-facing channel. Routine info logs (for example a
        // healthy cron repeatedly returning portfolio_full) are summarized at most
        // once per ten minutes; warnings/errors retain much shorter repeat windows.
        $dedupeSeconds = match ($severity) {
            'info' => 600,
            'warning' => 120,
            'error', 'critical' => 30,
            default => 60,
        };
        if ($this->recentlyQueued($pdo, $hash, $dedupeSeconds)) return;
'''
    if old not in b:
        raise SystemExit('Bale dedupe anchor missing')
    bale.write_text(b.replace(old, new, 1))

    Path('backend/tests/dashboard_truth_v1424_test.php').write_text(r'''<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nav = file_get_contents($root . '/backend/public/admin/_nav.php') ?: '';
$ui = file_get_contents($root . '/backend/public/admin/assets/cockpit-ui.css') ?: '';
$js = file_get_contents($root . '/backend/public/admin/assets/cockpit.js') ?: '';
$live = file_get_contents($root . '/backend/public/admin/live-dashboard.php') ?: '';
$bale = file_get_contents($root . '/backend/src/Observability/BaleSystemAlert.php') ?: '';

function d24(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

d24(str_contains($nav, 'trade-theme-bootstrap'), 'pre-paint theme bootstrap missing');
d24(str_contains($nav, 'data-theme-ready'), 'theme reveal guard missing');
d24(str_contains($nav, 'cockpit-ui.css?v=7') && str_contains($nav, 'cockpit.js?v=7'), 'theme assets must be cache-busted');
d24(str_contains($ui, 'Trade Dark Contrast v7'), 'dark contrast layer missing');
d24(str_contains($ui, '.cc-radar .excellent') && str_contains($ui, '.cc-danger'), 'command-center dark surfaces are not covered');
d24(str_contains($js, 'prepareLiveDashboard'), 'dashboard truth loading state missing');
d24(str_contains($js, 'ارزش کیف پول نوبیتکس') && str_contains($js, 'wallet_total_toman'), 'wallet truth is not visible on dashboard');
d24(str_contains($js, 'ظرفیت تکمیل؛ خرید جدید تا آزاد شدن اسلات متوقف است'), 'portfolio_full explanation missing from dashboard');
d24(str_contains($live, 'NobitexPortfolioSnapshotCache') && str_contains($live, "'portfolio_truth'=>"), 'live dashboard does not expose Nobitex wallet truth');
d24(str_contains($bale, "'info' => 600") && str_contains($bale, '$dedupeSeconds'), 'routine Bale info dedupe is not enforced');

echo "Trade 1.4.24 dashboard truth/theme regression checks passed.\n";
''')

    workflow = Path('.github/workflows/php-lint.yml')
    w = workflow.read_text()
    needle = "      - name: Trade 1.4.23 UI UX regression tests\n        run: php backend/tests/uiux_debug_v1423_test.php\n"
    add = needle + "      - name: Trade 1.4.24 dashboard truth and theme regression tests\n        run: php backend/tests/dashboard_truth_v1424_test.php\n"
    if 'dashboard_truth_v1424_test.php' not in w:
        if needle not in w:
            raise SystemExit('php-lint UIUX test anchor missing')
        w = w.replace(needle, add, 1)
    workflow.write_text(w)

    Path('backend/version.php').write_text("""<?php

declare(strict_types=1);

// Coordinated release: dashboard account truth + no-flash theme debug. Admin reads full Nobitex spot-wallet valuation through the shared cache, clearly separates exchange wallet truth from Trade's realized PnL ledger, explains portfolio_full capacity, fixes dark-mode contrast/FOUC, and rate-limits repeated routine Bale info fingerprints without suppressing local diagnostics.
return '1.4.24';
""")


if __name__ == '__main__':
    main()
