"""Jarvis ishga tushirish: Telethon userbot + (ixtiyoriy) qo'ng'iroq moduli."""
from __future__ import annotations

import asyncio
import logging
import os

from telethon import TelegramClient, events

from .brain import Brain, load_system_prompt
from .config import Config, ConfigError, load_config, load_dotenv_file
from .handlers import MessageHandlers
from .memory import Memory
from .providers import build_llm, build_stt, build_tts
from .security import AccessControl, RateLimiter

log = logging.getLogger("jarvis")


def setup_logging(level: str) -> None:
    logging.basicConfig(level=getattr(logging, level, logging.INFO),
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    # kutubxonalar kod/kalitni so'rov URL'larida log qilmasligi uchun
    for noisy in ("telethon", "httpx", "httpcore", "anthropic"):
        logging.getLogger(noisy).setLevel(logging.WARNING)


def secure_session_file(session_path: str) -> None:
    p = session_path if session_path.endswith(".session") else session_path + ".session"
    try:
        os.chmod(p, 0o600)
    except OSError:
        pass


async def run(cfg: Config) -> None:
    memory = Memory(cfg.db_path, cfg.history_limit)
    brain = Brain(build_llm(cfg), memory, load_system_prompt(cfg.system_prompt_path),
                  cfg.max_tokens_text, cfg.max_tokens_voice)
    stt, tts = build_stt(cfg), build_tts(cfg)
    access = AccessControl(cfg.allowed)
    limiter = RateLimiter(cfg.rate_limit_per_min)

    os.makedirs(os.path.dirname(os.path.abspath(cfg.session_path)), exist_ok=True)
    client = TelegramClient(cfg.session_path, cfg.api_id, cfg.api_hash)
    await client.connect()
    if not await client.is_user_authorized():
        raise SystemExit("Akkaunt kirmagan. Avval `python login.py` ni ishga tushiring.")
    secure_session_file(cfg.session_path)

    handlers = MessageHandlers(client, cfg, access, limiter, brain, memory, stt, tts)
    client.add_event_handler(handlers.on_message, events.NewMessage(incoming=True))

    if cfg.calls_enabled:
        try:
            from .calls import CallManager

            await CallManager(client, cfg, access, brain, memory, stt, tts).start()
        except Exception:
            log.exception("Qo'ng'iroq moduli yoqilmadi; matn va ovozli xabar ishlayveradi")
    else:
        log.info("Qo'ng'iroq moduli o'chirilgan (CALLS_ENABLED=false)")

    me = await client.get_me()
    log.info("Jarvis ishga tushdi: @%s", getattr(me, "username", None) or me.id)
    try:
        await client.run_until_disconnected()
    finally:
        memory.close()


def main() -> None:
    load_dotenv_file()
    try:
        cfg = load_config()
    except ConfigError as e:
        raise SystemExit(f"Sozlama xatosi: {e}")
    setup_logging(cfg.log_level)
    try:
        asyncio.run(run(cfg))
    except KeyboardInterrupt:
        pass
