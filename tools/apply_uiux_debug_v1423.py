from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if new in text:
        return
    if old not in text:
        raise SystemExit(f"UIUX patch anchor missing in {path}: {old[:120]!r}")
    p.write_text(text.replace(old, new, 1))


# Admin navigation: keep the full desktop IA, but collapse the 11-item primary
# navigation on phones. Also stop the old overflow guard from hiding page-local
# sub-navigation and expose aria-current to assistive technology.
nav = Path('backend/public/admin/_nav.php')
text = nav.read_text()
text = text.replace('/admin/assets/cockpit-ui.css?v=5', '/admin/assets/cockpit-ui.css?v=6')
text = text.replace('/admin/assets/cockpit.js?v=5', '/admin/assets/cockpit.js?v=6')
text = text.replace('    .global-subnav ~ .subnav:not(.global-subnav){display:none!important}\n', '')
text = text.replace(
    '.admin-nav{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:6px!important;overflow:hidden!important}.admin-nav a{width:100%!important}',
    '.admin-nav{display:none!important}.mobile-nav-menu{display:block!important}'
)
old_tools = '        <div class="trade-top-tools"><time class="trade-clock" data-iran-clock>زمان ایران…</time><button class="theme-toggle" type="button" data-theme-toggle>☾ تاریک</button></div>\n        <nav class="admin-nav" aria-label="منوی اصلی">'
new_tools = '''        <div class="trade-top-tools"><time class="trade-clock" data-iran-clock>زمان ایران…</time><button class="theme-toggle" type="button" data-theme-toggle>☾ تاریک</button></div>
        <details class="mobile-nav-menu">
            <summary><span>منوی اصلی</span><b><?=htmlspecialchars((string)($items[$active][1] ?? 'منو'), ENT_QUOTES, 'UTF-8')?></b></summary>
            <nav class="mobile-nav-grid" aria-label="منوی اصلی موبایل">
                <?php foreach ($items as $key => [$href, $label]): ?><a class="<?=$active === $key ? 'active' : ''?>" href="<?=$href?>" <?=$active === $key ? 'aria-current="page"' : ''?>><?=$label?></a><?php endforeach; ?>
                <a class="logout" href="/admin/?logout=1">خروج</a>
            </nav>
        </details>
        <nav class="admin-nav" aria-label="منوی اصلی">'''
if old_tools not in text and 'class="mobile-nav-menu"' not in text:
    raise SystemExit('Admin mobile navigation insertion anchor missing')
text = text.replace(old_tools, new_tools, 1)
text = text.replace(
    '<?php foreach ($items as $key => [$href, $label]): ?><a class="<?=$active === $key ? \'active\' : \'\'?>" href="<?=$href?>"><?=$label?></a><?php endforeach; ?>',
    '<?php foreach ($items as $key => [$href, $label]): ?><a class="<?=$active === $key ? \'active\' : \'\'?>" href="<?=$href?>" <?=$active === $key ? \'aria-current="page"\' : \'\'?>><?=$label?></a><?php endforeach; ?>',
    1,
)
# Mark active module links for screen readers as well.
text = text.replace('class="<?=$path===$href?\'active\':\'\'?>" href="<?=$href?>"', 'class="<?=$path===$href?\'active\':\'\'?>" href="<?=$href?>" <?=$path===$href?\'aria-current="page"\':\'\'?>>', 1)
nav.write_text(text)


