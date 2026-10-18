// Loaded in the head, before first paint, so a saved light/dark choice never flashes the other.
// With no saved choice nothing is set and the stylesheet follows the visitor's system.
(function () {
    try {
        var saved = window.localStorage.getItem('theme');

        if (saved === 'light' || saved === 'dark') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    } catch (error) {
        // Storage blocked: the system preference still applies.
    }
})();
