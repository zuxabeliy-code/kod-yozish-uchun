"""Sozlamalarni .env / muhit o'zgaruvchilaridan yuklash. Kalitlar kodda yo'q."""
from __future__ import annotations

import os
from collections.abc import Mapping
from dataclasses import dataclass, field


class ConfigError(Exception):
    pass


def _bool(v: str | None, default: bool) -> bool:
    if v is None or v.strip() == "":
        return default
    return v.strip().lower() in ("1", "true", "yes", "on", "ha")


def _int(env: Mapping[str, str], key: str, default: int) -> int:
    v = env.get(key, "").strip()
    if not v:
        return default
    try:
        return int(v)
    except ValueError as e:
        raise ConfigError(f"{key} butun son bo'lishi kerak") from e


def _float(env: Mapping[str, str], key: str, default: float) -> float:
    v = env.get(key, "").strip()
    if not v:
        return default
    try:
        return float(v)
    except ValueError as e:
        raise ConfigError(f"{key} son bo'lishi kerak") from e


def _ids(v: str) -> frozenset[int]:
    out: set[int] = set()
    for part in v.replace(";", ",").split(","):
        part = part.strip()
        if not part:
            continue
        try:
            out.add(int(part))
        except ValueError as e:
            raise ConfigError(f"ALLOWED_IDS ichida noto'g'ri id: {part!r}") from e
    return frozenset(out)


@dataclass(frozen=True)
class Config:
    api_id: int
    api_hash: str = field(repr=False)
    session_path: str = "data/jarvis"
    owner_id: int = 0
    allowed_ids: frozenset[int] = frozenset()

    llm_provider: str = "anthropic"
    anthropic_api_key: str = field(default="", repr=False)
    anthropic_model: str = "claude-sonnet-5-5"
    max_tokens_text: int = 800
    max_tokens_voice: int = 250
    history_limit: int = 20
    system_prompt_path: str = "prompts/system.md"

    stt_provider: str = "faster_whisper"
    stt_language: str = "uz"
    whisper_model: str = "small"
    whisper_device: str = "cpu"
    whisper_compute_type: str = "int8"
    stt_api_base_url: str = "https://api.openai.com/v1"
    stt_api_key: str = field(default="", repr=False)
    stt_api_model: str = "whisper-1"

    tts_provider: str = "edge"
    tts_lang: str = "uz"
    tts_voices: Mapping[str, str] = field(
        default_factory=lambda: {
            "uz": "uz-UZ-MadinaNeural",
            "ru": "ru-RU-SvetlanaNeural",
            "en": "en-US-GuyNeural",
        }
    )
    tts_rate: str = "+0%"

    calls_enabled: bool = True
    vad_aggressiveness: int = 2
    vad_silence_ms: int = 700
    call_greeting: str = "Salom Zuxa, eshitaman."

    rate_limit_per_min: int = 20
    reply_delay_min: float = 0.8
    reply_delay_max: float = 2.0
    max_voice_seconds: int = 120

    db_path: str = "data/jarvis.db"
    log_level: str = "INFO"

    @property
    def allowed(self) -> frozenset[int]:
        """Javob beriladigan barcha id'lar (OWNER_ID + ALLOWED_IDS)."""
        s = set(self.allowed_ids)
        if self.owner_id:
            s.add(self.owner_id)
        return frozenset(s)


