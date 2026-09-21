(() => {
  const themeToggleSelector = "[data-theme-toggle]";
  const themeKey = "nolelo-theme";
  const root = document.documentElement;

  const readCookie = (name) => {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(";").shift();
    return null;
  };

  const applyTheme = (theme) => {
    root.setAttribute("data-theme", theme);
    localStorage.setItem(themeKey, theme);
    document.cookie = `nolelo_theme=${theme}; path=/; max-age=31536000`;
  };

  const initialTheme = localStorage.getItem(themeKey) || readCookie("nolelo_theme") || root.getAttribute("data-theme") || "light";
  applyTheme(initialTheme);

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(themeToggleSelector).forEach((button) => {
      button.addEventListener("click", () => {
        const current = root.getAttribute("data-theme") || "light";
        applyTheme(current === "dark" ? "light" : "dark");
      });
    });

    document.querySelectorAll("[data-confirm]").forEach((button) => {
      button.addEventListener("click", (event) => {
        const message = button.getAttribute("data-confirm");
        if (!window.confirm(message || "Are you sure?")) {
          event.preventDefault();
        }
      });
    });

    document.querySelectorAll(".alert").forEach((alertEl) => {
      setTimeout(() => {
        const alert = bootstrap.Alert.getOrCreateInstance(alertEl);
        alert.close();
      }, 4200);
    });
  });
})();
