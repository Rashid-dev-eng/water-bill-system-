from __future__ import annotations

from django.contrib.auth import authenticate
from rest_framework import serializers
from rest_framework.exceptions import AuthenticationFailed
from rest_framework_simplejwt.serializers import TokenObtainPairSerializer

from apps.accounts.serializers import UserSerializer


class WorkerTokenObtainPairSerializer(TokenObtainPairSerializer):
    identifier = serializers.CharField()
    secret = serializers.CharField(write_only=True)

    def validate(self, attrs):
        identifier = attrs.pop("identifier")
        secret = attrs.pop("secret")
        request = self.context.get("request")

        user = authenticate(request=request, identifier=identifier, pin=secret)
        if user is None:
            user = authenticate(request=request, username=identifier, password=secret)

        if user is None:
            raise AuthenticationFailed("Invalid credentials.")

        refresh = self.get_token(user)
        return {
            "refresh": str(refresh),
            "access": str(refresh.access_token),
            "user": UserSerializer(user, context=self.context).data,
        }
