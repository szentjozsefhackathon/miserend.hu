import json
import os
from dataclasses import dataclass
from pathlib import Path


CONFIG_PATH = Path(__file__).resolve().parent / "test_config.json"


@dataclass(frozen=True)
class WindowSettings:
    width: int
    height: int


@dataclass(frozen=True)
class TestSettings:
    base_url: str
    browser: str
    headless: bool
    timeout_seconds: int
    window: WindowSettings


def load_settings() -> TestSettings:
    with CONFIG_PATH.open(encoding="utf-8") as config_file:
        config = json.load(config_file)

    environment_name = os.getenv("TEST_ENV", config["default_environment"])
    environments = config["environments"]

    if environment_name not in environments:
        available = ", ".join(sorted(environments))
        raise ValueError(
            f"Unknown TEST_ENV '{environment_name}'. Available environments: {available}"
        )

    environment = environments[environment_name]
    window = config["window"]

    return TestSettings(
        base_url=environment["base_url"],
        browser=config["browser"],
        headless=environment["headless"],
        timeout_seconds=config["timeout_seconds"],
        window=WindowSettings(width=window["width"], height=window["height"]),
    )
