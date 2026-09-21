from __future__ import annotations

from django.conf import settings
from django.db import models
from django.urls import reverse

from apps.core.choices import NotificationLevel
from apps.core.models import TimeStampedModel


class Notification(TimeStampedModel):
    recipient = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE, related_name="notifications")
    title = models.CharField(max_length=150)
    message = models.TextField()
    level = models.CharField(max_length=20, choices=NotificationLevel.choices, default=NotificationLevel.INFO, db_index=True)
    is_read = models.BooleanField(default=False, db_index=True)
    read_at = models.DateTimeField(blank=True, null=True)
    action_url = models.CharField(max_length=255, blank=True)
    metadata = models.JSONField(default=dict, blank=True)

    class Meta:
        ordering = ["-created_at"]
        indexes = [
            models.Index(fields=["recipient", "is_read", "created_at"]),
            models.Index(fields=["level", "created_at"]),
        ]

    def __str__(self):
        return f"{self.recipient.display_name} - {self.title}"

    def mark_read(self):
        from django.utils import timezone

        self.is_read = True
        self.read_at = timezone.now()
        self.save(update_fields=["is_read", "read_at", "updated_at"])

    def get_absolute_url(self):
        if self.action_url:
            return self.action_url
        return reverse("notifications:notification-list")
