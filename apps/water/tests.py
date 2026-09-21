from django.test import TestCase

from apps.accounts.models import User
from apps.operations.models import Category, OperationalSite

from .forms import WaterUsageEntryForm


class WaterUsageEntryTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_worker(
            email="worker@example.com",
            phone="+255700000003",
            first_name="Test",
            last_name="Worker",
            pin="1234",
        )
        self.category = Category.objects.create(name="Production")
        self.site = OperationalSite.objects.create(category=self.category, name="Main")
        self.user.assigned_sites.add(self.site)

    def test_entry_form_calculates_consumption(self):
        form = WaterUsageEntryForm(
            data={
                "entry_date": "2026-07-08",
                "morning_reading": "10",
                "evening_reading": "15",
                "notes": "ok",
                "shift": "full_day",
            },
            user=self.user,
            site=self.site,
        )
        self.assertTrue(form.is_valid(), form.errors)
        entry = form.save()
        self.assertEqual(str(entry.consumption_m3), "5.000")
