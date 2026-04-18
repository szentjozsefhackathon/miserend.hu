from selenium.webdriver.common.by import By

from core.base_page import BasePage


class HomePage(BasePage):
    LOGO_IMAGE = (By.CSS_SELECTOR, "div.logo a[href='/'] img[alt='Miserend oldal']")

    def open(self):
        return self.open_path("/")

    def find_logo_image(self):
        return self.find_visible(self.LOGO_IMAGE)

    def is_logo_loaded(self) -> bool:
        logo = self.find_logo_image()
        return self.image_is_loaded(logo)
