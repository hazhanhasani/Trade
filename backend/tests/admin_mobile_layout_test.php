<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cssPath = $root . '/public/admin/assets/cockpit.css';
$navPath = $root . '/public/admin/_nav.php';

$css = file_get_contents($cssPath);
$nav = file_get_contents($navPath);
if (!is_string($css) || !is_string($nav)) {
    fwrite(STDERR, "Admin responsive sources could not be read.\n");
    exit(1);
}

$failures = [];

if (preg_match('/overflow-x\s*:\s*auto/i', $css)) {
    $failures[] = 'cockpit.css must not reintroduce overflow-x:auto.';
}
if (!preg_match('/\.table-wrap\s*\{[^}]*overflow\s*:\s*hidden/is', $css)) {
    $failures[] = 'table-wrap must clamp overflow instead of requiring horizontal scrolling.';
}
if (!preg_match('/table-layout\s*:\s*fixed/i', $css)) {
    $failures[] = 'tables must use fixed layout so columns can shrink on narrow screens.';
}
if (!preg_match('/@media\s*\(max-width\s*:\s*1100px\)/i', $css)) {
    $failures[] = 'tablet/mobile responsive guard must start at 1100px.';
}
if (!preg_match('/overflow-x\s*:\s*clip\s*!important/i', $nav)) {
    $failures[] = 'inline admin shell must clamp root horizontal overflow.';
}
if (!preg_match('/table-layout\s*:\s*fixed\s*!important/i', $nav)) {
    $failures[] = 'inline admin shell must force fixed table layout for narrow screens.';
}
if (preg_match('/@media\s*\(max-width\s*:\s*(?:720|1100)px\)[^{]*\{.*?\.admin-topbar\s*\{[^}]*margin\s*:\s*-/is', $css)) {
    $failures[] = 'mobile/tablet topbar must not use negative margins.';
}

$adminFiles = array_merge(
    glob($root . '/public/admin/*.php') ?: [],
    glob($root . '/public/admin/bot/*.php') ?: [],
    glob($root . '/public/admin/update/*.php') ?: []
);
foreach ($adminFiles as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) continue;
    if (stripos($source, '<html') !== false && stripos($source, 'name="viewport"') === false && stripos($source, "name='viewport'") === false) {
        $failures[] = basename($file) . ' renders HTML without a viewport meta tag.';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Admin mobile layout regression checks passed.\n";
