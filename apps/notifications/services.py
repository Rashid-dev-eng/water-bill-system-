from __future__ import annotations

from django.db import transaction
from django.utils import timezone

from apps.audit.services import log_event
from apps.core.choices import NotificationLevel

from .models import Notification


@transaction.atomic
def notify_user(*, recipient, title: str, message: str, level: str = NotificationLevel.INFO, action_url: str = "", metadata=None, actor=None):
    notification = Notification.objects.create(
        recipient=recipient,
        title=title,
        message=message,
        level=level,
        action_url=action_url,
        metadata=metadata or {},
    )
    log_event(actor=actor or recipient, action="notification_created", object_instance=notification, metadata=notification.metadata)
    return notification


@transaction.atomic
def mark_notification_read(*, actor, notification: Notification):
    notification.is_read = True
    notification.read_at = timezone.now()
    notification.save(update_fields=["is_read", "read_at", "updated_at"])
    log_event(actor=actor, action="notification_read", object_instance=notification)
    return notification
