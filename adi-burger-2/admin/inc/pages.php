<?php
declare(strict_types=1);
defined('ADI') || exit;

function stat_card(string $label, string $value, string $sub = ''): string
{
    return '<div class="stat"><div class="l">' . e($label) . '</div><div class="v">' . $value . '</div>' . ($sub ? '<div class="s">' . $sub . '</div>' : '') . '</div>';
}

function nf(int|float|string|null $n): string
{
    return number_format((int)$n, 0, '.', ' ');
}

// ===================== Dashboard =====================
function page_dashboard(array $me): void
{
    $today = report_period(date('Y-m-d'), date('Y-m-d'));
    $week = report_period(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
    $shift = row("SELECT s.*, e.name AS emp FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.status = 'open' ORDER BY s.id DESC LIMIT 1");
    $tgNew = rows("SELECT * FROM orders WHERE source = 'telegram' AND status IN ('new') ORDER BY id DESC LIMIT 5");
    $diag = rows('SELECT code, title, status, message FROM diagnostic_checks ORDER BY FIELD(status, "error", "warn", "unchecked", "ok"), code');
    $card = $today['payments'];
    $h = '<div class="stats">'
        . stat_card('Bugungi savdo', nf($today['total']) . ' <small>so\'m</small>', $today['orders'] . ' ta buyurtma')
        . stat_card("O'rtacha chek", nf($today['avg_check']))
        . stat_card('Naqd', nf($card['cash'] ?? 0))
        . stat_card('Karta (terminal, click, onlayn)', nf(($card['terminal'] ?? 0) + ($card['click'] ?? 0) + ($card['card_transfer'] ?? 0) + ($card['hamyon'] ?? 0)))
        . stat_card('7 kunlik savdo', nf($week['total']), $week['orders'] . ' ta buyurtma')
        . stat_card('Smena', $shift ? '#' . $shift['number'] . ' ochiq' : 'Yopiq', $shift ? e($shift['emp']) . ' · ' . date('H:i', strtotime($shift['opened_at'])) : '')
        . '</div>';
    $max = max(1, ...array_map(fn($d) => (int)$d['total'], $week['by_day'] ?: [['total' => 1]]));
    $h .= '<div class="grid2"><div class="card"><h3>Oxirgi 7 kun</h3><div class="bars">';
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $v = 0;
        foreach ($week['by_day'] as $x) {
            if ($x['d'] === $d) {
                $v = (int)$x['total'];
            }
        }
        $h .= '<div class="bar"><div class="bv">' . ($v ? nf(round($v / 1000)) . 'k' : '') . '</div><div class="bf" style="height:' . max(2, round($v / $max * 140)) . 'px"></div><div class="bl">' . date('d.m', strtotime($d)) . '</div></div>';
    }
    $h .= '</div></div><div class="card"><h3>Tizim holati <a class="small" href="' . url('diagnostics') . '">Diagnostika →</a></h3>';
    if (!$diag) {
        $h .= '<p class="muted">Hali tekshirilmagan.</p>' . action_btn('diag.run', [], '🩺 Barchasini tekshirish', 'btn primary');
    }
    foreach (array_slice($diag, 0, 8) as $d) {
        $h .= '<div class="kv"><span>' . e($d['title']) . '</span><span>' . badge($d['status']) . '</span></div>';
    }
    $h .= '</div></div>';
    $h .= '<div class="card"><h3>Yangi Telegram buyurtmalari</h3>' . ($tgNew ? orders_table($tgNew) : '<p class="muted">Yangi buyurtma yo\'q.</p>') . '</div>';
    $h .= '<div class="card"><h3>Bugun eng ko\'p sotilganlar</h3><table class="t"><tr><th>Mahsulot</th><th class="r">Soni</th><th class="r">Summa</th></tr>';
    foreach (array_slice($today['top_products'], 0, 10) as $t) {
        $h .= '<tr><td>' . e($t['name']) . '</td><td class="r">' . (int)$t['qty'] . '</td><td class="r">' . nf($t['total']) . '</td></tr>';
    }
    $h .= '</table></div>';
    layout('dashboard', 'Dashboard', $h, $me);
}

function orders_table(array $list): string
{
    $h = '<div class="tw"><table class="t"><tr><th>Raqam</th><th>Sana</th><th>Manba</th><th>Turi</th><th>Holat</th><th>To\'lov</th><th class="r">Jami</th></tr>';
    foreach ($list as $o) {
        $h .= '<tr class="click" data-href="' . e(url('order', ['id' => $o['id']])) . '"><td><b>' . e($o['number']) . '</b></td><td>' . date('d.m H:i', strtotime($o['created_at'])) . '</td><td>'
            . ($o['source'] === 'telegram' ? '✈️ Telegram' : '🧮 Kassa') . '</td><td>' . e(ORDER_TYPES[$o['type']] ?? $o['type']) . '</td><td>' . badge($o['status']) . '</td><td>' . badge($o['pay_status']) . '</td><td class="r">' . nf($o['total']) . '</td></tr>';
    }
    return $h . '</table></div>';
}

// ===================== Buyurtmalar =====================
function page_orders(array $me): void
{
    $f = [
        'from' => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-6 days')),
        'to' => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d'),
        'status' => array_key_exists((string)($_GET['status'] ?? ''), ORDER_STATUSES) ? $_GET['status'] : '',
        'source' => in_array($_GET['source'] ?? '', ['pos', 'telegram'], true) ? $_GET['source'] : '',
        'q' => str_limit($_GET['q'] ?? '', 30),
    ];
    $w = ['o.created_at BETWEEN ? AND ?'];
    $p = [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];
    if ($f['status']) {
        $w[] = 'o.status = ?';
        $p[] = $f['status'];
    }
    if ($f['source']) {
        $w[] = 'o.source = ?';
        $p[] = $f['source'];
    }
    if ($f['q'] !== '') {
        $w[] = '(o.number LIKE ? OR o.phone LIKE ?)';
        $p[] = '%' . $f['q'] . '%';
        $p[] = '%' . $f['q'] . '%';
    }
    $where = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM orders o WHERE $where", $p);
    $sum = (int)val("SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE $where AND o.status IN ('accepted','ready','done')", $p);
    $pg = max(1, (int)($_GET['pg'] ?? 1));
    $list = rows("SELECT o.* FROM orders o WHERE $where ORDER BY o.id DESC LIMIT 50 OFFSET " . (($pg - 1) * 50), $p);
    $h = '<form class="filters card" method="get"><input type="hidden" name="p" value="orders">'
        . '<div class="field"><label>Dan</label><input type="date" name="from" value="' . e($f['from']) . '"></div><div class="field"><label>Gacha</label><input type="date" name="to" value="' . e($f['to']) . '"></div>'
        . select_field('Holat', 'status', ['' => 'Hammasi'] + ORDER_STATUSES, $f['status']) . select_field('Manba', 'source', ['' => 'Hammasi', 'pos' => 'Kassa', 'telegram' => 'Telegram'], $f['source'])
        . '<div class="field"><label>Raqam yoki telefon</label><input name="q" value="' . e($f['q']) . '"></div><button class="btn primary">Ko\'rsatish</button></form>';
    $h .= '<div class="card"><div class="row-between"><b>' . $total . ' ta buyurtma · savdo: ' . nf($sum) . " so'm</b></div>" . orders_table($list) . pager($total, 50, $pg, $f) . '</div>';
    layout('orders', 'Buyurtmalar', $h, $me);
}

