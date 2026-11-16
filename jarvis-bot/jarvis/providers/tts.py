from __future__ import annotations

import logging

from .base import ProviderError, TTSProvider

log = logging.getLogger(__name__)


class EdgeTTS(TTSProvider):
    def __init__(self, rate: str = "+0%", communicate_cls=None):
        self._rate = rate
        self._cls = communicate_cls

    async def synthesize(self, text: str, voice: str) -> bytes:
        text = text.strip()
        if not text:
            raise ProviderError("TTS uchun matn bo'sh")
        cls = self._cls
        if cls is None:
            import edge_tts

            cls = edge_tts.Communicate
        try:
            chunks = bytearray()
            async for ch in cls(text, voice, rate=self._rate).stream():
                if ch.get("type") == "audio":
                    chunks += ch["data"]
        except Exception as e:
            log.warning("edge-tts xato: %s", type(e).__name__)
            raise ProviderError(f"TTS xatosi: {type(e).__name__}") from e
        if not chunks:
            raise ProviderError("TTS audio qaytarmadi")
        return bytes(chunks)