# Shared admin polish: accessible touch targets/focus states, a compact mobile
# disclosure menu, more legible narrow tables without horizontal scrolling,
# and reduced-motion support.
ui = Path('backend/public/admin/assets/cockpit-ui.css')
css = ui.read_text()
marker = '/* Trade UI/UX Debug v6 */'
if marker not in css:
    css += r'''

/* Trade UI/UX Debug v6 */
.mobile-nav-menu{display:none;width:100%;margin-top:8px}
.mobile-nav-menu summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:44px;padding:10px 12px;border:1px solid var(--line);border-radius:13px;background:var(--surface);color:var(--ink);font-size:11px;font-weight:900}
.mobile-nav-menu summary::-webkit-details-marker{display:none}
.mobile-nav-menu summary:after{content:"⌄";color:var(--primary);font-size:16px;transition:transform .16s ease}
.mobile-nav-menu[open] summary:after{transform:rotate(180deg)}
.mobile-nav-menu summary b{color:var(--primary);font-size:11px;overflow-wrap:anywhere}
.mobile-nav-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:7px;padding:8px;border:1px solid var(--line);border-radius:14px;background:var(--surface)}
.mobile-nav-grid a{display:flex;align-items:center;justify-content:center;min-height:44px;padding:9px 8px;border:1px solid var(--line);border-radius:11px;background:var(--surface2);color:var(--ink);text-decoration:none;font-size:10.5px;font-weight:800;text-align:center;overflow-wrap:anywhere}
.mobile-nav-grid a.active{background:var(--purpleSoft);border-color:#d8d1ff;color:var(--primary)}
.mobile-nav-grid a.logout{background:var(--redSoft);color:var(--red);border-color:#f1caca}
.admin-nav a,.subnav a,.btn,.theme-toggle{min-height:44px}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,summary:focus-visible{outline:3px solid rgba(98,70,234,.24);outline-offset:2px}
.btn:disabled,button:disabled{cursor:not-allowed;opacity:.58}
.panel,.stat-card,.metric,.mobile-nav-menu summary,.mobile-nav-grid a{transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}
@media(max-width:720px){
  .mobile-nav-menu{display:block!important}
  .admin-brand{align-items:center}
  .trade-top-tools{display:grid;grid-template-columns:minmax(0,1fr) auto;width:100%;gap:7px}
  .trade-clock{width:100%;min-width:0;justify-content:center;overflow:hidden;text-overflow:ellipsis;font-size:9px}
  .theme-toggle{min-width:82px}
  .global-subnav{margin-top:9px}
  .global-subnav a{min-height:44px;font-size:10.5px}
  .page-head p,.panel-head p{font-size:11.5px;line-height:1.85}
  .table-wrap table,table{font-size:10px!important}
  th,td{font-size:10px!important;line-height:1.55!important;padding:8px 5px!important}
  pre,code,.code,.mono{word-break:break-word!important;overflow-wrap:anywhere!important}
}
@media(max-width:380px){.mobile-nav-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){
  html{scroll-behavior:auto!important}
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}
}
'''
ui.write_text(css)


# Mobile disclosure menu behavior: close it after navigation and keep only one
# disclosure open. No dependency and no effect on desktop behavior.
js = Path('backend/public/admin/assets/cockpit.js')
script = js.read_text()
if 'function wireMobileMenus()' not in script:
    anchor = "  function fixReplayLinks(scope=document){\n"
    block = '''  function wireMobileMenus(){
    document.querySelectorAll('.mobile-nav-menu').forEach(details=>{
      details.addEventListener('toggle',()=>{
        if(!details.open)return;
        document.querySelectorAll('.mobile-nav-menu[open]').forEach(other=>{if(other!==details)other.open=false;});
      });
      details.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{details.open=false;}));
    });
  }

'''
    if anchor not in script:
        raise SystemExit('cockpit.js mobile menu anchor missing')
    script = script.replace(anchor, block + anchor, 1)
    script = script.replace('updateThemeButton();updateClocks();localizeVisibleDates(document.body);fixReplayLinks(document);startPageSpecificLive();', 'updateThemeButton();updateClocks();localizeVisibleDates(document.body);fixReplayLinks(document);wireMobileMenus();startPageSpecificLive();', 1)
js.write_text(script)


# Android: make the Persian-first interface RTL even on a non-Persian system,
# remove cramped two-column input groups, make values readable instead of
# silently ellipsized, and localize visible English UI labels.
app = Path('android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt')
k = app.read_text()
if 'import androidx.compose.runtime.CompositionLocalProvider' not in k:
    k = k.replace('import androidx.compose.runtime.Composable\n', 'import androidx.compose.runtime.Composable\nimport androidx.compose.runtime.CompositionLocalProvider\n', 1)
if 'import androidx.compose.ui.platform.LocalLayoutDirection' not in k:
    k = k.replace('import androidx.compose.ui.platform.LocalContext\n', 'import androidx.compose.ui.platform.LocalContext\nimport androidx.compose.ui.platform.LocalLayoutDirection\n', 1)
if 'import androidx.compose.ui.unit.LayoutDirection' not in k:
    k = k.replace('import androidx.compose.ui.unit.dp\n', 'import androidx.compose.ui.unit.LayoutDirection\nimport androidx.compose.ui.unit.dp\n', 1)
if 'CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl)' not in k:
    k = k.replace('        Scaffold(\n', '        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {\n        Scaffold(\n', 1)
    k = k.replace('        }\n\n        if (emergencyConfirm != null) {', '        }\n        }\n\n        if (emergencyConfirm != null) {', 1)

