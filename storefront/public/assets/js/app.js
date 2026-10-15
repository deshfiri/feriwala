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
                stock.className = 'stock ' + (inStock ? 'in' : 'out');
                stock.textContent = stock.getAttribute(inStock ? 'data-in' : 'data-out') || stock.textContent;
            }

            if (add) {
                add.disabled = !inStock;
            }
        };

        picker.addEventListener('change', sync);
        sync();
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
            document.querySelectorAll('[data-gallery]').forEach(function (other) {
                other.removeAttribute('aria-current');
            });
            link.setAttribute('aria-current', 'true');
        });
    });

    // A second click must not send an order twice.
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                button.disabled = true;
            });
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
                option.textContent = (area.name && (area.name[document.documentElement.lang] || area.name.en)) || area.id;
                select.appendChild(option);
            });

            select.disabled = list.length === 0;
        };

        var load = function (parent, select) {
            var url = '/api/areas' + (parent ? '?parent=' + encodeURIComponent(parent) : '');

            return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : { data: [] }; })
                .then(function (body) { fill(select, body.data || []); })
                .catch(function () { /* the address field still works */ });
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
