"""Bir martalik interaktiv login: telefon, kod, 2FA. Kod/parol hech qayerga yozilmaydi.

Ishlatish:  python login.py
"""
import asyncio
import os

from telethon import TelegramClient

from jarvis.app import secure_session_file
from jarvis.config import ConfigError, load_config, load_dotenv_file


async def _login() -> None:
    load_dotenv_file()
    try:
        cfg = load_config(for_login=True)
    except ConfigError as e:
        raise SystemExit(f"Sozlama xatosi: {e}")
    os.makedirs(os.path.dirname(os.path.abspath(cfg.session_path)), exist_ok=True)
    client = TelegramClient(cfg.session_path, cfg.api_id, cfg.api_hash)
    # start() telefon/kod/2FA'ni terminaldan so'raydi (parol yashirin kiritiladi)
    await client.start()
    me = await client.get_me()
    await client.disconnect()
    secure_session_file(cfg.session_path)
    print(f"Muvaffaqiyatli kirildi: id={me.id}. Session fayli saqlandi (chmod 600).")


if __name__ == "__main__":
    asyncio.run(_login())
