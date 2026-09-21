from __future__ import annotations

import secrets
from typing import Iterable

from django.db import transaction
from django.utils import timezone

from apps.audit.services import log_event
from apps.core.choices import UserRole

from .models import User


def generate_worker_pin() -> str:
    return f"{secrets.randbelow(10000):04d}"


@transaction.atomic
def create_worker(*, created_by: User, email: str, phone: str, first_name: str, last_name: str, pin: str, **extra) -> User:
    user = User.objects.create_worker(
        email=email,
        phone=phone,
        first_name=first_name,
        last_name=last_name,
        pin=pin,
        **extra,
    )
    log_event(
        actor=created_by,
        action="worker_created",
        object_instance=user,
        metadata={"role": user.role, "all_sites_access": user.all_sites_access},
    )
    return user


@transaction.atomic
def create_super_admin(*, created_by: User, email: str, phone: str, first_name: str, last_name: str, password: str, **extra) -> User:
    user = User.objects.create_superuser(
        email=email,
        phone=phone,
        first_name=first_name,
        last_name=last_name,
        password=password,
        **extra,
    )
    log_event(actor=created_by, action="super_admin_created", object_instance=user, metadata={"role": user.role})
    return user


@transaction.atomic
def reset_pin(*, actor: User, user: User) -> str:
    new_pin = generate_worker_pin()
    user.set_password(new_pin)
    user.must_change_pin = True
    user.pin_changed_at = timezone.now()
    user.save(update_fields=["password", "must_change_pin", "pin_changed_at", "updated_at"])
    log_event(actor=actor, action="pin_reset", object_instance=user, metadata={"new_pin_generated": True})
    return new_pin


@transaction.atomic
def change_pin(*, actor: User, new_pin: str) -> User:
    actor.set_password(new_pin)
    actor.must_change_pin = False
    actor.pin_changed_at = timezone.now()
    actor.save(update_fields=["password", "must_change_pin", "pin_changed_at", "updated_at"])
    log_event(actor=actor, action="pin_changed", object_instance=actor, metadata={"by_user": actor.pk})
    return actor


@transaction.atomic
def update_profile(*, actor: User, email: str, phone: str, nickname: str = "", profile_picture=None) -> User:
    actor.email = email
    actor.phone = phone
    actor.nickname = nickname or ""
    if profile_picture is not None:
        actor.profile_picture = profile_picture
    actor.save(update_fields=["email", "phone", "nickname", "profile_picture", "updated_at"])
    log_event(actor=actor, action="profile_updated", object_instance=actor)
    return actor


@transaction.atomic
def update_assignments(*, actor: User, user: User, categories: Iterable, sites: Iterable) -> User:
    user.assigned_categories.set(categories)
    user.assigned_sites.set(sites)
    log_event(
        actor=actor,
        action="site_assignment_updated",
        object_instance=user,
        metadata={
            "assigned_category_ids": list(user.assigned_categories.values_list("id", flat=True)),
            "assigned_site_ids": list(user.assigned_sites.values_list("id", flat=True)),
        },
    )
    return user
