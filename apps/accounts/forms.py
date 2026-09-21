from __future__ import annotations

from django import forms
from django.contrib.auth import authenticate
from django.core.exceptions import ValidationError
from django.utils import timezone

from apps.core.choices import UserRole

from .models import User


class WorkerLoginForm(forms.Form):
    identifier = forms.CharField(
        label="Email or Phone Number",
        max_length=150,
        widget=forms.TextInput(attrs={"autocomplete": "username", "class": "form-control"}),
    )
    pin = forms.CharField(
        label="PIN",
        max_length=4,
        min_length=4,
        widget=forms.PasswordInput(attrs={"autocomplete": "current-password", "class": "form-control"}),
    )

    def clean_pin(self):
        pin = self.cleaned_data["pin"].strip()
        if not pin.isdigit() or len(pin) != 4:
            raise ValidationError("PIN must contain exactly four digits.")
        return pin

    def clean(self):
        cleaned_data = super().clean()
        identifier = cleaned_data.get("identifier")
        pin = cleaned_data.get("pin")
        if identifier and pin:
            cleaned_data["user"] = authenticate(self.request, identifier=identifier, pin=pin)
            if cleaned_data["user"] is None:
                raise ValidationError("Invalid login credentials.")
        return cleaned_data

    def __init__(self, *args, **kwargs):
        self.request = kwargs.pop("request", None)
        super().__init__(*args, **kwargs)


class WorkerProfileForm(forms.ModelForm):
    class Meta:
        model = User
        fields = ["email", "phone", "nickname", "profile_picture"]
        widgets = {
            "email": forms.EmailInput(attrs={"class": "form-control"}),
            "phone": forms.TextInput(attrs={"class": "form-control"}),
            "nickname": forms.TextInput(attrs={"class": "form-control"}),
            "profile_picture": forms.ClearableFileInput(attrs={"class": "form-control"}),
        }


class WorkerPinChangeForm(forms.Form):
    current_pin = forms.CharField(
        label="Current PIN",
        widget=forms.PasswordInput(attrs={"class": "form-control"}),
    )
    new_pin = forms.CharField(
        label="New PIN",
        min_length=4,
        max_length=4,
        widget=forms.PasswordInput(attrs={"class": "form-control"}),
    )
    confirm_pin = forms.CharField(
        label="Confirm New PIN",
        min_length=4,
        max_length=4,
        widget=forms.PasswordInput(attrs={"class": "form-control"}),
    )

    def __init__(self, *args, **kwargs):
        self.user = kwargs.pop("user")
        super().__init__(*args, **kwargs)

    def clean_current_pin(self):
        current_pin = self.cleaned_data["current_pin"]
        if not self.user.check_password(current_pin):
            raise ValidationError("Current PIN is incorrect.")
        return current_pin

    def clean_new_pin(self):
        new_pin = self.cleaned_data["new_pin"].strip()
        if not new_pin.isdigit() or len(new_pin) != 4:
            raise ValidationError("PIN must contain exactly four digits.")
        return new_pin

    def clean(self):
        cleaned_data = super().clean()
        new_pin = cleaned_data.get("new_pin")
        confirm_pin = cleaned_data.get("confirm_pin")
        if new_pin and confirm_pin and new_pin != confirm_pin:
            raise ValidationError("The new PINs do not match.")
        return cleaned_data

    def save(self):
        new_pin = self.cleaned_data["new_pin"]
        self.user.set_password(new_pin)
        self.user.must_change_pin = False
        self.user.pin_changed_at = timezone.now()
        self.user.save(update_fields=["password", "must_change_pin", "pin_changed_at", "updated_at"])
        return self.user


class AdminUserForm(forms.ModelForm):
    secret = forms.CharField(
        required=False,
        label="PIN / Password",
        widget=forms.PasswordInput(attrs={"class": "form-control"}),
        help_text="Workers must use a 4-digit PIN. Super Admins may use a secure password.",
    )
    secret_confirm = forms.CharField(
        required=False,
        label="Confirm Secret",
        widget=forms.PasswordInput(attrs={"class": "form-control"}),
    )

    class Meta:
        model = User
        fields = [
            "email",
            "phone",
            "first_name",
            "last_name",
            "nickname",
            "profile_picture",
            "role",
            "is_active",
            "all_sites_access",
            "must_change_pin",
            "assigned_categories",
            "assigned_sites",
            "groups",
            "user_permissions",
        ]
        widgets = {
            "email": forms.EmailInput(attrs={"class": "form-control"}),
            "phone": forms.TextInput(attrs={"class": "form-control"}),
            "first_name": forms.TextInput(attrs={"class": "form-control"}),
            "last_name": forms.TextInput(attrs={"class": "form-control"}),
            "nickname": forms.TextInput(attrs={"class": "form-control"}),
            "profile_picture": forms.ClearableFileInput(attrs={"class": "form-control"}),
            "role": forms.Select(attrs={"class": "form-select"}),
            "is_active": forms.CheckboxInput(attrs={"class": "form-check-input"}),
            "all_sites_access": forms.CheckboxInput(attrs={"class": "form-check-input"}),
            "must_change_pin": forms.CheckboxInput(attrs={"class": "form-check-input"}),
            "assigned_categories": forms.SelectMultiple(attrs={"class": "form-select", "size": 6}),
            "assigned_sites": forms.SelectMultiple(attrs={"class": "form-select", "size": 8}),
            "groups": forms.SelectMultiple(attrs={"class": "form-select", "size": 6}),
            "user_permissions": forms.SelectMultiple(attrs={"class": "form-select", "size": 8}),
        }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if self.instance and self.instance.pk:
            self.fields["must_change_pin"].required = False
            self.fields["secret"].help_text = "Leave blank to keep the current PIN or password."
            self.fields["secret_confirm"].help_text = "Confirm only if changing the secret."

    def clean(self):
        cleaned_data = super().clean()
        secret = cleaned_data.get("secret")
        secret_confirm = cleaned_data.get("secret_confirm")
        role = cleaned_data.get("role") or getattr(self.instance, "role", UserRole.WORKER)

        if secret or secret_confirm:
            if secret != secret_confirm:
                raise ValidationError("The secret values do not match.")
            if role == UserRole.WORKER:
                if not secret.isdigit() or len(secret) != 4:
                    raise ValidationError("Worker PINs must be exactly four digits.")
            elif len(secret) < 8:
                raise ValidationError("Super Admin passwords must be at least 8 characters long.")
        elif not self.instance.pk:
            raise ValidationError("A PIN or password is required when creating a user.")

        return cleaned_data

    def save(self, commit=True):
        user = super().save(commit=False)
        secret = self.cleaned_data.get("secret")
        role = self.cleaned_data.get("role")

        if role == UserRole.SUPER_ADMIN:
            user.is_staff = True
            user.is_superuser = True
            user.must_change_pin = False if user.pk else False
        else:
            user.is_staff = False
            user.is_superuser = False
            if not user.pk:
                user.must_change_pin = True

        if secret:
            user.set_password(secret)
            user.pin_changed_at = timezone.now()
            if role == UserRole.WORKER:
                user.must_change_pin = True

        if commit:
            user.save()
            self.save_m2m()
        return user
