from selenium.webdriver.support import expected_conditions as expected
from selenium.webdriver.support.wait import WebDriverWait


class BasePage:
    def __init__(self, driver, base_url: str, timeout_seconds: int):
        self.driver = driver
        self.base_url = base_url.rstrip("/")
        self.timeout_seconds = timeout_seconds

    def open_path(self, path: str = "/"):
        normalized_path = path if path.startswith("/") else f"/{path}"
        self.driver.get(f"{self.base_url}{normalized_path}")
        return self

    def find_visible(self, locator):
        return WebDriverWait(self.driver, self.timeout_seconds).until(
            expected.visibility_of_element_located(locator)
        )

    def image_is_loaded(self, image_element) -> bool:
        return self.driver.execute_script(
            """
            return arguments[0].complete
                && typeof arguments[0].naturalWidth !== 'undefined'
                && arguments[0].naturalWidth > 0;
            """,
            image_element,
        )
