from __future__ import annotations

from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.http import Http404, HttpRequest, HttpResponse
from django.shortcuts import get_object_or_404, redirect, render

from .models import Notification
from .services import mark_notification_read


@login_required
def notification_list_view(request: HttpRequest) -> HttpResponse:
    queryset = request.user.notifications.all()
    paginator = Paginator(queryset, 20)
    page_obj = paginator.get_page(request.GET.get("page"))
    return render(request, "notifications/notification_list.html", {"notifications": page_obj.object_list, "page_obj": page_obj, "title": "Notifications"})


@login_required
def notification_read_view(request: HttpRequest, pk: int) -> HttpResponse:
    notification = get_object_or_404(Notification, pk=pk, recipient=request.user)
    mark_notification_read(actor=request.user, notification=notification)
    if notification.action_url:
        return redirect(notification.action_url)
    return redirect("notifications:notification-list")
