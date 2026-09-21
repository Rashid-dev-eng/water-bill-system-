from rest_framework import serializers

from .models import Category, OperationalSite


class CategorySerializer(serializers.ModelSerializer):
    site_count = serializers.SerializerMethodField()

    class Meta:
        model = Category
        fields = ["id", "name", "code", "description", "color", "sort_order", "is_active", "is_archived", "archived_at", "site_count"]

    def get_site_count(self, obj):
        return obj.sites.count()


class OperationalSiteSerializer(serializers.ModelSerializer):
    category_name = serializers.CharField(source="category.name", read_only=True)

    class Meta:
        model = OperationalSite
        fields = [
            "id",
            "category",
            "category_name",
            "name",
            "code",
            "description",
            "location",
            "latitude",
            "longitude",
            "is_active",
            "is_archived",
            "archived_at",
        ]