translations = {
    '"${if (offline) "Offline Snapshot" else "Live Panel"} • v${BuildConfig.RELEASE_VERSION}"': '"${if (offline) "نسخه آفلاین" else "داده زنده"} • v${BuildConfig.RELEASE_VERSION}"',
    'V4SectionTitle("Market Radar", "Excellent / Good / Watch / Avoid")': 'V4SectionTitle("رادار بازار", "عالی / خوب / زیرنظر / پرریسک")',
    'val categories = listOf("excellent" to "Excellent", "good" to "Good", "watch" to "Watch", "avoid" to "Avoid")': 'val categories = listOf("excellent" to "عالی", "good" to "خوب", "watch" to "زیرنظر", "avoid" to "پرریسک")',
    'V4SectionTitle("Activity Timeline", "تصمیم‌ها و معاملات به زبان ساده")': 'V4SectionTitle("تایم‌لاین فعالیت", "تصمیم‌ها و معاملات به زبان ساده")',
    'V4SectionTitle("Risk Heatmap", "همبستگی پوزیشن‌های فعال")': 'V4SectionTitle("نقشه حرارتی ریسک", "همبستگی پوزیشن‌های فعال")',
    'V4TinyMetric("Entry",': 'V4TinyMetric("ورود",',
    'V4TinyMetric("Mark",': 'V4TinyMetric("قیمت فعلی",',
    'V4TinyMetric("Fee",': 'V4TinyMetric("کارمزد",',
    'V4TinyMetric("SL",': 'V4TinyMetric("حد ضرر",',
    'V4TinyMetric("TP",': 'V4TinyMetric("حد سود",',
    'V4TinyMetric("Exit",': 'V4TinyMetric("خروج",',
    'V4SectionTitle("Presetها", "محافظه‌کار، متعادل یا تهاجمی")': 'V4SectionTitle("پروفایل‌های آماده", "محافظه‌کار، متعادل یا تهاجمی")',
    'V4SectionTitle("تنظیمات سفارشی + Preview", "قبل از ذخیره تغییر ریسک را ببین")': 'V4SectionTitle("تنظیمات سفارشی و پیش‌نمایش", "قبل از ذخیره، اثر تغییر ریسک را ببین")',
    'label = { Text("Buy %") }': 'label = { Text("درصد هر خرید") }',
    'label = { Text("Exposure %") }': 'label = { Text("سقف سرمایه درگیر") }',
    'label = { Text("Max positions") }': 'label = { Text("حداکثر پوزیشن") }',
    'label = { Text("Daily loss %") }': 'label = { Text("حد زیان روزانه") }',
    '{ Text("Preview") }': '{ Text("پیش‌نمایش") }',
    'V4SectionTitle("Shadow / Paper", "ارزیابی بدون افزایش ریسک")': 'V4SectionTitle("ارزیابی آزمایشی", "بررسی سیگنال‌ها بدون افزایش ریسک")',
    'V4SettingRow("Shadow evaluation", "نتیجه سیگنال‌ها را بعداً مقایسه می‌کند"': 'V4SettingRow("ارزیابی Shadow", "نتیجه سیگنال‌ها را بعداً مقایسه می‌کند"',
    'V4SectionTitle("امنیت اپ", "Biometric Lock")': 'V4SectionTitle("امنیت اپ", "قفل بیومتریک")',
    'V4SectionTitle("Offline Snapshot", "آخرین Command Center به‌صورت رمزگذاری‌شده روی دستگاه")': 'V4SectionTitle("نسخه آفلاین", "آخرین وضعیت مرکز فرمان به‌صورت رمزگذاری‌شده روی دستگاه")',
    'V4SectionTitle("Settings History + Rollback", "${history.size} Snapshot اخیر")': 'V4SectionTitle("تاریخچه تنظیمات و بازگردانی", "${history.size} نسخه اخیر")',
    ') { Text("Rollback") }': ') { Text("بازگردانی") }',
}
for old, new in translations.items():
    k = k.replace(old, new)

