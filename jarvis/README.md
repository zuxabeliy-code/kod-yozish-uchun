# JARVIS AI — 1-bosqich (MVP)

Samsung Galaxy S10 (Android 12) uchun o'zbekcha ovozli AI yordamchi.
Java + XML, `com.jarvis.ai`, minSdk 26, Gradle 6.1.1 + AGP 4.0.1. Tashqi kutubxonalarsiz.

```
jarvis/
├── android/      ← AndroidIDE'da ochiladigan loyiha
├── backend/      ← PHP 8.x + MySQL server (Gemini kaliti faqat shu yerda)
└── docs/ROOT_MODUL.md
```

## Arxitektura

```
[Telefon: JARVIS]  --HTTPS + qurilma tokeni-->  [Sizning hosting: backend/api]  --API kalit-->  [Gemini]
```

- Ilovada Gemini kaliti **yo'q**. Ilova faqat o'zingizning serveringizga murojaat qiladi.
- Token admin panelda yaratiladi. Bazada faqat SHA-256 xeshi saqlanadi. Telefonda Android Keystore (AES-256-GCM) bilan shifrlangan.
- Har bir qurilma uchun daqiqalik va kunlik limit bor. Token o'g'irlansa, admin paneldan o'chiriladi.
- Suhbat matni serverda saqlanmaydi. Logda faqat vaqt, amal, holat va davomiylik turadi.

## 1-bosqichda nima bor

