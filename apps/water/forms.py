from __future__ import annotations

from django import forms
from django.utils import timezone

from apps.core.choices import EntryShift
from apps.core.permissions import can_user_access_site

from .models import WaterUsageEntry


class WaterUsageEntryForm(forms.ModelForm):
    class Meta:
        model = WaterUsageEntry
        fields = ["entry_date", "morning_reading", "evening_reading", "notes", "shift"]
        widgets = {
            "entry_date": forms.DateInput(attrs={"type": "date", "class": "form-control"}),
            "morning_reading": forms.NumberInput(attrs={"class": "form-control", "step": "0.001", "min": "0"}),
            "evening_reading": forms.NumberInput(attrs={"class": "form-control", "step": "0.001", "min": "0"}),
            "notes": forms.Textarea(attrs={"class": "form-control", "rows": 3}),
            "shift": forms.HiddenInput(),
        }

    def __init__(self, *args, **kwargs):
        self.user = kwargs.pop("user")
        self.site = kwargs.pop("site", None)
        super().__init__(*args, **kwargs)
        self.fields["entry_date"].initial = timezone.localdate()
        self.fields["shift"].initial = EntryShift.FULL_DAY

    def clean(self):
        cleaned_data = super().clean()
        if self.site and not can_user_access_site(self.user, self.site):
            raise forms.ValidationError("You are not authorized to record usage for this site.")
        return cleaned_data

    def save(self, commit=True):
        entry = super().save(commit=False)
        entry.worker = self.user
        entry.site = self.site
        entry.category = self.site.category
        if commit:
            entry.save()
        return entry
