from django.contrib.auth.base_user import BaseUserManager
from django.utils import timezone

from apps.core.choices import UserRole


class UserManager(BaseUserManager):
    use_in_migrations = True

    def _create_user(self, email, phone, first_name, last_name, secret, **extra_fields):
        if not email:
            raise ValueError("The email address must be set.")
        if not phone:
            raise ValueError("The phone number must be set.")
        if not secret:
            raise ValueError("A PIN or password must be provided.")

        role = extra_fields.pop("role", UserRole.WORKER)

        email = self.normalize_email(email)
        user = self.model(
            email=email,
            phone=str(phone).strip(),
            first_name=first_name.strip() if first_name else "",
            last_name=last_name.strip() if last_name else "",
            role=role,
            **extra_fields,
        )
        user.set_password(secret)
        user.must_change_pin = extra_fields.pop("must_change_pin", role == UserRole.WORKER)
        user.pin_changed_at = timezone.now()
        user.save(using=self._db)
        return user

    def create_user(self, email, phone, first_name, last_name, pin=None, password=None, **extra_fields):
        secret = pin or password or extra_fields.pop("secret", None)
        return self._create_user(email, phone, first_name, last_name, secret, **extra_fields)

    def create_superuser(self, email, phone, first_name, last_name, password=None, **extra_fields):
        extra_fields.setdefault("is_staff", True)
        extra_fields.setdefault("is_superuser", True)
        extra_fields.setdefault("role", UserRole.SUPER_ADMIN)
        extra_fields.setdefault("must_change_pin", False)

        if password is None:
            raise ValueError("Superusers must have a password.")
        user = self._create_user(email, phone, first_name, last_name, password, **extra_fields)
        user.is_staff = True
        user.is_superuser = True
        user.must_change_pin = False
        user.save(using=self._db)
        return user

    def create_worker(self, email, phone, first_name, last_name, pin, **extra_fields):
        extra_fields.setdefault("role", UserRole.WORKER)
        extra_fields.setdefault("must_change_pin", True)
        return self._create_user(email, phone, first_name, last_name, pin, **extra_fields)
