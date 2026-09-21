from django.urls import path

from . import views


app_name = "reports"

urlpatterns = [
    path("", views.reports_home_view, name="report-home"),
    path("entries.csv", views.export_entries_csv_view, name="entries-csv"),
    path("bills.csv", views.export_bills_csv_view, name="bills-csv"),
]
