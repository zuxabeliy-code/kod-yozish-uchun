"""Nutqni bo'laklarga ajratish (VAD). 16 kHz mono s16le, 30 ms kadrlar."""
from __future__ import annotations

from collections.abc import Callable

import numpy as np

SAMPLE_RATE = 16000
FRAME_MS = 30
FRAME_BYTES = SAMPLE_RATE * FRAME_MS // 1000 * 2  # 960


def downsample_48k_to_16k(pcm: bytes) -> bytes:
    """48 kHz mono s16le -> 16 kHz (3 ta namunaning o'rtachasi)."""
    arr = np.frombuffer(pcm[: len(pcm) - len(pcm) % 6], dtype=np.int16).astype(np.int32)
    if arr.size == 0:
        return b""
    return arr.reshape(-1, 3).mean(axis=1).astype(np.int16).tobytes()


def make_vad(aggressiveness: int = 2) -> Callable[[bytes], bool]:
    """webrtcvad bo'lsa shuni, bo'lmasa oddiy energiya (RMS) VAD'ni qaytaradi."""
    try:
        import webrtcvad

        v = webrtcvad.Vad(aggressiveness)
        return lambda frame: v.is_speech(frame, SAMPLE_RATE)
    except Exception:
        return energy_vad()


def energy_vad(threshold: float = 500.0) -> Callable[[bytes], bool]:
    def f(frame: bytes) -> bool:
        a = np.frombuffer(frame, dtype=np.int16).astype(np.float64)
        return a.size > 0 and float(np.sqrt(np.mean(a * a))) > threshold

    return f


class Segmenter:
    """Oqimdan nutq bo'laklarini ajratadi.

    feed(pcm16k) -> hodisalar ro'yxati: ("start", None) nutq boshlanganda,
    ("segment", pcm) nutq tugab, jimlik silence_ms davom etganda.
    """

    def __init__(self, vad: Callable[[bytes], bool], silence_ms: int = 700, min_speech_ms: int = 300,
                 max_segment_s: float = 15.0, start_frames: int = 3, preroll_frames: int = 8):
        self.vad = vad
        self.silence_frames = max(1, silence_ms // FRAME_MS)
        self.min_frames = max(1, min_speech_ms // FRAME_MS)
        self.max_frames = int(max_segment_s * 1000 // FRAME_MS)
        self.start_frames = start_frames
        self.preroll_frames = preroll_frames
        self._buf = b""
        self._pre: list[bytes] = []
        self._voiced_run = 0
        self._active = False
        self._seg: list[bytes] = []
        self._silence = 0
        self._voiced_total = 0

    def reset(self) -> None:
        self._buf = b""
        self._pre.clear()
        self._voiced_run = 0
        self._active = False
        self._seg = []
        self._silence = 0
        self._voiced_total = 0

    def feed(self, pcm16k: bytes) -> list[tuple[str, bytes | None]]:
        events: list[tuple[str, bytes | None]] = []
        self._buf += pcm16k
        while len(self._buf) >= FRAME_BYTES:
            frame, self._buf = self._buf[:FRAME_BYTES], self._buf[FRAME_BYTES:]
            speech = self.vad(frame)
            if not self._active:
                self._pre.append(frame)
                if len(self._pre) > self.preroll_frames:
                    self._pre.pop(0)
                self._voiced_run = self._voiced_run + 1 if speech else 0
                if self._voiced_run >= self.start_frames:
                    self._active = True
                    self._seg = list(self._pre)
                    self._pre.clear()
                    self._silence = 0
                    self._voiced_total = self._voiced_run
                    events.append(("start", None))
            else:
                self._seg.append(frame)
                if speech:
                    self._silence = 0
                    self._voiced_total += 1
                else:
                    self._silence += 1
                if self._silence >= self.silence_frames or len(self._seg) >= self.max_frames:
                    if self._voiced_total >= self.min_frames:
                        events.append(("segment", b"".join(self._seg)))
                    self._active = False
                    self._seg = []
                    self._voiced_run = 0
                    self._silence = 0
                    self._voiced_total = 0
        return events
