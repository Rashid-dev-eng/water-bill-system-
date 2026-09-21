from __future__ import annotations

from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.http import Http404, HttpRequest, HttpResponse
from django.shortcuts import render

from .filters import AuditEventFilter
from .models import AuditEvent


@login_required
def audit_list_view(request: HttpRequest) -> HttpResponse:
    if not request.user.is_staff and not request.user.is_superuser:
        raise Http404()
    queryset = AuditEvent.objects.select_related("actor", "content_type")
    filterset = AuditEventFilter(request.GET, queryset=queryset)
    paginator = Paginator(filterset.qs, 20)
    page_obj = paginator.get_page(request.GET.get("page"))
    return render(request, "audit/audit_list.html", {"filterset": filterset, "page_obj": page_obj, "events": page_obj.object_list, "title": "Audit Logs"})
