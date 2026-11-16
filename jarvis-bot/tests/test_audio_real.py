import shutil

import numpy as np
import pytest

from jarvis import audio

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg yo'q")


async def test_pcm_ogg_roundtrip():
    n = 48000
    pcm = (np.sin(np.arange(n) * 2 * np.pi * 440 / 48000) * 8000).astype(np.int16).tobytes()
    ogg = await audio.pcm_to_ogg_opus(pcm, 48000)
    assert ogg[:4] == b"OggS"
    back = await audio.to_pcm(ogg, 16000)
    assert 0.9 < audio.pcm_duration(back, 16000) < 1.2


async def test_bad_input_raises():
    with pytest.raises(audio.AudioError):
        await audio.to_pcm(b"not audio", 16000)
