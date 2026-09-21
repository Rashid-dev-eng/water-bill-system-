from django.urls import path

from . import views


app_name = "billing"

urlpatterns = [
    path("", views.bill_list_view, name="bill-list"),
    path("generate/", views.bill_create_view, name="bill-generate"),
    path("<int:pk>/", views.bill_detail_view, name="bill-detail"),
    path("<int:pk>/pdf/", views.bill_pdf_view, name="bill-pdf"),
    path("<int:pk>/reopen/", views.bill_reopen_view, name="bill-reopen"),
    path("<int:pk>/regenerate/", views.bill_regenerate_view, name="bill-regenerate"),
]
