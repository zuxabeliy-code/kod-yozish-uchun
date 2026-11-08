# ADI BURGER 2.0 — Test hisoboti

Sana: 2026-11-09 · Versiya: 2.0.0

**Sinov muhiti:**
- Linux, PHP 8.3, MariaDB 10.11.
- PHP built-in server va Apache 2.4 (.htaccess tekshiruvi uchun).
- Chromium (Playwright).
- Printer xizmati Linux uchun kompilyatsiya qilingan va **soxta ESC/POS LAN printer** (9100-port, qog'oz sensori taqlidi) bilan sinalgan.
- Hamyon API ham taqlid (mock) qilingan.

**Muhim:** haqiqiy Windows 10 monoblok, haqiqiy Xprinter va haqiqiy Telegram/Hamyon serverlarida sinov **o'tkazilmagan**. Ular pastda alohida "Kassada tekshirish kerak" bo'limida.

Belgilar: ✅ O'tdi · ❌ Xato · ⚪ Tekshirilmagan

## 1. Avtomatik va qo'lda (brauzer) testlar

| # | Test | Kutilgan natija | Haqiqiy natija | Holat |
|---|------|-----------------|----------------|-------|
| 1 | PHP sintaksisi | Hamma .php xatosiz | `php -l` — 0 xato | ✅ |
| 2 | MySQL migratsiyalari | 21 jadval, FK, qayta ishga tushirish xavfsiz | /install orqali yaratildi; diagnostika "21 ta jadval, sxema v1" | ✅ |
| 3 | Administrator login | Login/parol; 8 noto'g'ri urinishdan keyin blok | Kirdi; blok ishladi | ✅ |
| 4 | Kassir PIN | 4 raqam, offline tekshiruv, PIN ochiq saqlanmaydi | WebCrypto PBKDF2 = PHP xeshi; 5 xatodan keyin vaqtinchalik blok | ✅ |
| 5 | Mahsulot qo'shish | Admin → saqlanadi, kassaga boradi | "Test Burger" qo'shildi va kassada chiqdi | ✅ |
| 6 | Mahsulot o'chirish | Tarix buzilmasin | O'chirilmaydi, "yashirish" (is_active=0) | ✅ |
| 7 | Buyurtma tasdiqlash | Saqlanadi + "Buyurtma tasdiqlandi" + oshxona cheki | Ishladi, chek printerga keldi | ✅ |
| 8 | Oshxona cheki | Narxsiz, katta yozuv, turi/raqami/vaqti | Chek matni tekshirildi | ✅ |
| 9 | Mijoz cheki | Narxlar, jami, to'lov turi, qaytim | Naqd 130 000 → qaytim 2 000 | ✅ |
| 10 | Bekor qilish cheki | "BEKOR QILINDI", taom, soni, sabab, vaqt | Chiqdi; tarixda saqlandi | ✅ |
| 11 | Stol ochish | Stol band bo'ladi | "Stol 2" sariq, summa ko'rinadi | ✅ |
| 12 | Stolga mahsulot qo'shish | Oshxonaga faqat yangilari | "*** QO'SHIMCHA ***" chekida faqat 2 ta yangi taom | ✅ |
| 13 | Stolni yopish | To'lov → stol bo'shaydi | Ishladi | ✅ |
| 14 | Naqd to'lov | Summa va qaytim saqlanadi | payments: received/change to'g'ri | ✅ |
| 15 | Karta (terminal) to'lov | Terminal sifatida yoziladi | pay_method=card, paid | ✅ |
| 16 | Aralash to'lov | Har usul alohida | terminal 20 000 + naqd 55 000 (qaytim 5 000) | ✅ |
| 17 | Smena ochish | Boshlang'ich naqd bilan | Ishladi, serverga yetdi | ✅ |
| 18 | Smena yopish | Sanalgan naqd, farq, qayta ochilmaydi | Farq hisoblandi; yopilgan smena qayta yopish/ochishga ta'sir qilmaydi | ✅ |
| 19 | Barcha stollarni yopish | "HAMMASINI YOPISH", umumiy summa, tasdiq belgisi, chek varianti | Har stol uchun alohida to'lov yozildi | ✅ |
| 20 | Z-hisobot | Smena yopilganda avtomatik | Chek chiqdi (savdo, naqd, karta, bekor, farq) | ✅ |
| 21 | Telegram buyurtmasi | Bot → server → kassa, ovoz, qabul → oshxona cheki | Kassaga keldi; qabulda oshxona cheki chiqdi (serverda "sent") | ✅ |
| 22 | WebApp buyurtmasi | Katalog, savatcha, telefon, manzil, to'lov | Naqd, karta (chek rasmi bilan), Hamyon — hammasi | ✅ |
| 23 | Internet uzilishi | Savdo davom etadi | Offline: sahifa Service Worker'dan ochildi, savdo saqlandi, outbox=2 | ✅ |
| 24 | Internet qaytishi | Avtomatik yuboriladi | outbox 0 ga tushdi, server buyurtmalari +1 | ✅ |
| 25 | Offline sinxronlash | Dublikatsiz | Takroriy amal "duplicate" bo'ldi, eski rev o'tkazib yuborildi | ✅ |
| 26 | Windows qayta ishga tushishi | Smena, stollar, tasdiqlanmagan taomlar tiklanadi | Brauzer profili yopilib qayta ochildi: hammasi tiklandi | ✅ (brauzer darajasida) |
| 27 | Printer uzilishi | Buyurtma yo'qolmaydi, sabab ko'rsatiladi, tiklanganda chiqadi | "Printer ulanishni rad etdi…"; printer yoqilgach chek o'zi chiqdi | ✅ (soxta printer) |
| 28 | Qog'oz tugashi | Chek yuborilmaydi, ogohlantirish | "Printerda qog'oz tugagan"; qog'oz qo'yilgach chiqdi | ✅ (soxta printer) |
| 29 | Takroriy print-job | Bir chek ikki marta chiqmaydi | Bir xil ID → printer 1 marta oldi; yuborish paytida to'xtasa "noma'lum", avtomatik qayta chiqmaydi | ✅ |
| 30 | Diagnostika | Haqiqiy tekshiruv, "Tekshirilmagan" holati | 21 komponent; sinov serveridagi HTTPS yo'qligi va .htaccess ishlamasligi to'g'ri aniqlandi | ✅ |
| 31 | Backup | Yaratiladi, yuklab olinadi, internetdan yopiq | .sql.gz yaratildi va o'qildi; Apache'da 403 | ✅ |
| 32 | Xavfsizlik | Pastdagi ro'yxat | Hammasi o'tdi | ✅ |

### Xavfsizlik tekshiruvlari

- Soxta yoki eskirgan Telegram initData rad etiladi (401). Webhook maxfiy tokensiz rad etiladi (403).
- Hamyon callback: soxta imzo rad etiladi (403). To'g'ri imzo bo'lsa ham to'lov Hamyon'ning o'zidan qayta tekshiriladi va bir marta yoziladi.
- **To'lovi tasdiqlanmagan karta yoki Hamyon buyurtmasi qabul qilinmaydi.** Bu kassa, admin panel, Telegram tugmasi va server tomonida tekshirildi.
- Rasm o'rniga PHP kod yuklansa — rad etiladi. Rasmlar qayta kodlanadi.
- Qurilma tokenisiz API ishlamaydi (401). Chek rasmlari tokensiz ochilmaydi.
- Printer xizmati faqat 127.0.0.1 da ishlaydi. Begona sayt rad etiladi, tokensiz so'rov rad etiladi, DNS-rebinding (begona Host) rad etiladi.
- Apache'da `config`, `app`, `database`, `storage`, `admin/inc`, `tests`, `docs` va agent manba kodi — 403.
- Bot tokeni va Hamyon kaliti bazada shifrlangan (AES-256-GCM), ekranda maskalangan, loglarda yashirilgan.

### Sinov paytida topilgan va tuzatilgan xatolar

1. Kassa: mahsulot kartochkalari 1366×768 ekranda qisilib, nomlar ko'rinmasdi → grid tuzatildi.
2. Kassa: yuqori panel kengligi ekrandan oshib ketardi → tuzatildi.
3. Printer xizmatiga ulanishda Chrome manzil turi noto'g'ri ko'rsatilgan edi (CORS bloki) → olib tashlandi.
4. Chekda "·" belgisi "?" bo'lib chiqardi → PC866 kodlash qo'shildi.
5. Admin dashboard: tip xatosi (500) → tuzatildi.
6. WebApp: karta to'lovida buyurtmadan keyin karta raqami chiqmasdi → tuzatildi.
7. Telegram buyurtmasining oshxona cheki holati serverga yozilmasdi (ID formati) → tuzatildi.
8. To'lanmagan karta buyurtmasini qabul qilish mumkin edi → hamma joyda bloklandi.
9. O'rnatuvchi config faylga 0640 ruxsat qo'yardi, ba'zi hostinglarda bu saytni to'xtatardi → olib tashlandi.

Tuzatishlardan keyin: server integratsiya testlari **43/43**, printer xizmati unit testlari **6/6**, brauzer ssenariylari — JS xatosiz.

## 2. Kassada tekshirish kerak (haqiqiy uskunada)

| Test | Nima uchun bu yerda tekshirilmadi | Qanday tekshirish |
|------|-----------------------------------|-------------------|
| `kassa-ornatish.bat` | Windows yo'q | Monoblokda ishga tushiring: yorliq, avtozapusk va "Printer xizmati ishlayapti" yozuvi chiqishi kerak |
| `adi-printer.exe` Windows'da | Windows uchun kompilyatsiya qilindi, lekin ishga tushirilmadi | Kassa ⚙ → Printer: yashil holat |
| Haqiqiy Xprinter LAN chek | Haqiqiy printer yo'q | Test chek: o'zbekcha matn, kesish, signal |
| Xprinter qog'oz sensori (DLE EOT) | Modelga bog'liq | Qog'ozni oling → test chek → "qog'oz tugagan" chiqishi kerak. Javob bermasa, xizmat buni eslab qoladi va kutmaydi |
| USB rejim (Windows spooler) | Windows yo'q | Faqat USB printer bo'lsa |
| Windows qayta yoqilishi | Windows yo'q | Kompyuterni qayta yoqing: kassa va printer xizmati o'zi ochilishi kerak |
| Chrome "Local Network Access" ruxsati | Faqat HTTPS saytdan Chrome'ning yangi versiyalarida | O'rnatuvchi ruxsatni ro'yxatga yozadi. Chrome so'rasa — "Ruxsat berish" |
| Haqiqiy Telegram bot va webhook | Tashqi tarmoq ishlatilmadi | Admin → Telegram → "Ulanishni tekshirish" |
| Haqiqiy Hamyon (hamyon-api.uz) | Haqiqiy API hujjati sizdagi kabinetda, mock bilan sinaldi | Admin → To'lovlar → "Hamyon ulanishini tekshirish", keyin kichik summa bilan sinov to'lovi |
| myxvest.ru hosting | Hosting yo'q | Diagnostika → "Barchasini tekshirish": "Fayl ruxsatlari" yashil bo'lishi kerak |

## 3. Qolgan xavflar (ochiq aytilgan)

- **Printerga yuborildi ≠ qog'ozga chiqdi.** Shuning uchun holatlar ajratilgan. Natija noma'lum bo'lsa, tizim chekni o'zi qayta chiqarmaydi — kassir qog'ozni ko'rib qaror qiladi.
- **Karta to'lovini kassir ko'z bilan tekshiradi.** Kartaga o'tkazmaning avtomatik API'si yo'q.
- **Hamyon API mock bilan sinaldi.** Eski loyihangizda Hamyon nega ishlamay qolgani noma'lum. Yangi tizimda Diagnostika → Hamyon aniq sababni ko'rsatadi: kalit, ulanish yoki callback.
- **Brauzer ma'lumotini tozalamang.** Chrome profili tozalansa, serverga yuborilmagan offline buyurtmalar yo'qolishi mumkin. Kassa doimiy saqlash (persistent storage) so'raydi va alohida Chrome profilidan foydalanadi.
- **Bitta kassa uchun mo'ljallangan.** Buyurtma raqamlari har kuni 1 dan boshlanadi; ikki kassada ular takrorlanishi mumkin.
- **.htaccess ishlamaydigan (nginx) hostingda** maxfiy papkalarni hosting sozlamasida yopish kerak. Diagnostika buni avtomatik aniqlaydi.
- **Z-hisobot ichki boshqaruv hisoboti**, rasmiy fiskal hisobot emas.
