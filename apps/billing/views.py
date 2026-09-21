from __future__ import annotations

from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.core.files.base import ContentFile
from django.http import FileResponse, Http404, HttpRequest, HttpResponse
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.choices import BillingScope, UserRole
from apps.core.permissions import StaffRequiredMixin
from apps.operations.models import Category, OperationalSite

from .filters import BillFilter, TariffFilter
from .forms import BillGenerationForm
from .models import Bill, TariffTier
from .services import generate_bill, regenerate_bill, reopen_bill


def _bill_queryset(request):
    return Bill.objects.select_related("generated_by", "worker", "category", "site").prefetch_related("lines")


@login_required
def bill_list_view(request: HttpRequest) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    queryset = _bill_queryset(request)
    filterset = BillFilter(request.GET, queryset=queryset, request=request)
    paginator = Paginator(filterset.qs, 15)
    page_obj = paginator.get_page(request.GET.get("page"))
    return render(request, "billing/bill_list.html", {"filterset": filterset, "page_obj": page_obj, "bills": page_obj.object_list, "title": "Bills"})


@login_required
def bill_detail_view(request: HttpRequest, pk: int) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    bill = get_object_or_404(_bill_queryset(request), pk=pk)
    return render(request, "billing/bill_detail.html", {"bill": bill, "title": f"Bill {bill.bill_number}"})


@login_required
def bill_create_view(request: HttpRequest) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    worker_queryset = request.user.__class__.objects.filter(role=UserRole.WORKER, is_active=True).order_by("first_name", "last_name")
    form = BillGenerationForm(request.POST or None, user_queryset=worker_queryset)
    if request.method == "POST" and form.is_valid():
        bill = generate_bill(
            prepared_by=request.user,
            scope=form.cleaned_data["scope"],
            worker=form.cleaned_data.get("worker"),
            category=form.cleaned_data.get("category"),
            site=form.cleaned_data.get("site"),
            period_start=form.cleaned_data["period_start"],
            period_end=form.cleaned_data["period_end"],
            notes=form.cleaned_data.get("notes", ""),
        )
        messages.success(request, f"Bill {bill.bill_number} generated successfully.")
        return redirect("billing:bill-detail", pk=bill.pk)
    return render(request, "billing/bill_form.html", {"form": form, "title": "Generate Bill"})


@login_required
def bill_pdf_view(request: HttpRequest, pk: int) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    bill = get_object_or_404(_bill_queryset(request), pk=pk)
    if not bill.pdf_file:
        from .services import build_pdf

        bill.pdf_file.save(f"{bill.bill_number}.pdf", ContentFile(build_pdf(bill)), save=True)
    return FileResponse(bill.pdf_file.open("rb"), as_attachment=True, filename=f"{bill.bill_number}.pdf")


@login_required
def bill_reopen_view(request: HttpRequest, pk: int) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    bill = get_object_or_404(_bill_queryset(request), pk=pk)
    if request.method == "POST":
        reopen_bill(actor=request.user, bill=bill)
        messages.success(request, f"Bill {bill.bill_number} reopened.")
        return redirect("billing:bill-detail", pk=bill.pk)
    return render(request, "billing/bill_confirm_reopen.html", {"bill": bill, "title": "Reopen Bill"})


@login_required
def bill_regenerate_view(request: HttpRequest, pk: int) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    bill = get_object_or_404(_bill_queryset(request), pk=pk)
    if request.method == "POST":
        new_bill = regenerate_bill(actor=request.user, bill=bill)
        messages.success(request, f"Bill {new_bill.bill_number} regenerated.")
        return redirect("billing:bill-detail", pk=new_bill.pk)
    return render(request, "billing/bill_confirm_regenerate.html", {"bill": bill, "title": "Regenerate Bill"})
