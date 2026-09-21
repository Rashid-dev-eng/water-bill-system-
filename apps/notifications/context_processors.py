def notification_context(request):
    if request.user.is_authenticated:
        unread_count = request.user.notifications.filter(is_read=False).count()
        recent_notifications = request.user.notifications.all()[:5]
    else:
        unread_count = 0
        recent_notifications = []
    return {
        "notification_unread_count": unread_count,
        "recent_notifications": recent_notifications,
    }