old_fields = '''                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(customPosition, { customPosition = it }, label = { Text("درصد هر خرید") }, modifier = Modifier.weight(1f), singleLine = true)
                    OutlinedTextField(customExposure, { customExposure = it }, label = { Text("سقف سرمایه درگیر") }, modifier = Modifier.weight(1f), singleLine = true)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(customMax, { customMax = it }, label = { Text("حداکثر پوزیشن") }, modifier = Modifier.weight(1f), singleLine = true)
                    OutlinedTextField(customDaily, { customDaily = it }, label = { Text("حد زیان روزانه") }, modifier = Modifier.weight(1f), singleLine = true)
                }'''
new_fields = '''                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(customPosition, { customPosition = it }, label = { Text("درصد هر خرید") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(customExposure, { customExposure = it }, label = { Text("سقف سرمایه درگیر") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(customMax, { customMax = it }, label = { Text("حداکثر پوزیشن") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(customDaily, { customDaily = it }, label = { Text("حد زیان روزانه") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                }'''
if old_fields in k:
    k = k.replace(old_fields, new_fields, 1)

old_priority = '''                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("info", "success", "warning", "critical").forEach { p ->
                        FilledTonalButton(onClick = {
                            minPriority = p
                            scope.launch {
                                val body = JSONObject().put("min_priority", p).put("enabled", true)
                                runCatching { api.updateNotificationRules(body.toString()) }
                                onReload()
                            }
                        }, colors = ButtonDefaults.filledTonalButtonColors(containerColor = if (minPriority == p) V4PrimarySoft else Color.White)) { Text(p) }
                    }
                }'''
new_priority = '''                listOf("info", "success", "warning", "critical").chunked(2).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
                        row.forEach { p ->
                            FilledTonalButton(onClick = {
                                minPriority = p
                                scope.launch {
                                    val body = JSONObject().put("min_priority", p).put("enabled", true)
                                    runCatching { api.updateNotificationRules(body.toString()) }
                                    onReload()
                                }
                            }, modifier = Modifier.weight(1f), colors = ButtonDefaults.filledTonalButtonColors(containerColor = if (minPriority == p) V4PrimarySoft else Color.White)) { Text(p) }
                        }
                    }
                }'''
if old_priority in k:
    k = k.replace(old_priority, new_priority, 1)

k = k.replace('style = MaterialTheme.typography.titleLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)', 'style = MaterialTheme.typography.titleLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)', 1)
k = k.replace('Text(value, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)', 'Text(value, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)', 1)
app.write_text(k)


# Static regression guard for the UX contract.
test = Path('backend/tests/uiux_debug_v1423_test.php')
test.write_text(r'''<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nav = file_get_contents($root . '/backend/public/admin/_nav.php');
$ui = file_get_contents($root . '/backend/public/admin/assets/cockpit-ui.css');
$js = file_get_contents($root . '/backend/public/admin/assets/cockpit.js');
$app = file_get_contents($root . '/android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt');

function ux(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

ux(is_string($nav) && is_string($ui) && is_string($js) && is_string($app), 'UI/UX sources unavailable');
ux(str_contains($nav, 'mobile-nav-menu'), 'mobile primary navigation disclosure missing');
ux(str_contains($nav, 'aria-current="page"'), 'active navigation must expose aria-current');
ux(!str_contains($nav, '.global-subnav ~ .subnav:not(.global-subnav)'), 'global nav must not hide page-local sub-navigation');
ux(str_contains($ui, 'a:focus-visible'), 'keyboard focus treatment missing');
ux(str_contains($ui, 'prefers-reduced-motion:reduce'), 'reduced-motion support missing');
ux(str_contains($ui, 'min-height:44px'), 'mobile touch targets must be at least 44px');
ux(str_contains($js, 'wireMobileMenus'), 'mobile disclosure behavior missing');
ux(str_contains($app, 'CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl)'), 'Persian app must force RTL layout independently of device locale');
ux(str_contains($app, 'رادار بازار') && str_contains($app, 'نقشه حرارتی ریسک'), 'primary Android navigation content is not localized');
ux(str_contains($app, 'listOf("info", "success", "warning", "critical").chunked(2)'), 'priority controls must not overflow narrow screens');
ux(str_contains($app, 'modifier = Modifier.fillMaxWidth(), singleLine = true'), 'custom risk inputs must have full-width mobile layout');
ux(str_contains($app, 'style = MaterialTheme.typography.titleLarge, maxLines = 2'), 'metric values must not be clipped to one line');
ux(str_contains($app, 'Text(value, fontWeight = FontWeight.Bold, maxLines = 2'), 'tiny metric values must remain readable');

echo "Trade 1.4.23 UI/UX regression checks passed.\n";
''')
