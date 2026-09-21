def theme_context(request):
    theme = request.COOKIES.get("nolelo_theme", "light")
    return {
        "theme_preference": theme,
        "system_name": "NOLELO Clean Water Supply Management System",
    }