function page_order(array $me): void
{
    $o = order_get((int)($_GET['id'] ?? 0));
    if (!$o) {
        throw new UserError('Buyurtma topilmadi');
    }
    $items = order_items((int)$o['id']);
    $pays = rows('SELECT p.*, e.name AS emp FROM payments p LEFT JOIN employees e ON e.id = p.employee_id WHERE p.order_id = ? ORDER BY p.id', [$o['id']]);
    $cancels = rows('SELECT c.*, e.name AS emp FROM item_cancels c LEFT JOIN employees e ON e.id = c.employee_id WHERE c.order_id = ? ORDER BY c.id', [$o['id']]);
    $prints = rows('SELECT * FROM print_jobs WHERE order_id = ? ORDER BY id', [$o['id']]);
    $receipts = rows('SELECT * FROM card_receipts WHERE order_id = ? ORDER BY id DESC', [$o['id']]);
    $emp = $o['employee_id'] ? val('SELECT name FROM employees WHERE id = ?', [$o['employee_id']]) : null;
    $table = $o['table_id'] ? val('SELECT name FROM dining_tables WHERE id = ?', [$o['table_id']]) : null;
    $h = '<p><a href="' . url('orders') . '">← Buyurtmalar</a></p><div class="grid2"><div class="card"><h3>Buyurtma ' . e($o['number']) . ' ' . badge($o['status']) . ' ' . badge($o['pay_status']) . '</h3>';
    foreach ([
        'Manba' => $o['source'] === 'telegram' ? 'Telegram' : 'Kassa', 'Turi' => (ORDER_TYPES[$o['type']] ?? $o['type']) . ($table ? " · $table" . ($o['bill_no'] > 1 ? " (Hisob-{$o['bill_no']})" : '') : ''),
        'Yaratilgan' => $o['created_at'], 'Yopilgan' => $o['closed_at'], 'Kassir' => $emp, 'Mijoz' => trim(($o['customer_name'] ?? '') . ' ' . ($o['phone'] ?? '')),
        'Manzil' => $o['address'], 'Izoh' => $o['comment'], 'Rad sababi' => $o['reject_reason'], 'Bekor sababi' => $o['cancel_reason'],
    ] as $k => $v) {
        if ($v) {
            $h .= '<div class="kv"><span>' . e($k) . '</span><b>' . e($v) . '</b></div>';
        }
    }
    if ($o['source'] === 'telegram') {
        $h .= '<div class="btns" style="margin-top:12px">';
        foreach (TG_FLOW[$o['status']] ?? [] as $to) {
            if ($to === 'new' || ($to === 'accepted' && in_array($o['pay_method'], ['card', 'hamyon'], true) && $o['pay_status'] !== 'paid')) {
                continue; // to'lanmagan karta/Hamyon buyurtmasi qabul qilinmaydi
            }
            $label = ['accepted' => '✅ Qabul qilish', 'ready' => 'Tayyor', 'done' => 'Yakunlash', 'rejected' => '❌ Rad etish', 'cancelled' => 'Bekor qilish'][$to];
            $cls = in_array($to, ['rejected', 'cancelled'], true) ? 'btn danger' : 'btn primary';
            if (in_array($to, ['rejected', 'cancelled'], true)) {
                $h .= form_open('order.status', ['id' => $o['id'], 'to' => $to], 'class="inline" data-confirm="Rostdan ham?"') . '<input name="reason" placeholder="Sabab" required style="width:160px"> <button class="' . $cls . '">' . $label . '</button></form>';
            } else {
                $h .= action_btn('order.status', ['id' => $o['id'], 'to' => $to], $label, $cls);
            }
        }
        $h .= '</div>';
    }
    $h .= '</div><div class="card"><h3>Tarkibi</h3><table class="t"><tr><th>Taom</th><th class="r">Narx</th><th class="r">Soni</th><th class="r">Bekor</th><th class="r">Summa</th></tr>';
    foreach ($items as $i) {
        $h .= '<tr><td>' . e($i['name']) . ($i['comment'] ? '<div class="muted small">' . e($i['comment']) . '</div>' : '') . '</td><td class="r">' . nf($i['price']) . '</td><td class="r">' . (int)$i['qty'] . '</td><td class="r">' . ((int)$i['cancelled_qty'] ?: '') . '</td><td class="r">' . nf($i['price'] * $i['qty']) . '</td></tr>';
    }
    if ((int)$o['delivery_fee']) {
        $h .= '<tr><td>Yetkazish</td><td></td><td></td><td></td><td class="r">' . nf($o['delivery_fee']) . '</td></tr>';
    }
    $h .= '<tr><td><b>Jami</b></td><td></td><td></td><td></td><td class="r"><b>' . nf($o['total']) . '</b></td></tr></table></div></div>';
    $h .= '<div class="grid2"><div class="card"><h3>To\'lovlar</h3><table class="t"><tr><th>Vaqt</th><th>Usul</th><th class="r">Summa</th><th class="r">Qaytim</th><th>Kassir</th></tr>';
    foreach ($pays as $p) {
        $h .= '<tr><td>' . e($p['created_at']) . '</td><td>' . e(PAY_METHODS[$p['method']] ?? $p['method']) . '</td><td class="r">' . nf($p['amount']) . '</td><td class="r">' . nf($p['change_amount']) . '</td><td>' . e($p['emp']) . '</td></tr>';
    }
    $h .= '</table>';
    foreach ($receipts as $r) {
        $h .= '<div class="receipt"><div class="kv"><span>Mijoz yuborgan chek · ' . e($r['created_at']) . '</span>' . badge($r['status']) . '</div><img src="' . e(url('download', ['f' => 'receipt', 'id' => $r['id']])) . '" alt="chek" loading="lazy">';
        if ($r['status'] === 'checking') {
            $h .= '<div class="btns">' . action_btn('receipt.review', ['id' => $r['id'], 'approve' => '1'], "✅ To'lov tushdi", 'btn primary', "Pul kartaga tushganini tekshirdingizmi?")
                . form_open('receipt.review', ['id' => $r['id'], 'approve' => '0'], 'class="inline"') . '<input name="note" placeholder="Rad sababi" required style="width:160px"> <button class="btn danger">Rad etish</button></form></div>';
        }
        $h .= '</div>';
    }
    $h .= '</div><div class="card"><h3>Bekor qilishlar va cheklar</h3>';
    foreach ($cancels as $c) {
        $h .= '<div class="kv"><span>' . e($c['created_at']) . ' · ' . e($c['name']) . ' ×' . (int)$c['qty'] . ' · ' . e($c['emp']) . '</span><b>' . e($c['reason']) . '</b></div>';
    }
    foreach ($prints as $p) {
        $h .= '<div class="kv"><span>' . e($p['created_at']) . ' · ' . e(['kitchen' => 'Oshxona', 'customer' => 'Mijoz', 'cancel' => 'Bekor', 'zreport' => 'Z-hisobot', 'test' => 'Test', 'report' => 'Hisobot'][$p['kind']]) . '</span><b>'
            . e(['pending' => 'Kutilmoqda', 'sending' => 'Yuborilmoqda', 'sent' => 'Yuborildi', 'error' => 'Xato', 'unknown' => "Noma'lum", 'reprinted' => 'Qayta chiqarildi'][$p['status']]) . ($p['error'] ? ' · ' . e($p['error']) : '') . '</b></div>';
    }
    if (!$cancels && !$prints) {
        $h .= '<p class="muted">Yo\'q</p>';
    }
    $h .= '</div></div>';
    layout('orders', 'Buyurtma ' . $o['number'], $h, $me);
}

