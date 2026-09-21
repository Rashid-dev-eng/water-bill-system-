from rest_framework import serializers

from .models import Bill, BillLine, TariffTier


class TariffTierSerializer(serializers.ModelSerializer):
    class Meta:
        model = TariffTier
        fields = ["id", "name", "min_volume", "max_volume", "rate_per_m3", "fixed_charge", "order", "effective_from", "effective_to", "is_active", "notes"]


class BillLineSerializer(serializers.ModelSerializer):
    class Meta:
        model = BillLine
        fields = ["id", "position", "tariff_name", "min_volume", "max_volume", "volume", "unit_rate", "amount"]


class BillSerializer(serializers.ModelSerializer):
    lines = BillLineSerializer(many=True, read_only=True)
    generated_by_name = serializers.CharField(source="generated_by.display_name", read_only=True)

    class Meta:
        model = Bill
        fields = [
            "id",
            "bill_number",
            "scope",
            "status",
            "worker",
            "category",
            "site",
            "period_start",
            "period_end",
            "date_generated",
            "total_consumption",
            "total_amount",
            "worker_name",
            "category_name",
            "site_name",
            "prepared_by_name",
            "generated_by_name",
            "notes",
            "pdf_file",
            "is_reopened",
            "reopened_at",
            "regenerated_from",
            "lines",
        ]
