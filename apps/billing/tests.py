from decimal import Decimal

from django.test import TestCase

from apps.accounts.models import User
from apps.operations.models import Category, OperationalSite
from apps.water.models import WaterUsageEntry

from .models import TariffTier
from .services import generate_bill


class BillingServiceTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser(
            email="admin@example.com",
            phone="+255700000010",
            first_name="Admin",
            last_name="User",
            password="StrongPass123",
        )
        self.worker = User.objects.create_worker(
            email="worker@example.com",
            phone="+255700000011",
            first_name="Water",
            last_name="Worker",
            pin="1234",
        )
        self.category = Category.objects.create(name="Distribution")
        self.site = OperationalSite.objects.create(category=self.category, name="Tank 1")
        TariffTier.objects.create(name="Tier 1", min_volume=Decimal("0"), max_volume=Decimal("10"), rate_per_m3=Decimal("1000"))
        TariffTier.objects.create(name="Tier 2", min_volume=Decimal("10"), max_volume=None, rate_per_m3=Decimal("1500"))
        WaterUsageEntry.objects.create(
            worker=self.worker,
            category=self.category,
            site=self.site,
            entry_date="2026-07-08",
            morning_reading=Decimal("10"),
            evening_reading=Decimal("20"),
        )

    def test_generate_bill_creates_pdf_and_lines(self):
        bill = generate_bill(
            prepared_by=self.admin,
            scope="site",
            site=self.site,
            period_start="2026-07-01",
            period_end="2026-07-31",
        )
        self.assertEqual(bill.total_consumption, Decimal("10.000"))
        self.assertTrue(bill.lines.exists())
        self.assertTrue(bill.pdf_file)
