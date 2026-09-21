from __future__ import annotations

from django.contrib.auth.decorators import login_required
from django.http import Http404, HttpRequest, HttpResponse
from django.shortcuts import render

from apps.billing.models import Bill
from apps.water.models import WaterUsageEntry

from .services import csv_response


@login_required
def reports_home_view(request: HttpRequest) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    return render(request, "reports/report_home.html", {"title": "Reports"})


@login_required
def export_entries_csv_view(request: HttpRequest) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    queryset = WaterUsageEntry.objects.select_related("worker", "category", "site").order_by("-entry_date")
    rows = [
        [
            entry.entry_date,
            entry.worker.display_name,
            entry.category.name,
            entry.site.name,
            entry.shift,
            entry.morning_reading,
            entry.evening_reading,
            entry.consumption_m3,
            entry.notes,
        ]
        for entry in queryset
    ]
    return csv_response(
        "usage-entries.csv",
        ["Date", "Worker", "Category", "Site", "Shift", "Morning Reading", "Evening Reading", "Consumption", "Notes"],
        rows,
    )


@login_required
def export_bills_csv_view(request: HttpRequest) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    queryset = Bill.objects.select_related("generated_by", "worker", "category", "site").order_by("-date_generated")
    rows = [
        [
            bill.bill_number,
            bill.scope,
            bill.status,
            bill.worker_name,
            bill.category_name,
            bill.site_name,
            bill.period_start,
            bill.period_end,
            bill.total_consumption,
            bill.total_amount,
            bill.prepared_by_name,
        ]
        for bill in queryset
    ]
    return csv_response(
        "bills.csv",
        ["Bill Number", "Scope", "Status", "Worker", "Category", "Site", "Period Start", "Period End", "Consumption", "Amount", "Prepared By"],
        rows,
    )
