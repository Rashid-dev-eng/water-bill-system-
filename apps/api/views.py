from __future__ import annotations

from django.urls import reverse
from rest_framework import permissions, status, views
from rest_framework.response import Response
from rest_framework_simplejwt.views import TokenRefreshView, TokenViewBase

from .serializers import WorkerTokenObtainPairSerializer
from .throttles import LoginRateThrottle


class APIRootView(views.APIView):
    permission_classes = [permissions.AllowAny]

    def get(self, request, *args, **kwargs):
        return Response(
            {
                "name": "NOLELO Clean Water Supply Management System API",
                "version": "v1",
                "endpoints": {
                    "auth_token": reverse("api:token-obtain"),
                    "auth_refresh": reverse("api:token-refresh"),
                    "users": reverse("api:user-list"),
                    "categories": reverse("api:category-list"),
                    "sites": reverse("api:site-list"),
                    "entries": reverse("api:entry-list"),
                    "tariffs": reverse("api:tariff-list"),
                    "bills": reverse("api:bill-list"),
                    "notifications": reverse("api:notification-list"),
                    "audit": reverse("api:audit-list"),
                },
            }
        )


class TokenObtainPairView(TokenViewBase):
    serializer_class = WorkerTokenObtainPairSerializer
    throttle_classes = [LoginRateThrottle]


class TokenRefreshAPIView(TokenRefreshView):
    pass
