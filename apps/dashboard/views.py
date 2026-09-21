from __future__ import annotations

from django.contrib.auth.decorators import login_required
from django.http import HttpRequest, HttpResponse
from django.shortcuts import redirect, render

from apps.core.choices import UserRole

from .services import build_admin_dashboard_context, build_worker_dashboard_context


def health_check(request: HttpRequest) -> HttpResponse:
    return HttpResponse("ok", content_type="text/plain")


@login_required
def home(request: HttpRequest) -> HttpResponse:
    if request.user.is_staff or request.user.is_superuser or request.user.role == UserRole.SUPER_ADMIN:
        return redirect("dashboard:admin")
    return redirect("dashboard:worker")


@login_required
def admin_dashboard_view(request: HttpRequest) -> HttpResponse:
    if not (request.user.is_staff or request.user.is_superuser or request.user.role == UserRole.SUPER_ADMIN):
        return redirect("dashboard:worker")
    context = build_admin_dashboard_context()
    context.update({"title": "Administrator Dashboard"})
    return render(request, "dashboard/admin_dashboard.html", context)


@login_required
def worker_dashboard_view(request: HttpRequest) -> HttpResponse:
    if request.user.is_staff or request.user.is_superuser or request.user.role == UserRole.SUPER_ADMIN:
        return redirect("dashboard:admin")
    context = build_worker_dashboard_context(request.user)
    context.update({"title": "Worker Dashboard"})
    return render(request, "dashboard/worker_dashboard.html", context)
