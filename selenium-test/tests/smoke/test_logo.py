import pytest

from tests.base_test import BaseTest


@pytest.mark.smoke
class TestLogo(BaseTest):
    def test_logo_exists_and_image_is_loaded(self):
        self.home_page.open()

        assert self.home_page.is_logo_loaded()
