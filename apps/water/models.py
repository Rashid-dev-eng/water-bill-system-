from __future__ import annotations

from decimal import Decimal

from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import models
from django.utils import timezone

from apps.core.choices import EntryShift
from apps.core.models import TimeStampedModel


class WaterUsageEntry(TimeStampedModel):
    worker = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.PROTECT, related_name="water_entries")
    category = models.ForeignKey("operations.Category", on_delete=models.PROTECT, related_name="water_entries")
    site = models.ForeignKey("operations.OperationalSite", on_delete=models.PROTECT, related_name="water_entries")
    entry_date = models.DateField(db_index=True, default=timezone.localdate)
    shift = models.CharField(max_length=20, choices=EntryShift.choices, default=EntryShift.FULL_DAY, db_index=True)
    morning_reading = models.DecimalField(max_digits=12, decimal_places=3)
    evening_reading = models.DecimalField(max_digits=12, decimal_places=3)
    consumption_m3 = models.DecimalField(max_digits=12, decimal_places=3, default=Decimal("0.000"), editable=False)
    notes = models.TextField(blank=True)

    class Meta:
        ordering = ["-entry_date", "-created_at"]
        constraints = [
            models.UniqueConstraint(fields=["worker", "site", "entry_date", "shift"], name="unique_worker_site_date_shift"),
        ]
        indexes = [
            models.Index(fields=["worker", "entry_date"]),
            models.Index(fields=["category", "site", "entry_date"]),
            models.Index(fields=["shift", "entry_date"]),
        ]

    def __str__(self):
        return f"{self.worker.display_name} - {self.site.display_label} - {self.entry_date}"

    def clean(self):
        super().clean()
        if self.site_id and self.category_id and self.site.category_id != self.category_id:
            raise ValidationError({"site": "The selected site does not belong to the selected category."})
        if self.evening_reading < self.morning_reading:
            raise ValidationError({"evening_reading": "Evening reading must be greater than or equal to morning reading."})

    def save(self, *args, **kwargs):
        morning = Decimal(str(self.morning_reading))
        evening = Decimal(str(self.evening_reading))
        self.consumption_m3 = (evening - morning).quantize(Decimal("0.001"))
        self.full_clean(exclude=None)
        super().save(*args, **kwargs)

    @property
    def consumption(self):
        return self.consumption_m3
