from __future__ import annotations

from django.contrib import messages
from django.contrib.auth import authenticate, login, logout
from django.contrib.auth.decorators import login_required
from django.http import HttpRequest, HttpResponse
from django.shortcuts import redirect, render
from django.urls import reverse

from apps.audit.services import log_event
from apps.core.choices import UserRole
from apps.core.permissions import StaffRequiredMixin, WorkerRequiredMixin

from .forms import WorkerLoginForm, WorkerPinChangeForm, WorkerProfileForm
from .services import update_profile


def login_view(request: HttpRequest) -> HttpResponse:
    if request.user.is_authenticated:
        return redirect("dashboard:home")

    form = WorkerLoginForm(request.POST or None, request=request)
    if request.method == "POST" and form.is_valid():
        user = form.cleaned_data["user"]
        login(request, user)
        messages.success(request, f"Welcome back, {user.welcome_name}.")
        if user.must_change_pin:
            return redirect("accounts:change-pin")
        return redirect("dashboard:home")

    return render(request, "accounts/login.html", {"form": form, "title": "Worker Login"})


@login_required
def logout_view(request: HttpRequest) -> HttpResponse:
    logout(request)
    messages.info(request, "You have been signed out.")
    return redirect("accounts:login")


@login_required
def profile_view(request: HttpRequest) -> HttpResponse:
    user = request.user
    form = WorkerProfileForm(request.POST or None, request.FILES or None, instance=user)
    if request.method == "POST" and form.is_valid():
        update_profile(
            actor=user,
            email=form.cleaned_data["email"],
            phone=form.cleaned_data["phone"],
            nickname=form.cleaned_data.get("nickname", ""),
            profile_picture=form.cleaned_data.get("profile_picture"),
        )
        messages.success(request, "Your profile was updated successfully.")
        return redirect("accounts:profile")

    return render(request, "accounts/profile.html", {"form": form, "user_profile": user, "title": "My Profile"})


@login_required
def change_pin_view(request: HttpRequest) -> HttpResponse:
    user = request.user
    form = WorkerPinChangeForm(request.POST or None, user=user)
    if request.method == "POST" and form.is_valid():
        form.save()
        messages.success(request, "Your PIN has been updated.")
        log_event(actor=user, action="pin_changed_from_form", request=request, object_instance=user)
        return redirect("dashboard:home")

    return render(request, "accounts/change_pin.html", {"form": form, "title": "Change PIN"})
