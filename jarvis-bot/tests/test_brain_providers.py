from types import SimpleNamespace

import httpx
import pytest

from jarvis.brain import FALLBACK_TEXT, Brain
from jarvis.config import ConfigError, load_config
from jarvis.memory import Memory
from jarvis.providers import build_llm, build_stt, build_tts
from jarvis.providers.base import LLMProvider, ProviderError
from jarvis.providers.llm import AnthropicLLM
from jarvis.providers.stt import FasterWhisperSTT, OpenAICompatSTT, pcm_to_wav
from jarvis.providers.tts import EdgeTTS


class FakeLLM(LLMProvider):
    def __init__(self, fail=False):
        self.calls = []
        self.fail = fail

    async def complete(self, system, messages, max_tokens):
        self.calls.append((system, list(messages), max_tokens))
        if self.fail:
            raise ProviderError("x")
        return "javob"


async def test_brain_saves_history_and_voice_mode():
    llm, mem = FakeLLM(), Memory(":memory:")
    b = Brain(llm, mem, "SYS", 800, 250)
    assert await b.reply(1, "salom") == "javob"
    assert await b.reply(1, "yana", "voice") == "javob"
    sys_t, msgs, mt = llm.calls[0]
    assert sys_t == "SYS" and mt == 800 and msgs == [{"role": "user", "content": "salom"}]
    sys_v, msgs_v, mt_v = llm.calls[1]
    assert "ovozli rejim" in sys_v and mt_v == 250 and len(msgs_v) == 3


async def test_brain_failure_not_stored():
    mem = Memory(":memory:")
    b = Brain(FakeLLM(fail=True), mem, "SYS")
    assert await b.reply(1, "salom") == FALLBACK_TEXT
    assert await mem.get_history(1) == []


async def test_anthropic_llm_with_mock_client():
    calls = {}

    class Msgs:
        async def create(self, **kw):
            calls.update(kw)
            return SimpleNamespace(content=[SimpleNamespace(type="text", text=" Salom ")])

    llm = AnthropicLLM("k", "claude-sonnet-5-5", client=SimpleNamespace(messages=Msgs()))
    assert await llm.complete("S", [{"role": "user", "content": "q"}], 50) == "Salom"
    assert calls["model"] == "claude-sonnet-5-5" and calls["system"] == "S" and calls["max_tokens"] == 50


async def test_anthropic_llm_errors_wrapped():
    class Msgs:
        async def create(self, **kw):
            raise RuntimeError("secret-key-xyz")

    llm = AnthropicLLM("k", "m", client=SimpleNamespace(messages=Msgs()))
    with pytest.raises(ProviderError) as e:
        await llm.complete("S", [], 5)
    assert "secret-key-xyz" not in str(e.value)


async def test_openai_compat_stt_mock_transport():
    seen = {}

    def handler(req: httpx.Request):
        seen["url"] = str(req.url)
        seen["auth"] = req.headers["authorization"]
        seen["body"] = req.content
        return httpx.Response(200, json={"text": " salom dunyo "})

    stt = OpenAICompatSTT("https://api.example.com/v1/", "KEY", "whisper-1",
                          client=httpx.AsyncClient(transport=httpx.MockTransport(handler)))
    assert await stt.transcribe(b"\0\0" * 1600, "uz") == "salom dunyo"
    assert seen["url"] == "https://api.example.com/v1/audio/transcriptions"
    assert seen["auth"] == "Bearer KEY" and b"RIFF" in seen["body"] and b'name="language"' in seen["body"]


async def test_openai_compat_stt_http_error():
    stt = OpenAICompatSTT("https://x/v1", "K", client=httpx.AsyncClient(
        transport=httpx.MockTransport(lambda r: httpx.Response(500))))
    with pytest.raises(ProviderError):
        await stt.transcribe(b"\0\0" * 100, None)


async def test_faster_whisper_with_fake_model():
    class M:
        def transcribe(self, audio, **kw):
            assert audio.dtype.name == "float32" and kw["language"] == "uz"
            return iter([SimpleNamespace(text=" bir "), SimpleNamespace(text="ikki")]), None

    stt = FasterWhisperSTT(model_obj=M())
    assert await stt.transcribe(b"\x01\x00" * 1600, "uz") == "bir ikki"
    assert await stt.transcribe(b"", "uz") == ""


async def test_edge_tts_with_fake_communicate():
    class C:
        def __init__(self, text, voice, rate):
            assert voice == "uz-UZ-MadinaNeural"

        async def stream(self):
            yield {"type": "WordBoundary"}
            yield {"type": "audio", "data": b"ab"}
            yield {"type": "audio", "data": b"cd"}

    assert await EdgeTTS(communicate_cls=C).synthesize("salom", "uz-UZ-MadinaNeural") == b"abcd"
    with pytest.raises(ProviderError):
        await EdgeTTS(communicate_cls=C).synthesize("  ", "v")


def test_pcm_to_wav_header():
    assert pcm_to_wav(b"\0\0" * 10)[:4] == b"RIFF"


def test_factories(cfg):
    assert isinstance(build_llm(cfg), AnthropicLLM)
    assert isinstance(build_stt(cfg), FasterWhisperSTT)
    assert isinstance(build_tts(cfg), EdgeTTS)
    env = {"API_ID": "1", "API_HASH": "h", "OWNER_ID": "1", "ANTHROPIC_API_KEY": "k"}
    with pytest.raises(ConfigError):
        build_stt(load_config({**env, "STT_PROVIDER": "openai"}))  # kalit yo'q
    assert isinstance(build_stt(load_config({**env, "STT_PROVIDER": "openai", "STT_API_KEY": "z"})), OpenAICompatSTT)
    with pytest.raises(ConfigError):
        build_llm(load_config({**env, "LLM_PROVIDER": "nope"}))
