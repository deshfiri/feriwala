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
    // Buttons may sit outside the form and name it with the `form` attribute.
    var submitButtons = function (form) {
        var own = Array.prototype.slice.call(
            form.querySelectorAll('button[type="submit"]'),
        );

        if (form.id) {
            document
                .querySelectorAll(
                    'button[type="submit"][form="' + form.id + '"]',
                )
                .forEach(function (button) {
                    if (own.indexOf(button) === -1) {
                        own.push(button);
                    }
                });
        }

        return own;
    };

    document.querySelectorAll('form[data-once]').forEach(function (form) {
        var labels = new WeakMap();

        form.addEventListener('submit', function (event) {
            // A submit that something else stopped (e.g. a field to fix) sends nothing.
            if (event.defaultPrevented) {
                return;
            }

            submitButtons(form).forEach(function (button) {
                var label = button.querySelector('.co-btn-label');

                button.setAttribute('aria-busy', 'true');

                if (label && button.getAttribute('data-busy')) {
                    labels.set(button, label.textContent);
                    label.textContent = button.getAttribute('data-busy');
                }

                // Disabled on the next tick: disabling inside the submit handler can cancel the submit in some browsers.
                window.setTimeout(function () {
                    button.disabled = true;
                }, 0);
            });
        });

        // Coming back with the browser's Back button must not leave the page locked.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }

            submitButtons(form).forEach(function (button) {
                var label = button.querySelector('.co-btn-label');

                button.removeAttribute('aria-busy');
                button.disabled = false;

                if (label && labels.has(button)) {
                    label.textContent = labels.get(button);
                }
            });
        });
    });

    // Checkout form: say what is wrong beside the field, before the round trip.
    // Display only: the server validates again and has the last word.
    var checkout = document.querySelector('form[data-checkout]');

    if (checkout) {
        var rules = {
            phone: function (value) {
                var digits = value.replace(/\D/g, '');

                return (
                    /^[0-9+\-\s()]+$/.test(value) &&
                    digits.length >= 10 &&
                    digits.length <= 15
                );
            },
            email: function (value) {
                return value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            },
            name: function (value) {
                return value.length >= 2;
            },
        };

        var message = function (field, text) {
            var id = 'err-' + field.id;
            var existing = document.getElementById(id);

            if (!text) {
                if (existing) {
                    existing.remove();
                }

                field.removeAttribute('aria-describedby');
                field.removeAttribute('aria-invalid');

                return;
            }

            if (!existing) {
                existing = document.createElement('p');
                existing.className = 'co-error';
                existing.id = id;
                existing.setAttribute('role', 'alert');
                field.parentNode.appendChild(existing);
            }

            existing.textContent = text;
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', id);
        };

        var check = function (field) {
            var value = field.value.trim();
            var rule = rules[field.id];
            var ok = field.required
                ? value !== '' && (!rule || rule(value))
                : !rule || rule(value);

            message(field, ok ? '' : field.getAttribute('data-msg'));
            field.classList.toggle('is-valid', ok && value !== '');

            return ok;
        };

        var fields = checkout.querySelectorAll('input[data-msg]');

        fields.forEach(function (field) {
            field.addEventListener('blur', function () {
                if (field.value !== '' || field.required) {
                    check(field);
                }
            });

            // Once flagged, tell the customer the moment it is fixed.
            field.addEventListener('input', function () {
                if (field.getAttribute('aria-invalid') === 'true') {
                    check(field);
                }
            });
        });

        // Capture phase: runs before the double-submit guard, which respects a cancelled submit.
        checkout.addEventListener(
            'submit',
            function (event) {
                var first = null;

                fields.forEach(function (field) {
                    if (!check(field) && first === null) {
                        first = field;
                    }
                });

                if (first !== null) {
                    event.preventDefault();
                    first.focus();
                    first.scrollIntoView({
                        block: 'center',
                        behavior: window.matchMedia(
                            '(prefers-reduced-motion: reduce)',
                        ).matches
                            ? 'auto'
                            : 'smooth',
                    });
                }
            },
            true,
        );
    }

    // Order summary: always open beside the form on a wide screen, folded
    // away on a phone (where the bar below the page still shows the total).
    var summary = document.querySelector('details[data-summary]');

    if (summary && window.matchMedia) {
        var wide = window.matchMedia('(min-width: 901px)');
        var head = summary.querySelector('summary');

        var fit = function () {
            summary.open = wide.matches;
        };

        head.addEventListener('click', function (event) {
            if (wide.matches) {
                event.preventDefault();
            }
        });

        fit();

        if (wide.addEventListener) {
            wide.addEventListener('change', fit);
        }
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Gateway choices belong to "Pay online": shown only while it is selected.
    var gatewayBox = document.querySelector('[data-gateways]');

    if (gatewayBox) {
        var syncGateways = function () {
            var online = document.querySelector(
                'input[name="payment_method"][value="online"]',
            );
            gatewayBox.hidden = !(online && online.checked);
        };

        document
            .querySelectorAll('input[name="payment_method"]')
            .forEach(function (radio) {
                radio.addEventListener('change', syncGateways);
            });
        syncGateways();
    }

    // Delivery area: division → district → upazila, each fetched from our own
    // endpoint (which holds the credentials). Optional: without it the customer
    // just types the address.
    var areas = document.querySelector('[data-areas]');

    if (areas && window.fetch) {
        var selects = areas.querySelectorAll('select');
        var hidden = document.getElementById('area_id');
        var district = document.getElementById('district');
        var city = document.getElementById('city');

        // The selects start empty, so a value carried over from a failed
        // submit would name an area the customer cannot see.
        hidden.value = '';

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

        // A newer request for the same select supersedes an older, slower one.
        var tickets = new WeakMap();

        var load = function (parent, select) {
            var ticket = (tickets.get(select) || 0) + 1;
            var url =
                '/api/areas' +
                (parent ? '?parent=' + encodeURIComponent(parent) : '');

            tickets.set(select, ticket);
            select.setAttribute('aria-busy', 'true');
            select.parentNode.classList.add('is-loading');

            return fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.ok ? response.json() : { data: [] };
                })
                .then(function (body) {
                    if (tickets.get(select) === ticket) {
                        fill(select, body.data || []);
                    }
                })
                .catch(function () {
                    /* the address field still works */
                })
                .then(function () {
                    if (tickets.get(select) === ticket) {
                        select.removeAttribute('aria-busy');
                        select.parentNode.classList.remove('is-loading');
                    }
                });
        };

        // Remember what we typed into a text field ourselves, so a changed
        // selection may replace it but something the customer wrote never is.
        var written = new WeakMap();

        var suggest = function (field, text) {
            if (field && (!field.value || field.value === written.get(field))) {
                field.value = text;
                written.set(field, text);
            }
        };

        var invalidate = function (select) {
            // Cancel any request still in flight for a level below.
            tickets.set(select, (tickets.get(select) || 0) + 1);
            select.removeAttribute('aria-busy');
            select.parentNode.classList.remove('is-loading');
            fill(select, []);
        };

        selects.forEach(function (select, level) {
            select.addEventListener('change', function () {
                for (var i = level + 1; i < selects.length; i++) {
                    invalidate(selects[i]);
                }

                var chosen = select.value;
                var label = select.options[select.selectedIndex].textContent;

                // The most specific area chosen so far is the one submitted.
                hidden.value = chosen;

                if (!chosen && level > 0) {
                    hidden.value = selects[level - 1].value;
                }

                if (level === 1) {
                    suggest(district, chosen ? label : '');
                }

                if (level === 2) {
                    suggest(city, chosen ? label : '');
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
