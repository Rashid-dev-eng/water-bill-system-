from django.contrib import admin

from apps.audit.services import log_event

from .models import WaterUsageEntry


@admin.register(WaterUsageEntry)
class WaterUsageEntryAdmin(admin.ModelAdmin):
    list_display = ("entry_date", "worker", "category", "site", "shift", "morning_reading", "evening_reading", "consumption_m3", "created_at")
    list_filter = ("entry_date", "shift", "category", "site")
    search_fields = ("worker__first_name", "worker__last_name", "worker__email", "site__name", "site__code", "category__name")
    autocomplete_fields = ("worker", "category", "site")
    readonly_fields = ("consumption_m3", "created_at", "updated_at")

    def save_model(self, request, obj, form, change):
        super().save_model(request, obj, form, change)
        log_event(actor=request.user, action="usage_entry_admin_saved", request=request, object_instance=obj)

    def delete_model(self, request, obj):
        log_event(actor=request.user, action="usage_entry_admin_deleted", request=request, object_instance=obj)
        super().delete_model(request, obj)
