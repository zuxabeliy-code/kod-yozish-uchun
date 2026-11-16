from jarvis.memory import Memory
from jarvis.security import AccessControl, RateLimiter


def test_access_control():
    a = AccessControl({1, 2})
    assert a.is_allowed(1) and a.is_allowed(2)
    assert not a.is_allowed(3) and not a.is_allowed(None) and not a.is_allowed(0)


def test_rate_limiter_window():
    t = [0.0]
    r = RateLimiter(3, clock=lambda: t[0])
    assert [r.allow(1) for _ in range(4)] == [True, True, True, False]
    assert r.allow(2)  # boshqa kalit mustaqil
    t[0] = 61.0
    assert r.allow(1)


async def test_memory_history_limit_and_order():
    m = Memory(":memory:", history_limit=4)
    for i in range(6):
        await m.add_message(1, "user", f"u{i}")
        await m.add_message(1, "assistant", f"a{i}")
    h = await m.get_history(1)
    assert len(h) <= 4 and h[0]["role"] == "user"
    assert h[-1] == {"role": "assistant", "content": "a5"}
    assert await m.get_history(2) == []


async def test_memory_clear_and_settings():
    m = Memory(":memory:")
    await m.add_message(1, "user", "x")
    await m.clear(1)
    assert await m.get_history(1) == []
    assert await m.get_setting("k", "d") == "d"
    await m.set_setting("k", "v1")
    await m.set_setting("k", "v2")
    assert await m.get_setting("k") == "v2"


async def test_memory_persists_on_disk(tmp_path):
    p = str(tmp_path / "sub" / "m.db")
    m = Memory(p)
    await m.add_message(5, "user", "salom")
    m.close()
    assert (await Memory(p).get_history(5))[0]["content"] == "salom"
