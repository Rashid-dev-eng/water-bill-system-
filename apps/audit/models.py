from __future__ import annotations

from django.conf import settings
from django.contrib.contenttypes.fields import GenericForeignKey
from django.contrib.contenttypes.models import ContentType
from django.db import models
from django.utils import timezone

from apps.core.models import TimeStampedModel


class AuditEvent(TimeStampedModel):
    actor = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.SET_NULL, blank=True, null=True, related_name="audit_events")
    action = models.CharField(max_length=120, db_index=True)
    content_type = models.ForeignKey(ContentType, on_delete=models.SET_NULL, blank=True, null=True)
    object_id = models.CharField(max_length=64, blank=True, db_index=True)
    content_object = GenericForeignKey("content_type", "object_id")
    object_repr = models.CharField(max_length=255, blank=True)
    ip_address = models.GenericIPAddressField(blank=True, null=True)
    user_agent = models.CharField(max_length=255, blank=True)
    request_path = models.CharField(max_length=255, blank=True)
    request_method = models.CharField(max_length=12, blank=True)
    status_code = models.PositiveIntegerField(blank=True, null=True)
    metadata = models.JSONField(default=dict, blank=True)

    class Meta:
        ordering = ["-created_at"]
        indexes = [
            models.Index(fields=["action", "created_at"]),
            models.Index(fields=["actor", "created_at"]),
            models.Index(fields=["object_id", "created_at"]),
        ]

    def __str__(self):
        actor = self.actor.display_name if self.actor else "System"
        return f"{actor} - {self.action} - {self.created_at:%Y-%m-%d %H:%M}"
