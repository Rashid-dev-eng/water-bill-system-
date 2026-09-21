from django.test import TestCase

from apps.accounts.models import User


class ReportsTests(TestCase):
    def test_report_home_requires_staff(self):
        user = User.objects.create_worker(
            email="worker@example.com",
            phone="+255700000060",
            first_name="Report",
            last_name="Worker",
            pin="1234",
        )
        self.client.force_login(user)
        response = self.client.get("/reports/")
        self.assertEqual(response.status_code, 404)