// ===================== Mahsulotlar =====================
function page_products(array $me): void
{
    $cats = rows('SELECT * FROM categories ORDER BY sort, id');
    $catOpt = array_column($cats, 'name', 'id');
    $show = (string)($_GET['show'] ?? 'active');
    $list = rows('SELECT p.*, c.name AS cat FROM products p JOIN categories c ON c.id = p.category_id ' . ($show === 'all' ? '' : 'WHERE p.is_active = 1') . ' ORDER BY c.sort, p.sort, p.id');
    $edit = isset($_GET['edit']) ? row('SELECT * FROM products WHERE id = ?', [(int)$_GET['edit']]) : null;
    $e = $edit ?? ['id' => 0, 'category_id' => array_key_first($catOpt), 'name' => '', 'description' => '', 'price' => '', 'sort' => 0, 'is_active' => 1, 'in_pos' => 1, 'in_bot' => 1, 'is_available' => 1, 'image' => null];
    $h = '<div class="grid2 wide-left"><div class="card"><div class="row-between"><h3>Mahsulotlar (' . count($list) . ')</h3><span><a class="small" href="' . url('products', ['show' => $show === 'all' ? 'active' : 'all']) . '">' . ($show === 'all' ? 'Faqat faollar' : 'Yashirinlarni ham ko\'rsatish') . '</a></span></div><div class="tw"><table class="t"><tr><th></th><th>Nomi</th><th>Kategoriya</th><th class="r">Narx</th><th>Kassa/Bot</th><th>Holat</th><th></th></tr>';
    foreach ($list as $p) {
        $img = $p['image'] ? '<img class="thumb" src="../' . e(is_file(ROOT . '/uploads/products/thumb/' . basename($p['image'])) ? 'uploads/products/thumb/' . basename($p['image']) : $p['image']) . '" loading="lazy" alt="">' : '<div class="thumb"></div>';
        $h .= '<tr' . ($p['is_active'] ? '' : ' class="dim"') . '><td>' . $img . '</td><td><b>' . e($p['name']) . '</b></td><td>' . e($p['cat']) . '</td><td class="r">' . nf($p['price']) . '</td><td>' . ($p['in_pos'] ? '🧮' : '') . ' ' . ($p['in_bot'] ? '✈️' : '') . '</td><td>'
            . action_btn('product.toggle', ['id' => $p['id']], $p['is_available'] ? '<span class="badge green">Bor</span>' : '<span class="badge red">Tugagan</span>', 'linkbtn') . '</td><td class="r"><a class="btn sm" href="' . url('products', ['edit' => $p['id']]) . '">✎</a></td></tr>';
    }
    $h .= '</table></div></div><div class="card sticky"><h3>' . ($edit ? 'Tahrirlash' : 'Yangi mahsulot') . '</h3>'
        . form_open('product.save', ['id' => $e['id']], 'enctype="multipart/form-data"')
        . field('Nomi', 'name', $e['name'], 'text', 'required maxlength="150"') . select_field('Kategoriya', 'category_id', $catOpt, $e['category_id'])
        . '<div class="row2">' . field("Narx (so'm)", 'price', $e['price'], 'number', 'required min="0"') . field('Tartib', 'sort', $e['sort'], 'number') . '</div>'
        . field('Tavsif (ixtiyoriy)', 'description', $e['description'], 'text', 'maxlength="255"')
        . ($e['image'] ? '<img class="preview" src="../' . e($e['image']) . '" alt="">' : '') . field('Rasm (JPG/PNG/WEBP)', 'image', '', 'file', 'accept="image/*"')
        . field('Mavjud (tugamagan)', 'is_available', $e['is_available'], 'checkbox') . field('Kassada ko\'rinsin', 'in_pos', $e['in_pos'], 'checkbox') . field('Telegram botda ko\'rinsin', 'in_bot', $e['in_bot'], 'checkbox') . field('Faol', 'is_active', $e['is_active'], 'checkbox')
        . '<button class="btn primary w100">Saqlash</button></form>'
        . ($edit ? '<div class="btns" style="margin-top:8px"><a class="btn" href="' . url('products') . '">Bekor</a>' . action_btn('product.hide', ['id' => $e['id']], 'Yashirish', 'btn danger', 'Mahsulot yashirilsinmi? (Tarixdagi buyurtmalar saqlanadi)') . '</div>' : '') . '</div></div>';
    layout('products', 'Mahsulotlar', $h, $me);
}

