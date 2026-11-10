<?php
declare(strict_types=1);

/**
 * MCP manzili (Streamable HTTP, JSON javob). Claude konnektori shu yerga ulanadi.
 * Manzil va kalit: admin panel → "Claude (MCP)". Standart holatda o'chiq.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$fail = static function (int $http, int $code, string $msg): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $msg]], JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405); // server tomonidan SSE oqimi yo'q — faqat POST
    exit;
}
$ip = client_ip();
if (!rate_limit('mcp_fail:' . $ip, 20, 600)) {
    $fail(429, -32002, "Juda ko'p noto'g'ri urinish. 10 daqiqadan keyin urinib ko'ring.");
}
if (!mcp_auth()) {
    usleep(400000); // kalitni tanlab topishni sekinlashtirish
    mcp_log('AUTH FAIL ' . $ip);
    $fail(401, -32001, "Kalit noto'g'ri yoki MCP o'chirilgan (admin panel → Claude (MCP)).");
}
rate_limit_reset('mcp_fail:' . $ip);
schema_autoupdate();

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    $fail(400, -32700, 'Parse error');
}
$batch = $body !== [] && array_keys($body) === range(0, count($body) - 1); // PHP 8.0 da array_is_list yo'q
$out = [];
foreach ($batch ? $body : [$body] as $req) {
    $r = is_array($req) ? mcp_handle($req) : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
    if ($r !== null) {
        $out[] = $r;
    }
}
if (!$out) {
    http_response_code(202); // faqat bildirishnomalar
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($batch ? $out : $out[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
