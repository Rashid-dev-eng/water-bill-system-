from django.contrib import admin, messages

from apps.audit.services import log_event

from .models import Bill, BillLine, TariffTier
from .services import regenerate_bill, reopen_bill


@admin.register(TariffTier)
class TariffTierAdmin(admin.ModelAdmin):
    list_display = ("name", "min_volume", "max_volume", "rate_per_m3", "fixed_charge", "order", "is_active", "effective_from", "effective_to")
    list_filter = ("is_active", "effective_from", "effective_to")
    search_fields = ("name", "notes")
    ordering = ("min_volume", "order", "name")

    @admin.action(description="Activate selected tariff tiers")
    def activate_selected(self, request, queryset):
        count = queryset.update(is_active=True)
        self.message_user(request, f"{count} tariff tier(s) activated.", level=messages.SUCCESS)
        log_event(actor=request.user, action="tariff_tiers_activated", request=request, metadata={"count": count})

    @admin.action(description="Deactivate selected tariff tiers")
    def deactivate_selected(self, request, queryset):
        count = queryset.update(is_active=False)
        self.message_user(request, f"{count} tariff tier(s) deactivated.", level=messages.WARNING)
        log_event(actor=request.user, action="tariff_tiers_deactivated", request=request, metadata={"count": count})

    actions = ["activate_selected", "deactivate_selected"]


class BillLineInline(admin.TabularInline):
    model = BillLine
    extra = 0
    can_delete = False
    readonly_fields = ("position", "tariff_name", "min_volume", "max_volume", "volume", "unit_rate", "amount")


@admin.register(Bill)
class BillAdmin(admin.ModelAdmin):
    list_display = ("bill_number", "scope", "status", "worker_name", "category_name", "site_name", "period_start", "period_end", "total_consumption", "total_amount", "date_generated")
    list_filter = ("scope", "status", "period_start", "period_end")
    search_fields = ("bill_number", "worker_name", "category_name", "site_name", "prepared_by_name")
    readonly_fields = ("bill_number", "date_generated", "total_consumption", "total_amount", "worker_name", "category_name", "site_name", "prepared_by_name", "pdf_file", "is_reopened", "reopened_at", "regenerated_from")
    inlines = [BillLineInline]

    @admin.action(description="Reopen selected bills")
    def reopen_selected(self, request, queryset):
        count = 0
        for bill in queryset:
            reopen_bill(actor=request.user, bill=bill)
            count += 1
        self.message_user(request, f"{count} bill(s) reopened.", level=messages.SUCCESS)

    @admin.action(description="Regenerate selected bills")
    def regenerate_selected(self, request, queryset):
        count = 0
        for bill in queryset:
            regenerate_bill(actor=request.user, bill=bill)
            count += 1
        self.message_user(request, f"{count} bill(s) regenerated.", level=messages.SUCCESS)

    actions = ["reopen_selected", "regenerate_selected"]
