from rest_framework import serializers

from .models import WaterUsageEntry


class WaterUsageEntrySerializer(serializers.ModelSerializer):
    worker_name = serializers.CharField(source="worker.display_name", read_only=True)
    category_name = serializers.CharField(source="category.name", read_only=True)
    site_name = serializers.CharField(source="site.name", read_only=True)

    class Meta:
        model = WaterUsageEntry
        fields = [
            "id",
            "worker",
            "worker_name",
            "category",
            "category_name",
            "site",
            "site_name",
            "entry_date",
            "shift",
            "morning_reading",
            "evening_reading",
            "consumption_m3",
            "notes",
            "created_at",
            "updated_at",
        ]
        read_only_fields = ["worker", "category", "consumption_m3", "created_at", "updated_at"]
