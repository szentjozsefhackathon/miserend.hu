from config.settings import load_settings
from core.driver_factory import create_driver
from pages.home_page import HomePage


class BaseTest:
    def setup_method(self):
        self.settings = load_settings()
        self.driver = create_driver(self.settings)
        self.home_page = HomePage(
            self.driver,
            self.settings.base_url,
            self.settings.timeout_seconds,
        )

    def teardown_method(self):
        self.driver.quit()
