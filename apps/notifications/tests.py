from django.test import TestCase

from apps.accounts.models import User

from .services import notify_user


class NotificationTests(TestCase):
    def test_notify_user_creates_notification(self):
        user = User.objects.create_worker(
            email="worker@example.com",
            phone="+255700000030",
            first_name="Notify",
            last_name="Worker",
            pin="1234",
        )
        notification = notify_user(recipient=user, title="Hello", message="Welcome")
        self.assertEqual(notification.recipient, user)
        self.assertEqual(notification.title, "Hello")
