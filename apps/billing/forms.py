from __future__ import annotations

from django import forms
from django.core.exceptions import ValidationError

from apps.accounts.models import User
from apps.core.choices import BillingScope
from apps.operations.models import Category, OperationalSite

from .models import TariffTier


class BillGenerationForm(forms.Form):
    scope = forms.ChoiceField(choices=BillingScope.choices, widget=forms.Select(attrs={"class": "form-select"}))
    worker = forms.ModelChoiceField(queryset=User.objects.none(), required=False, widget=forms.Select(attrs={"class": "form-select"}))
    category = forms.ModelChoiceField(queryset=Category.objects.all(), required=False, widget=forms.Select(attrs={"class": "form-select"}))
    site = forms.ModelChoiceField(queryset=OperationalSite.objects.select_related("category"), required=False, widget=forms.Select(attrs={"class": "form-select"}))
    period_start = forms.DateField(widget=forms.DateInput(attrs={"type": "date", "class": "form-control"}))
    period_end = forms.DateField(widget=forms.DateInput(attrs={"type": "date", "class": "form-control"}))
    notes = forms.CharField(required=False, widget=forms.Textarea(attrs={"class": "form-control", "rows": 3}))

    def __init__(self, *args, **kwargs):
        user_queryset = kwargs.pop("user_queryset", None)
        super().__init__(*args, **kwargs)
        if user_queryset is not None:
            self.fields["worker"].queryset = user_queryset
        else:
            self.fields["worker"].queryset = User.objects.none()

    def clean(self):
        cleaned_data = super().clean()
        scope = cleaned_data.get("scope")
        worker = cleaned_data.get("worker")
        category = cleaned_data.get("category")
        site = cleaned_data.get("site")
        period_start = cleaned_data.get("period_start")
        period_end = cleaned_data.get("period_end")

        if period_start and period_end and period_end < period_start:
            raise ValidationError("The billing period end date must be on or after the start date.")

        if scope == BillingScope.INDIVIDUAL and not worker:
            raise ValidationError("Select a worker for an individual bill.")
        if scope == BillingScope.SITE and not site:
            raise ValidationError("Select a site for a site bill.")
        if scope == BillingScope.CATEGORY and not category:
            raise ValidationError("Select a category for a category bill.")
        return cleaned_data
