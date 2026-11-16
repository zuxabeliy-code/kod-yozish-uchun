# Jarvis — Telegram userbot (Zuxa uchun)

Alohida Telegram akkaunt (MTProto, Telethon) orqali ishlaydi. Faqat egasiga (`OWNER_ID`) javob beradi:

1. Matnli xabar -> AI javob.
2. Ovozli xabar -> matnga (STT) -> AI -> ovozli xabar (TTS).
3. Qo'ng'iroq -> avtomatik qabul -> jonli suhbat (VAD -> STT -> AI -> TTS).

Bu Python loyiha, shuning uchun PHP installer yo'q: o'rnatish `install.sh` orqali.

## VPS'ga o'rnatish (Ubuntu 22.04/24.04, kamida 2 GB RAM)

**1. api_id va api_hash olish.** Jarvis uchun ajratilgan akkaunt bilan https://my.telegram.org ga kiring ->
"API development tools" -> ilova yarating -> `api_id` va `api_hash` ni oling.
Egasining id'sini (OWNER_ID) o'z akkauntingizdan @userinfobot ga yozib bilib oling.

**2. Kodni VPS'ga qo'ying va o'rnating, keyin `.env` to'ldiring.**
```bash
sudo useradd -m -s /bin/bash jarvis            # ixtiyoriy, tavsiya etiladi
sudo mkdir -p /opt/jarvis-bot && sudo chown jarvis: /opt/jarvis-bot
# ZIP'ni /opt/jarvis-bot ga oching, so'ng:
cd /opt/jarvis-bot && bash install.sh
nano .env      # API_ID, API_HASH, OWNER_ID, ANTHROPIC_API_KEY
```
Ubuntu 22.04'da standart Python 3.10; 3.11+ kerak: `sudo apt install python3.11 python3.11-venv`, so'ng `PYTHON=python3.11 bash install.sh`.

**3. Birinchi login (interaktiv, bir marta).**
```bash
cd /opt/jarvis-bot && .venv/bin/python login.py
```
Jarvis akkauntining telefon raqami, Telegram'dan kelgan kod va (bo'lsa) 2FA parol so'raladi.
Ular hech qayerga yozilmaydi; `data/jarvis.session` fayli `chmod 600` bilan saqlanadi. Bu faylni hech kimga bermang.

**4. Xizmat sifatida ishga tushirish.**
```bash
sudo cp jarvis.service /etc/systemd/system/    # ichidagi yo'l va User ni tekshiring
sudo systemctl daemon-reload
sudo systemctl enable --now jarvis
journalctl -u jarvis -f                        # loglar
```
Keyin o'z akkauntingizdan Jarvis akkauntiga yozing, ovozli xabar yuboring yoki qo'ng'iroq qiling.

## Sozlamalar (`.env`)
Hammasi `.env.example` da izohlangan. Asosiylari:
- `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`), `LLM_PROVIDER`.
- `STT_PROVIDER=faster_whisper` (lokal, `WHISPER_MODEL=small` ~2 GB RAMga mos; `base` yengilroq) yoki `openai` (`STT_API_BASE_URL`, `STT_API_KEY`, `STT_API_MODEL`).
- `TTS_LANG=uz|ru|en`, ovozlar `TTS_VOICE_UZ/RU/EN` (edge-tts). Chatda `/til ru` bilan o'zgartiriladi.
- `CALLS_ENABLED=false` — qo'ng'iroq modulini butunlay o'chiradi (matn/ovoz baribir ishlaydi).
- `ALLOWED_IDS` — OWNER_ID'dan tashqari ruxsat berilganlar.

Shaxsni `prompts/system.md` da tahrirlang (qayta ishga tushirish kerak: `sudo systemctl restart jarvis`).

Chat buyruqlari: `/reset` (xotirani tozalash), `/til uz|ru|en`, `/holat`, `/yordam`.

## Xavfsizlik
- Faqat `OWNER_ID`/`ALLOWED_IDS` ga javob; begonalarga umuman yozmaydi va qo'ng'iroqni qabul ham, rad ham qilmaydi.
- Barcha kalitlar `.env` da (`.gitignore`da `.env` va `*.session`). Loglarga xabar matni, kod va kalit yozilmaydi.
- Flood himoya: daqiqada `RATE_LIMIT_PER_MIN` ta xabar, javoblar orasida `REPLY_DELAY_MIN..MAX` pauza, Telegram FloodWait'da kutadi.
- Userbot Telegram qoidalariga zid bo'lishi mumkin: faqat ajratilgan akkaunt bilan va o'z hisobingizdan ishlating.

## Arxitektura
`jarvis/providers/` — abstrakt `LLMProvider`, `STTProvider`, `TTSProvider` (yangi provayder = bitta klass + `providers/__init__.py` da bitta qator).
`handlers.py` matn/ovoz, `calls.py` qo'ng'iroq, `vad.py` nutq bo'laklash, `memory.py` SQLite (oxirgi `HISTORY_LIMIT` xabar).

## Ma'lum cheklovlar
- **Qo'ng'iroq qismi haqiqiy Telegram'da sinalmagan** (py-tgcalls 3.0.1 API'si manba koddan o'qildi, mock bilan tekshirildi). Birinchi ishga tushirishda `journalctl` loglarini kuzating. Faqat 1:1 shaxsiy qo'ng'iroq (guruh emas).
- Barge-in oddiy: bot gapirayotganda Zuxa gap boshlasa, bot yuborishni to'xtatadi (VAD bo'yicha); aks-sado bostirish yo'q, shuning uchun Zuxa karnay o'rniga quloqchin ishlatgani ma'qul.
- Qo'ng'iroqda javob kechikishi: STT + AI + TTS ketma-ket (odatda 2-5 soniya, `small` modelda CPU'da sekinroq bo'lishi mumkin). Oqimli (streaming) STT/TTS yo'q.
- Outgoing audio 10 ms kadrlarda real vaqt tezligida yuboriladi; ntgcalls kutadigan aniq kadr o'lchami real qo'ng'iroqda tasdiqlanmagan.
- Uzbek tilida faster-whisper aniqligi o'rtacha; yaxshiroq natija uchun `WHISPER_MODEL=medium` (ko'proq RAM) yoki `STT_PROVIDER=openai`.
- Faqat ovozli xabar (voice note) qayta ishlanadi; rasm, fayl, video e'tiborsiz.

## Testlar
```bash
.venv/bin/pip install -r requirements-dev.txt
.venv/bin/python -m pytest -q
```
