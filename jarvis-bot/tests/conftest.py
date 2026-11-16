import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from jarvis.config import load_config  # noqa: E402

BASE_ENV = {
    "API_ID": "12345",
    "API_HASH": "hash",
    "OWNER_ID": "111",
    "ANTHROPIC_API_KEY": "test-key",
}


@pytest.fixture
def cfg():
    env = dict(BASE_ENV, REPLY_DELAY_MIN="0", REPLY_DELAY_MAX="0")
    return load_config(env)
