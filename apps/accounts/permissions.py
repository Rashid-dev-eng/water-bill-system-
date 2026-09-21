from rest_framework.permissions import BasePermission


class IsSelfOrStaff(BasePermission):
    def has_object_permission(self, request, view, obj):
        return request.user.is_staff or request.user.is_superuser or obj.pk == request.user.pk


class IsSuperAdmin(BasePermission):
    def has_permission(self, request, view):
        return bool(request.user and request.user.is_authenticated and request.user.is_superuser)


class IsWorker(BasePermission):
    def has_permission(self, request, view):
        return bool(request.user and request.user.is_authenticated and request.user.role == "worker")
