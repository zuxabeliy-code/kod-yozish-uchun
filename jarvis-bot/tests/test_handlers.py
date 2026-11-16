from contextlib import asynccontextmanager
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from jarvis import handlers as H
from jarvis.memory import Memory
from jarvis.providers.base import ProviderError, STTProvider, TTSProvider
from jarvis.security import AccessControl, RateLimiter

OWNER = 111


class FakeClient:
    def __init__(self):
        self.send_message = AsyncMock()
        self.send_file = AsyncMock()
        self.actions = []

    @asynccontextmanager
    async def action(self, chat, kind):
        self.actions.append(kind)
        yield


class FakeSTT(STTProvider):
    def __init__(self, text="salom", fail=False):
        self.text, self.fail = text, fail

    async def transcribe(self, pcm16k, language):
        if self.fail:
            raise ProviderError("x")
        return self.text


class FakeTTS(TTSProvider):
    def __init__(self, fail=False):
        self.fail, self.voices = fail, []

    async def synthesize(self, text, voice):
        self.voices.append(voice)
        if self.fail:
            raise ProviderError("x")
        return b"mp3"


class FakeBrain:
    def __init__(self):
        self.calls = []

    async def reply(self, chat_id, text, mode="text"):
        self.calls.append((chat_id, text, mode))
        return "javob"


def ev(sender=OWNER, text="salom", voice=False, private=True, out=False):
    msg = SimpleNamespace(voice=object() if voice else None, download_media=AsyncMock(return_value=b"ogg"))
    return SimpleNamespace(sender_id=sender, chat_id=sender, raw_text=text, message=msg,
                           is_private=private, out=out)


@pytest.fixture
def env(cfg, monkeypatch):
    client, brain, mem = FakeClient(), FakeBrain(), Memory(":memory:")
    tts = FakeTTS()
    h = H.MessageHandlers(client, cfg, AccessControl(cfg.allowed), RateLimiter(5), brain, mem,
                          FakeSTT(), tts, sleep=AsyncMock())
    monkeypatch.setattr(H.audio, "to_pcm", AsyncMock(return_value=b"\0\0" * 16000))
    monkeypatch.setattr(H.audio, "pcm_to_ogg_opus", AsyncMock(return_value=b"OggS"))
    return SimpleNamespace(h=h, client=client, brain=brain, mem=mem, tts=tts)


async def test_stranger_totally_ignored(env):
    for e in (ev(sender=999), ev(sender=999, voice=True), ev(sender=999, text="/reset")):
        await env.h.on_message(e)
    env.client.send_message.assert_not_called()
    env.client.send_file.assert_not_called()
    assert env.brain.calls == [] and env.client.actions == []


async def test_group_and_outgoing_ignored(env):
    await env.h.on_message(ev(private=False))
    await env.h.on_message(ev(out=True))
    assert env.brain.calls == []


async def test_owner_text_reply(env):
    await env.h.on_message(ev(text="  salom  "))
    assert env.brain.calls == [(OWNER, "salom", "text")]
    env.client.send_message.assert_awaited_once_with(OWNER, "javob")
    assert "typing" in env.client.actions


async def test_rate_limit_one_notice_then_silence(env):
    for _ in range(5):
        await env.h.on_message(ev())
    await env.h.on_message(ev())
    await env.h.on_message(ev())
    assert len(env.brain.calls) == 5
    assert env.client.send_message.await_count == 5 + 1  # 5 javob + 1 ogohlantirish


async def test_voice_flow_stt_ai_tts(env):
    await env.h.on_message(ev(voice=True))
    assert env.brain.calls == [(OWNER, "salom", "voice")]
    env.client.send_file.assert_awaited_once()
    args, kw = env.client.send_file.call_args
    assert args[0] == OWNER and kw["voice_note"] is True and kw["attributes"][0].voice is True
    assert args[1].read() == b"OggS"
    env.client.send_message.assert_not_called()
    assert env.tts.voices == ["uz-UZ-MadinaNeural"]


async def test_voice_stt_failure_text_notice(env):
    env.h.stt = FakeSTT(fail=True)
    await env.h.on_message(ev(voice=True))
    assert env.brain.calls == [] and env.client.send_file.await_count == 0
    assert "eshitib bo'lmadi" in env.client.send_message.call_args.args[1]


async def test_voice_empty_transcript(env):
    env.h.stt = FakeSTT(text="")
    await env.h.on_message(ev(voice=True))
    assert env.brain.calls == [] and "eshitilmadi" in env.client.send_message.call_args.args[1]


async def test_voice_tts_failure_falls_back_to_text(env):
    env.h.tts = FakeTTS(fail=True)
    await env.h.on_message(ev(voice=True))
    env.client.send_message.assert_awaited_once_with(OWNER, "javob")
    assert env.client.send_file.await_count == 0


async def test_voice_too_long(env, monkeypatch):
    monkeypatch.setattr(H.audio, "to_pcm", AsyncMock(return_value=b"\0\0" * 16000 * 500))
    await env.h.on_message(ev(voice=True))
    assert env.brain.calls == [] and "uzun" in env.client.send_message.call_args.args[1]


async def test_commands_lang_and_reset(env):
    await env.h.on_message(ev(text="/til ru"))
    assert await env.mem.get_setting(f"lang:{OWNER}") == "ru"
    await env.h.on_message(ev(voice=True))
    assert env.tts.voices == ["ru-RU-SvetlanaNeural"]
    await env.mem.add_message(OWNER, "user", "x")
    await env.h.on_message(ev(text="/reset"))
    assert await env.mem.get_history(OWNER) == []
    await env.h.on_message(ev(text="/til de"))
    assert "Ishlatilishi" in env.client.send_message.call_args.args[1]
    assert env.brain.calls == [(OWNER, "salom", "voice")]  # buyruqlar AI'ga bormaydi


async def test_exception_in_handler_does_not_propagate(env):
    env.brain.reply = AsyncMock(side_effect=RuntimeError("boom"))
    await env.h.on_message(ev())  # istisno ichkarida ushlanadi
