from django.contrib.auth.signals import user_logged_in, user_logged_out
from django.dispatch import receiver

from apps.audit.services import log_event


@receiver(user_logged_in)
def handle_user_logged_in(sender, request, user, **kwargs):
    log_event(actor=user, action="login", request=request, object_instance=user)


@receiver(user_logged_out)
def handle_user_logged_out(sender, request, user, **kwargs):
    if user is not None:
        log_event(actor=user, action="logout", request=request, object_instance=user)
