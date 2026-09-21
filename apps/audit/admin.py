from django.contrib import admin

from .models import AuditEvent


@admin.register(AuditEvent)
class AuditEventAdmin(admin.ModelAdmin):
    list_display = ("created_at", "actor", "action", "object_repr", "ip_address", "request_method")
    list_filter = ("action", "request_method", "created_at")
    search_fields = ("actor__email", "actor__first_name", "actor__last_name", "object_repr", "action", "request_path")
    readonly_fields = (
        "actor",
        "action",
        "content_type",
        "object_id",
        "object_repr",
        "ip_address",
        "user_agent",
        "request_path",
        "request_method",
        "status_code",
        "metadata",
        "created_at",
        "updated_at",
    )