def load_config(env: Mapping[str, str] | None = None, *, for_login: bool = False) -> Config:
    """Muhitdan Config yasaydi. for_login=True bo'lsa faqat API_ID/API_HASH shart."""
    env = os.environ if env is None else env
    missing: list[str] = []

    def req(key: str) -> str:
        v = env.get(key, "").strip()
        if not v:
            missing.append(key)
        return v

    api_id_raw = req("API_ID")
    api_hash = req("API_HASH")
    owner_raw = "" if for_login else req("OWNER_ID")
    provider = env.get("LLM_PROVIDER", "anthropic").strip() or "anthropic"
    key = env.get("ANTHROPIC_API_KEY", "").strip()
    if not for_login and provider == "anthropic" and not key:
        missing.append("ANTHROPIC_API_KEY")
    if missing:
        raise ConfigError("Quyidagi sozlamalar .env'da yo'q: " + ", ".join(missing))

    try:
        api_id = int(api_id_raw)
    except ValueError as e:
        raise ConfigError("API_ID butun son bo'lishi kerak") from e
    owner_id = 0
    if owner_raw:
        try:
            owner_id = int(owner_raw)
        except ValueError as e:
            raise ConfigError("OWNER_ID butun son bo'lishi kerak") from e

    voices = {
        "uz": env.get("TTS_VOICE_UZ", "").strip() or "uz-UZ-MadinaNeural",
        "ru": env.get("TTS_VOICE_RU", "").strip() or "ru-RU-SvetlanaNeural",
        "en": env.get("TTS_VOICE_EN", "").strip() or "en-US-GuyNeural",
    }
    tts_lang = (env.get("TTS_LANG", "uz").strip() or "uz").lower()
    if tts_lang not in voices:
        raise ConfigError("TTS_LANG: uz, ru yoki en bo'lishi kerak")

    def s(k: str, d: str) -> str:
        return env.get(k, "").strip() or d

    cfg = Config(
        api_id=api_id,
        api_hash=api_hash,
        session_path=s("SESSION_PATH", "data/jarvis"),
        owner_id=owner_id,
        allowed_ids=_ids(env.get("ALLOWED_IDS", "")),
        llm_provider=provider,
        anthropic_api_key=key,
        anthropic_model=s("ANTHROPIC_MODEL", "claude-sonnet-5-5"),
        max_tokens_text=_int(env, "MAX_TOKENS_TEXT", 800),
        max_tokens_voice=_int(env, "MAX_TOKENS_VOICE", 250),
        history_limit=_int(env, "HISTORY_LIMIT", 20),
        system_prompt_path=s("SYSTEM_PROMPT_PATH", "prompts/system.md"),
        stt_provider=s("STT_PROVIDER", "faster_whisper"),
        stt_language=s("STT_LANGUAGE", "uz"),
        whisper_model=s("WHISPER_MODEL", "small"),
        whisper_device=s("WHISPER_DEVICE", "cpu"),
        whisper_compute_type=s("WHISPER_COMPUTE_TYPE", "int8"),
        stt_api_base_url=s("STT_API_BASE_URL", "https://api.openai.com/v1"),
        stt_api_key=env.get("STT_API_KEY", "").strip(),
        stt_api_model=s("STT_API_MODEL", "whisper-1"),
        tts_provider=s("TTS_PROVIDER", "edge"),
        tts_lang=tts_lang,
        tts_voices=voices,
        tts_rate=s("TTS_RATE", "+0%"),
        calls_enabled=_bool(env.get("CALLS_ENABLED"), True),
        vad_aggressiveness=_int(env, "VAD_AGGRESSIVENESS", 2),
        vad_silence_ms=_int(env, "VAD_SILENCE_MS", 700),
        call_greeting=env.get("CALL_GREETING", "Salom Zuxa, eshitaman.").strip(),
        rate_limit_per_min=_int(env, "RATE_LIMIT_PER_MIN", 20),
        reply_delay_min=_float(env, "REPLY_DELAY_MIN", 0.8),
        reply_delay_max=_float(env, "REPLY_DELAY_MAX", 2.0),
        max_voice_seconds=_int(env, "MAX_VOICE_SECONDS", 120),
        db_path=s("DB_PATH", "data/jarvis.db"),
        log_level=s("LOG_LEVEL", "INFO").upper(),
    )
    if not for_login and not cfg.allowed:
        raise ConfigError("OWNER_ID yoki ALLOWED_IDS bo'sh bo'lmasligi kerak")
    if not 0 <= cfg.vad_aggressiveness <= 3:
        raise ConfigError("VAD_AGGRESSIVENESS 0..3 oralig'ida bo'lishi kerak")
    return cfg


def load_dotenv_file(path: str = ".env") -> None:
    """.env faylni muhitga yuklaydi (mavjud o'zgaruvchilarni bosmaydi)."""
    try:
        from dotenv import load_dotenv
    except ImportError:  # pragma: no cover
        return
    load_dotenv(path, override=False)
