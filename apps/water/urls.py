from django.urls import path

from . import views


app_name = "water"

urlpatterns = [
    path("history/", views.entry_list_view, name="history"),
    path("entries/create/<int:site_id>/", views.create_entry_view, name="entry-create"),
    path("entries/<int:pk>/edit/", views.entry_edit_view, name="entry-edit"),
    path("entries/<int:pk>/delete/", views.entry_delete_view, name="entry-delete"),
]
