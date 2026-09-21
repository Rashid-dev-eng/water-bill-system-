from __future__ import annotations

from django.db.models import Q
from rest_framework import mixins, permissions, viewsets
from rest_framework.exceptions import PermissionDenied

from apps.accounts.models import User
from apps.accounts.permissions import IsSelfOrStaff, IsSuperAdmin
from apps.accounts.serializers import ProfileSerializer, UserSerializer
from apps.audit.filters import AuditEventFilter
from apps.audit.models import AuditEvent
from apps.audit.serializers import AuditEventSerializer
from apps.billing.filters import BillFilter, TariffFilter
from apps.billing.models import Bill, TariffTier
from apps.billing.serializers import BillSerializer, TariffTierSerializer
from apps.core.choices import UserRole
from apps.core.permissions import can_user_access_category, can_user_access_site
from apps.notifications.models import Notification
from apps.notifications.serializers import NotificationSerializer
from apps.operations.filters import CategoryFilter, SiteFilter
from apps.operations.models import Category, OperationalSite
from apps.operations.serializers import CategorySerializer, OperationalSiteSerializer
from apps.water.filters import WaterUsageEntryFilter
from apps.water.models import WaterUsageEntry
from apps.water.serializers import WaterUsageEntrySerializer


class UserViewSet(viewsets.ModelViewSet):
    serializer_class = UserSerializer
    permission_classes = [IsSuperAdmin]
    queryset = User.objects.all().prefetch_related("assigned_categories", "assigned_sites")
    search_fields = ["email", "phone", "first_name", "last_name", "nickname"]
    ordering_fields = ["email", "first_name", "last_name", "date_joined"]


class ProfileViewSet(viewsets.GenericViewSet, mixins.RetrieveModelMixin, mixins.UpdateModelMixin):
    serializer_class = ProfileSerializer
    permission_classes = [IsSelfOrStaff]

    def get_object(self):
        return self.request.user


class CategoryViewSet(viewsets.ModelViewSet):
    serializer_class = CategorySerializer
    filterset_class = CategoryFilter
    search_fields = ["name", "code", "description"]
    ordering_fields = ["name", "sort_order", "created_at"]

    def get_queryset(self):
        user = self.request.user
        qs = Category.objects.all()
        if user.is_staff or user.is_superuser or user.role == UserRole.SUPER_ADMIN:
            return qs
        return qs.filter(Q(pk__in=user.assigned_categories.values_list("pk", flat=True)) | Q(sites__pk__in=user.assigned_sites.values_list("pk", flat=True))).distinct()

    def get_permissions(self):
        if self.action in {"list", "retrieve"}:
            return [permissions.IsAuthenticated()]
        return [IsSuperAdmin()]


class OperationalSiteViewSet(viewsets.ModelViewSet):
    serializer_class = OperationalSiteSerializer
    filterset_class = SiteFilter
    search_fields = ["name", "code", "location", "description", "category__name"]
    ordering_fields = ["name", "created_at"]

    def get_queryset(self):
        user = self.request.user
        qs = OperationalSite.objects.select_related("category")
        if user.is_staff or user.is_superuser or user.role == UserRole.SUPER_ADMIN:
            return qs
        return qs.filter(Q(pk__in=user.assigned_sites.values_list("pk", flat=True)) | Q(category__pk__in=user.assigned_categories.values_list("pk", flat=True))).distinct()

    def get_permissions(self):
        if self.action in {"list", "retrieve"}:
            return [permissions.IsAuthenticated()]
        return [IsSuperAdmin()]


class WaterUsageEntryViewSet(viewsets.ModelViewSet):
    serializer_class = WaterUsageEntrySerializer
    filterset_class = WaterUsageEntryFilter
    search_fields = ["site__name", "site__code", "category__name", "worker__first_name", "worker__last_name"]
    ordering_fields = ["entry_date", "created_at", "updated_at", "consumption_m3"]

    def get_queryset(self):
        user = self.request.user
        qs = WaterUsageEntry.objects.select_related("worker", "category", "site", "site__category")
        if user.is_staff or user.is_superuser or user.role == UserRole.SUPER_ADMIN:
            return qs
        return qs.filter(worker=user)

    def perform_create(self, serializer):
        site = serializer.validated_data["site"]
        if not can_user_access_site(self.request.user, site):
            raise PermissionDenied("You are not allowed to use this site.")
        serializer.save(worker=self.request.user, category=site.category)

    def perform_update(self, serializer):
        instance = serializer.instance
        if not (self.request.user.is_staff or instance.worker_id == self.request.user.pk):
            raise PermissionDenied("You can only edit your own entries.")
        serializer.save()

    def perform_destroy(self, instance):
        if not (self.request.user.is_staff or instance.worker_id == self.request.user.pk):
            raise PermissionDenied("You can only delete your own entries.")
        instance.delete()


class TariffViewSet(viewsets.ModelViewSet):
    serializer_class = TariffTierSerializer
    filterset_class = TariffFilter
    search_fields = ["name", "notes"]
    ordering_fields = ["min_volume", "rate_per_m3", "order"]

    def get_queryset(self):
        return TariffTier.objects.all()

    def get_permissions(self):
        return [IsSuperAdmin()]


class BillViewSet(viewsets.ModelViewSet):
    serializer_class = BillSerializer
    filterset_class = BillFilter
    search_fields = ["bill_number", "worker_name", "category_name", "site_name", "prepared_by_name"]
    ordering_fields = ["date_generated", "period_start", "period_end", "total_amount"]

    def get_queryset(self):
        return Bill.objects.select_related("generated_by", "worker", "category", "site").prefetch_related("lines")

    def get_permissions(self):
        return [IsSuperAdmin()]


class NotificationViewSet(viewsets.ModelViewSet):
    serializer_class = NotificationSerializer
    filterset_fields = ["level", "is_read"]
    search_fields = ["title", "message"]
    ordering_fields = ["created_at"]

    def get_queryset(self):
        user = self.request.user
        if user.is_staff or user.is_superuser:
            return Notification.objects.select_related("recipient")
        return Notification.objects.filter(recipient=user)

    def get_permissions(self):
        if self.action in {"list", "retrieve", "update", "partial_update", "destroy"}:
            return [permissions.IsAuthenticated()]
        return [IsSuperAdmin()]

    def perform_create(self, serializer):
        serializer.save(recipient=self.request.user)


class AuditEventViewSet(viewsets.ReadOnlyModelViewSet):
    serializer_class = AuditEventSerializer
    filterset_class = AuditEventFilter
    search_fields = ["object_repr", "action", "request_path"]
    ordering_fields = ["created_at"]

    def get_queryset(self):
        return AuditEvent.objects.select_related("actor", "content_type")

    def get_permissions(self):
        return [IsSuperAdmin()]
