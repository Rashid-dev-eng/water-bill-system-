from django.contrib import admin, messages
from django.contrib.auth.admin import UserAdmin as DjangoUserAdmin
from django.utils.html import format_html

from apps.audit.services import log_event
from apps.core.choices import UserRole

from .forms import AdminUserForm
from .models import User
from .services import reset_pin


@admin.register(User)
class UserAdmin(DjangoUserAdmin):
    form = AdminUserForm
    add_form = AdminUserForm
    model = User
    ordering = ("first_name", "last_name")
    list_display = (
        "email",
        "phone",
        "official_full_name",
        "nickname",
        "role",
        "is_active",
        "must_change_pin",
        "all_sites_access",
        "joined_badge",
    )
    list_filter = ("role", "is_active", "all_sites_access", "must_change_pin", "groups")
    search_fields = ("email", "phone", "first_name", "last_name", "nickname")
    readonly_fields = ("date_joined", "last_login", "pin_changed_at")
    filter_horizontal = ("groups", "user_permissions", "assigned_categories", "assigned_sites")
    fieldsets = (
        ("Identity", {"fields": ("email", "phone", "first_name", "last_name", "nickname", "profile_picture")}),
        ("Access", {"fields": ("role", "is_active", "all_sites_access", "must_change_pin")}),
        ("Assignments", {"fields": ("assigned_categories", "assigned_sites", "groups", "user_permissions")}),
        ("Security", {"fields": ("date_joined", "last_login", "pin_changed_at")}),
    )
    add_fieldsets = (
        (
            "Identity",
            {
                "classes": ("wide",),
                "fields": ("email", "phone", "first_name", "last_name", "nickname", "profile_picture", "role", "secret", "secret_confirm"),
            },
        ),
        (
            "Access",
            {
                "classes": ("wide",),
                "fields": ("is_active", "all_sites_access", "must_change_pin", "assigned_categories", "assigned_sites", "groups", "user_permissions"),
            },
        ),
    )

    def get_queryset(self, request):
        return super().get_queryset(request).select_related().prefetch_related("assigned_categories", "assigned_sites", "groups")

    def joined_badge(self, obj):
        return format_html("<span>{}</span>", obj.date_joined.strftime("%Y-%m-%d %H:%M"))

    joined_badge.short_description = "Joined"

    @admin.action(description="Activate selected users")
    def activate_users(self, request, queryset):
        count = queryset.update(is_active=True)
        self.message_user(request, f"{count} user(s) activated.", level=messages.SUCCESS)
        log_event(actor=request.user, action="users_activated", request=request, metadata={"count": count})

    @admin.action(description="Deactivate selected users")
    def deactivate_users(self, request, queryset):
        count = queryset.update(is_active=False)
        self.message_user(request, f"{count} user(s) deactivated.", level=messages.WARNING)
        log_event(actor=request.user, action="users_deactivated", request=request, metadata={"count": count})

    @admin.action(description="Reset PINs for workers")
    def reset_pins(self, request, queryset):
        count = 0
        for user in queryset.filter(role=UserRole.WORKER):
            reset_pin(actor=request.user, user=user)
            count += 1
        self.message_user(request, f"{count} worker PIN(s) reset.", level=messages.SUCCESS)
        log_event(actor=request.user, action="workers_pin_reset", request=request, metadata={"count": count})

    actions = ["activate_users", "deactivate_users", "reset_pins"]

    def save_model(self, request, obj, form, change):
        super().save_model(request, obj, form, change)
        log_event(
            actor=request.user,
            action="user_updated" if change else "user_created",
            request=request,
            object_instance=obj,
            metadata={"role": obj.role, "all_sites_access": obj.all_sites_access},
        )

    def save_related(self, request, form, formsets, change):
        super().save_related(request, form, formsets, change)
        obj = form.instance
        log_event(
            actor=request.user,
            action="user_assignments_updated",
            request=request,
            object_instance=obj,
            metadata={
                "assigned_sites": list(obj.assigned_sites.values_list("id", flat=True)),
                "assigned_categories": list(obj.assigned_categories.values_list("id", flat=True)),
            },
        )

    def delete_model(self, request, obj):
        log_event(actor=request.user, action="user_deleted", request=request, object_instance=obj)
        super().delete_model(request, obj)
