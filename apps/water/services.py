from __future__ import annotations

from django.db import transaction

from apps.audit.services import log_event
from apps.core.permissions import can_user_access_site

from .models import WaterUsageEntry


@transaction.atomic
def create_entry(*, actor, site, form):
    if not can_user_access_site(actor, site):
        raise PermissionError("You are not allowed to create entries for this site.")
    entry = form.save()
    log_event(actor=actor, action="usage_entry_created", object_instance=entry, metadata={"site_id": site.pk, "entry_date": str(entry.entry_date)})
    return entry


@transaction.atomic
def update_entry(*, actor, entry: WaterUsageEntry, form):
    saved_entry = form.save()
    log_event(actor=actor, action="usage_entry_updated", object_instance=saved_entry, metadata={"entry_id": saved_entry.pk})
    return saved_entry


@transaction.atomic
def delete_entry(*, actor, entry: WaterUsageEntry):
    log_event(actor=actor, action="usage_entry_deleted", object_instance=entry, metadata={"entry_id": entry.pk})
    entry.delete()
