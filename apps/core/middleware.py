from threading import local


_request_state = local()


def set_current_request(request):
    _request_state.request = request


def get_current_request():
    return getattr(_request_state, "request", None)


class CurrentRequestMiddleware:
    """Expose the active request to service-layer code and audit logging."""

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        set_current_request(request)
        try:
            response = self.get_response(request)
        finally:
            set_current_request(None)
        return response
