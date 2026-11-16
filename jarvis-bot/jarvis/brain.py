"""Miya: xotira + system prompt + LLM."""
from __future__ import annotations

import asyncio
import logging
from collections import defaultdict
from pathlib import Path

from .memory import Memory
from .providers.base import LLMProvider, ProviderError

log = logging.getLogger(__name__)

FALLBACK_TEXT = "Hozir miyam ishlamayapti, Zuxa. Birozdan keyin qayta urinib ko'ring."
DEFAULT_PROMPT = "Sen Jarvis — Zuxaning yordamchisisan. O'zbek tilida qisqa va do'stona javob ber."
VOICE_HINT = (
    "\n\n[Hozir ovozli rejim: javobing ovoz chiqarib o'qiladi. "
    "1-3 qisqa gap yoz, markdown, ro'yxat, havola va emoji ishlatma.]"
)


def load_system_prompt(path: str) -> str:
    try:
        text = Path(path).read_text(encoding="utf-8").strip()
        return text or DEFAULT_PROMPT
    except OSError:
        log.warning("System prompt fayli o'qilmadi (%s), standart ishlatiladi", path)
        return DEFAULT_PROMPT


class Brain:
    def __init__(self, llm: LLMProvider, memory: Memory, system_prompt: str,
                 max_tokens_text: int = 800, max_tokens_voice: int = 250):
        self.llm = llm
        self.memory = memory
        self.system_prompt = system_prompt
        self.max_tokens_text = max_tokens_text
        self.max_tokens_voice = max_tokens_voice
        self._locks: dict[int, asyncio.Lock] = defaultdict(asyncio.Lock)

    async def reply(self, chat_id: int, user_text: str, mode: str = "text") -> str:
        """mode: 'text' | 'voice' | 'call'. Xato bo'lsa xotiraga yozmaydi."""
        voice = mode in ("voice", "call")
        async with self._locks[chat_id]:
            history = await self.memory.get_history(chat_id)
            messages = history + [{"role": "user", "content": user_text}]
            system = self.system_prompt + (VOICE_HINT if voice else "")
            try:
                answer = await self.llm.complete(
                    system, messages, self.max_tokens_voice if voice else self.max_tokens_text
                )
            except ProviderError:
                return FALLBACK_TEXT
            await self.memory.add_message(chat_id, "user", user_text)
            await self.memory.add_message(chat_id, "assistant", answer)
            return answer
