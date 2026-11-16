"""ffmpeg orqali ovoz konvertatsiya (pipe orqali, vaqtinchalik fayl yo'q)."""
from __future__ import annotations

import asyncio


class AudioError(Exception):
    pass


async def _ffmpeg(args: list[str], data: bytes, timeout: float = 60.0) -> bytes:
    try:
        proc = await asyncio.create_subprocess_exec(
            "ffmpeg", "-hide_banner", "-loglevel", "error", *args,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
    except FileNotFoundError as e:
        raise AudioError("ffmpeg o'rnatilmagan") from e
    try:
        out, err = await asyncio.wait_for(proc.communicate(data), timeout)
    except TimeoutError as e:
        proc.kill()
        raise AudioError("ffmpeg vaqti tugadi") from e
    if proc.returncode != 0:
        raise AudioError("ffmpeg xato: " + err.decode(errors="replace")[:200])
    return out


async def to_pcm(data: bytes, sample_rate: int) -> bytes:
    """Istalgan audio (ogg/mp3/...) -> mono s16le PCM."""
    return await _ffmpeg(["-i", "pipe:0", "-f", "s16le", "-ar", str(sample_rate), "-ac", "1", "pipe:1"], data)


async def pcm_to_ogg_opus(pcm: bytes, sample_rate: int = 48000) -> bytes:
    """Mono s16le PCM -> Telegram ovozli xabar (ogg/opus)."""
    return await _ffmpeg(
        ["-f", "s16le", "-ar", str(sample_rate), "-ac", "1", "-i", "pipe:0",
         "-c:a", "libopus", "-b:a", "32k", "-application", "voip", "-f", "ogg", "pipe:1"],
        pcm,
    )


def pcm_duration(pcm: bytes, sample_rate: int) -> float:
    return len(pcm) / (2.0 * sample_rate)
