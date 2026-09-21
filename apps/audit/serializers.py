from rest_framework import serializers

from .models import AuditEvent


class AuditEventSerializer(serializers.ModelSerializer):
    actor_name = serializers.CharField(source="actor.display_name", read_only=True)

    class Meta:
        model = AuditEvent
        fields = [
            "id",
            "created_at",
            "actor",
            "actor_name",
            "action",
            "object_repr",
            "ip_address",
            "request_path",
            "request_method",
            "status_code",
            "metadata",
        ]
