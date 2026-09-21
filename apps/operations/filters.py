import django_filters

from .models import Category, OperationalSite


class CategoryFilter(django_filters.FilterSet):
    q = django_filters.CharFilter(method="filter_query", label="Search")

    class Meta:
        model = Category
        fields = ["is_active", "is_archived"]

    def filter_query(self, queryset, name, value):
        return queryset.filter(name__icontains=value)


class SiteFilter(django_filters.FilterSet):
    q = django_filters.CharFilter(method="filter_query", label="Search")

    class Meta:
        model = OperationalSite
        fields = ["category", "is_active", "is_archived"]

    def filter_query(self, queryset, name, value):
        return queryset.filter(name__icontains=value)
