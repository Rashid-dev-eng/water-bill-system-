from __future__ import annotations

import csv
from decimal import Decimal

from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.db.models import Q
from django.http import Http404, HttpRequest, HttpResponse
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.choices import UserRole
from apps.core.permissions import can_user_access_site
from apps.operations.models import OperationalSite

from .filters import WaterUsageEntryFilter
from .forms import WaterUsageEntryForm
from .models import WaterUsageEntry
from .services import create_entry, delete_entry, update_entry


def _base_queryset_for_user(user):
    if user.is_staff or user.is_superuser or user.role == UserRole.SUPER_ADMIN:
        return WaterUsageEntry.objects.select_related("worker", "category", "site", "site__category")
    return WaterUsageEntry.objects.select_related("worker", "category", "site", "site__category").filter(worker=user)


def _export_csv(queryset):
    response = HttpResponse(content_type="text/csv")
    response["Content-Disposition"] = 'attachment; filename="water-usage-history.csv"'
    writer = csv.writer(response)
    writer.writerow([
        "Date",
        "Shift",
        "Worker",
        "Category",
        "Site",
        "Morning Reading",
        "Evening Reading",
        "Consumption",
        "Notes",
        "Created At",
    ])
    for entry in queryset:
        writer.writerow([
            entry.entry_date,
            entry.shift,
            entry.worker.display_name,
            entry.category.name,
            entry.site.name,
            entry.morning_reading,
            entry.evening_reading,
            entry.consumption_m3,
            entry.notes,
            entry.created_at,
        ])
    return response


@login_required
def create_entry_view(request: HttpRequest, site_id: int) -> HttpResponse:
    site = get_object_or_404(OperationalSite.objects.select_related("category"), pk=site_id)
    if not can_user_access_site(request.user, site):
        raise Http404()

    form = WaterUsageEntryForm(request.POST or None, user=request.user, site=site)
    if request.method == "POST" and form.is_valid():
        entry = create_entry(actor=request.user, site=site, form=form)
        messages.success(request, f"Usage entry saved for {site.display_label}.")
        return redirect("dashboard:home")

    return render(request, "water/entry_form.html", {"form": form, "site": site, "title": f"New Entry - {site.name}"})


@login_required
def entry_list_view(request: HttpRequest) -> HttpResponse:
    queryset = _base_queryset_for_user(request.user)
    filterset = WaterUsageEntryFilter(request.GET, queryset=queryset, request=request)
    filtered = filterset.qs

    if request.GET.get("export") == "csv":
        return _export_csv(filtered)

    paginator = Paginator(filtered, 15)
    page_number = request.GET.get("page")
    page_obj = paginator.get_page(page_number)

    context = {
        "filterset": filterset,
        "page_obj": page_obj,
        "entries": page_obj.object_list,
        "title": "Water Usage History",
    }
    template = "water/admin_history.html" if request.user.is_staff or request.user.is_superuser else "water/history.html"
    return render(request, template, context)


@login_required
def entry_edit_view(request: HttpRequest, pk: int) -> HttpResponse:
    entry = get_object_or_404(_base_queryset_for_user(request.user), pk=pk)
    if request.user.role == UserRole.WORKER and entry.worker_id != request.user.pk:
        raise Http404()

    form = WaterUsageEntryForm(request.POST or None, instance=entry, user=request.user, site=entry.site)
    if request.method == "POST" and form.is_valid():
        update_entry(actor=request.user, entry=entry, form=form)
        messages.success(request, "Usage entry updated.")
        return redirect("water:history")

    return render(request, "water/entry_form.html", {"form": form, "site": entry.site, "entry": entry, "title": "Edit Entry"})


@login_required
def entry_delete_view(request: HttpRequest, pk: int) -> HttpResponse:
    entry = get_object_or_404(_base_queryset_for_user(request.user), pk=pk)
    if request.user.role == UserRole.WORKER and entry.worker_id != request.user.pk:
        raise Http404()

    if request.method == "POST":
        delete_entry(actor=request.user, entry=entry)
        messages.success(request, "Usage entry deleted.")
        return redirect("water:history")

    return render(request, "water/entry_confirm_delete.html", {"entry": entry, "title": "Delete Entry"})
