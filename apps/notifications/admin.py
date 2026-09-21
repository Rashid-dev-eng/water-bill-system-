from django.contrib import admin

from .models import Notification


@admin.register(Notification)
class NotificationAdmin(admin.ModelAdmin):
    list_display = ("created_at", "recipient", "title", "level", "is_read", "read_at")
    list_filter = ("level", "is_read", "created_at")
    search_fields = ("recipient__email", "recipient__first_name", "recipient__last_name", "title", "message")
    readonly_fields = ("created_at", "updated_at", "read_at")
