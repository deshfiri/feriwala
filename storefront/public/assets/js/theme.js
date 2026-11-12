// The storefront is light only. Nothing a visitor or their system prefers can change that:
// the page is marked light, and a "dark" choice saved by an earlier version is forgotten.
(function () {
    document.documentElement.setAttribute('data-theme', 'light');

    try {
        window.localStorage.removeItem('theme');
    } catch (error) {
        // Storage blocked: nothing was saved, nothing to forget.
    }
})();