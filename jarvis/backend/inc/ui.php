<?php
/** Installer va admin panel uchun umumiy dizayn (qora + neon-ko'k). */
declare(strict_types=1);

function jv_page_start(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:");
    header('Referrer-Policy: no-referrer');
    ?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= jv_h($title) ?> · JARVIS</title>
<style>
:root{--bg:#03060b;--panel:#08111c;--line:#0f2a3f;--cyan:#00e5ff;--blue:#2979ff;--text:#d8f6ff;--muted:#6f8fa3;--ok:#00e676;--bad:#ff3d57;--warn:#ffc400}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 50% -10%,#062238 0,var(--bg) 55%);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;min-height:100vh}
.wrap{max-width:860px;margin:0 auto;padding:24px 16px 60px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.core{width:38px;height:38px;border-radius:50%;border:2px solid var(--cyan);box-shadow:0 0 18px var(--cyan),inset 0 0 12px var(--cyan);position:relative}
.core:after{content:"";position:absolute;inset:9px;border-radius:50%;background:var(--cyan);box-shadow:0 0 14px var(--cyan)}
.brand h1{font-size:20px;letter-spacing:6px;margin:0;color:var(--cyan);text-shadow:0 0 10px rgba(0,229,255,.6)}
.brand small{display:block;color:var(--muted);letter-spacing:1px}
.card{background:linear-gradient(180deg,rgba(8,17,28,.95),rgba(4,10,18,.95));border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 0 0 1px rgba(0,229,255,.04),0 10px 30px rgba(0,0,0,.4)}
.card h2{margin:0 0 12px;font-size:16px;color:var(--cyan);letter-spacing:1px}
label{display:block;margin:10px 0 4px;color:var(--muted);font-size:13px}
input,select,textarea{width:100%;background:#020509;border:1px solid var(--line);color:var(--text);padding:11px 12px;border-radius:10px;font:inherit}
textarea{min-height:140px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(0,229,255,.15)}
.btn{display:inline-block;background:linear-gradient(90deg,#00b8d4,var(--blue));color:#001018;border:0;padding:11px 18px;border-radius:10px;font-weight:700;cursor:pointer;text-decoration:none;margin-top:12px}
.btn.ghost{background:transparent;color:var(--cyan);border:1px solid var(--line)}
.btn.danger{background:transparent;color:var(--bad);border:1px solid #4a1520}
.btn.sm{padding:6px 10px;margin:0;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
th{color:var(--muted);font-weight:500}
.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}.muted{color:var(--muted)}
.msg{padding:12px 14px;border-radius:10px;margin-bottom:14px;border:1px solid}
.msg.ok{border-color:#0b5;background:rgba(0,230,118,.07)}
.msg.bad{border-color:#a12;background:rgba(255,61,87,.07)}
.msg.warn{border-color:#a80;background:rgba(255,196,0,.07)}
.token{font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all;background:#020509;border:1px dashed var(--cyan);padding:12px;border-radius:10px;color:var(--cyan)}
nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
nav a{color:var(--text);text-decoration:none;padding:8px 12px;border:1px solid var(--line);border-radius:999px;font-size:14px}
nav a.on{border-color:var(--cyan);color:var(--cyan);box-shadow:0 0 10px rgba(0,229,255,.2)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.stat b{display:block;font-size:26px;color:var(--cyan)}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.scroll{overflow-x:auto}
</style>
</head>
<body><div class="wrap">
<div class="brand"><div class="core"></div><div><h1>J.A.R.V.I.S</h1><small><?= jv_h($title) ?></small></div></div>
<?php
}

function jv_page_end(): void
{
    echo '<p class="muted" style="margin-top:30px;font-size:12px">JARVIS backend v' . JV_VERSION . '</p></div></body></html>';
}
