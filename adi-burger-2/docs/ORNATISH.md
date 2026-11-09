# ADI BURGER 2.0 — O'rnatish qo'llanmasi

Bu qo'llanma oddiy foydalanuvchi uchun yozilgan. Qadamlarni tartib bilan bajaring.

---

## 1. myxvest.ru hostingga yuklash

1. Hosting paneliga kiring → **File Manager** (Fayl menejeri).
2. Saytingiz papkasini oching (odatda `public_html` yoki `www/saytingiz.uz`).
3. `adi-burger-2.zip` faylini shu papkaga yuklang va **Extract** (Arxivdan chiqarish) qiling.
   - Fayllar to'g'ridan-to'g'ri sayt papkasida bo'lishi kerak: `index.php`, `pos/`, `admin/`, `api/` …
   - Agar `adi-burger-2/` degan ichki papka paydo bo'lsa, ichidagi hamma narsani bir pog'ona yuqoriga ko'chiring.

## 2. MySQL baza yaratish

1. Hosting paneli → **MySQL bazalar**.
2. Yangi baza yarating (masalan `adiburger`).
3. Yangi foydalanuvchi yarating va kuchli parol bering.
4. Foydalanuvchini bazaga qo'shing va **barcha huquqlarni (ALL PRIVILEGES)** bering.
5. Baza nomi, foydalanuvchi va parolni yozib qo'ying — keyingi qadamda kerak.

## 3. Domen va HTTPS

