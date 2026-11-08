<?php
declare(strict_types=1);
defined('ADI') || exit;

/** Davr bo'yicha savdo hisobotlari (admin panel). $from/$to — 'Y-m-d'. */
function report_period(string $from, string $to): array
{
    $a = $from . ' 00:00:00';
    $b = $to . ' 23:59:59';
    $sold = SOLD_STATUSES_SQL;
    $sum = row("SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total, COALESCE(AVG(total), 0) AS avg_check
                FROM orders WHERE status IN $sold AND created_at BETWEEN ? AND ?", [$a, $b]);
    $pay = [];
    foreach (rows("SELECT p.method, SUM(p.amount) AS s FROM payments p JOIN orders o ON o.id = p.order_id
                   WHERE p.status = 'ok' AND o.status IN $sold AND p.created_at BETWEEN ? AND ? GROUP BY p.method", [$a, $b]) as $r) {
        $pay[$r['method']] = (int)$r['s'];
    }
    return [
        'orders' => (int)$sum['cnt'],
        'total' => (int)$sum['total'],
        'avg_check' => (int)round((float)$sum['avg_check']),
        'payments' => $pay,
        'by_day' => rows("SELECT DATE(created_at) AS d, COUNT(*) AS cnt, SUM(total) AS total FROM orders
                          WHERE status IN $sold AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d", [$a, $b]),
        'top_products' => rows("SELECT i.name, SUM(i.qty) AS qty, SUM(i.qty * i.price) AS total FROM order_items i JOIN orders o ON o.id = i.order_id
                                WHERE o.status IN $sold AND o.created_at BETWEEN ? AND ? AND i.qty > 0 GROUP BY i.name ORDER BY qty DESC LIMIT 20", [$a, $b]),
        'by_cashier' => rows("SELECT COALESCE(e.name, 'Telegram (qabul qilinmagan)') AS name, COUNT(*) AS cnt, SUM(o.total) AS total FROM orders o
                              LEFT JOIN employees e ON e.id = o.employee_id WHERE o.status IN $sold AND o.created_at BETWEEN ? AND ? GROUP BY e.id, e.name ORDER BY total DESC", [$a, $b]),
        'by_type' => rows("SELECT type, COUNT(*) AS cnt, SUM(total) AS total FROM orders WHERE status IN $sold AND created_at BETWEEN ? AND ? GROUP BY type", [$a, $b]),
        'by_source' => rows("SELECT source, COUNT(*) AS cnt, SUM(total) AS total FROM orders WHERE status IN $sold AND created_at BETWEEN ? AND ? GROUP BY source", [$a, $b]),
        'cancels' => rows("SELECT c.created_at, c.name, c.qty, c.price, c.reason, o.number, e.name AS employee FROM item_cancels c
                           JOIN orders o ON o.id = c.order_id LEFT JOIN employees e ON e.id = c.employee_id
                           WHERE c.created_at BETWEEN ? AND ? ORDER BY c.id DESC LIMIT 200", [$a, $b]),
        'cancelled_orders' => (int)val("SELECT COUNT(*) FROM orders WHERE status IN ('cancelled','rejected') AND created_at BETWEEN ? AND ?", [$a, $b]),
    ];
}

/** Smena hisoboti (serverdagi ma'lumotdan). */
function report_shift(int $shiftId): array
{
    $s = row('SELECT s.*, e.name AS employee FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.id = ?', [$shiftId]);
    if (!$s) {
        throw new UserError('Smena topilmadi');
    }
    $sold = SOLD_STATUSES_SQL;
    $pay = ['cash' => 0, 'terminal' => 0, 'click' => 0, 'card_transfer' => 0, 'hamyon' => 0];
    foreach (rows("SELECT p.method, SUM(p.amount) AS s FROM payments p JOIN orders o ON o.id = p.order_id
                   WHERE p.shift_id = ? AND p.status = 'ok' AND o.status IN $sold GROUP BY p.method", [$shiftId]) as $r) {
        $pay[$r['method']] = (int)$r['s'];
    }
    $orders = row("SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total FROM orders WHERE shift_id = ? AND status IN $sold", [$shiftId]);
    $cancels = row('SELECT COUNT(*) AS cnt, COALESCE(SUM(qty * price), 0) AS total FROM item_cancels WHERE shift_id = ?', [$shiftId]);
    return [
        'shift' => $s,
        'payments' => $pay,
        'sales' => array_sum($pay),
        'orders' => (int)$orders['cnt'],
        'orders_total' => (int)$orders['total'],
        'cancels_count' => (int)$cancels['cnt'],
        'cancels_total' => (int)$cancels['total'],
        'expected_cash' => (int)$s['opening_cash'] + $pay['cash'],
        'counted_cash' => $s['counted_cash'] === null ? null : (int)$s['counted_cash'],
        'top' => rows("SELECT i.name, SUM(i.qty) AS qty, SUM(i.qty * i.price) AS total FROM order_items i JOIN orders o ON o.id = i.order_id
                       WHERE o.shift_id = ? AND o.status IN $sold AND i.qty > 0 GROUP BY i.name ORDER BY qty DESC LIMIT 30", [$shiftId]),
        'list' => rows("SELECT o.number, o.type, o.total, o.pay_method, o.status, o.created_at FROM orders o WHERE o.shift_id = ? ORDER BY o.id", [$shiftId]),
    ];
}

function shift_totals(int $shiftId): array
{
    $sold = SOLD_STATUSES_SQL;
    $r = ['cash' => 0, 'card' => 0, 'total' => 0, 'orders' => 0];
    foreach (rows("SELECT p.method, SUM(p.amount) AS s FROM payments p JOIN orders o ON o.id = p.order_id
                   WHERE p.shift_id = ? AND p.status = 'ok' AND o.status IN $sold GROUP BY p.method", [$shiftId]) as $x) {
        if ($x['method'] === 'cash') {
            $r['cash'] += (int)$x['s'];
        } else {
            $r['card'] += (int)$x['s'];
        }
        $r['total'] += (int)$x['s'];
    }
    $r['orders'] = (int)val("SELECT COUNT(*) FROM orders WHERE shift_id = ? AND status IN $sold", [$shiftId]);
    return $r;
}
