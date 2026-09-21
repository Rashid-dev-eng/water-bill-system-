from __future__ import annotations

from datetime import date, datetime, timedelta
from decimal import Decimal

from django.utils import timezone


def get_client_ip(request) -> str:
    x_forwarded_for = request.META.get("HTTP_X_FORWARDED_FOR")
    if x_forwarded_for:
        return x_forwarded_for.split(",")[0].strip()
    return request.META.get("REMOTE_ADDR", "") or ""


def month_bounds(year: int, month: int) -> tuple[date, date]:
    start = date(year, month, 1)
    if month == 12:
        end = date(year + 1, 1, 1) - timedelta(days=1)
    else:
        end = date(year, month + 1, 1) - timedelta(days=1)
    return start, end


def normalize_money(value) -> Decimal:
    if value is None:
        return Decimal("0.00")
    return Decimal(str(value)).quantize(Decimal("0.01"))


def now_local():
    return timezone.localtime(timezone.now())


def display_currency(amount, currency_symbol="TZS"):
    amount = normalize_money(amount)
    return f"{currency_symbol} {amount:,.2f}"


def daterange(start_date: date, end_date: date):
    current = start_date
    while current <= end_date:
        yield current
        current += timedelta(days=1)


def start_of_today():
    return timezone.localdate()
