from django.urls import path

from . import views


app_name = "dashboard"

urlpatterns = [
    path("", views.home, name="home"),
    path("dashboard/admin/", views.admin_dashboard_view, name="admin"),
    path("dashboard/worker/", views.worker_dashboard_view, name="worker"),
]
