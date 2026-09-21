from __future__ import annotations

import django_filters
from django.db.models import Q

from .models import Bill, TariffTier


class BillFilter(django_filters.FilterSet):
    q = django_filters.CharFilter(method="filter_query", label="Search")

    class Meta:
        model = Bill
        fields = ["scope", "status", "worker", "category", "site", "period_start", "period_end"]

    def filter_query(self, queryset, name, value):
        return queryset.filter(
            Q(bill_number__icontains=value)
            | Q(worker_name__icontains=value)
            | Q(category_name__icontains=value)
            | Q(site_name__icontains=value)
            | Q(prepared_by_name__icontains=value)
        )


class TariffFilter(django_filters.FilterSet):
    class Meta:
        model = TariffTier
        fields = ["is_active", "effective_from", "effective_to"]
