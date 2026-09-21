# NOLELO Clean Water Supply Management System

NOLELO is a production-oriented Django platform for water utility operations, usage logging, billing, reporting, auditing, and staff management.

## Stack

- Django 5
- Django REST Framework
- SQLite for development
- PostgreSQL-ready settings
- JWT authentication for APIs
- Session authentication for the web app
- Celery-ready async task configuration
- WhiteNoise static handling
- Bootstrap 5 frontend
- ReportLab PDF generation for bills

## Local Setup

```bash
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
python manage.py migrate
python manage.py createsuperuser
python manage.py runserver
```

## Project Layout

- `config/` project settings, URLs, ASGI, WSGI, Celery
- `apps/accounts/` custom users, auth, profile, role and site assignment
- `apps/operations/` operational categories and sites
- `apps/water/` usage entry capture and history
- `apps/billing/` tariff tiers, bill generation, PDF export
- `apps/dashboard/` admin and worker dashboards
- `apps/notifications/` system notifications
- `apps/audit/` audit trail and request logging
- `apps/reports/` CSV and export endpoints
- `apps/api/` REST API version 1

## Authentication

- Workers authenticate with email or phone plus PIN.
- Super admins can use the Django admin site and full Django permissions.
- PINs are stored using Django's password hashing infrastructure.

## Deployment

Use the supplied `Dockerfile`, `docker-compose.yml`, and `nginx/default.conf` for containerized deployment.

## Environment Variables

See `.env.example` for the full list of supported values.
