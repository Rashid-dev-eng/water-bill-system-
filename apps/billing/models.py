from __future__ import annotations

import secrets
from decimal import Decimal

from django.conf import settings
from django.core.exceptions import ValidationError
from django.core.validators import MinValueValidator
from django.db import models
from django.urls import reverse
from django.utils import timezone

from apps.core.choices import BillStatus, BillingScope
from apps.core.models import TimeStampedModel


class TariffTierQuerySet(models.QuerySet):
    def active(self):
        today = timezone.localdate()
        return self.filter(is_active=True).filter(models.Q(effective_from__lte=today), models.Q(effective_to__isnull=True) | models.Q(effective_to__gte=today))


class TariffTier(TimeStampedModel):
    name = models.CharField(max_length=150, unique=True)
    min_volume = models.DecimalField(max_digits=12, decimal_places=3, validators=[MinValueValidator(Decimal("0"))])
    max_volume = models.DecimalField(max_digits=12, decimal_places=3, blank=True, null=True)
    rate_per_m3 = models.DecimalField(max_digits=12, decimal_places=2, validators=[MinValueValidator(Decimal("0"))])
    fixed_charge = models.DecimalField(max_digits=12, decimal_places=2, default=Decimal("0.00"))
    order = models.PositiveIntegerField(default=0, db_index=True)
    effective_from = models.DateField(default=timezone.localdate)
    effective_to = models.DateField(blank=True, null=True)
    is_active = models.BooleanField(default=True, db_index=True)
    notes = models.TextField(blank=True)

    objects = TariffTierQuerySet.as_manager()

    class Meta:
        ordering = ["min_volume", "order", "name"]
        indexes = [
            models.Index(fields=["is_active", "effective_from", "effective_to"]),
            models.Index(fields=["order", "min_volume"]),
        ]

    def __str__(self):
        return self.name

    def clean(self):
        if self.max_volume is not None and self.max_volume < self.min_volume:
            raise ValidationError("Maximum volume cannot be lower than minimum volume.")

    @property
    def display_range(self):
        if self.max_volume is None:
            return f"{self.min_volume}+"
        return f"{self.min_volume} - {self.max_volume}"


class BillQuerySet(models.QuerySet):
    def issued(self):
        return self.filter(status=BillStatus.ISSUED)

    def reopened(self):
        return self.filter(status=BillStatus.REOPENED)


class Bill(TimeStampedModel):
    bill_number = models.CharField(max_length=40, unique=True, db_index=True)
    scope = models.CharField(max_length=30, choices=BillingScope.choices, db_index=True)
    status = models.CharField(max_length=20, choices=BillStatus.choices, default=BillStatus.ISSUED, db_index=True)
    generated_by = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.PROTECT, related_name="generated_bills")
    worker = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.SET_NULL, blank=True, null=True, related_name="bills")
    category = models.ForeignKey("operations.Category", on_delete=models.SET_NULL, blank=True, null=True, related_name="bills")
    site = models.ForeignKey("operations.OperationalSite", on_delete=models.SET_NULL, blank=True, null=True, related_name="bills")
    period_start = models.DateField(db_index=True)
    period_end = models.DateField(db_index=True)
    date_generated = models.DateTimeField(default=timezone.now, db_index=True)
    total_consumption = models.DecimalField(max_digits=12, decimal_places=3, default=Decimal("0.000"))
    total_amount = models.DecimalField(max_digits=12, decimal_places=2, default=Decimal("0.00"))
    worker_name = models.CharField(max_length=255, blank=True)
    category_name = models.CharField(max_length=255, blank=True)
    site_name = models.CharField(max_length=255, blank=True)
    prepared_by_name = models.CharField(max_length=255, blank=True)
    notes = models.TextField(blank=True)
    pdf_file = models.FileField(upload_to="bills/pdfs/", blank=True, null=True)
    is_reopened = models.BooleanField(default=False, db_index=True)
    reopened_at = models.DateTimeField(blank=True, null=True)
    regenerated_from = models.ForeignKey("self", on_delete=models.SET_NULL, blank=True, null=True, related_name="regenerated_bills")

    objects = BillQuerySet.as_manager()

    class Meta:
        ordering = ["-date_generated", "-created_at"]
        indexes = [
            models.Index(fields=["scope", "status", "period_start", "period_end"]),
            models.Index(fields=["bill_number"]),
            models.Index(fields=["worker", "site", "category"]),
        ]

    def __str__(self):
        return self.bill_number

    @classmethod
    def generate_bill_number(cls):
        today = timezone.localdate()
        while True:
            candidate = f"NWC-{today:%Y%m%d}-{secrets.randbelow(10000):04d}"
            if not cls.objects.filter(bill_number=candidate).exists():
                return candidate

    def reopen(self):
        self.status = BillStatus.REOPENED
        self.is_reopened = True
        self.reopened_at = timezone.now()
        self.save(update_fields=["status", "is_reopened", "reopened_at", "updated_at"])

    def issue(self):
        self.status = BillStatus.ISSUED
        self.save(update_fields=["status", "updated_at"])

    def get_absolute_url(self):
        return reverse("billing:bill-detail", kwargs={"pk": self.pk})


class BillLine(TimeStampedModel):
    bill = models.ForeignKey(Bill, on_delete=models.CASCADE, related_name="lines")
    position = models.PositiveIntegerField(default=0)
    tariff_name = models.CharField(max_length=150)
    min_volume = models.DecimalField(max_digits=12, decimal_places=3)
    max_volume = models.DecimalField(max_digits=12, decimal_places=3, blank=True, null=True)
    volume = models.DecimalField(max_digits=12, decimal_places=3)
    unit_rate = models.DecimalField(max_digits=12, decimal_places=2)
    amount = models.DecimalField(max_digits=12, decimal_places=2)

    class Meta:
        ordering = ["position", "id"]
        constraints = [
            models.UniqueConstraint(fields=["bill", "position"], name="unique_bill_line_position"),
        ]

    def __str__(self):
        return f"{self.bill.bill_number} - {self.tariff_name}"
