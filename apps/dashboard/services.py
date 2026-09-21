from __future__ import annotations

from datetime import timedelta
from decimal import Decimal

from django.db.models import Count, DecimalField, Sum, Value
from django.db.models.functions import Coalesce
from django.utils import timezone

from apps.audit.models import AuditEvent
from apps.billing.models import Bill
from apps.water.forms import WaterUsageEntryForm
from apps.water.models import WaterUsageEntry


def _sum(queryset):
    return queryset.aggregate(total=Coalesce(Sum("consumption_m3"), Value(Decimal("0.000"), output_field=DecimalField(max_digits=12, decimal_places=3))))["total"]


def _series(queryset, start_date, end_date):
    totals = {
        row["entry_date"]: row["total"]
        for row in queryset.filter(entry_date__range=(start_date, end_date)).values("entry_date").annotate(total=Coalesce(Sum("consumption_m3"), Value(Decimal("0.000"), output_field=DecimalField(max_digits=12, decimal_places=3))))
    }
    labels = []
    values = []
    current = start_date
    while current <= end_date:
        labels.append(current.strftime("%d %b"))
        values.append(float(totals.get(current, Decimal("0.000"))))
        current += timedelta(days=1)
    return {"labels": labels, "values": values}


def build_admin_dashboard_context():
    today = timezone.localdate()
    week_start = today - timedelta(days=6)
    month_start = today.replace(day=1)
    year_start = today.replace(month=1, day=1)

    entries = WaterUsageEntry.objects.select_related("worker", "category", "site", "site__category")

    today_qs = entries.filter(entry_date=today)
    week_qs = entries.filter(entry_date__gte=week_start)
    month_qs = entries.filter(entry_date__gte=month_start)
    year_qs = entries.filter(entry_date__gte=year_start)

    top_workers = (
        entries.values("worker__id", "worker__first_name", "worker__last_name")
        .annotate(total_consumption=Coalesce(Sum("consumption_m3"), Value(Decimal("0.000"), output_field=DecimalField(max_digits=12, decimal_places=3))), entry_count=Count("id"))
        .order_by("-total_consumption")[:5]
    )
    top_sites = (
        entries.values("site__id", "site__name", "category__name")
        .annotate(total_consumption=Coalesce(Sum("consumption_m3"), Value(Decimal("0.000"), output_field=DecimalField(max_digits=12, decimal_places=3))), entry_count=Count("id"))
        .order_by("-total_consumption")[:5]
    )
    recent_audits = AuditEvent.objects.select_related("actor").order_by("-created_at")[:8]
    recent_bills = Bill.objects.select_related("generated_by", "worker", "category", "site").order_by("-date_generated")[:8]

    return {
        "today_usage": _sum(today_qs),
        "week_usage": _sum(week_qs),
        "month_usage": _sum(month_qs),
        "year_usage": _sum(year_qs),
        "today_count": today_qs.count(),
        "active_workers": entries.values("worker").distinct().count(),
        "active_sites": entries.values("site").distinct().count(),
        "active_categories": entries.values("category").distinct().count(),
        "today_series": _series(entries, week_start, today),
        "month_series": _series(entries, month_start, today),
        "top_workers": top_workers,
        "top_sites": top_sites,
        "recent_audits": recent_audits,
        "recent_bills": recent_bills,
    }


def build_worker_dashboard_context(user):
    today = timezone.localdate()
    month_start = today.replace(day=1)
    entries = WaterUsageEntry.objects.filter(worker=user).select_related("category", "site", "site__category")
    today_entries = entries.filter(entry_date=today)
    assigned_sites = user.visible_sites().select_related("category")
    quick_forms = [
        {
            "site": site,
            "form": WaterUsageEntryForm(user=user, site=site),
        }
        for site in assigned_sites
    ]

    return {
        "welcome_name": user.welcome_name,
        "today_entries": today_entries,
        "recent_entries": entries.order_by("-entry_date", "-created_at")[:8],
        "total_entries": entries.count(),
        "month_usage": entries.filter(entry_date__gte=month_start).aggregate(total=Coalesce(Sum("consumption_m3"), Value(Decimal("0.000"), output_field=DecimalField(max_digits=12, decimal_places=3))))["total"],
        "assigned_sites": assigned_sites,
        "quick_forms": quick_forms,
        "assigned_categories": user.visible_categories().select_related() if hasattr(user.visible_categories(), "select_related") else user.visible_categories(),
    }
