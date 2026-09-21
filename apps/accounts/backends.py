from django.contrib.auth.backends import ModelBackend
from django.db.models import Q

from .models import User


class EmailOrPhoneBackend(ModelBackend):
    def authenticate(self, request, identifier=None, pin=None, **kwargs):
        identifier = (identifier or kwargs.get("username") or "").strip()
        secret = pin or kwargs.get("password") or kwargs.get("secret")
        if not identifier or not secret:
            return None

        try:
            user = User.objects.get(Q(email__iexact=identifier) | Q(phone=identifier))
        except User.DoesNotExist:
            return None

        if not user.is_active:
            return None

        if user.check_password(secret):
            return user
        return None
