from django.test import TestCase

from apps.accounts.models import User


class DashboardTests(TestCase):
    def test_health_check(self):
        response = self.client.get("/health/")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.content, b"ok")

    def test_admin_user_redirects_to_admin_dashboard(self):
        user = User.objects.create_superuser(
            email="admin@example.com",
            phone="+255700000040",
            first_name="Admin",
            last_name="User",
            password="StrongPass123",
        )
        self.client.force_login(user)
        response = self.client.get("/")
        self.assertEqual(response.status_code, 302)
        self.assertEqual(response.url, "/dashboard/admin/")

    def test_admin_dashboard_is_accessible_under_custom_path(self):
        user = User.objects.create_superuser(
            email="admin2@example.com",
            phone="+255700000041",
            first_name="Admin",
            last_name="Two",
            password="StrongPass123",
        )
        self.client.force_login(user)
        response = self.client.get("/dashboard/admin/")
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "Administrator Dashboard")
