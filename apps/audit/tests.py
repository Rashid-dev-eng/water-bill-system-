from django.test import TestCase

from apps.accounts.models import User

from .services import log_event


class AuditServiceTests(TestCase):
    def test_log_event_creates_audit_record(self):
        user = User.objects.create_superuser(
            email="admin@example.com",
            phone="+255700000020",
            first_name="Admin",
            last_name="User",
            password="StrongPass123",
        )
        event = log_event(actor=user, action="test_action")
        self.assertEqual(event.action, "test_action")
        self.assertEqual(event.actor, user)