function page_categories(array $me): void
{
    $list = rows('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS n FROM categories c ORDER BY sort, id');
    $edit = isset($_GET['edit']) ? row('SELECT * FROM categories WHERE id = ?', [(int)$_GET['edit']]) : null;
    $e = $edit ?? ['id' => 0, 'name' => '', 'icon' => '🍔', 'sort' => count($list) + 1, 'is_active' => 1];
    $h = '<div class="grid2 wide-left"><div class="card"><table class="t"><tr><th>Ikonka</th><th>Nomi</th><th>Mahsulotlar</th><th>Tartib</th><th>Holat</th><th></th></tr>';
    foreach ($list as $c) {
        $h .= '<tr' . ($c['is_active'] ? '' : ' class="dim"') . '><td style="font-size:22px">' . e($c['icon']) . '</td><td><b>' . e($c['name']) . '</b></td><td>' . (int)$c['n'] . '</td><td>' . (int)$c['sort'] . '</td><td>' . ($c['is_active'] ? '<span class="badge green">Faol</span>' : '<span class="badge gray">Yashirin</span>') . '</td><td class="r"><a class="btn sm" href="' . url('categories', ['edit' => $c['id']]) . '">✎</a></td></tr>';
    }
    $h .= '</table></div><div class="card"><h3>' . ($edit ? 'Tahrirlash' : 'Yangi kategoriya') . '</h3>' . form_open('category.save', ['id' => $e['id']])
        . field('Nomi', 'name', $e['name'], 'text', 'required') . '<div class="row2">' . field('Ikonka (emoji)', 'icon', $e['icon'], 'text', 'maxlength="8"') . field('Tartib', 'sort', $e['sort'], 'number') . '</div>'
        . field('Faol', 'is_active', $e['is_active'], 'checkbox') . '<button class="btn primary w100">Saqlash</button></form></div></div>';
    layout('categories', 'Kategoriyalar', $h, $me);
}

function page_tables(array $me): void
{
    $list = rows('SELECT * FROM dining_tables ORDER BY sort, id');
    $open = [];
    foreach (rows("SELECT table_id, COUNT(*) n, SUM(total) s FROM orders WHERE type = 'table' AND status = 'accepted' GROUP BY table_id") as $r) {
        $open[(int)$r['table_id']] = $r;
    }
    $edit = isset($_GET['edit']) ? row('SELECT * FROM dining_tables WHERE id = ?', [(int)$_GET['edit']]) : null;
    $e = $edit ?? ['id' => 0, 'name' => 'Stol ' . (count($list) + 1), 'sort' => count($list) + 1, 'is_active' => 1];
    $h = '<div class="grid2 wide-left"><div class="card"><p class="muted small">Stol holati kassadagi oxirgi sinxronlashdan olinadi.</p><div class="tables">';
    foreach ($list as $t) {
        $o = $open[(int)$t['id']] ?? null;
        $h .= '<a class="tcard ' . ($o ? 'busy' : '') . ($t['is_active'] ? '' : ' dim') . '" href="' . url('tables', ['edit' => $t['id']]) . '"><b>' . e($t['name']) . '</b><span>' . ($o ? $o['n'] . ' ta hisob · ' . nf($o['s']) : "Bo'sh") . '</span></a>';
    }
    $h .= '</div></div><div class="card"><h3>' . ($edit ? 'Tahrirlash' : 'Yangi stol') . '</h3>' . form_open('table.save', ['id' => $e['id']]) . field('Nomi', 'name', $e['name'], 'text', 'required')
        . field('Tartib', 'sort', $e['sort'], 'number') . field('Faol', 'is_active', $e['is_active'], 'checkbox') . '<button class="btn primary w100">Saqlash</button></form><hr>'
        . form_open('table.bulk') . field("Bir nechta stol qo'shish", 'count', 5, 'number', 'min="1" max="100"') . '<button class="btn w100">Qo\'shish</button></form></div></div>';
    layout('tables', 'Stollar', $h, $me);
}

function page_employees(array $me): void
{
    $list = rows('SELECT e.*, u.username, u.last_login_at FROM employees e LEFT JOIN users u ON u.employee_id = e.id ORDER BY e.is_active DESC, e.id');
    $edit = isset($_GET['edit']) ? row('SELECT e.*, u.username FROM employees e LEFT JOIN users u ON u.employee_id = e.id WHERE e.id = ?', [(int)$_GET['edit']]) : null;
    $e = $edit ?? ['id' => 0, 'name' => '', 'role' => 'cashier', 'is_active' => 1, 'username' => ''];
    $h = '<div class="grid2 wide-left"><div class="card"><table class="t"><tr><th>Ism</th><th>Rol</th><th>Admin login</th><th>Holat</th><th></th></tr>';
    foreach ($list as $x) {
        $h .= '<tr' . ($x['is_active'] ? '' : ' class="dim"') . '><td><b>' . e($x['name']) . '</b></td><td>' . ($x['role'] === 'admin' ? 'Administrator' : 'Kassir') . '</td><td>' . e($x['username'] ?? '—') . '</td><td>' . ($x['is_active'] ? '<span class="badge green">Faol</span>' : '<span class="badge gray">O\'chirilgan</span>') . '</td><td class="r"><a class="btn sm" href="' . url('employees', ['edit' => $x['id']]) . '">✎</a></td></tr>';
    }
    $h .= '</table><p class="muted small">Kassir kassaga faqat 4 xonali PIN bilan kiradi. PIN bazada ochiq holda saqlanmaydi (PBKDF2 xesh). Xodim o\'chirilmaydi — "faol emas" qilinadi, tarix saqlanadi.</p></div>'
        . '<div class="card"><h3>' . ($edit ? 'Tahrirlash' : 'Yangi xodim') . '</h3>' . form_open('employee.save', ['id' => $e['id']])
        . field('Ism', 'name', $e['name'], 'text', 'required') . select_field('Rol', 'role', ['cashier' => 'Kassir', 'admin' => 'Administrator'], $e['role'])
        . field($edit ? 'Yangi PIN (o\'zgartirmasangiz bo\'sh qoldiring)' : 'PIN (4 raqam)', 'pin', '', 'text', 'inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off"' . ($edit ? '' : ' required'))
        . '<div class="admin-only"><p class="muted small">Administrator uchun admin panel logini:</p>' . field('Login', 'username', $e['username'] ?? '', 'text', 'autocomplete="off"') . field($edit ? 'Yangi parol (bo\'sh — o\'zgarmaydi)' : 'Parol (kamida 8 belgi)', 'password', '', 'password', 'autocomplete="new-password"') . '</div>'
        . field('Faol', 'is_active', $e['is_active'], 'checkbox') . '<button class="btn primary w100">Saqlash</button></form></div></div>';
    layout('employees', 'Kassir va administrator', $h, $me);
}

