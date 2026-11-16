"""Provayderlar reestri: .env'dagi nom bo'yicha tanlanadi."""
from __future__ import annotations

from ..config import Config, ConfigError
from .base import LLMProvider, ProviderError, STTProvider, TTSProvider

__all__ = ["LLMProvider", "STTProvider", "TTSProvider", "ProviderError", "build_llm", "build_stt", "build_tts"]


def build_llm(cfg: Config) -> LLMProvider:
    if cfg.llm_provider == "anthropic":
        from .llm import AnthropicLLM

        return AnthropicLLM(cfg.anthropic_api_key, cfg.anthropic_model)
    raise ConfigError(f"Noma'lum LLM_PROVIDER: {cfg.llm_provider}")


def build_stt(cfg: Config) -> STTProvider:
    if cfg.stt_provider == "faster_whisper":
        from .stt import FasterWhisperSTT

        return FasterWhisperSTT(cfg.whisper_model, cfg.whisper_device, cfg.whisper_compute_type)
    if cfg.stt_provider == "openai":
        from .stt import OpenAICompatSTT

        if not cfg.stt_api_key:
            raise ConfigError("STT_PROVIDER=openai uchun STT_API_KEY kerak")
        return OpenAICompatSTT(cfg.stt_api_base_url, cfg.stt_api_key, cfg.stt_api_model)
    raise ConfigError(f"Noma'lum STT_PROVIDER: {cfg.stt_provider}")


def build_tts(cfg: Config) -> TTSProvider:
    if cfg.tts_provider == "edge":
        from .tts import EdgeTTS

        return EdgeTTS(cfg.tts_rate)
    raise ConfigError(f"Noma'lum TTS_PROVIDER: {cfg.tts_provider}")
