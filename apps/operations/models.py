from __future__ import annotations

from django.db import models
from django.urls import reverse
from django.utils import timezone
from django.utils.text import slugify

from apps.core.models import TimeStampedModel


def _unique_slug(instance, value, field_name="code"):
    base = slugify(value)
    slug = base
    model = instance.__class__
    counter = 1
    while model.objects.exclude(pk=instance.pk).filter(**{field_name: slug}).exists():
        slug = f"{base}-{counter}"
        counter += 1
    return slug


class CategoryQuerySet(models.QuerySet):
    def active(self):
        return self.filter(is_active=True, is_archived=False)

    def archived(self):
        return self.filter(is_archived=True)

    def inactive(self):
        return self.filter(is_active=False, is_archived=False)


class Category(TimeStampedModel):
    name = models.CharField(max_length=150, unique=True, db_index=True)
    code = models.SlugField(max_length=180, unique=True, blank=True)
    description = models.TextField(blank=True)
    color = models.CharField(max_length=20, default="#0d6efd", blank=True)
    sort_order = models.PositiveIntegerField(default=0, db_index=True)
    is_active = models.BooleanField(default=True, db_index=True)
    is_archived = models.BooleanField(default=False, db_index=True)
    archived_at = models.DateTimeField(blank=True, null=True, db_index=True)

    objects = CategoryQuerySet.as_manager()

    class Meta:
        ordering = ["sort_order", "name"]
        indexes = [
            models.Index(fields=["is_active", "is_archived"]),
            models.Index(fields=["sort_order", "name"]),
        ]

    def __str__(self):
        return self.name

    def save(self, *args, **kwargs):
        if not self.code:
            self.code = _unique_slug(self, self.name)
        super().save(*args, **kwargs)

    def archive(self):
        self.is_active = False
        self.is_archived = True
        self.archived_at = timezone.now()
        self.save(update_fields=["is_active", "is_archived", "archived_at", "updated_at"])

    def activate(self):
        self.is_active = True
        self.is_archived = False
        self.archived_at = None
        self.save(update_fields=["is_active", "is_archived", "archived_at", "updated_at"])

    def deactivate(self):
        self.is_active = False
        self.is_archived = False
        self.archived_at = None
        self.save(update_fields=["is_active", "is_archived", "archived_at", "updated_at"])

    @property
    def display_label(self):
        return self.name

    def get_absolute_url(self):
        return reverse("operations:category-detail", kwargs={"pk": self.pk})


class OperationalSiteQuerySet(models.QuerySet):
    def active(self):
        return self.filter(is_active=True, is_archived=False)

    def archived(self):
        return self.filter(is_archived=True)

    def inactive(self):
        return self.filter(is_active=False, is_archived=False)


class OperationalSite(TimeStampedModel):
    category = models.ForeignKey(Category, on_delete=models.PROTECT, related_name="sites")
    name = models.CharField(max_length=150)
    code = models.SlugField(max_length=180, unique=True, blank=True)
    description = models.TextField(blank=True)
    location = models.CharField(max_length=255, blank=True)
    latitude = models.DecimalField(max_digits=9, decimal_places=6, blank=True, null=True)
    longitude = models.DecimalField(max_digits=9, decimal_places=6, blank=True, null=True)
    is_active = models.BooleanField(default=True, db_index=True)
    is_archived = models.BooleanField(default=False, db_index=True)
    archived_at = models.DateTimeField(blank=True, null=True, db_index=True)

    objects = OperationalSiteQuerySet.as_manager()

    class Meta:
        ordering = ["category__name", "name"]
        constraints = [
            models.UniqueConstraint(fields=["category", "name"], name="unique_site_name_per_category"),
        ]
        indexes = [
            models.Index(fields=["category", "is_active", "is_archived"]),
            models.Index(fields=["name", "code"]),
        ]

    def __str__(self):
        return f"{self.category.name} - {self.name}"

    def save(self, *args, **kwargs):
        if not self.code:
            self.code = _unique_slug(self, f"{self.category.name}-{self.name}")
        super().save(*args, **kwargs)

    def archive(self):
        self.is_active = False
        self.is_archived = True
        self.archived_at = timezone.now()
        self.save(update_fields=["is_active", "is_archived", "archived_at", "updated_at"])

    def activate(self):
        self.is_active = True
        self.is_archived = False
        self.archived_at = None
        self.save(update_fields=["is_active", "is_archived", "archived_at", "updated_at"])

    def deactivate(self):
        self.is_active = False
        self.is_archived = False
        self.archived_at = None
        self.save(update_fields=["is_active", "is_archived", "archived_at", "updated_at"])

    @property
    def display_label(self):
        return f"{self.category.name} - {self.name}"

    def get_absolute_url(self):
        return reverse("operations:site-detail", kwargs={"pk": self.pk})