// ===================== Smenalar =====================
function page_shifts(array $me): void
{
    $list = rows('SELECT s.*, e.name AS emp FROM shifts s JOIN employees e ON e.id = s.employee_id ORDER BY s.id DESC LIMIT 100');
    $h = '<div class="card"><div class="tw"><table class="t"><tr><th>№</th><th>Kassir</th><th>Ochildi</th><th>Yopildi</th><th class="r">Jami savdo</th><th class="r">Naqd</th><th class="r">Karta</th><th class="r">Buyurtmalar</th><th>Holat</th></tr>';
    foreach ($list as $s) {
        $t = shift_totals((int)$s['id']);
        $h .= '<tr class="click" data-href="' . e(url('shift_view', ['id' => $s['id']])) . '"><td><b>#' . (int)$s['number'] . '</b></td><td>' . e($s['emp']) . '</td><td>' . e($s['opened_at']) . '</td><td>' . e($s['closed_at'] ?? '—') . '</td><td class="r">' . nf($t['total']) . '</td><td class="r">' . nf($t['cash']) . '</td><td class="r">' . nf($t['card']) . '</td><td class="r">' . $t['orders'] . '</td><td>' . badge($s['status']) . '</td></tr>';
    }
    $h .= '</table></div><p class="muted small">Yopilgan smena qayta ochilmaydi. Ma\'lumot kassa sinxronlashidan keyin ko\'rinadi.</p></div>';
    layout('shifts', 'Smenalar', $h, $me);
}

function page_shift_view(array $me): void
{
    $r = report_shift((int)($_GET['id'] ?? 0));
    $s = $r['shift'];
    $diff = $r['counted_cash'] === null ? null : $r['counted_cash'] - $r['expected_cash'];
    $h = '<p><a href="' . url('shifts') . '">← Smenalar</a></p><div class="stats">'
        . stat_card('Jami savdo', nf($r['sales'])) . stat_card('Naqd', nf($r['payments']['cash'])) . stat_card('Terminal', nf($r['payments']['terminal'])) . stat_card('Click', nf($r['payments']['click']))
        . stat_card('Onlayn (karta/Hamyon)', nf($r['payments']['card_transfer'] + $r['payments']['hamyon'])) . stat_card('Buyurtmalar', (string)$r['orders']) . stat_card('Bekor qilishlar', $r['cancels_count'] . ' · ' . nf($r['cancels_total'])) . '</div>';
    $h .= '<div class="grid2"><div class="card"><h3>Smena #' . (int)$s['number'] . ' ' . badge($s['status']) . '</h3>';
    foreach (['Kassir' => $s['employee'], 'Ochildi' => $s['opened_at'], 'Yopildi' => $s['closed_at'] ?? '—', "Boshlang'ich naqd" => nf($s['opening_cash']), 'Hisoblangan naqd' => nf($r['expected_cash']),
        'Sanalgan naqd' => $r['counted_cash'] === null ? '—' : nf($r['counted_cash']), 'Farq' => $diff === null ? '—' : ($diff > 0 ? '+' : '') . nf($diff)] as $k => $v) {
        $h .= '<div class="kv"><span>' . e($k) . '</span><b>' . e($v) . '</b></div>';
    }
    $h .= '</div><div class="card"><h3>Mahsulotlar</h3><table class="t">';
    foreach ($r['top'] as $t) {
        $h .= '<tr><td>' . e($t['name']) . '</td><td class="r">' . (int)$t['qty'] . '</td><td class="r">' . nf($t['total']) . '</td></tr>';
    }
    $h .= '</table></div></div><div class="card"><h3>Buyurtmalar</h3><table class="t"><tr><th>Raqam</th><th>Vaqt</th><th>Turi</th><th>Holat</th><th class="r">Jami</th></tr>';
    foreach ($r['list'] as $o) {
        $h .= '<tr><td>' . e($o['number']) . '</td><td>' . e($o['created_at']) . '</td><td>' . e(ORDER_TYPES[$o['type']] ?? '') . '</td><td>' . badge($o['status']) . '</td><td class="r">' . nf($o['total']) . '</td></tr>';
    }
    $h .= '</table></div>';
    layout('shifts', 'Smena #' . $s['number'], $h, $me);
}

