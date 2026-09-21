from __future__ import annotations

from datetime import date
from decimal import Decimal
from io import BytesIO

from django.core.files.base import ContentFile
from django.db import transaction
from django.db.models import Sum
from django.template.loader import render_to_string
from django.utils import timezone

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

from apps.audit.services import log_event
from apps.core.choices import BillStatus, BillingScope
from apps.water.models import WaterUsageEntry

from .models import Bill, BillLine, TariffTier


def _coerce_date(value):
    if isinstance(value, date):
        return value
    return date.fromisoformat(str(value))


def _select_entries(scope, *, worker=None, site=None, category=None, period_start=None, period_end=None):
    queryset = WaterUsageEntry.objects.select_related("worker", "category", "site", "site__category")
    if period_start and period_end:
        queryset = queryset.filter(entry_date__range=(period_start, period_end))
    if scope == BillingScope.INDIVIDUAL and worker:
        queryset = queryset.filter(worker=worker)
    elif scope == BillingScope.SITE and site:
        queryset = queryset.filter(site=site)
    elif scope == BillingScope.CATEGORY and category:
        queryset = queryset.filter(category=category)
    return queryset.order_by("entry_date", "created_at")


def _tier_breakdown(consumption: Decimal, tiers):
    remaining = consumption
    previous_max = Decimal("0.000")
    breakdown = []

    for tier in tiers:
        tier_max = tier.max_volume if tier.max_volume is not None else consumption
        tier_cap = tier_max - previous_max if tier_max > previous_max else Decimal("0.000")
        if tier_cap <= 0:
            previous_max = tier_max
            continue

        volume = min(remaining, tier_cap)
        if volume <= 0:
            previous_max = tier_max
            continue

        amount = (volume * tier.rate_per_m3).quantize(Decimal("0.01"))
        if tier.fixed_charge and not breakdown:
            amount += tier.fixed_charge

        breakdown.append(
            {
                "tier_name": tier.name,
                "min_volume": tier.min_volume,
                "max_volume": tier.max_volume,
                "volume": volume.quantize(Decimal("0.001")),
                "unit_rate": tier.rate_per_m3,
                "amount": amount,
            }
        )
        remaining -= volume
        previous_max = tier_max
        if remaining <= 0:
            break

    if remaining > 0 and tiers:
        last_tier = tiers[-1]
        amount = (remaining * last_tier.rate_per_m3).quantize(Decimal("0.01"))
        breakdown.append(
            {
                "tier_name": f"{last_tier.name} (overflow)",
                "min_volume": last_tier.min_volume,
                "max_volume": None,
                "volume": remaining.quantize(Decimal("0.001")),
                "unit_rate": last_tier.rate_per_m3,
                "amount": amount,
            }
        )

    return breakdown


def build_pdf(bill: Bill) -> bytes:
    buffer = BytesIO()
    document = SimpleDocTemplate(
        buffer,
        pagesize=A4,
        leftMargin=18 * mm,
        rightMargin=18 * mm,
        topMargin=16 * mm,
        bottomMargin=16 * mm,
    )
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle(name="BillTitle", parent=styles["Title"], fontSize=18, leading=22, textColor=colors.HexColor("#0f172a")))
    styles.add(ParagraphStyle(name="Small", parent=styles["BodyText"], fontSize=9, leading=11))

    story = []
    story.append(Paragraph("NOLELO Clean Water Supply Management System", styles["BillTitle"]))
    story.append(Spacer(1, 6 * mm))
    story.append(Paragraph(f"<b>Bill Number:</b> {bill.bill_number}", styles["BodyText"]))
    story.append(Paragraph(f"<b>Date Generated:</b> {bill.date_generated:%Y-%m-%d %H:%M}", styles["BodyText"]))
    story.append(Paragraph(f"<b>Billing Period:</b> {bill.period_start} to {bill.period_end}", styles["BodyText"]))
    story.append(Paragraph(f"<b>Prepared By:</b> {bill.prepared_by_name}", styles["BodyText"]))
    story.append(Spacer(1, 5 * mm))

    summary_rows = [
        ["Worker", bill.worker_name or "-"],
        ["Category", bill.category_name or "-"],
        ["Site", bill.site_name or "-"],
        ["Scope", bill.get_scope_display()],
        ["Status", bill.get_status_display()],
        ["Total Consumption", f"{bill.total_consumption} m3"],
        ["Total Amount", f"TZS {bill.total_amount:,.2f}"],
    ]
    story.append(
        Table(
            summary_rows,
            colWidths=[45 * mm, 110 * mm],
            style=TableStyle(
                [
                    ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#e2e8f0")),
                    ("TEXTCOLOR", (0, 0), (-1, -1), colors.HexColor("#0f172a")),
                    ("BOX", (0, 0), (-1, -1), 0.5, colors.HexColor("#94a3b8")),
                    ("INNERGRID", (0, 0), (-1, -1), 0.25, colors.HexColor("#cbd5e1")),
                    ("FONTNAME", (0, 0), (0, -1), "Helvetica-Bold"),
                    ("VALIGN", (0, 0), (-1, -1), "TOP"),
                    ("PADDING", (0, 0), (-1, -1), 6),
                ]
            ),
        )
    )
    story.append(Spacer(1, 6 * mm))

    line_data = [["#", "Tariff", "Volume (m3)", "Rate", "Amount"]]
    for line in bill.lines.all():
        line_data.append(
            [
                str(line.position),
                line.tariff_name,
                f"{line.volume}",
                f"{line.unit_rate:,.2f}",
                f"{line.amount:,.2f}",
            ]
        )
    story.append(
        Table(
            line_data,
            colWidths=[10 * mm, 70 * mm, 25 * mm, 25 * mm, 30 * mm],
            style=TableStyle(
                [
                    ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#0f172a")),
                    ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
                    ("GRID", (0, 0), (-1, -1), 0.25, colors.HexColor("#cbd5e1")),
                    ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
                    ("FONTSIZE", (0, 0), (-1, -1), 8.5),
                    ("LEADING", (0, 0), (-1, -1), 10),
                    ("PADDING", (0, 0), (-1, -1), 5),
                ]
            ),
        )
    )
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph(f"<b>Notes:</b> {bill.notes or 'No additional notes.'}", styles["Small"]))
    story.append(Spacer(1, 8 * mm))
    story.append(Paragraph("This bill was generated automatically by NOLELO and should be reviewed against meter records where required.", styles["Small"]))

    document.build(story)
    return buffer.getvalue()


