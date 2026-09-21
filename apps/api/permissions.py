from rest_framework.permissions import SAFE_METHODS, BasePermission

from apps.core.choices import UserRole


class IsStaffOrReadOnly(BasePermission):
    def has_permission(self, request, view):
        if request.method in SAFE_METHODS:
            return request.user and request.user.is_authenticated
        return bool(request.user and request.user.is_authenticated and (request.user.is_staff or request.user.is_superuser or request.user.role == UserRole.SUPER_ADMIN))


class IsOwnerOrStaff(BasePermission):
    def has_object_permission(self, request, view, obj):
        if request.user and request.user.is_staff:
            return True
        return getattr(obj, "pk", None) == getattr(request.user, "pk", None)


class IsSuperAdmin(BasePermission):
    def has_permission(self, request, view):
        return bool(request.user and request.user.is_authenticated and (request.user.is_superuser or request.user.role == UserRole.SUPER_ADMIN))
