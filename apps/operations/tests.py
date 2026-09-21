from django.test import TestCase

from .models import Category, OperationalSite


class OperationModelTests(TestCase):
    def test_category_and_site_archive(self):
        category = Category.objects.create(name="Production")
        site = OperationalSite.objects.create(category=category, name="Main Pump")

        category.archive()
        site.archive()

        category.refresh_from_db()
        site.refresh_from_db()

        self.assertTrue(category.is_archived)
        self.assertTrue(site.is_archived)