@transaction.atomic
def generate_bill(
    *,
    prepared_by,
    scope: str,
    period_start,
    period_end,
    worker=None,
    site=None,
    category=None,
    notes: str = "",
    reopen_bill: Bill | None = None,
) -> Bill:
    period_start = _coerce_date(period_start)
    period_end = _coerce_date(period_end)

    entries = _select_entries(scope, worker=worker, site=site, category=category, period_start=period_start, period_end=period_end)
    total_consumption = entries.aggregate(total=Sum("consumption_m3"))["total"] or Decimal("0.000")
    active_tiers = list(TariffTier.objects.active().order_by("min_volume", "order", "id"))
    if not active_tiers:
        raise ValueError("No active tariff tiers are configured.")

    breakdown = _tier_breakdown(Decimal(total_consumption), active_tiers)
    total_amount = sum((row["amount"] for row in breakdown), Decimal("0.00")).quantize(Decimal("0.01"))

    bill = Bill.objects.create(
        bill_number=Bill.generate_bill_number(),
        scope=scope,
        status=BillStatus.ISSUED,
        generated_by=prepared_by,
        worker=worker,
        category=category,
        site=site,
        period_start=period_start,
        period_end=period_end,
        date_generated=timezone.now(),
        total_consumption=Decimal(total_consumption).quantize(Decimal("0.001")),
        total_amount=total_amount,
        worker_name=getattr(worker, "display_name", "") if worker else "",
        category_name=getattr(category, "name", "") if category else "",
        site_name=getattr(site, "display_label", "") if site else "",
        prepared_by_name=prepared_by.display_name,
        notes=notes,
        regenerated_from=reopen_bill,
    )

    for position, row in enumerate(breakdown, start=1):
        BillLine.objects.create(
            bill=bill,
            position=position,
            tariff_name=row["tier_name"],
            min_volume=row["min_volume"],
            max_volume=row["max_volume"],
            volume=row["volume"],
            unit_rate=row["unit_rate"],
            amount=row["amount"],
        )

    pdf_bytes = build_pdf(bill)
    bill.pdf_file.save(f"{bill.bill_number}.pdf", ContentFile(pdf_bytes), save=False)
    bill.save(update_fields=["pdf_file", "updated_at"])

    if reopen_bill is not None:
        reopen_bill.reopen()

    log_event(
        actor=prepared_by,
        action="bill_generated",
        object_instance=bill,
        metadata={
            "scope": scope,
            "period_start": str(period_start),
            "period_end": str(period_end),
            "total_consumption": str(bill.total_consumption),
            "total_amount": str(bill.total_amount),
        },
    )
    return bill


@transaction.atomic
def reopen_bill(*, actor, bill: Bill) -> Bill:
    bill.reopen()
    log_event(actor=actor, action="bill_reopened", object_instance=bill)
    return bill


@transaction.atomic
def regenerate_bill(*, actor, bill: Bill) -> Bill:
    new_bill = generate_bill(
        prepared_by=actor,
        scope=bill.scope,
        period_start=bill.period_start,
        period_end=bill.period_end,
        worker=bill.worker,
        site=bill.site,
        category=bill.category,
        notes=bill.notes,
        reopen_bill=bill,
    )
    log_event(actor=actor, action="bill_regenerated", object_instance=new_bill, metadata={"source_bill": bill.bill_number})
    return new_bill
