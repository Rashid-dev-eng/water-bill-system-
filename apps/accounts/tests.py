from django.contrib.auth import authenticate
from django.test import TestCase

from apps.core.choices import UserRole

from .models import User


class AccountModelTests(TestCase):
    def test_worker_login_with_pin_backend(self):
        user = User.objects.create_worker(
            email="worker@example.com",
            phone="+255700000001",
            first_name="Water",
            last_name="Worker",
            pin="1234",
        )

        authenticated = authenticate(identifier="worker@example.com", pin="1234")

        self.assertIsNotNone(authenticated)
        self.assertEqual(authenticated.pk, user.pk)

    def test_superuser_creation_sets_staff_flags(self):
        user = User.objects.create_superuser(
            email="admin@example.com",
            phone="+255700000002",
            first_name="Super",
            last_name="Admin",
            password="StrongPass123",
        )

        self.assertTrue(user.is_staff)
        self.assertTrue(user.is_superuser)
        self.assertEqual(user.role, UserRole.SUPER_ADMIN)
