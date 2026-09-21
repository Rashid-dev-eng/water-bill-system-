from rest_framework import serializers

from .models import Notification


class NotificationSerializer(serializers.ModelSerializer):
    recipient_name = serializers.CharField(source="recipient.display_name", read_only=True)

    class Meta:
        model = Notification
        fields = [
            "id",
            "recipient",
            "recipient_name",
            "title",
            "message",
            "level",
            "is_read",
            "read_at",
            "action_url",
            "metadata",
            "created_at",
        ]
