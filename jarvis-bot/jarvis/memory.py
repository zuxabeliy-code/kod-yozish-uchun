"""SQLite: har chat uchun oxirgi N xabar va sozlamalar."""
from __future__ import annotations

import asyncio
import os
import sqlite3
import threading
import time


class Memory:
    def __init__(self, path: str, history_limit: int = 20):
        self.path = path
        self.history_limit = history_limit
        if path != ":memory:":
            os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._lock = threading.Lock()
        with self._lock:
            self._db.executescript(
                """
                CREATE TABLE IF NOT EXISTS messages(
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    chat_id INTEGER NOT NULL,
                    role TEXT NOT NULL CHECK(role IN ('user','assistant')),
                    content TEXT NOT NULL,
                    ts REAL NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_messages_chat ON messages(chat_id, id);
                CREATE TABLE IF NOT EXISTS settings(
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                """
            )
            self._db.commit()
        if path != ":memory:":
            try:
                os.chmod(path, 0o600)
            except OSError:
                pass

    # --- sinxron yadro (testlar uchun ham qulay) ---
    def _add(self, chat_id: int, role: str, content: str) -> None:
        with self._lock:
            self._db.execute(
                "INSERT INTO messages(chat_id, role, content, ts) VALUES (?,?,?,?)",
                (chat_id, role, content, time.time()),
            )
            # eskilarini kesib tashlash (limitdan 2 barobar ko'p saqlanmaydi)
            self._db.execute(
                "DELETE FROM messages WHERE chat_id=? AND id NOT IN "
                "(SELECT id FROM messages WHERE chat_id=? ORDER BY id DESC LIMIT ?)",
                (chat_id, chat_id, max(self.history_limit * 2, 2)),
            )
            self._db.commit()

    def _history(self, chat_id: int) -> list[dict[str, str]]:
        with self._lock:
            rows = self._db.execute(
                "SELECT role, content FROM messages WHERE chat_id=? ORDER BY id DESC LIMIT ?",
                (chat_id, self.history_limit),
            ).fetchall()
        msgs = [{"role": r, "content": c} for r, c in reversed(rows)]
        # Anthropic API birinchi xabar 'user' bo'lishini talab qiladi
        while msgs and msgs[0]["role"] != "user":
            msgs.pop(0)
        return msgs

    def _clear(self, chat_id: int) -> None:
        with self._lock:
            self._db.execute("DELETE FROM messages WHERE chat_id=?", (chat_id,))
            self._db.commit()

    def _get_setting(self, key: str, default: str | None) -> str | None:
        with self._lock:
            row = self._db.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
        return row[0] if row else default

    def _set_setting(self, key: str, value: str) -> None:
        with self._lock:
            self._db.execute(
                "INSERT INTO settings(key, value) VALUES (?,?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                (key, value),
            )
            self._db.commit()

    # --- async interfeys ---
    async def add_message(self, chat_id: int, role: str, content: str) -> None:
        await asyncio.to_thread(self._add, chat_id, role, content)

    async def get_history(self, chat_id: int) -> list[dict[str, str]]:
        return await asyncio.to_thread(self._history, chat_id)

    async def clear(self, chat_id: int) -> None:
        await asyncio.to_thread(self._clear, chat_id)

    async def get_setting(self, key: str, default: str | None = None) -> str | None:
        return await asyncio.to_thread(self._get_setting, key, default)

    async def set_setting(self, key: str, value: str) -> None:
        await asyncio.to_thread(self._set_setting, key, value)

    def close(self) -> None:
        with self._lock:
            self._db.close()
