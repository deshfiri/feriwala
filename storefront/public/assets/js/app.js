/*
 * Progressive enhancement only. Every page works without this file: variants are
 * a <select>, the delivery area is optional, and every form posts normally.
 * Nothing here computes a price — it only shows what the server already put in
 * the page.
 */
(function () {
    'use strict';

    // Product options: show the chosen variant's price and availability.
    var picker = document.querySelector('[data-variants]');

    if (picker) {
        var price = document.getElementById('price');
        var stock = document.getElementById('stock');
        var add = document.getElementById('add');
        var qty = document.getElementById('quantity');

        var sync = function () {
            var option = picker.options[picker.selectedIndex];
            var inStock = option.getAttribute('data-stock') === '1';
            var compare = option.getAttribute('data-compare');

            if (price) {
                price.textContent = '';
                var strong = document.createElement('strong');
                strong.textContent = option.getAttribute('data-price') || '';
                price.appendChild(strong);

                if (compare) {
                    var old = document.createElement('s');
                    old.className = 'muted';
                    old.textContent = ' ' + compare;
                    price.appendChild(old);
                }
            }

            if (stock) {
                var left = parseInt(option.getAttribute('data-qty') || '0', 10);
                var threshold = parseInt(
                    stock.getAttribute('data-threshold') || '5',
                    10,
                );
                var low = inStock && left > 0 && left <= threshold;

                stock.className =
                    'stock ' + (!inStock ? 'out' : low ? 'low' : 'in');
                stock.textContent = !inStock
                    ? stock.getAttribute('data-out') || stock.textContent
                    : low
                      ? (stock.getAttribute('data-low') || '').replace(
                            ':n',
                            String(left),
                        )
                      : stock.getAttribute('data-in') || stock.textContent;
            }

            if (qty) {
                var cap = parseInt(qty.getAttribute('data-max') || '0', 10);
                var available = parseInt(
                    option.getAttribute('data-qty') || '0',
                    10,
                );
                var ceiling = cap > 0 ? Math.min(cap, available) : available;

                if (inStock && ceiling > 0) {
                    qty.max = String(ceiling);
                    if (parseInt(qty.value || '1', 10) > ceiling) {
                        qty.value = String(ceiling);
                    }
                }
            }

            if (add) {
                add.disabled = !inStock;
            }
        };

        picker.addEventListener('change', sync);
        sync();
    }

    // Quantity − / + (the buttons are hidden until this runs, so without script the field just works).
    document.querySelectorAll('[data-stepper]').forEach(function (stepper) {
        var input = stepper.querySelector('input');

        stepper.querySelectorAll('[data-step]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () {
                var min = parseInt(input.min || '1', 10);
                var max = parseInt(input.max || '0', 10);
                var next =
                    (parseInt(input.value || String(min), 10) || min) +
                    parseInt(button.getAttribute('data-step'), 10);

                next = Math.max(min, next);
                input.value = String(max > 0 ? Math.min(max, next) : next);
            });
        });
    });

    // A picture that cannot be loaded is replaced by its fallback — never a broken-image icon.
    var swapFallback = function (image) {
        var id = image.getAttribute('data-fallback');
        var fallback = id
            ? document.getElementById(id) ||
              image.parentNode.querySelector('.' + id)
            : null;

        if (!fallback) {
            return;
        }

        image.hidden = true;
        fallback.hidden = false;
    };

    document.querySelectorAll('img[data-fallback]').forEach(function (image) {
        image.addEventListener('error', function () {
            swapFallback(image);
        });

        // It may have failed before this script ran.
        if (image.complete && image.naturalWidth === 0) {
            swapFallback(image);
        }
    });

    // Light / dark switch. The page follows the system until the visitor chooses;
    // the choice is remembered in this browser (theme.js applies it before paint).
    var themeToggle = document.querySelector('[data-theme-toggle]');

    if (themeToggle) {
        var root = document.documentElement;
        var systemDark = window.matchMedia('(prefers-color-scheme: dark)');
        var isDark = function () {
            var set = root.getAttribute('data-theme');

            return set ? set === 'dark' : systemDark.matches;
        };
        var syncLabel = function () {
            themeToggle.setAttribute(
                'aria-label',
                themeToggle.getAttribute(
                    isDark() ? 'data-label-light' : 'data-label-dark',
                ),
            );
        };

        themeToggle.hidden = false;
        syncLabel();

        themeToggle.addEventListener('click', function () {
            var next = isDark() ? 'light' : 'dark';

            root.setAttribute('data-theme', next);

            try {
                window.localStorage.setItem('theme', next);
            } catch (error) {
                // Storage blocked: the choice lasts for this page view only.
            }

            syncLabel();
        });

        systemDark.addEventListener('change', syncLabel);
    }

    // Gallery thumbnails swap the large image.
    var hero = document.getElementById('hero-image');

    document.querySelectorAll('[data-gallery]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!hero) {
                return;
            }

            event.preventDefault();
            hero.src = link.getAttribute('data-gallery');
            hero.alt = link.getAttribute('data-alt') || '';
            document
                .querySelectorAll('[data-gallery]')
                .forEach(function (other) {
                    other.removeAttribute('aria-current');
                });
            link.setAttribute('aria-current', 'true');
        });
    });

    // A second click must not send an order twice.
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(
                function (button) {
                    button.setAttribute('aria-busy', 'true');
                    // Disabled on the next tick: disabling inside the submit handler can cancel the submit in some browsers.
                    window.setTimeout(function () {
                        button.disabled = true;
                    }, 0);
                },
            );
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Delivery area: division → district → upazila, each fetched from our own
    // endpoint (which holds the credentials). Optional: without it the customer
    // just types the address.
    var areas = document.querySelector('[data-areas]');

    if (areas && window.fetch) {
        var selects = areas.querySelectorAll('select');
        var hidden = document.getElementById('area_id');
        var district = document.getElementById('district');
        var city = document.getElementById('city');

        var fill = function (select, list) {
            while (select.options.length > 1) {
                select.remove(1);
            }

            list.forEach(function (area) {
                var option = document.createElement('option');
                option.value = area.id;
                option.textContent =
                    (area.name &&
                        (area.name[document.documentElement.lang] ||
                            area.name.en)) ||
                    area.id;
                select.appendChild(option);
            });

            select.disabled = list.length === 0;
        };

        var load = function (parent, select) {
            var url =
                '/api/areas' +
                (parent ? '?parent=' + encodeURIComponent(parent) : '');

            return fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.ok ? response.json() : { data: [] };
                })
                .then(function (body) {
                    fill(select, body.data || []);
                })
                .catch(function () {
                    /* the address field still works */
                });
        };

        selects.forEach(function (select, level) {
            select.addEventListener('change', function () {
                for (var i = level + 1; i < selects.length; i++) {
                    fill(selects[i], []);
                }

                var chosen = select.value;
                hidden.value = chosen;

                var label = select.options[select.selectedIndex].textContent;

                if (chosen && level === 1 && district && !district.value) {
                    district.value = label;
                }

                if (chosen && level === 2 && city && !city.value) {
                    city.value = label;
                }

                if (chosen && level < selects.length - 1) {
                    load(chosen, selects[level + 1]);
                }
            });
        });

        load('', selects[0]).then(function () {
            if (selects[0].options.length > 1) {
                areas.hidden = false;
            }
        });
    }
})();
