from rest_framework.views import exception_handler


def custom_exception_handler(exc, context):
    response = exception_handler(exc, context)
    if response is not None:
        detail = response.data
        message = "Request failed."
        if isinstance(detail, dict) and "detail" in detail:
            message = detail["detail"]
        response.data = {
            "success": False,
            "message": message,
            "errors": detail,
        }
    return response
