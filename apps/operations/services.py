from __future__ import annotations

from django.db import transaction

from apps.audit.services import log_event

from .models import Category, OperationalSite


@transaction.atomic
def archive_category(*, actor, category: Category) -> Category:
    category.archive()
    log_event(actor=actor, action="category_archived", object_instance=category)
    return category


@transaction.atomic
def activate_category(*, actor, category: Category) -> Category:
    category.activate()
    log_event(actor=actor, action="category_activated", object_instance=category)
    return category


@transaction.atomic
def deactivate_category(*, actor, category: Category) -> Category:
    category.deactivate()
    log_event(actor=actor, action="category_deactivated", object_instance=category)
    return category


@transaction.atomic
def archive_site(*, actor, site: OperationalSite) -> OperationalSite:
    site.archive()
    log_event(actor=actor, action="site_archived", object_instance=site)
    return site


@transaction.atomic
def activate_site(*, actor, site: OperationalSite) -> OperationalSite:
    site.activate()
    log_event(actor=actor, action="site_activated", object_instance=site)
    return site


@transaction.atomic
def deactivate_site(*, actor, site: OperationalSite) -> OperationalSite:
    site.deactivate()
    log_event(actor=actor, action="site_deactivated", object_instance=site)
    return site
