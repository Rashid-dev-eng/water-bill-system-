from __future__ import annotations

import django_filters

from .models import AuditEvent


class AuditEventFilter(django_filters.FilterSet):
    q = django_filters.CharFilter(method="filter_query", label="Search")

    class Meta:
        model = AuditEvent
        fields = ["action", "actor", "request_method"]

    def filter_query(self, queryset, name, value):
        return queryset.filter(object_repr__icontains=value)
