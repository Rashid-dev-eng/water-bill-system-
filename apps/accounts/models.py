from __future__ import annotations

from django.contrib.auth.models import AbstractBaseUser, PermissionsMixin
from django.core.validators import RegexValidator
from django.db import models
from django.urls import reverse
from django.utils import timezone

from apps.core.choices import UserRole
from apps.core.models import TimeStampedModel

from .managers import UserManager


phone_validator = RegexValidator(
    regex=r"^\+?[0-9]{9,15}$",
    message="Enter a valid phone number using 9 to 15 digits, optionally prefixed with +.",
)


class User(AbstractBaseUser, PermissionsMixin, TimeStampedModel):
    email = models.EmailField(unique=True, db_index=True)
    phone = models.CharField(max_length=20, unique=True, validators=[phone_validator], db_index=True)
    first_name = models.CharField(max_length=150)
    last_name = models.CharField(max_length=150)
    nickname = models.CharField(max_length=120, blank=True)
    profile_picture = models.ImageField(upload_to="profiles/", blank=True, null=True)
    role = models.CharField(max_length=20, choices=UserRole.choices, default=UserRole.WORKER, db_index=True)
    all_sites_access = models.BooleanField(default=False, db_index=True)
    must_change_pin = models.BooleanField(default=True, db_index=True)
    pin_changed_at = models.DateTimeField(blank=True, null=True)
    is_active = models.BooleanField(default=True, db_index=True)
    is_staff = models.BooleanField(default=False)
    date_joined = models.DateTimeField(default=timezone.now, db_index=True)

    assigned_categories = models.ManyToManyField(
        "operations.Category",
        blank=True,
        related_name="assigned_users",
    )
    assigned_sites = models.ManyToManyField(
        "operations.OperationalSite",
        blank=True,
        related_name="assigned_users",
    )

    objects = UserManager()

    USERNAME_FIELD = "email"
    REQUIRED_FIELDS = ["phone", "first_name", "last_name"]

    class Meta:
        indexes = [
            models.Index(fields=["email"]),
            models.Index(fields=["phone"]),
            models.Index(fields=["role", "is_active"]),
        ]
        ordering = ["first_name", "last_name"]

    def __str__(self):
        return self.display_name

    def save(self, *args, **kwargs):
        self.email = self.email.lower().strip()
        self.phone = self.phone.strip()
        self.first_name = self.first_name.strip()
        self.last_name = self.last_name.strip()
        if self.role == UserRole.SUPER_ADMIN:
            self.is_staff = True
            self.is_superuser = True
            self.must_change_pin = False
        elif not self.pk:
            self.is_staff = False
            self.is_superuser = False
        super().save(*args, **kwargs)

    @property
    def display_name(self):
        return f"{self.first_name} {self.last_name}".strip()

    @property
    def official_full_name(self):
        return self.display_name

    @property
    def welcome_name(self):
        return self.nickname.strip() if self.nickname else self.first_name

    @property
    def is_super_admin(self):
        return self.role == UserRole.SUPER_ADMIN or self.is_superuser

    def get_full_name(self):
        return self.display_name

    def get_short_name(self):
        return self.first_name

    def get_absolute_url(self):
        return reverse("accounts:profile")

    def can_access_all_sites(self):
        return self.is_super_admin or self.all_sites_access

    def visible_categories(self):
        from apps.operations.models import Category

        if self.can_access_all_sites():
            return Category.objects.filter(is_active=True).order_by("name")
        return Category.objects.filter(
            models.Q(pk__in=self.assigned_categories.values_list("pk", flat=True))
            | models.Q(sites__pk__in=self.assigned_sites.values_list("pk", flat=True))
        ).distinct().order_by("name")

    def visible_sites(self):
        from apps.operations.models import OperationalSite

        if self.can_access_all_sites():
            return OperationalSite.objects.select_related("category").filter(is_active=True).order_by("category__name", "name")
        return OperationalSite.objects.select_related("category").filter(
            models.Q(pk__in=self.assigned_sites.values_list("pk", flat=True))
            | models.Q(category__pk__in=self.assigned_categories.values_list("pk", flat=True)),
            is_active=True,
        ).distinct().order_by("category__name", "name")
