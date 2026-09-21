from django.contrib import admin, messages
from django.utils.html import format_html

from apps.audit.services import log_event

from .models import Category, OperationalSite
from .services import activate_category, activate_site, archive_category, archive_site, deactivate_category, deactivate_site


@admin.register(Category)
class CategoryAdmin(admin.ModelAdmin):
    list_display = ("name", "code", "is_active", "is_archived", "sort_order", "site_count", "updated_at")
    list_filter = ("is_active", "is_archived")
    search_fields = ("name", "code", "description")
    ordering = ("sort_order", "name")
    prepopulated_fields = {"code": ("name",)}

    def site_count(self, obj):
        return obj.sites.count()

    site_count.short_description = "Sites"

    @admin.action(description="Archive selected categories")
    def archive_selected(self, request, queryset):
        count = 0
        for category in queryset:
            archive_category(actor=request.user, category=category)
            count += 1
        self.message_user(request, f"{count} category(s) archived.", level=messages.SUCCESS)

    @admin.action(description="Activate selected categories")
    def activate_selected(self, request, queryset):
        count = 0
        for category in queryset:
            activate_category(actor=request.user, category=category)
            count += 1
        self.message_user(request, f"{count} category(s) activated.", level=messages.SUCCESS)

    @admin.action(description="Deactivate selected categories")
    def deactivate_selected(self, request, queryset):
        count = 0
        for category in queryset:
            deactivate_category(actor=request.user, category=category)
            count += 1
        self.message_user(request, f"{count} category(s) deactivated.", level=messages.WARNING)

    actions = ["archive_selected", "activate_selected", "deactivate_selected"]

    def save_model(self, request, obj, form, change):
        super().save_model(request, obj, form, change)
        log_event(actor=request.user, action="category_saved", request=request, object_instance=obj)

    def delete_model(self, request, obj):
        log_event(actor=request.user, action="category_deleted", request=request, object_instance=obj)
        super().delete_model(request, obj)


@admin.register(OperationalSite)
class OperationalSiteAdmin(admin.ModelAdmin):
    list_display = (
        "display_name",
        "category",
        "code",
        "location",
        "is_active",
        "is_archived",
        "updated_at",
    )
    list_filter = ("category", "is_active", "is_archived")
    search_fields = ("name", "code", "location", "description", "category__name")
    ordering = ("category__name", "name")
    autocomplete_fields = ("category",)
    prepopulated_fields = {"code": ("name",)}

    def display_name(self, obj):
        return format_html("<strong>{}</strong>", obj.display_label)

    display_name.short_description = "Site"

    @admin.action(description="Archive selected sites")
    def archive_selected(self, request, queryset):
        count = 0
        for site in queryset:
            archive_site(actor=request.user, site=site)
            count += 1
        self.message_user(request, f"{count} site(s) archived.", level=messages.SUCCESS)

    @admin.action(description="Activate selected sites")
    def activate_selected(self, request, queryset):
        count = 0
        for site in queryset:
            activate_site(actor=request.user, site=site)
            count += 1
        self.message_user(request, f"{count} site(s) activated.", level=messages.SUCCESS)

    @admin.action(description="Deactivate selected sites")
    def deactivate_selected(self, request, queryset):
        count = 0
        for site in queryset:
            deactivate_site(actor=request.user, site=site)
            count += 1
        self.message_user(request, f"{count} site(s) deactivated.", level=messages.WARNING)

    actions = ["archive_selected", "activate_selected", "deactivate_selected"]

    def save_model(self, request, obj, form, change):
        super().save_model(request, obj, form, change)
        log_event(actor=request.user, action="site_saved", request=request, object_instance=obj)

    def delete_model(self, request, obj):
        log_event(actor=request.user, action="site_deleted", request=request, object_instance=obj)
        super().delete_model(request, obj)
