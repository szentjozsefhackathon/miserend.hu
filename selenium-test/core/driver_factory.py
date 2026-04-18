from selenium import webdriver
from selenium.webdriver.chrome.options import Options as ChromeOptions

from config.settings import TestSettings


def create_driver(settings: TestSettings):
    if settings.browser.lower() != "chrome":
        raise ValueError(f"Unsupported browser: {settings.browser}")

    options = ChromeOptions()

    if settings.headless:
        options.add_argument("--headless=new")

    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-extensions")
    options.add_argument("--disable-background-networking")
    options.add_argument("--remote-debugging-port=0")
    options.add_argument(
        f"--window-size={settings.window.width},{settings.window.height}"
    )

    driver = webdriver.Chrome(options=options)
    driver.set_page_load_timeout(settings.timeout_seconds)
    driver.set_window_size(settings.window.width, settings.window.height)

    return driver
