from __future__ import annotations

from django.contrib.contenttypes.models import ContentType
from django.db import transaction

from apps.core.middleware import get_current_request
from apps.core.utils import get_client_ip

from .models import AuditEvent


@transaction.atomic
def log_event(*, actor=None, action: str, request=None, object_instance=None, metadata=None, status_code=None):
    request = request or get_current_request()
    ip_address = get_client_ip(request) if request else None
    user_agent = (request.META.get("HTTP_USER_AGENT", "") if request else "") or ""
    request_path = (getattr(request, "path", "") if request else "") or ""
    request_method = (getattr(request, "method", "") if request else "") or ""

    content_type = None
    object_id = ""
    object_repr = ""
    if object_instance is not None:
        content_type = ContentType.objects.get_for_model(object_instance, for_concrete_model=False)
        object_id = str(object_instance.pk)
        object_repr = str(object_instance)

    return AuditEvent.objects.create(
        actor=actor,
        action=action,
        content_type=content_type,
        object_id=object_id,
        object_repr=object_repr,
        ip_address=ip_address,
        user_agent=user_agent[:255],
        request_path=request_path[:255],
        request_method=request_method[:12],
        status_code=status_code,
        metadata=metadata or {},
    )
