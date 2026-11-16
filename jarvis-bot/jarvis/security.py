"""Faqat egasiga javob berish va oddiy flood himoya."""
from __future__ import annotations

import time
from collections import defaultdict, deque
from collections.abc import Callable, Iterable


class AccessControl:
    """OWNER_ID / ALLOWED_IDS filtri. Begonalar butunlay e'tiborsiz."""

    def __init__(self, allowed_ids: Iterable[int]):
        self._allowed = frozenset(int(i) for i in allowed_ids)

    def is_allowed(self, user_id: int | None) -> bool:
        return user_id is not None and user_id in self._allowed

    @property
    def allowed(self) -> frozenset[int]:
        return self._allowed


class RateLimiter:
    """Sirpanuvchi oyna: 60 soniyada ko'pi bilan `per_minute` ta xabar."""

    def __init__(self, per_minute: int, clock: Callable[[], float] = time.monotonic):
        self.per_minute = per_minute
        self._clock = clock
        self._hits: dict[int, deque[float]] = defaultdict(deque)

    def allow(self, key: int) -> bool:
        if self.per_minute <= 0:
            return True
        now = self._clock()
        q = self._hits[key]
        while q and now - q[0] >= 60.0:
            q.popleft()
        if len(q) >= self.per_minute:
            return False
        q.append(now)
        return True
