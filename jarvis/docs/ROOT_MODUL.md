# ROOT moduli: xavfsiz reja

Hozir, 1-bosqichda, ROOT faqat **tekshiriladi** (`RootManager.check()` → `su -c id`). Hech qanday root buyrug'i bajarilmaydi.

## Tamoyillar

1. **Oq ro'yxat.** Faqat oldindan yozilgan aniq buyruqlar bajariladi. Ixtiyoriy shell buyrug'i yo'q.
2. **AI root buyruq bera olmaydi.** Gemini javobi yoki tanilgan matn hech qachon shell'ga uzatilmaydi. AI faqat buyruq *turini* tanlashi mumkin (masalan `WIFI_ON`), aniq buyruqni ilova o'zi tuzadi.
3. **Ochiq yoqish.** Sozlamalarda "ROOT funksiyalari" alohida kalit bilan yoqiladi, standart holatda o'chiq.
4. **Tasdiqlash.** Xavfli amallar (qayta yuklash, ma'lumot o'chirish) faqat ekrandagi tasdiq bilan bajariladi. Ular umuman oq ro'yxatga kirmasligi ham mumkin.
5. **Timeout va log.** Har bir buyruqqa 5 soniya vaqt beriladi. Natija lokal logga yoziladi, serverga yuborilmaydi.
6. **Rootsiz rejim.** ROOT bo'lmasa yoki rad etilsa, oddiy Android yo'li ishlaydi (panel ochiladi).

## Rejalashtirilgan oq ro'yxat (5-bosqich)

| Turi | Buyruq | Izoh |
|---|---|---|
| WIFI_ON / WIFI_OFF | `svc wifi enable` / `svc wifi disable` | Android 12 da ishlaydi (telefonda tekshiriladi) |
| DATA_ON / DATA_OFF | `svc data enable` / `svc data disable` | Mobil internet |
| BT_ON / BT_OFF | `svc bluetooth enable` / `svc bluetooth disable` | `svc bluetooth` Android 12 da bor-yo'qligi **tekshirilmagan**. Zaxira: `cmd bluetooth_manager enable` |
| SCREEN_OFF | `input keyevent 26` | Ekranni o'chirish |
| BRIGHTNESS | `settings put system screen_brightness N` | N faqat 0–255 butun son, ilova o'zi tekshiradi |

`svc` va `cmd` buyruqlarining Samsung One UI 4 (Android 12) dagi aniq ishlashi faqat S10 telefonda sinab ko'rilgandan keyin tasdiqlanadi.

## Kod tuzilmasi (5-bosqich)

```java
enum RootAction { WIFI_ON, WIFI_OFF, DATA_ON, DATA_OFF, BT_ON, BT_OFF, SCREEN_OFF }

// Faqat enum'dan buyruq tuziladi: tashqi matn kirmaydi
static String commandFor(RootAction a) { switch (a) { case WIFI_ON: return "svc wifi enable"; ... } }
```
