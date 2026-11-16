"""Jonli ovozli qo'ng'iroq (py-tgcalls 3.x). XAVFLI MODUL: xato bo'lsa matn/ovoz ishlayveradi.

Oqim: kiruvchi qo'ng'iroq -> qabul (play) -> record() -> kiruvchi PCM (48 kHz mono)
-> 16 kHz -> VAD -> STT -> AI -> TTS -> 10 ms kadrlar bilan send_frame().
py-tgcalls API'si kutubxona manba kodidan o'qildi (v3.0.1), haqiqiy qo'ng'iroqda SINALMAGAN.
"""
from __future__ import annotations

import asyncio
import logging
import time

from . import audio
from .brain import Brain
from .config import Config
from .handlers import pick_voice
from .memory import Memory
from .providers.base import ProviderError, STTProvider, TTSProvider
from .security import AccessControl
from .vad import Segmenter, downsample_48k_to_16k, make_vad

log = logging.getLogger(__name__)

CALL_RATE = 48000
CHUNK_BYTES = CALL_RATE // 100 * 2  # 10 ms


class CallSession:
    """Bitta qo'ng'iroq holati: VAD, navbat, gapirish va to'xtatish (barge-in)."""

    def __init__(self, chat_id: int, mgr: CallManager):
        self.chat_id = chat_id
        self.mgr = mgr
        self.seg = Segmenter(make_vad(mgr.cfg.vad_aggressiveness), silence_ms=mgr.cfg.vad_silence_ms)
        self.queue: asyncio.Queue[bytes] = asyncio.Queue(maxsize=4)
        self.speaking = False
        self.interrupt = asyncio.Event()
        self.task: asyncio.Task | None = None

    def feed(self, pcm48: bytes) -> None:
        """Sinxron va tez: kiruvchi kadrlar tartibi buzilmasligi uchun."""
        for kind, payload in self.seg.feed(downsample_48k_to_16k(pcm48)):
            if kind == "start" and self.speaking:
                self.interrupt.set()  # barge-in: Zuxa gap boshladi
            elif kind == "segment" and payload:
                try:
                    self.queue.put_nowait(payload)
                except asyncio.QueueFull:
                    log.warning("Qo'ng'iroq navbati to'la, bo'lak tashlandi")

    async def run(self) -> None:
        mgr = self.mgr
        try:
            if mgr.cfg.call_greeting:
                await self.speak(mgr.cfg.call_greeting)
            while True:
                pcm = await self.queue.get()
                try:
                    text = await mgr.stt.transcribe(pcm, mgr.cfg.stt_language or None)
                    if not text:
                        continue
                    answer = await mgr.brain.reply(self.chat_id, text, "call")
                    await self.speak(answer)
                except (ProviderError, audio.AudioError) as e:
                    log.warning("Qo'ng'iroq bo'lagi xato: %s", type(e).__name__)
        except asyncio.CancelledError:
            raise
        except Exception:
            log.exception("Qo'ng'iroq sessiyasi to'xtadi")

    async def speak(self, text: str) -> None:
        mgr = self.mgr
        lang = await mgr.memory.get_setting(f"lang:{self.chat_id}", mgr.cfg.tts_lang)
        mp3 = await mgr.tts.synthesize(text, pick_voice(mgr.cfg, lang))
        pcm = await audio.to_pcm(mp3, CALL_RATE)
        self.interrupt.clear()
        self.speaking = True
        try:
            next_t = time.monotonic()
            for i in range(0, len(pcm), CHUNK_BYTES):
                if self.interrupt.is_set():
                    break
                await mgr.send_frame(self.chat_id, pcm[i : i + CHUNK_BYTES].ljust(CHUNK_BYTES, b"\0"))
                next_t += 0.01
                delay = next_t - time.monotonic()
                if delay > 0:
                    await asyncio.sleep(delay)
        finally:
            self.speaking = False
            self.interrupt.clear()


class CallManager:
    def __init__(self, client, cfg: Config, access: AccessControl, brain: Brain, memory: Memory,
                 stt: STTProvider, tts: TTSProvider):
        self.client = client
        self.cfg = cfg
        self.access = access
        self.brain = brain
        self.memory = memory
        self.stt = stt
        self.tts = tts
        self.tg = None
        self.sessions: dict[int, CallSession] = {}
        self._Device = None

    async def start(self) -> None:
        # kutubxonalar faqat shu yerda import qilinadi: o'rnatilmagan bo'lsa ham bot ishlaydi
        from pytgcalls import PyTgCalls, filters
        from pytgcalls.types import ChatUpdate, Device, Direction

        self._Device = Device
        self.tg = PyTgCalls(self.client)  # ishlayotgan event loop ichida yaratilishi shart

        @self.tg.on_update(filters.chat_update(ChatUpdate.Status.INCOMING_CALL))
        async def _incoming(_, update):
            await self._on_incoming(update.chat_id)

        @self.tg.on_update(filters.chat_update(ChatUpdate.Status.LEFT_CALL))
        async def _ended(_, update):
            await self._end(update.chat_id)

        @self.tg.on_update(filters.stream_frame(Direction.INCOMING))
        async def _frames(_, update):
            if update.device is not None and update.device & (Device.CAMERA | Device.SCREEN):
                return
            s = self.sessions.get(update.chat_id)
            if s is not None:
                for fr in update.frames:
                    s.feed(fr.frame)

        await self.tg.start()
        log.info("Qo'ng'iroq moduli ishga tushdi")

    async def _on_incoming(self, user_id: int) -> None:
        if not self.access.is_allowed(user_id):
            return  # begona: qabul ham, rad ham qilmaymiz (jim)
        if user_id in self.sessions:
            return
        try:
            await self._accept(user_id)
        except Exception:
            log.exception("Qo'ng'iroqni qabul qilib bo'lmadi")
            await self._end(user_id)

    async def _accept(self, user_id: int) -> None:
        from ntgcalls import MediaSource
        from pytgcalls.types import RecordStream
        from pytgcalls.types.raw import AudioParameters, AudioStream, Stream

        params = AudioParameters(CALL_RATE, 1)  # (sample rate, kanallar)
        # play() kiruvchi p2p qo'ng'iroqni qabul qiladi; EXTERNAL = kadrlarni o'zimiz yuboramiz
        out = Stream(microphone=AudioStream(MediaSource.EXTERNAL, "", params))
        session = CallSession(user_id, self)
        self.sessions[user_id] = session
        await self.tg.play(user_id, out)
        await self.tg.record(user_id, RecordStream(audio=True, audio_parameters=params))
        session.task = asyncio.create_task(session.run())
        log.info("Qo'ng'iroq qabul qilindi")

    async def send_frame(self, chat_id: int, data: bytes) -> None:
        await self.tg.send_frame(chat_id, self._Device.MICROPHONE, data)

    async def _end(self, chat_id: int) -> None:
        s = self.sessions.pop(chat_id, None)
        if s and s.task:
            s.task.cancel()
            try:
                await s.task
            except (asyncio.CancelledError, Exception):
                pass
            log.info("Qo'ng'iroq tugadi")
