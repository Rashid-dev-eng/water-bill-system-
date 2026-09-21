from django.test import TestCase

from apps.accounts.models import User
from apps.operations.models import Category, OperationalSite

from apps.water.models import WaterUsageEntry


class ApiTests(TestCase):
    def setUp(self):
        self.worker = User.objects.create_worker(
            email="worker@example.com",
            phone="+255700000050",
            first_name="Api",
            last_name="Worker",
            pin="1234",
        )
        self.category = Category.objects.create(name="Production")
        self.site = OperationalSite.objects.create(category=self.category, name="Pump 1")
        WaterUsageEntry.objects.create(
            worker=self.worker,
            category=self.category,
            site=self.site,
            entry_date="2026-07-08",
            morning_reading=10,
            evening_reading=12,
        )

    def test_root_endpoint(self):
        response = self.client.get("/api/v1/")
        self.assertEqual(response.status_code, 200)
