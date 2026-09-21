from __future__ import annotations

import django_filters
from django.db import models
from django.utils import timezone

from apps.core.utils import month_bounds

from .models import WaterUsageEntry


class WaterUsageEntryFilter(django_filters.FilterSet):
    q = django_filters.CharFilter(method="filter_query", label="Search")
    month = django_filters.NumberFilter(method="filter_month", label="Month")
    year = django_filters.NumberFilter(method="filter_year", label="Year")
    date_range = django_filters.CharFilter(method="filter_date_range", label="Date Range")

    class Meta:
        model = WaterUsageEntry
        fields = ["worker", "category", "site", "shift", "entry_date"]

    def filter_query(self, queryset, name, value):
        return queryset.filter(
            models.Q(site__name__icontains=value)
            | models.Q(site__code__icontains=value)
            | models.Q(category__name__icontains=value)
            | models.Q(worker__first_name__icontains=value)
            | models.Q(worker__last_name__icontains=value)
        )

    def filter_month(self, queryset, name, value):
        if not value:
            return queryset
        year = int(self.data.get("year") or timezone.localdate().year)
        start, end = month_bounds(year, int(value))
        return queryset.filter(entry_date__range=(start, end))

    def filter_year(self, queryset, name, value):
        if not value:
            return queryset
        return queryset.filter(entry_date__year=value)

    def filter_date_range(self, queryset, name, value):
        if not value or " to " not in value:
            return queryset
        start_value, end_value = value.split(" to ", 1)
        return queryset.filter(entry_date__range=(start_value, end_value))
