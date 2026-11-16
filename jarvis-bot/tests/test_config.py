import pytest

from jarvis.config import ConfigError, load_config

BASE = {"API_ID": "1", "API_HASH": "h", "OWNER_ID": "5", "ANTHROPIC_API_KEY": "k"}


def test_defaults():
    c = load_config(BASE)
    assert c.anthropic_model == "claude-sonnet-5-5"
    assert c.tts_voices["uz"] == "uz-UZ-MadinaNeural"
    assert c.allowed == frozenset({5})
    assert c.calls_enabled is True


def test_missing_required():
    with pytest.raises(ConfigError) as e:
        load_config({})
    msg = str(e.value)
    assert "API_ID" in msg and "OWNER_ID" in msg and "ANTHROPIC_API_KEY" in msg


def test_allowed_ids_and_overrides():
    c = load_config({**BASE, "ALLOWED_IDS": "7, 8;9", "CALLS_ENABLED": "false",
                     "ANTHROPIC_MODEL": "x", "TTS_LANG": "ru"})
    assert c.allowed == frozenset({5, 7, 8, 9})
    assert c.calls_enabled is False and c.anthropic_model == "x" and c.tts_lang == "ru"


@pytest.mark.parametrize("bad", [{"API_ID": "abc"}, {"OWNER_ID": "x"}, {"ALLOWED_IDS": "1,z"},
                                 {"TTS_LANG": "de"}, {"VAD_AGGRESSIVENESS": "9"}, {"HISTORY_LIMIT": "q"}])
def test_invalid_values(bad):
    with pytest.raises(ConfigError):
        load_config({**BASE, **bad})


def test_login_needs_only_api():
    c = load_config({"API_ID": "1", "API_HASH": "h"}, for_login=True)
    assert c.api_id == 1


def test_secrets_not_in_repr():
    assert "test-key" not in repr(load_config({**BASE, "ANTHROPIC_API_KEY": "test-key"}))
    assert "hhh" not in repr(load_config({**BASE, "API_HASH": "hhh"}))
