from django.db import models


class UserRole(models.TextChoices):
    SUPER_ADMIN = "super_admin", "Super Admin"
    WORKER = "worker", "Worker"


class SiteStatus(models.TextChoices):
    ACTIVE = "active", "Active"
    INACTIVE = "inactive", "Inactive"
    ARCHIVED = "archived", "Archived"


class EntryShift(models.TextChoices):
    MORNING = "morning", "Morning"
    EVENING = "evening", "Evening"
    FULL_DAY = "full_day", "Full Day"


class BillingScope(models.TextChoices):
    INDIVIDUAL = "individual", "Individual Worker"
    SITE = "site", "Specific Site"
    CATEGORY = "category", "Specific Category"
    ORGANIZATION = "organization", "Entire Organization"
    CUSTOM_RANGE = "custom_range", "Custom Date Range"


class BillStatus(models.TextChoices):
    DRAFT = "draft", "Draft"
    ISSUED = "issued", "Issued"
    REOPENED = "reopened", "Reopened"
    VOID = "void", "Void"


class NotificationLevel(models.TextChoices):
    INFO = "info", "Info"
    SUCCESS = "success", "Success"
    WARNING = "warning", "Warning"
    DANGER = "danger", "Danger"
