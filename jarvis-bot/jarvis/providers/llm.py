from __future__ import annotations

import logging

from .base import LLMProvider, ProviderError

log = logging.getLogger(__name__)


class AnthropicLLM(LLMProvider):
    def __init__(self, api_key: str, model: str, client=None):
        if client is None:
            from anthropic import AsyncAnthropic

            client = AsyncAnthropic(api_key=api_key, timeout=60.0, max_retries=2)
        self._client = client
        self.model = model

    async def complete(self, system: str, messages: list[dict[str, str]], max_tokens: int) -> str:
        try:
            resp = await self._client.messages.create(
                model=self.model,
                max_tokens=max_tokens,
                system=system,
                messages=messages,
            )
        except Exception as e:  # SDK xatolari turlicha; matnni emas, turini yozamiz
            log.warning("Anthropic so'rovi xato: %s", type(e).__name__)
            raise ProviderError(f"LLM xatosi: {type(e).__name__}") from e
        text = "".join(b.text for b in resp.content if getattr(b, "type", "") == "text").strip()
        if not text:
            raise ProviderError("LLM bo'sh javob qaytardi")
        return text
