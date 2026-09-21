from django.urls import path

from . import views


app_name = "notifications"

urlpatterns = [
    path("", views.notification_list_view, name="notification-list"),
    path("<int:pk>/read/", views.notification_read_view, name="notification-read"),
]
