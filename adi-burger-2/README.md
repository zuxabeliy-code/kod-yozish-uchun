# ADI BURGER 2.0

Fast food uchun savdo tizimi. Tarkibi:
- kassa (Chrome PWA, offline ishlaydi);
- avtomatik chek chiqaradigan Windows printer xizmati;
- Telegram bot va WebApp;
- admin panel;
- diagnostika va ta'mirlash bo'limi.

PHP 8.2 + MySQL, oddiy shared hosting (myxvest.ru) uchun moslangan. Node.js, Redis, WebSocket, SSH va cron shart emas.

**O'rnatish:** `docs/ORNATISH.md` · **Test hisoboti:** `docs/TEST-HISOBOTI.md`

## Tuzilma

| Papka | Vazifasi |
|---|---|
| `install/` | Brauzer orqali o'rnatuvchi. O'rnatilgandan keyin bloklanadi |
| `pos/` | Kassa (PWA): `index.html`, `js/`, `css/`, `sw.js`, `manifest.json` |
| `admin/` | Admin panel. Chap tepadagi ☰ tugmasi barcha bo'limlarni ochadi |
| `api/` | Kassa API: qurilma tokeni, sinxronlash, katalog |
| `telegram/` | `webhook.php` (bot) va `webapp/` (mijoz menyusi) |
| `pay/hamyon.php` | Hamyon callback |
| `app/` | Server mantig'i. Internetdan yopiq |
| `database/` | `schema.sql` (21 jadval) va boshlang'ich menyu. Yopiq |
| `config/` | `config.php` — o'rnatuvchi yaratadi. Yopiq |
| `storage/` | Loglar, zaxiralar, kesh. Yopiq |
| `uploads/` | Mahsulot rasmlari (ochiq) va mijoz to'lov cheklari (yopiq) |
| `windows/` | `kassa-ornatish.bat`, `kassa-ochish.bat`, `adi-printer.exe` va uning manba kodi (`printer-agent/`, Go) |
| `tests/` | Server integratsiya testlari (faqat sinov bazasida) |
| `docs/` | Qo'llanma va test hisoboti |

Hosting odatda butun papkani hujjat ildizi (document root) qiladi. Shuning uchun maxfiy papkalar ikki qavat himoya bilan yopilgan:
1. har bir maxfiy papkada `.htaccess` (`Require all denied`);
2. har bir PHP fayl boshida `defined('ADI') || exit`.

Log fayllari `.log.php` bo'lib, birinchi qatori `exit` — shuning uchun brauzerda ochilmaydi.

## Chek qanday chiqadi (Chrome Print oynasisiz)

```
Kassa (Chrome) ──fetch──▶ 127.0.0.1:9123 printer xizmati ──TCP 9100 / ESC-POS──▶ Xprinter
               (Origin + token tekshiruvi)    (diskdagi navbat, bitta ishchi)
```

Printer sozlamalari (IP, port, 58/80 mm) kassadagi **⚙ → Printer** bo'limida kiritiladi va xizmatda saqlanadi. Xizmat Windows bilan birga ishga tushadi va printerga o'zi ulanadi.

Chek holatlari: Kutilmoqda · Yuborilmoqda · Yuborildi · Xato · Natija noma'lum · Qayta chiqarildi.

## Printer xizmatini qayta kompilyatsiya qilish (ixtiyoriy)

```
cd windows/printer-agent
GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w -H windowsgui" -o ../adi-printer.exe .
go test ./...
```
