from __future__ import annotations

import csv
from io import StringIO

from django.http import HttpResponse


def csv_response(filename: str, headers: list[str], rows):
    response = HttpResponse(content_type="text/csv")
    response["Content-Disposition"] = f'attachment; filename="{filename}"'
    writer = csv.writer(response)
    writer.writerow(headers)
    for row in rows:
        writer.writerow(row)
    return response