| Funksiya | Holat | Izoh |
|---|---|---|
| Futuristik UI, animatsiyali yadro | ✅ | Canvas: 5 holat (kutish, tinglash, o'ylash, gapirish, xato) |
| Matn orqali suhbat | ✅ | Gemini, oxirgi 20 xabar konteksti |
| Ovozli kiritish (Android) | ✅ kod / ⚠ telefonda sinalmagan | `uz-UZ`. Google ilovasida o'zbek tili bo'lmasa, avtomatik server rejimiga o'tadi |
| Ovozli kiritish (server/Gemini) | ✅ | 16 kHz WAV, o'zi jimlikni aniqlaydi |
| Ovozli javob | ✅ kod / ⚠ telefonda sinalmagan | Android'da o'zbek ovozi bo'lsa Android TTS, bo'lmasa Gemini TTS |
| Fonar | ✅ | `fonarni yoq / o'chir` |
| Ovoz balandligi | ✅ | `ovozni balandlat / pasaytir / o'chir / maksimal` |
| Ilovalarni ochish | ✅ | `telegramni och`, `kamerani och`, `kalkulyatorni ishga tushir` |
| Wi-Fi / Bluetooth | ⚠ faqat panel | Android 10+ oddiy ilovaga yoqib-o'chirishga ruxsat bermaydi. Sozlamalar paneli ochiladi. To'liq boshqaruv ROOT bosqichida |
| Vaqt, sana, batareya | ✅ | `soat nechchi`, `bugun nechanchi`, `batareya necha foiz` |
| ROOT | ⚠ faqat tekshiruv | Sozlamalar → "ROOT holatini tekshirish" |
| Qo'ng'iroq / SMS | ❌ 2-bosqich | Tasdiqlash oynasi bilan |
| Ekranni tahlil qilish | ❌ 4-bosqich | MediaProjection + Gemini Vision |

Buyruq tanilmasa, matn Gemini'ga suhbat sifatida yuboriladi. Kirill yozuvidagi o'zbekcha ham tushuniladi.

---

## 1. Backendni o'rnatish (hosting, 5 daqiqa)

1. Hosting panelida MySQL baza va foydalanuvchi yarating.
2. `backend/` papkasidagi **hamma** fayllarni hostingga yuklang, masalan `public_html/jarvis/`.
3. Brauzerda oching: `https://saytingiz.uz/jarvis/install/`
4. Baza ma'lumotlari, Gemini API kaliti va admin login/parolni kiriting, so'ng **O'rnatish** tugmasini bosing.
5. Admin panel → **Qurilmalar** → "Galaxy S10" → **Token yaratish**. Tokenni nusxalang (u faqat bir marta ko'rsatiladi).
6. Xavfsizlik uchun `install/` papkasini o'chiring. Installer baribir qayta ishlamaydi.

Talablar: PHP 8.0+, `pdo_mysql`, `curl`, `mbstring`, **HTTPS** (bepul Let's Encrypt yetadi).

Gemini modellari admin panel → Sozlamalar'da o'zgartiriladi. Standart qiymatlar:
- suhbat va STT: `gemini-3.8-flash`
- TTS: `gemini-3.8-flash-tts`, ovoz `Kore`

Model nomlari Google hujjatidan 2026-11 holatida olingan. Google ularni o'zgartirsa, panelda yangilang.

## 2. APK yig'ish (AndroidIDE)

1. `android/` papkasini telefonga ko'chiring, masalan `/storage/emulated/0/AndroidIDEProjects/JARVIS`.
2. AndroidIDE → **Open project** → `JARVIS` papkasini tanlang.
3. Gradle sinxronlashini kuting. Birinchi marta AGP 4.0.1 yuklanadi, shuning uchun internet kerak.
4. Yuqoridagi ▶ (Run) tugmasi yoki **Build → assembleDebug**.
5. APK manzili: `app/build/outputs/apk/debug/app-debug.apk`

Agar AndroidIDE "SDK location not found" desa, `local.properties` faylini yarating:
`sdk.dir=/data/data/com.itsaky.androidide/files/home/android-sdk`.
Aniq yo'l sizning AndroidIDE versiyangizga bog'liq.

Agar ishlagan test loyihangizda `com.android.tools.build:gradle:X.Y.Z` boshqa versiya bo'lsa, `android/build.gradle` faylidagi `4.0.1` ni o'shanga almashtiring.

**Gradle 6.1.1 tanlangani sababi:** siz aytdingiz, shu versiyada test APK yig'ilgan. AGP 4.0.1 Gradle 6.1.1 bilan rasmiy juftlik. `<queries>` (Android 11+ ilovalar ro'yxati) AGP 4.0.1 dan boshlab qo'llanadi. `targetSdk 30` Android 12 da to'liq ishlaydi.

## 3. Ilovani sozlash

1. JARVIS → ⚙ Sozlamalar.
2. Server manzili: `https://saytingiz.uz/jarvis`, oxirida `/` bo'lmasin.
3. Token: admin paneldan nusxalangan 64 belgili kod.
4. **Ulanishni tekshirish** bosilganda `✔ Server: JARVIS v1.0.0, Qurilma: Galaxy S10` chiqishi kerak.
5. **Saqlash**.

## 4. Telefonda sinov ro'yxati

| # | Sinov | Kutilgan natija |
|---|---|---|
| 1 | Ilovani ochish | Yadro aylanadi, yuqorida holat yozuvi |
| 2 | Sozlamalar → Ulanishni tekshirish | ✔ Server javobi |
| 3 | Matn: `Salom, kimsan?` | O'ylash animatsiyasi → o'zbekcha javob → ovoz |
| 4 | 🎤 → "Fonarni yoq" | Fonar yonadi |
| 5 | 🎤 → "Ovozni pasaytir" | Tizim ovoz paneli chiqadi |
| 6 | 🎤 → "Telegramni och" | Telegram ochiladi |
| 7 | "Wi-Fi ni yoq" | Wi-Fi paneli ochiladi (halol izoh bilan) |
| 8 | Sozlamalar → Diagnostika | Android STT/TTS o'zbekchani biladimi, ko'rsatadi |
| 9 | Sozlamalar → ROOT tekshirish | Magisk oynasi → "ROOT: ruxsat berildi" |
| 10 | Internetni o'chirib suhbat | "Serverga ulanib bo'lmadi" xabari, ilova qulamaydi |

Agar Android STT o'zbekchani yomon tanisa (boshqa tilga o'girsa), Sozlamalar → STT → **Faqat server (Gemini)** ni tanlang.

## 5. Shu yerda o'tkazilgan sinovlar (2026-11-17)

- ✅ `assembleDebug` muvaffaqiyatli (Gradle 6.1.1, AGP 4.0.1, JDK 11, SDK 30). Debug APK 76 KB.
- ✅ Unit-testlar: 8/8 o'tdi. 40+ o'zbekcha buyruq namunasi, jumladan kirill yozuv; ilova nomlarini moslash.
- ✅ Backend: PHP 8.3 + MariaDB, brauzerda to'liq o'rnatish, installer bloklanishi, admin kirish, noto'g'ri parol, token yaratish.
- ✅ API (soxta Gemini serveri bilan): tokensiz/noto'g'ri token 401, ping, chat (system prompt + tarix), STT, TTS (PCM → WAV 24 kHz), limit 429, noto'g'ri JSON/metod, Gemini kalit xatosi.
- ❌ **Haqiqiy Gemini kaliti bilan sinalmagan** (kalit yo'q edi). Birinchi haqiqiy so'rovda admin panel → Loglar'ni tekshiring.
- ❌ **Telefonda sinalmagan:** mikrofon, Android STT/TTS, fonar, ilova ochish, UI ko'rinishi. Bu muhitda Android emulyatori ishlamaydi (KVM yo'q).

## Keyingi bosqichlar

| Bosqich | Mazmuni |
|---|---|
| 2 | Qo'ng'iroq va SMS: kontaktdan qidirish, **tasdiqlash oynasi**, `CALL_PHONE` va `SEND_SMS` ruxsatlari |
| 3 | Gemini function-calling: buyruqlarni AI aniqlaydi ("akamga ertaga qo'ng'iroq qilishni eslat") |
| 4 | Ekranni tahlil qilish: MediaProjection (har safar ruxsat) + Gemini Vision |
| 5 | ROOT moduli: Wi-Fi/Bluetooth/mobil internet to'liq boshqaruvi (oq ro'yxat), qarang `docs/ROOT_MODUL.md` |
| 6 | "Jarvis" uyg'otish so'zi (fon xizmati), vidjet |
