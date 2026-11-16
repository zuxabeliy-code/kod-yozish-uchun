"""Abstrakt provayder interfeyslari: LLM, STT, TTS."""
from __future__ import annotations

from abc import ABC, abstractmethod


class ProviderError(Exception):
    """Provayder ishlamadi (tarmoq, kalit, limit...). Matnda sirlar bo'lmasin."""


class LLMProvider(ABC):
    @abstractmethod
    async def complete(self, system: str, messages: list[dict[str, str]], max_tokens: int) -> str:
        """messages: [{'role': 'user'|'assistant', 'content': str}, ...]."""


class STTProvider(ABC):
    @abstractmethod
    async def transcribe(self, pcm16k: bytes, language: str | None) -> str:
        """16 kHz, mono, s16le PCM -> matn."""


class TTSProvider(ABC):
    @abstractmethod
    async def synthesize(self, text: str, voice: str) -> bytes:
        """Matn -> audio baytlari (ffmpeg o'qiy oladigan format, masalan mp3)."""
