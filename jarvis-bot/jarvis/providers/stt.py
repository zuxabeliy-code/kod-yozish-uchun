from __future__ import annotations

import asyncio
import io
import logging
import wave

from .base import ProviderError, STTProvider

log = logging.getLogger(__name__)


def pcm_to_wav(pcm: bytes, sample_rate: int = 16000) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(sample_rate)
        w.writeframes(pcm)
    return buf.getvalue()


class FasterWhisperSTT(STTProvider):
    """Lokal faster-whisper. Model birinchi chaqiruvda yuklanadi."""

    def __init__(self, model: str = "small", device: str = "cpu", compute_type: str = "int8", model_obj=None):
        self._model_name = model
        self._device = device
        self._compute = compute_type
        self._model = model_obj
        self._lock = asyncio.Lock()

    def _load(self):
        if self._model is None:
            from faster_whisper import WhisperModel

            self._model = WhisperModel(self._model_name, device=self._device, compute_type=self._compute)
        return self._model

    def _run(self, pcm: bytes, language: str | None) -> str:
        import numpy as np

        audio = np.frombuffer(pcm, dtype=np.int16).astype(np.float32) / 32768.0
        segments, _info = self._load().transcribe(audio, language=language or None, vad_filter=True, beam_size=1)
        return " ".join(s.text.strip() for s in segments).strip()

    async def transcribe(self, pcm16k: bytes, language: str | None) -> str:
        if not pcm16k:
            return ""
        async with self._lock:  # bir vaqtda bitta transkripsiya (RAM tejash)
            try:
                return await asyncio.to_thread(self._run, pcm16k, language)
            except Exception as e:
                log.warning("faster-whisper xato: %s", type(e).__name__)
                raise ProviderError(f"STT xatosi: {type(e).__name__}") from e


class OpenAICompatSTT(STTProvider):
    """OpenAI-mos /audio/transcriptions (OpenAI, Groq va h.k.)."""

    def __init__(self, base_url: str, api_key: str, model: str = "whisper-1", client=None):
        import httpx

        self._url = base_url.rstrip("/") + "/audio/transcriptions"
        self._key = api_key
        self._model = model
        self._client = client or httpx.AsyncClient(timeout=60.0)

    async def transcribe(self, pcm16k: bytes, language: str | None) -> str:
        if not pcm16k:
            return ""
        data = {"model": self._model, "response_format": "json"}
        if language:
            data["language"] = language
        headers = {"Authorization": f"Bearer {self._key}"} if self._key else {}
        try:
            r = await self._client.post(
                self._url,
                headers=headers,
                data=data,
                files={"file": ("audio.wav", pcm_to_wav(pcm16k), "audio/wav")},
            )
            r.raise_for_status()
            return str(r.json().get("text", "")).strip()
        except Exception as e:
            log.warning("STT API xato: %s", type(e).__name__)
            raise ProviderError(f"STT API xatosi: {type(e).__name__}") from e