1. Domenni hostingga ulang (hosting panelidagi "Domenlar" bo'limi).
2. **SSL (HTTPS)** sertifikatini yoqing (odatda bepul Let's Encrypt).
   Telegram bot va WebApp **faqat HTTPS** bilan ishlaydi.

## 4. PHP sozlash

Hosting panelida PHP versiyasini **8.2** qiling. Tizim **PHP 8.0 va undan yuqori** hamma versiyada ishlaydi.
Kerakli kengaytmalar: `pdo_mysql`, `mbstring`, `curl`, `openssl`, `json`. Tavsiya: `gd`, `zlib`.
O'rnatuvchi bularni o'zi tekshiradi va yo'q bo'lsa aytadi.

## 5. Brauzer orqali o'rnatish

1. Brauzerda oching: `https://SAYTINGIZ/install/`
2. "Server tekshiruvi" hammasi ✓ ekanini ko'ring.
3. Baza ma'lumotlarini (2-qadam) kiriting.
4. Administrator ismi, admin panel **logini** va **paroli** (kamida 8 belgi), **administrator PIN** (4 raqam) kiriting.
   Xohlasangiz, kassir ismi va PIN ini ham shu yerda kiriting.
5. **O'rnatish** tugmasini bosing.

Natija: jadvallar yaratiladi, menyu (72 ta mahsulot, rasmlari bilan) va 10 ta stol qo'shiladi.
O'rnatuvchi o'zini bloklaydi — qayta ochilsa "allaqachon o'rnatilgan" deydi.

> Qo'lda SQL import qilish yoki config faylini tahrirlash shart emas.

## 6. Telegram botni ulash

1. Telegramda **@BotFather** → `/newbot` → bot nomini bering → **tokenni** nusxalang.
2. **@userinfobot** ga yozib, o'z Telegram ID raqamingizni bilib oling.
3. Admin panel → **☰ → Telegram bot**:
   - Bot tokenini kiriting.
   - Admin Telegram ID ni kiriting (bir nechta bo'lsa vergul bilan).
   - "Botni yoqish" ni belgilang. Ish vaqti va yetkazish narxini kiriting.
   - **Saqlash** — webhook avtomatik o'rnatiladi.
4. **Ulanishni tekshirish** tugmasini bosing — admin Telegramiga test xabar keladi.

### To'lovlar (Admin → To'lovlar)

- **Naqd** — mijoz pulni olib ketishda yoki yetkazilganda beradi.
- **Kartaga o'tkazma** — karta raqamini kiriting. Mijoz WebApp ichida chek rasmini yuboradi.
  Kassir **"To'lov tushdi"** ni bosmaguncha buyurtma qabul qilinmaydi (oshxonaga ketmaydi).
- **Hamyon** — Shop ID va Shop key ni kiriting. Hamyon kabinetida prepare va complete manzili:
  `https://SAYTINGIZ/pay/hamyon.php`. **"Hamyon ulanishini tekshirish"** tugmasi ishlashini ko'rsatadi.

## 7. Windows monoblokka kassani o'rnatish

1. Monoblokda **Google Chrome** o'rnatilgan bo'lsin.
2. Monoblokda admin panelni oching → **☰ → POS va printer** → **⬇ kassa-ornatish.bat** (shu bitta fayl yetarli: sayt manzili ichiga yozilgan).
3. Barcha Chrome oynalarini yoping va faylni ikki marta bosing.
   Windows ogohlantirsa: **«Подробнее» → «Выполнить в любом случае»**.
4. O'rnatuvchi:
   - Windows va Chrome ni tekshiradi;
   - **sayt manzilini tozalab, saytni haqiqatan tekshiradi** (manzilga `/pos` yoki `/admin` yozib yuborsangiz ham o'zi to'g'rilaydi);
   - printer xizmatini (`adi-printer.exe`) saytdan yuklab o'rnatadi (fayl yoniga qo'yish shart emas) va Windows bilan birga ishga tushadigan qiladi;
   - kassa ochuvchi faylni o'zi yaratadi;
   - ish stolida **ADI BURGER POS** yorlig'ini yaratadi;
   - "Windows yoqilganda kassa ochilsinmi?" deb so'raydi — **H** (ha) ni bosing.
5. Xato chiqsa, o'rnatuvchi sababini yozadi (masalan: "Saytga ulanib bo'lmadi", "Bu manzilda ADI BURGER topilmadi"). Sayt papkada o'rnatilgan bo'lsa, manzilga papka nomini ham yozing: `https://sayt.uz/Adi`.
5. Kassa ochiladi → admin login/parol bilan **qurilmani ulang** (bir marta).
6. Kassir PIN bilan kiradi va smenani ochadi.

> Kassa — bu Chrome App Mode (PWA) oynasi: manzil satri va tablar ko'rinmaydi.
> Bu mustaqil .exe dastur emas. Printer xizmati esa alohida kichik Windows dasturi (`adi-printer.exe`).

### Kassada ishlash (qisqa)

- **Tepada:** 🍽 Stolda · 🛍 Olib ketish · 🛵 Yetkazish. Har birining yonida ochiq buyurtmalar soni ko'rinadi. Tugmani bosing — shu rejimda buyurtma ochiladi.
- **✓ Tasdiqlash** hech narsa so'ramaydi: telefon va manzil ixtiyoriy (👤 Mijoz tugmasi orqali, xohlasangiz).
- **Yetkazish narxi** ixtiyoriy: buyurtma panelidagi maydonga o'zingiz yozasiz. Bo'sh qoldirsangiz — bepul.
- **Oshxona cheki** — faqat taom va soni (narxsiz, mijoz raqamisiz). Ko'rinishini **Chek dizayni** bo'limida o'zgartirasiz.
- **Mijoz cheki** (to'lovda) — mijoz ma'lumoti kiritilgan bo'lsa, telefon va manzil ham chiqadi.
- **Kirish:** 4 xonali PIN yoki «Login va parol bilan kirish».

## 8. Xprinter LAN printerni sozlash

1. Printerni yoqing va LAN kabel bilan routerga ulang.
2. **IP manzilni bilish:** printerni o'chiring, **FEED** tugmasini bosib turgan holda yoqing.
   Printer o'z sozlamalarini chop etadi — u yerda IP manzil yozilgan (masalan `192.168.1.87`).
   Kompyuter va printer bitta tarmoqda (masalan `192.168.1.x`) bo'lishi kerak.
3. Kassada **⚙ Sozlamalar → Printer**:
   - Ulanish turi: **LAN**.
   - **Printer IP manzili** — chop etilgan IP.
   - **Port** — `9100` (Xprinter standarti).
   - **Chek o'lchami** — 80 mm yoki 58 mm.
   - **Saqlash** — xizmat printerga ulanadi va natijani ko'rsatadi.
4. Maslahat: routerda printerga doimiy IP bering (DHCP reservation), aks holda IP o'zgarib qolishi mumkin.

USB printer bo'lsa: Ulanish turi **USB (Windows printer)** → ro'yxatdan printerni tanlang.

## 8.1. Chek dizayni (admin panel → ☰ → 🧾 Chek dizayni)

Chekni o'zingiz xohlagandek sozlang. O'ng tomonda chek **darhol** o'zgaradi.

- **Bloklar:** oshxona va mijoz cheki bloklardan iborat. Har bir blokni yoqish/o'chirish, **▲ ▼** bilan tartibini o'zgartirish, tekislash (chap/o'rta/o'ng), o'lcham (oddiy/katta) va qalinlikni tanlash mumkin.
- **O'z matningiz:** pastdagi «＋ Blok qo'shish» → «Matn». Istalgan joyga qo'ying (masalan: Wi-Fi paroli, aksiya).
- **Logo:** PNG yoki JPG yuklang. Kattaligi va qoraligini sozlang. Chekda oq-qora bo'lib chiqadi. Keyin «Logo» blokini yoqing va xohlagan joyga qo'ying.
- **Umumiy:** chek eni (58/80 mm), shrift (oddiy/kichik), chap-o'ng chet, qatorlar orasi, hamma yozuvni qalin qilish, nusxalar soni.
- **📏 O'lchov cheki** va **🖨 Namuna chek** tugmalari (kassa monoblokida ishlaydi): chizg'ich bilan o'lchab, chek eni va chetini to'g'rilang.
- Ma'lumot kiritilmagan blok (masalan, mijoz telefoni) chekda umuman chiqmaydi.

## 9. Test chek chiqarish

Kassada **⚙ → Printer → 🧾 Test chek chiqarish**. Chekda printer manzili, o'lcham va vaqt chiqadi.
Chiqmasa, ekranda sabab ko'rinadi (printer o'chiq, IP noto'g'ri, qog'oz tugagan…). Tuzatib **↻ Qayta ulash** ni bosing.

Avtomatik cheklar:
- **Tasdiqlash** → "Buyurtma tasdiqlandi" → oshxona cheki (faqat yangi taomlar, narxsiz).
- **To'lov** → "To'lov qabul qilindi" → mijoz cheki.
- Oshxonaga yuborilgan taom bekor qilinsa → "BEKOR QILINDI" cheki.
- Smena yopilganda → Z-hisobot (ichki, fiskal emas).

Chrome Print oynasi **umuman ochilmaydi**.

## 10. Offline rejimni tekshirish

1. Kassada savdo qilib turing.
2. Monoblokda internetni uzing (Wi-Fi yoki kabelni chiqaring). Yuqorida **Offline** chiqadi.
3. Buyurtma qiling, to'lov oling — chek chiqadi (printer lokal tarmoqda).
   Yuqoridagi **⇅** belgisida yuborilmagan amallar soni ko'rinadi.
4. Internetni qayting — bir necha soniyada **⇅ 0** bo'ladi (hammasi serverga yuborildi).
   Tugma bosish shart emas.
5. Admin panel → Buyurtmalar — offline buyurtmalar ko'rinadi.

---

## Muammo bo'lsa

Admin panel → **☰ → Diagnostika va ta'mirlash** → **Barchasini tekshirish**.
Har bir komponent uchun holat, sabab, tavsiya va (mumkin bo'lsa) **Tuzatish** tugmasi bor.
Hisobotni yuklab olib, yordam so'rash mumkin.

## Mavjud tizimni yangilash (ma'lumot yo'qolmaydi)

1. Admin → Zaxira → **Hozir zaxira olish**.
2. Yangi ZIP ni ustidan yuklang va Extract qiling (fayllarni almashtirsin). **`config/`, `storage/`, `uploads/` papkalarini o'chirmang.**
3. Admin panelga kiring: baza **o'zi yangilanadi** (yangi jadval va ustunlar qo'shiladi, eski ma'lumot saqlanadi).
4. Admin → ☰ → **Kassir va administrator** → o'zingizni oching va **parolni qayta kiriting** (kassaga login bilan kirish uchun).
5. Monoblokda kassa-ornatish.bat ni qayta ishga tushiring (printer xizmati yangilanadi). Kassada **Ctrl+F5**.

## Bot va stollar

- **Botda «Buyurtma berish»:** tugma bosilganda bot «Menyuni ochish» tugmasini yuboradi. Menyu shu tugma yoki bot yonidagi «Menyu» tugmasi orqali ochiladi.
- **Mijozga yozuvlar:** Admin → Telegram bot → «Mijozga ko'rinadigan yozuvlar»: yetkazish, olib ketish va to'lov oldidan yozuv. Mijoz buyurtma oynasida ko'radi.
- **Yetkazish narxi:** Telegram bot sahifasida 0 = bepul.
- **Stollarni o'chirish:** Admin → Stollar → 🗑. Tarixda ishlatilgan stol hisobotlar buzilmasligi uchun faqat yashiriladi.
- **Kassir va administrator:** har biriga login va parol berish mumkin (kassir uchun kamida 6, administrator uchun 8 belgi). Admin panelga faqat administrator kiradi.
- **Webhook ikki marta javob bermaydi:** har bir Telegram xabari faqat bir marta qayta ishlanadi.

## Cron (ixtiyoriy)

Cron shart emas: zaxira va Hamyon tekshiruvi kassa ishlab turganda avtomatik bajariladi.
