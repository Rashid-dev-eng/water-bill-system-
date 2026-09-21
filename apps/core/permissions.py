from django.contrib.auth.mixins import AccessMixin, UserPassesTestMixin

from apps.core.choices import UserRole


class StaffRequiredMixin(AccessMixin):
    def dispatch(self, request, *args, **kwargs):
        if not request.user.is_authenticated:
            return self.handle_no_permission()
        if not request.user.is_staff and not request.user.is_superuser:
            return self.handle_no_permission()
        return super().dispatch(request, *args, **kwargs)


class WorkerRequiredMixin(AccessMixin):
    def dispatch(self, request, *args, **kwargs):
        if not request.user.is_authenticated:
            return self.handle_no_permission()
        if request.user.role != UserRole.WORKER:
            return self.handle_no_permission()
        return super().dispatch(request, *args, **kwargs)


class RoleRequiredMixin(UserPassesTestMixin):
    allowed_roles = ()

    def test_func(self):
        user = self.request.user
        return user.is_authenticated and user.role in self.allowed_roles


def can_user_access_site(user, site) -> bool:
    if not user.is_authenticated:
        return False
    if user.is_superuser or user.role == UserRole.SUPER_ADMIN:
        return True
    if getattr(user, "all_sites_access", False):
        return True
    if site is None:
        return False
    if user.assigned_sites.filter(pk=site.pk).exists():
        return True
    if user.assigned_categories.filter(pk=site.category_id).exists():
        return True
    return False


def can_user_access_category(user, category) -> bool:
    if not user.is_authenticated:
        return False
    if user.is_superuser or user.role == UserRole.SUPER_ADMIN:
        return True
    if getattr(user, "all_sites_access", False):
        return True
    if category is None:
        return False
    if user.assigned_categories.filter(pk=category.pk).exists():
        return True
    if user.assigned_sites.filter(category_id=category.pk).exists():
        return True
    return False
