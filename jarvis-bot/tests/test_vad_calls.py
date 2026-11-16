import asyncio
from types import SimpleNamespace
from unittest.mock import AsyncMock

import numpy as np

from jarvis import calls as C
from jarvis.security import AccessControl
from jarvis.vad import FRAME_BYTES, Segmenter, downsample_48k_to_16k, energy_vad


def tone(ms, rate=16000, amp=8000):
    n = rate * ms // 1000
    return (np.sin(np.arange(n) * 2 * np.pi * 440 / rate) * amp).astype(np.int16).tobytes()


def silence(ms, rate=16000):
    return b"\0\0" * (rate * ms // 1000)


def test_downsample_len_and_value():
    pcm48 = np.full(4800, 1000, dtype=np.int16).tobytes()
    out = downsample_48k_to_16k(pcm48)
    assert len(out) == 1600 * 2 and np.frombuffer(out, dtype=np.int16)[0] == 1000
    assert downsample_48k_to_16k(b"\0\0") == b""


def test_segmenter_one_segment():
    s = Segmenter(energy_vad(), silence_ms=300)
    ev = s.feed(silence(300) + tone(900) + silence(600))
    kinds = [k for k, _ in ev]
    assert kinds == ["start", "segment"]
    seg = ev[1][1]
    assert len(seg) % FRAME_BYTES == 0 and len(seg) >= 900 * 32 * 0.8


def test_segmenter_ignores_short_noise_and_silence():
    s = Segmenter(energy_vad(), silence_ms=300, min_speech_ms=300)
    assert s.feed(silence(2000)) == []
    ev = s.feed(tone(90) + silence(1000))  # 3 kadr: boshlanadi, lekin min_speech'dan qisqa
    assert [k for k, _ in ev] == ["start"]


def test_segmenter_chunked_feed_and_two_segments():
    s = Segmenter(energy_vad(), silence_ms=300)
    data = tone(600) + silence(500) + tone(600) + silence(500)
    ev = []
    for i in range(0, len(data), 700):  # tartibsiz kichik bo'laklar
        ev += s.feed(data[i:i + 700])
    assert [k for k, _ in ev] == ["start", "segment", "start", "segment"]


def make_mgr(cfg, **over):
    mgr = SimpleNamespace(
        cfg=cfg, memory=SimpleNamespace(get_setting=AsyncMock(return_value="uz")),
        stt=SimpleNamespace(transcribe=AsyncMock(return_value="salom")),
        tts=SimpleNamespace(synthesize=AsyncMock(return_value=b"mp3")),
        brain=SimpleNamespace(reply=AsyncMock(return_value="javob")),
        send_frame=AsyncMock(),
    )
    for k, v in over.items():
        setattr(mgr, k, v)
    return mgr


async def test_call_session_pipeline(cfg, monkeypatch):
    monkeypatch.setattr(C.audio, "to_pcm", AsyncMock(return_value=b"\x01\x00" * (48000 // 5)))  # 200 ms
    mgr = make_mgr(cfg)
    s = C.CallSession(7, mgr)
    s.seg = Segmenter(energy_vad(), silence_ms=300)
    task = asyncio.create_task(s.run())
    await asyncio.sleep(0.5)  # salomlashuv gapirib bo'lsin
    greet_frames = mgr.send_frame.await_count
    assert greet_frames >= 19  # 200 ms / 10 ms
    pcm48 = np.repeat(np.frombuffer(tone(800) + silence(500), dtype=np.int16), 3).tobytes()
    s.feed(pcm48)
    await asyncio.sleep(0.6)
    task.cancel()
    mgr.stt.transcribe.assert_awaited()
    mgr.brain.reply.assert_awaited_once_with(7, "salom", "call")
    assert mgr.send_frame.await_count > greet_frames
    assert all(len(c.args[1]) == C.CHUNK_BYTES for c in mgr.send_frame.await_args_list)


async def test_call_session_barge_in(cfg, monkeypatch):
    monkeypatch.setattr(C.audio, "to_pcm", AsyncMock(return_value=b"\x01\x00" * 48000 * 3))  # 3 soniya
    mgr = make_mgr(cfg)
    s = C.CallSession(7, mgr)
    s.seg = Segmenter(energy_vad(), silence_ms=300)
    speak = asyncio.create_task(s.speak("uzun gap"))
    await asyncio.sleep(0.15)
    assert s.speaking
    s.feed(np.repeat(np.frombuffer(tone(300), dtype=np.int16), 3).tobytes())  # Zuxa gap boshladi
    await asyncio.wait_for(speak, 1.0)
    assert mgr.send_frame.await_count < 100  # 300 kadrdan ancha oldin to'xtadi
    assert not s.speaking


async def test_stranger_call_not_accepted(cfg):
    mgr = C.CallManager(None, cfg, AccessControl({111}), None, None, None, None)
    mgr._accept = AsyncMock()
    await mgr._on_incoming(999)
    mgr._accept.assert_not_called()
    await mgr._on_incoming(111)
    mgr._accept.assert_awaited_once_with(111)


async def test_accept_failure_is_contained(cfg):
    mgr = C.CallManager(None, cfg, AccessControl({111}), None, None, None, None)
    mgr._accept = AsyncMock(side_effect=RuntimeError("boom"))
    await mgr._on_incoming(111)  # istisno chiqmaydi
    assert 111 not in mgr.sessions
