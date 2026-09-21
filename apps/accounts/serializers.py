from rest_framework import serializers

from apps.core.choices import UserRole

from .models import User


class UserSerializer(serializers.ModelSerializer):
    assigned_site_ids = serializers.PrimaryKeyRelatedField(
        source="assigned_sites",
        many=True,
        read_only=True,
    )
    assigned_category_ids = serializers.PrimaryKeyRelatedField(
        source="assigned_categories",
        many=True,
        read_only=True,
    )

    class Meta:
        model = User
        fields = [
            "id",
            "email",
            "phone",
            "first_name",
            "last_name",
            "nickname",
            "profile_picture",
            "role",
            "is_active",
            "all_sites_access",
            "must_change_pin",
            "assigned_site_ids",
            "assigned_category_ids",
            "date_joined",
            "last_login",
        ]


class ProfileSerializer(serializers.ModelSerializer):
    class Meta:
        model = User
        fields = ["email", "phone", "nickname", "profile_picture"]


class UserCreateSerializer(serializers.ModelSerializer):
    secret = serializers.CharField(write_only=True)

    class Meta:
        model = User
        fields = [
            "email",
            "phone",
            "first_name",
            "last_name",
            "nickname",
            "profile_picture",
            "role",
            "all_sites_access",
            "secret",
        ]

    def validate(self, attrs):
        secret = attrs["secret"]
        role = attrs.get("role", UserRole.WORKER)
        if role == UserRole.WORKER and (not secret.isdigit() or len(secret) != 4):
            raise serializers.ValidationError("Worker PINs must be exactly four digits.")
        if role == UserRole.SUPER_ADMIN and len(secret) < 8:
            raise serializers.ValidationError("Super Admin passwords must be at least 8 characters long.")
        return attrs

    def create(self, validated_data):
        secret = validated_data.pop("secret")
        role = validated_data.get("role", UserRole.WORKER)
        user = User(**validated_data)
        user.set_password(secret)
        user.must_change_pin = role == UserRole.WORKER
        if role == UserRole.SUPER_ADMIN:
            user.is_staff = True
            user.is_superuser = True
            user.must_change_pin = False
        user.save()
        return user
