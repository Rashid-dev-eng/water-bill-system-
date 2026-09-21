from django.db import models
from django.utils import timezone


class TimeStampedModel(models.Model):
    created_at = models.DateTimeField(auto_now_add=True, db_index=True)
    updated_at = models.DateTimeField(auto_now=True, db_index=True)

    class Meta:
        abstract = True


class ActiveArchiveModel(TimeStampedModel):
    is_active = models.BooleanField(default=True, db_index=True)
    archived_at = models.DateTimeField(blank=True, null=True, db_index=True)

    class Meta:
        abstract = True

    def archive(self):
        self.is_active = False
        self.archived_at = timezone.now()
        self.save(update_fields=["is_active", "archived_at", "updated_at"])

    def activate(self):
        self.is_active = True
        self.archived_at = None
        self.save(update_fields=["is_active", "archived_at", "updated_at"])
