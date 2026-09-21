from django.urls import include, path
from rest_framework.routers import DefaultRouter
from rest_framework_simplejwt.views import TokenRefreshView

from .views import APIRootView, TokenObtainPairView, TokenRefreshAPIView
from .viewsets import (
    AuditEventViewSet,
    BillViewSet,
    CategoryViewSet,
    NotificationViewSet,
    OperationalSiteViewSet,
    ProfileViewSet,
    TariffViewSet,
    UserViewSet,
    WaterUsageEntryViewSet,
)

app_name = "api"

router = DefaultRouter()
router.register("users", UserViewSet, basename="user")
router.register("categories", CategoryViewSet, basename="category")
router.register("sites", OperationalSiteViewSet, basename="site")
router.register("entries", WaterUsageEntryViewSet, basename="entry")
router.register("tariffs", TariffViewSet, basename="tariff")
router.register("bills", BillViewSet, basename="bill")
router.register("notifications", NotificationViewSet, basename="notification")
router.register("audit", AuditEventViewSet, basename="audit")

urlpatterns = [
    path("", APIRootView.as_view(), name="root"),
    path("auth/token/", TokenObtainPairView.as_view(), name="token-obtain"),
    path("auth/token/refresh/", TokenRefreshAPIView.as_view(), name="token-refresh"),
    path("me/", ProfileViewSet.as_view({"get": "retrieve", "put": "update", "patch": "partial_update"}), name="me"),
    path("", include(router.urls)),
]