// ===================== Hisobotlar =====================
function page_reports(array $me): void
{
    $preset = (string)($_GET['preset'] ?? '');
    $from = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d');
    $to = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
    if ($preset === 'week') {
        [$from, $to] = [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')];
    } elseif ($preset === 'month') {
        [$from, $to] = [date('Y-m-01'), date('Y-m-d')];
    }
    $r = report_period($from, $to);
    $pm = $r['payments'];
    $h = '<form class="filters card" method="get"><input type="hidden" name="p" value="reports"><div class="field"><label>Dan</label><input type="date" name="from" value="' . e($from) . '"></div><div class="field"><label>Gacha</label><input type="date" name="to" value="' . e($to) . '"></div><button class="btn primary">Ko\'rsatish</button>'
        . '<a class="btn" href="' . url('reports') . '">Bugun</a><a class="btn" href="' . url('reports', ['preset' => 'week']) . '">7 kun</a><a class="btn" href="' . url('reports', ['preset' => 'month']) . '">Shu oy</a><a class="btn" href="' . e(url('download', ['f' => 'report', 'from' => $from, 'to' => $to])) . '">⬇ CSV (Excel)</a></form>';
    $h .= '<div class="stats">' . stat_card('Savdo', nf($r['total'])) . stat_card('Buyurtmalar', (string)$r['orders']) . stat_card("O'rtacha chek", nf($r['avg_check']))
        . stat_card('Naqd', nf($pm['cash'] ?? 0)) . stat_card('Terminal', nf($pm['terminal'] ?? 0)) . stat_card('Click', nf($pm['click'] ?? 0)) . stat_card('Onlayn (karta + Hamyon)', nf(($pm['card_transfer'] ?? 0) + ($pm['hamyon'] ?? 0)))
        . stat_card('Bekor/rad etilgan', (string)$r['cancelled_orders']) . '</div>';
    $tbl = function (string $title, array $rows, array $cols) {
        $h = '<div class="card"><h3>' . e($title) . '</h3><table class="t"><tr>';
        foreach ($cols as $c) {
            $h .= '<th' . ($c[2] ?? '' ? ' class="r"' : '') . '>' . e($c[0]) . '</th>';
        }
        $h .= '</tr>';
        foreach ($rows as $x) {
            $h .= '<tr>';
            foreach ($cols as $c) {
                $v = $x[$c[1]] ?? '';
                $h .= '<td' . (($c[2] ?? '') ? ' class="r"' : '') . '>' . (($c[2] ?? '') === 'n' ? nf($v) : e($v)) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . (!$rows ? '<tr><td class="muted">Ma\'lumot yo\'q</td></tr>' : '') . '</table></div>';
    };
    $types = array_map(fn($x) => ['name' => ORDER_TYPES[$x['type']] ?? $x['type'], 'cnt' => $x['cnt'], 'total' => $x['total']], $r['by_type']);
    $src = array_map(fn($x) => ['name' => $x['source'] === 'telegram' ? 'Telegram' : 'Kassa', 'cnt' => $x['cnt'], 'total' => $x['total']], $r['by_source']);
    $h .= '<div class="grid2">' . $tbl('Kunlar bo\'yicha', $r['by_day'], [['Sana', 'd'], ['Buyurtma', 'cnt', 'r'], ['Savdo', 'total', 'n']])
        . $tbl('Eng ko\'p sotilganlar', $r['top_products'], [['Mahsulot', 'name'], ['Soni', 'qty', 'r'], ['Summa', 'total', 'n']]) . '</div>'
        . '<div class="grid2">' . $tbl('Kassirlar bo\'yicha', $r['by_cashier'], [['Kassir', 'name'], ['Buyurtma', 'cnt', 'r'], ['Savdo', 'total', 'n']])
        . $tbl('Turlar va manbalar', array_merge($types, $src), [['Nomi', 'name'], ['Buyurtma', 'cnt', 'r'], ['Savdo', 'total', 'n']]) . '</div>'
        . $tbl('Bekor qilingan taomlar', $r['cancels'], [['Vaqt', 'created_at'], ['Buyurtma', 'number'], ['Taom', 'name'], ['Soni', 'qty', 'r'], ['Sabab', 'reason'], ['Kassir', 'employee']]);
    layout('reports', 'Savdo hisobotlari', $h, $me);
}

// ===================== To'lovlar =====================
function page_payments(array $me): void
{
    $cards = cards_list();
    $cards[] = ['number' => '', 'holder' => '', 'bank' => ''];
    $h = form_open('settings.payments') . '<div class="grid2"><div class="card"><h3>Kassa (POS) to\'lov usullari</h3>'
        . field('Naqd', 'pos_pay_cash', setting_on('pos_pay_cash'), 'checkbox') . field('Terminal (plastik karta)', 'pos_pay_terminal', setting_on('pos_pay_terminal'), 'checkbox') . field('Click', 'pos_pay_click', setting_on('pos_pay_click'), 'checkbox')
        . '<h3 style="margin-top:18px">Telegram bot to\'lov usullari</h3>' . field('Naqd pul', 'pay_cash_bot', setting_on('pay_cash_bot'), 'checkbox')
        . field("Kartaga o'tkazma (mijoz chek rasmini yuboradi)", 'pay_card_bot', setting_on('pay_card_bot'), 'checkbox')
        . field("Hamyon (avtomatik to'lov)", 'pay_hamyon', setting_on('pay_hamyon'), 'checkbox') . '</div>'
        . '<div class="card"><h3>Karta raqamlari (kartaga o\'tkazma uchun)</h3><div id="cards">';
    foreach ($cards as $c) {
        $h .= '<div class="row3"><input name="card_number[]" placeholder="8600 0000 0000 0000" value="' . e($c['number']) . '" inputmode="numeric"><input name="card_holder[]" placeholder="Egasining ismi" value="' . e($c['holder'] ?? '') . '"><input name="card_bank[]" placeholder="Bank (ixtiyoriy)" value="' . e($c['bank'] ?? '') . '"></div>';
    }
    $h .= '</div><p class="muted small">Karta raqamini o\'chirish uchun maydonni bo\'sh qoldiring. Yangisini qo\'shish uchun oxirgi bo\'sh qatorni to\'ldiring.</p>'
        . '<h3 style="margin-top:18px">Hamyon (hamyon-api.uz)</h3>' . field('Shop ID', 'hamyon_shop_id', setting('hamyon_shop_id'))
        . field('Shop key', 'hamyon_shop_key', '', 'password', 'autocomplete="new-password" placeholder="' . e(setting('hamyon_shop_key') ? 'Saqlangan: ' . mask_secret(setting('hamyon_shop_key')) . ' (o\'zgartirish uchun yangisini kiriting)' : 'Kiritilmagan') . '"')
        . field('API manzili', 'hamyon_api_url', setting('hamyon_api_url'))
        . '<div class="note">Hamyon kabinetida <b>prepare_url</b> va <b>complete_url</b> sifatida shu manzilni kiriting:<br><code>' . e(app_url('pay/hamyon.php')) . '</code></div></div></div>'
        . '<button class="btn primary">Saqlash</button></form><div style="margin-top:10px">' . action_btn('hamyon.test', [], '🔌 Hamyon ulanishini tekshirish', 'btn') . '</div>';
    layout('payments', "To'lovlar", $h, $me);
}

// ===================== Telegram =====================
function page_telegram(array $me): void
{
    $tok = setting('bot_token');
    $uname = setting('bot_username');
    $wh = null;
    if ($tok !== '') {
        $r = tg_api('getWebhookInfo', [], 5);
        $wh = $r['result'] ?? ['error' => $GLOBALS['TG_ERR'] ?? ''];
    }
    $h = '<div class="grid2"><div class="card">' . form_open('settings.telegram') . '<h3>Bot sozlamalari</h3>'
        . field('Bot tokeni (@BotFather)', 'bot_token', '', 'password', 'autocomplete="new-password" placeholder="' . e($tok ? 'Saqlangan: ' . mask_secret($tok) . ' — o\'zgartirish uchun yangisini kiriting' : '123456789:AA...') . '"', 'Token ekranda ochiq ko\'rsatilmaydi.')
        . field('Admin Telegram ID lari (vergul bilan)', 'admin_tg_ids', setting('admin_tg_ids'), 'text', '', 'Yangi buyurtma xabari shu ID larga boradi. ID ni @userinfobot dan bilib oling.')
        . field('Botni yoqish (buyurtma qabul qilish)', 'bot_enabled', setting_on('bot_enabled'), 'checkbox', '', "O'chirilsa ham kassa va admin panel ishlayveradi.")
        . field('Olib ketish', 'bot_pickup', setting_on('bot_pickup'), 'checkbox') . field('Yetkazib berish', 'bot_delivery', setting_on('bot_delivery'), 'checkbox')
        . '<div class="row2">' . field("Yetkazish narxi (so'm)", 'delivery_fee', setting('delivery_fee'), 'number', 'min="0"') . field("Minimal buyurtma (so'm)", 'min_order', setting('min_order'), 'number', 'min="0"') . '</div>'
        . '<div class="row2">' . field('Ish boshlanishi', 'work_open', setting('work_open'), 'time') . field('Ish tugashi', 'work_close', setting('work_close'), 'time') . '</div>'
        . field("Bog'lanish matni", 'contact_text', setting('contact_text'), 'textarea', 'rows="3"')
        . '<button class="btn primary w100">Saqlash</button></form></div><div class="card"><h3>Holat</h3>'
        . '<div class="kv"><span>Bot</span><b>' . ($uname ? '<a href="https://t.me/' . e($uname) . '" target="_blank">@' . e($uname) . '</a>' : ($tok ? 'Token bor' : 'Ulanmagan')) . '</b></div>'
        . '<div class="kv"><span>Buyurtma qabul qilish</span><b>' . (setting_on('bot_enabled') ? '<span class="badge green">Yoqilgan</span>' : '<span class="badge gray">O\'chiq</span>') . '</b></div>';
    if ($wh) {
        $ok = ($wh['url'] ?? '') === app_url('telegram/webhook.php');
        $h .= '<div class="kv"><span>Webhook</span><b>' . ($ok ? badge('ok') : badge('error')) . '</b></div><div class="kv"><span>Manzil</span><b class="small">' . e($wh['url'] ?? ($wh['error'] ?? '')) . '</b></div>'
            . '<div class="kv"><span>Navbatdagi xabarlar</span><b>' . (int)($wh['pending_update_count'] ?? 0) . '</b></div>'
            . (!empty($wh['last_error_message']) ? '<div class="kv"><span>Oxirgi xato</span><b class="small">' . e(date('d.m H:i', (int)$wh['last_error_date'])) . ' — ' . e($wh['last_error_message']) . '</b></div>' : '');
    }
    $h .= '<div class="kv"><span>WebApp</span><b class="small">' . e(tg_webapp_url()) . '</b></div><div class="btns" style="margin-top:12px">'
        . action_btn('telegram.webhook', [], "↻ Webhookni qayta o'rnatish", 'btn') . action_btn('telegram.test', [], '🔌 Ulanishni tekshirish', 'btn') . '</div></div></div>';
    layout('telegram', 'Telegram bot', $h, $me);
}

// ===================== POS va printer =====================
function page_pos(array $me): void
{
    $devices = rows('SELECT * FROM devices ORDER BY id DESC');
    $h = '<div class="grid2"><div class="card">' . form_open('settings.pos') . '<h3>Chek sozlamalari</h3>'
        . field("Do'kon nomi (chek sarlavhasi)", 'brand_name', setting('brand_name')) . '<div class="row2">' . field('Telefon', 'brand_phone', setting('brand_phone')) . field('Manzil', 'brand_address', setting('brand_address')) . '</div>'
        . field('Chek oxiridagi matn', 'receipt_footer', setting('receipt_footer'))
        . select_field("Standart chek o'lchami", 'paper_width', ['80' => '80 mm', '58' => '58 mm'], setting('paper_width'))
        . field('Buyurtma tasdiqlanganda oshxona chekini avtomatik chiqarish', 'kitchen_auto', setting_on('kitchen_auto'), 'checkbox')
        . field("To'lovda mijoz chekini avtomatik chiqarish", 'customer_auto', setting_on('customer_auto'), 'checkbox')
        . '<div class="row2">' . field('Oshxona cheki nusxasi', 'kitchen_copies', setting('kitchen_copies'), 'number', 'min="1" max="3"') . field('Mijoz cheki nusxasi', 'customer_copies', setting('customer_copies'), 'number', 'min="1" max="3"') . '</div>'
        . '<button class="btn primary w100">Saqlash</button></form></div>';
    $h .= '<div class="card"><h3>Monoblokka o\'rnatish</h3><ol class="steps"><li>Monoblokda shu sahifani oching va <b>kassa-ornatish.bat</b> ni yuklab oling.</li><li>Faylni ikki marta bosing. Windows ogohlantirsa: «Подробнее» → «Выполнить в любом случае».</li>'
        . '<li>O\'rnatuvchi printer xizmatini o\'rnatadi, avtozapuskka qo\'yadi va ish stolida <b>ADI BURGER POS</b> yorlig\'ini yaratadi.</li><li>Kassada ⚙ Sozlamalar → Printer: Xprinter IP manzili, port (9100) va chek o\'lchamini kiriting → Saqlash → Test chek.</li></ol>'
        . '<a class="btn primary" href="' . e(url('download', ['f' => 'bat'])) . '">⬇ kassa-ornatish.bat</a> <a class="btn" href="../windows/adi-printer.exe">⬇ adi-printer.exe</a>'
        . '<p class="muted small">Kassa Chrome App Mode (PWA) oynasida ochiladi — manzil satri va tablar ko\'rinmaydi. Bu Chrome oynasi, mustaqil .exe dastur emas. Printer xizmati (adi-printer.exe) — alohida kichik Windows dasturi.</p>'
        . '<h3 style="margin-top:16px">Shu kompyuterdagi printer xizmati</h3><div id="local-agent" class="note">Tekshirilmoqda…</div></div></div>';
    $h .= '<div class="card"><h3>Kassa qurilmalari</h3><table class="t"><tr><th>Nomi</th><th>Oxirgi aloqa</th><th>Versiya</th><th>Printer</th><th>Holat</th><th></th></tr>';
    foreach ($devices as $d) {
        $st = json_decode((string)$d['status_json'], true) ?: [];
        $pr = $st['printer'] ?? null;
        $h .= '<tr><td><b>' . e($d['name']) . '</b></td><td>' . e($d['last_seen_at'] ?? '—') . '</td><td>' . e($d['app_version'] ?? '') . '</td><td>'
            . ($pr ? (!empty($pr['ok']) ? '<span class="badge green">' . e($pr['target'] ?? 'OK') . '</span>' : '<span class="badge red" title="' . e($pr['error'] ?? '') . '">Xato</span>') : '<span class="badge gray">Noma\'lum</span>') . '</td><td>'
            . ($d['is_active'] ? '<span class="badge green">Faol</span>' : '<span class="badge gray">Uzilgan</span>') . '</td><td class="r">' . action_btn('device.toggle', ['id' => $d['id']], $d['is_active'] ? 'Uzish' : 'Qayta yoqish', 'btn sm ' . ($d['is_active'] ? 'danger' : ''), $d['is_active'] ? 'Qurilma uzilsinmi? U serverga ma\'lumot yubora olmaydi.' : '') . '</td></tr>';
    }
    $h .= '</table></div>';
    layout('pos', 'POS va printer', $h, $me);
}

// ===================== Diagnostika =====================
function page_diagnostics(array $me): void
{
    $checks = [];
    foreach (rows('SELECT * FROM diagnostic_checks') as $c) {
        $checks[$c['code']] = $c;
    }
    $errors = rows("SELECT * FROM error_logs ORDER BY (fix_status = 'open') DESC, last_seen_at DESC LIMIT 100");
    $cnt = ['ok' => 0, 'warn' => 0, 'error' => 0, 'unchecked' => 0];
    foreach (DIAG_TITLES as $code => $t) {
        $cnt[$checks[$code]['status'] ?? 'unchecked']++;
    }
    $last = $checks ? max(array_column($checks, 'checked_at')) : null;
    $h = '<div class="card diag-head"><div class="stats mini">' . stat_card('Ishlayapti', '<span class="c-green">' . $cnt['ok'] . '</span>') . stat_card('Ogohlantirish', '<span class="c-yellow">' . $cnt['warn'] . '</span>')
        . stat_card('Xato', '<span class="c-red">' . $cnt['error'] . '</span>') . stat_card('Tekshirilmagan', '<span class="c-gray">' . $cnt['unchecked'] . '</span>') . '</div>'
        . '<div class="btns">' . action_btn('diag.run', [], '🩺 Barchasini tekshirish', 'btn primary') . '<a class="btn" href="' . e(url('download', ['f' => 'diag'])) . '">⬇ Hisobotni yuklab olish</a>'
        . '<button class="btn" id="agent-test">🖨 Test chek (shu kompyuterdan)</button></div><p class="muted small">Oxirgi tekshiruv: ' . e($last ?? 'hali yo\'q') . '. Tizim faqat haqiqatan tekshirilgan narsani "Ishlayapti" deydi.</p>'
        . '<div id="local-agent" class="note">Shu kompyuterdagi printer xizmati tekshirilmoqda…</div></div>';
    $h .= '<div class="diag-grid">';
    foreach (DIAG_TITLES as $code => $title) {
        $c = $checks[$code] ?? null;
        $st = $c['status'] ?? 'unchecked';
        $fix = DIAG_FIXES[$code] ?? null;
        $h .= '<div class="dcard s-' . $st . '"><div class="dh"><b>' . e($title) . '</b>' . badge($st) . '</div><div class="dm">' . e($c['message'] ?? 'Hali tekshirilmagan') . '</div>'
            . (!empty($c['advice']) && $st !== 'ok' ? '<div class="da">💡 ' . e($c['advice']) . '</div>' : '')
            . '<div class="db">' . action_btn('diag.run', ['code' => $code], '↻ Qayta tekshirish', 'btn sm')
            . (!empty($c['details']) ? '<details><summary class="btn sm">Xatoni ko\'rish</summary><pre>' . e($c['details']) . '</pre></details>' : '')
            . ($fix && $st !== 'ok' ? action_btn('diag.fix', ['code' => $code, 'confirmed' => $fix['confirm'] ? '1' : '0'], '🔧 Tuzatish: ' . e($fix['label']), 'btn sm primary', $fix['confirm'] ? 'Bu amal muhim sozlamani o\'zgartiradi. Davom etasizmi?' : '') : '')
            . ($code === 'sync' ? action_btn('diag.fix', ['code' => 'sync', 'confirmed' => '0'], '⇅ Sinxronlashni tekshirish', 'btn sm') : '')
            . ($code === 'webhook' ? action_btn('telegram.webhook', [], '✈️ Webhookni tekshirish', 'btn sm') : '')
            . '</div><div class="dt">' . e($c['checked_at'] ?? '') . '</div></div>';
    }
    $h .= '</div><div class="card"><h3>Xatolar tarixi</h3><div class="tw"><table class="t"><tr><th>Vaqt</th><th>Komponent</th><th>Kod</th><th>Izoh</th><th>Tavsiya</th><th>Soni</th><th>Holat</th><th></th></tr>';
    foreach ($errors as $er) {
        $h .= '<tr' . ($er['fix_status'] !== 'open' ? ' class="dim"' : '') . '><td class="nowrap">' . e($er['last_seen_at']) . '</td><td>' . e($er['component']) . '</td><td><code>' . e($er['code']) . '</code></td><td>' . e($er['message'])
            . ($er['details'] ? '<details><summary class="small">Texnik tafsilot</summary><pre>' . e($er['details']) . '</pre></details>' : '') . '</td><td class="small">' . e($er['advice']) . '</td><td>' . (int)$er['occurrences'] . '</td><td>' . badge($er['fix_status']) . '</td><td class="nowrap">'
            . ($er['fix_status'] === 'open' ? action_btn('error.resolve', ['id' => $er['id'], 'to' => 'fixed'], '✓', 'btn sm') . action_btn('error.resolve', ['id' => $er['id'], 'to' => 'ignored'], '✕', 'btn sm') : '') . '</td></tr>';
    }
    if (!$errors) {
        $h .= '<tr><td colspan="8" class="muted">Xato qayd etilmagan.</td></tr>';
    }
    $h .= '</table></div><p class="muted small">Barcha xatolarni avtomatik tuzatib bo\'lmaydi: internet kabeli, printer buzilishi, qog\'oz tugashi yoki hosting to\'xtashi — qo\'lda hal qilinadi. Tavsiyaga amal qiling. Loglarda token va parollar yashiriladi.</p></div>';
    layout('diagnostics', "Tizim diagnostikasi va ta'mirlash", $h, $me);
}

// ===================== Zaxira =====================
function page_backup(array $me): void
{
    $list = backup_list();
    $h = '<div class="card"><div class="btns">' . action_btn('backup.create', [], '💾 Hozir zaxira olish', 'btn primary') . '</div><p class="muted small">Kassa ishlab tursa, zaxira har kuni avtomatik olinadi. Oxirgi 14 ta nusxa saqlanadi. Fayllar internetdan yopiq papkada turadi.</p>'
        . '<table class="t"><tr><th>Fayl</th><th>Sana</th><th class="r">Hajmi</th><th></th></tr>';
    foreach ($list as $b) {
        $h .= '<tr><td><code>' . e($b['name']) . '</code></td><td>' . date('d.m.Y H:i', $b['time']) . '</td><td class="r">' . round($b['size'] / 1024) . ' KB</td><td class="r"><a class="btn sm" href="' . e(url('download', ['f' => 'backup', 'name' => $b['name']])) . '">⬇ Yuklab olish</a></td></tr>';
    }
    if (!$list) {
        $h .= '<tr><td colspan="4" class="muted">Hali zaxira yo\'q</td></tr>';
    }
    $h .= '</table><p class="muted small">Tiklash: hosting panelidagi phpMyAdmin → Import orqali .sql.gz faylni yuklang (avval joriy bazaning zaxirasini oling).</p></div>';
    layout('backup', 'Zaxira', $h, $me);
}
