<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$health = file_get_contents($root . '/backend/public/admin/project-health.php');
$nav = file_get_contents($root . '/backend/public/admin/_nav.php');
$bootstrap = file_get_contents($root . '/backend/bootstrap.php');
$ops = file_get_contents($root . '/docs/PROJECT_OPERATING_SYSTEM.md');

expect(is_string($health) && $health !== '', 'Project Health page must exist.');
expect(str_contains($health, 'آمادگی خرید'), 'Project Health must expose BUY readiness in human-readable Persian.');
expect(str_contains($health, "'ready'=>'مسیر BUY آماده است'"), 'Project Health must distinguish ready BUY state.');
expect(str_contains($health, "'waiting_market'=>'زیرساخت آماده است؛ بازار هنوز شرایط ورود نداده'"), 'Project Health must distinguish market-wait state from infrastructure failure.');
expect(str_contains($health, "'blocked'=>'BUY به‌وسیله یکی از کنترل‌های اجرایی مسدود است'"), 'Project Health must distinguish hard runtime blockers.');
expect(str_contains($health, 'این صفحه Read-only است'), 'Project Health must explicitly remain read-only.');
expect(!str_contains($health, 'createOrder('), 'Project Health must never submit an exchange order.');
expect(!str_contains($health, 'updateSettings('), 'Project Health must never mutate trading settings.');

expect(is_string($nav) && str_contains($nav, '/admin/project-health.php'), 'Admin navigation must link to Project Health.');
expect(str_contains($nav, "'health' => ['/admin/project-health.php', 'پایش پروژه']"), 'Project Health must have a dedicated navigation item.');

expect(is_string($bootstrap) && str_contains($bootstrap, "header('X-Robots-Tag: noindex, nofollow, noarchive')"), 'Private web surfaces must emit X-Robots-Tag noindex.');
expect(str_contains($bootstrap, "str_starts_with(\$requestPath, '/admin')"), 'Admin surface must be covered by noindex policy.');
expect(str_contains($bootstrap, "str_starts_with(\$requestPath, '/api')"), 'API surface must be covered by noindex policy.');
expect(str_contains($bootstrap, "header('X-Content-Type-Options: nosniff')"), 'Baseline nosniff header must remain enabled.');

expect(is_string($ops) && str_contains($ops, '/human'), 'Operating system doc must include /human workflow.');
expect(str_contains($ops, '/expert'), 'Operating system doc must include /expert workflow.');
expect(str_contains($ops, '/ceo'), 'Operating system doc must include /ceo workflow.');
expect(str_contains($ops, '/seo'), 'Operating system doc must include /seo workflow.');
expect(str_contains($ops, '/critic'), 'Operating system doc must include /critic workflow.');
expect(str_contains($ops, '/plan'), 'Operating system doc must include /plan workflow.');
expect(str_contains($ops, '/habit'), 'Operating system doc must include /habit workflow.');
expect(str_contains($ops, '/focus'), 'Operating system doc must include /focus workflow.');
expect(str_contains($ops, '/track'), 'Operating system doc must include /track workflow.');
expect(str_contains($ops, '/review'), 'Operating system doc must include /review workflow.');
expect(str_contains($ops, '/planner'), 'Operating system doc must include /planner workflow.');
expect(str_contains($ops, '/prioritize'), 'Operating system doc must include /prioritize workflow.');
expect(str_contains($ops, '/concise'), 'Operating system doc must include /concise workflow.');
expect(str_contains($ops, '/customer'), 'Operating system doc must include /customer workflow.');
expect(str_contains($ops, '/audience'), 'Operating system doc must include /audience workflow.');
expect(str_contains($ops, '/competitor'), 'Operating system doc must include /competitor workflow.');
expect(str_contains($ops, '/research'), 'Operating system doc must include /research workflow.');
expect(str_contains($ops, '/evaluate'), 'Operating system doc must include /evaluate workflow.');
expect(str_contains($ops, '/innovate'), 'Operating system doc must include /innovate workflow.');
expect(str_contains($ops, '/debug'), 'Operating system doc must include /debug workflow.');

fwrite(STDOUT, "Project operating system regression tests passed.\n");
