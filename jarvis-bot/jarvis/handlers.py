"""Telegram xabarlari: matn va ovozli xabar. Faqat ruxsat etilganlarga javob."""
from __future__ import annotations

import asyncio
import io
import logging
import random
from collections.abc import Awaitable, Callable

from . import audio
from .brain import Brain
from .config import Config
from .memory import Memory
from .providers.base import ProviderError, STTProvider, TTSProvider
from .security import AccessControl, RateLimiter

log = logging.getLogger(__name__)

LANGS = ("uz", "ru", "en")
HELP = "Buyruqlar: /reset — xotirani tozalash, /til uz|ru|en — ovoz tili, /holat — holat."


def pick_voice(cfg: Config, lang: str | None) -> str:
    lang = lang if lang in cfg.tts_voices else cfg.tts_lang
    return cfg.tts_voices[lang]


class MessageHandlers:
    def __init__(self, client, cfg: Config, access: AccessControl, limiter: RateLimiter,
                 brain: Brain, memory: Memory, stt: STTProvider, tts: TTSProvider,
                 sleep: Callable[[float], Awaitable[None]] = asyncio.sleep):
        self.client = client
        self.cfg = cfg
        self.access = access
        self.limiter = limiter
        self.brain = brain
        self.memory = memory
        self.stt = stt
        self.tts = tts
        self._sleep = sleep
        self._warned: set[int] = set()

    # --- umumiy yordamchilar ---
    async def _pause(self) -> None:
        lo, hi = self.cfg.reply_delay_min, max(self.cfg.reply_delay_min, self.cfg.reply_delay_max)
        if hi > 0:
            await self._sleep(random.uniform(lo, hi))

    async def _send(self, fn, *args, **kwargs):
        """Telegram FloodWait bo'lsa bir marta kutib qayta urinadi."""
        from telethon.errors import FloodWaitError

        try:
            return await fn(*args, **kwargs)
        except FloodWaitError as e:
            wait = min(int(getattr(e, "seconds", 5)) + 1, 120)
            log.warning("FloodWait: %s soniya kutaman", wait)
            await self._sleep(wait)
            return await fn(*args, **kwargs)

    async def _lang(self, chat_id: int) -> str:
        return await self.memory.get_setting(f"lang:{chat_id}", self.cfg.tts_lang) or self.cfg.tts_lang

    # --- asosiy kirish nuqtasi ---
    async def on_message(self, event) -> None:
        try:
            await self._on_message(event)
        except Exception:
            log.exception("Xabarni qayta ishlashda xato")

    async def _on_message(self, event) -> None:
        if getattr(event, "out", False) or not getattr(event, "is_private", False):
            return
        sender = getattr(event, "sender_id", None)
        if not self.access.is_allowed(sender):
            return  # begona: umuman e'tiborsiz, hech narsa yozilmaydi
        if not self.limiter.allow(sender):
            if sender not in self._warned:
                self._warned.add(sender)
                await self._send(self.client.send_message, event.chat_id, "Sekinroq, Zuxa. Bir daqiqadan keyin yozing.")
            return
        self._warned.discard(sender)

        msg = event.message
        if getattr(msg, "voice", None):
            await self._handle_voice(event, sender)
            return
        text = (getattr(event, "raw_text", "") or "").strip()
        if not text:
            return
        if text.startswith("/"):
            await self._handle_command(event, sender, text)
            return
        await self._handle_text(event, sender, text)

    # --- matn ---
    async def _handle_text(self, event, chat_id: int, text: str) -> None:
        async with self.client.action(event.chat_id, "typing"):
            answer = await self.brain.reply(chat_id, text, "text")
            await self._pause()
        await self._send(self.client.send_message, event.chat_id, answer)

    # --- buyruqlar ---
    async def _handle_command(self, event, chat_id: int, text: str) -> None:
        parts = text.split()
        cmd = parts[0].lower().split("@")[0]
        if cmd == "/reset":
            await self.memory.clear(chat_id)
            reply = "Xotira tozalandi, Zuxa."
        elif cmd == "/til":
            lang = parts[1].lower() if len(parts) > 1 else ""
            if lang in LANGS:
                await self.memory.set_setting(f"lang:{chat_id}", lang)
                reply = f"Ovoz tili: {lang}."
            else:
                reply = "Ishlatilishi: /til uz | ru | en"
        elif cmd == "/holat":
            calls = "yoqilgan" if self.cfg.calls_enabled else "o'chirilgan"
            reply = (f"Ishlayapman. Model: {self.cfg.anthropic_model}, STT: {self.cfg.stt_provider}, "
                     f"ovoz tili: {await self._lang(chat_id)}, qo'ng'iroq: {calls}.")
        elif cmd in ("/start", "/help", "/yordam"):
            reply = HELP
        else:
            return
        await self._pause()
        await self._send(self.client.send_message, event.chat_id, reply)

    # --- ovozli xabar ---
    async def _handle_voice(self, event, chat_id: int) -> None:
        cfg = self.cfg
        async with self.client.action(event.chat_id, "record-voice"):
            try:
                raw = await event.message.download_media(file=bytes)
                pcm = await audio.to_pcm(raw, 16000)
                if audio.pcm_duration(pcm, 16000) > cfg.max_voice_seconds:
                    await self._send(self.client.send_message, event.chat_id,
                                     f"Ovozli xabar juda uzun (>{cfg.max_voice_seconds} soniya), Zuxa.")
                    return
                lang = await self._lang(chat_id)
                text = await self.stt.transcribe(pcm, cfg.stt_language or None)
            except (ProviderError, audio.AudioError) as e:
                log.warning("Ovozni matnga o'girib bo'lmadi: %s", type(e).__name__)
                await self._send(self.client.send_message, event.chat_id,
                                 "Ovozni eshitib bo'lmadi, Zuxa. Qayta yuboring.")
                return
            if not text:
                await self._send(self.client.send_message, event.chat_id,
                                 "Hech narsa eshitilmadi, Zuxa. Qayta yuboring.")
                return
            answer = await self.brain.reply(chat_id, text, "voice")
            try:
                mp3 = await self.tts.synthesize(answer, pick_voice(cfg, lang))
                out_pcm = await audio.to_pcm(mp3, 48000)
                ogg = await audio.pcm_to_ogg_opus(out_pcm, 48000)
            except (ProviderError, audio.AudioError) as e:
                log.warning("Ovoz sintezi xato: %s", type(e).__name__)
                await self._pause()
                await self._send(self.client.send_message, event.chat_id, answer)  # ovoz bo'lmasa matn bilan
                return
            await self._pause()
        await self._send_voice(event.chat_id, ogg, audio.pcm_duration(out_pcm, 48000))

    async def _send_voice(self, chat_id: int, ogg: bytes, duration: float) -> None:
        from telethon.tl.types import DocumentAttributeAudio

        f = io.BytesIO(ogg)
        f.name = "jarvis.ogg"
        await self._send(
            self.client.send_file, chat_id, f, voice_note=True,
            attributes=[DocumentAttributeAudio(duration=max(1, int(round(duration))), voice=True)],
        )
