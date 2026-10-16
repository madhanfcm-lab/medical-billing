/**
 * Clinic Billing — Medical-Grade Theme Engine
 * Developed with senior enterprise architecture standards.
 * Supports:
 * - Clinical Light (Daylight hospital slate & crisp teal)
 * - Obsidian Dark (Medical charcoal slate dark mode, calibrated contrast)
 * - Midnight Navy (Deep oceanic telemetry navy with cyan & ice-blue accents)
 * - Clinical Jade (Therapeutic evergreen / jade night mode)
 * - Match System (Live OS light/dark synchronization)
 */
(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'clinic_theme';
    var VALID_THEMES = ['light', 'dark', 'navy', 'emerald'];

    var themeMeta = {
        light: { name: 'Clinical Light', icon: 'bi-sun-fill text-warning', bs: 'light' },
        dark: { name: 'Obsidian Dark', icon: 'bi-moon-stars-fill text-info', bs: 'dark' },
        navy: { name: 'Midnight Navy', icon: 'bi-water text-primary', bs: 'dark' },
        emerald: { name: 'Clinical Jade', icon: 'bi-heart-pulse-fill text-success', bs: 'dark' },
        system: { name: 'Match System', icon: 'bi-display text-secondary', bs: null }
    };

    function getSavedPreference() {
        try {
            return localStorage.getItem(STORAGE_KEY) || 'system';
        } catch (e) {
            return 'system';
        }
    }

    function isSystemDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function resolveEffective(pref) {
        if (pref === 'system') {
            return isSystemDark() ? 'dark' : 'light';
        }
        return VALID_THEMES.indexOf(pref) !== -1 ? pref : 'light';
    }

    function applyTheme(pref, persist) {
        if (persist !== false) {
            try {
                localStorage.setItem(STORAGE_KEY, pref);
            } catch (e) {}
        }

        var effective = resolveEffective(pref);
        var meta = themeMeta[effective] || themeMeta.light;
        var isDark = meta.bs === 'dark';

        document.documentElement.setAttribute('data-theme', effective);
        document.documentElement.setAttribute('data-bs-theme', meta.bs);

        updateActiveUI(pref, effective);

        try {
            window.dispatchEvent(new CustomEvent('clinic-theme-changed', {
                detail: {
                    preference: pref,
                    effective: effective,
                    isDark: isDark,
                    meta: meta
                }
            }));
        } catch (e) {}
    }

    function updateActiveUI(pref, effective) {
        var meta = themeMeta[pref] || themeMeta[effective] || themeMeta.light;

        // Topbar active icon
        var icons = document.querySelectorAll('.theme-icon-active');
        icons.forEach(function (el) {
            el.className = 'bi ' + (meta.icon || 'bi-sun-fill text-warning') + ' theme-icon-active fs-6';
        });

        // Dropdown menu items
        var pickers = document.querySelectorAll('.js-theme-pick');
        pickers.forEach(function (btn) {
            var val = btn.getAttribute('data-theme-val');
            var isMatch = val === pref;
            if (isMatch) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
            var check = btn.querySelector('.theme-check');
            if (check) {
                if (isMatch) {
                    check.classList.remove('d-none');
                } else {
                    check.classList.add('d-none');
                }
            }
        });

        // Theme preview cards (e.g. on Profile page)
        var cards = document.querySelectorAll('.js-theme-card');
        cards.forEach(function (card) {
            var val = card.getAttribute('data-theme-val');
            var isMatch = (val === pref) || (pref === 'system' && val === effective);
            if (isMatch) {
                card.classList.add('is-active');
            } else {
                card.classList.remove('is-active');
            }
        });
    }

    function init() {
        var currentPref = getSavedPreference();
        applyTheme(currentPref, false);

        // System media query watcher
        if (window.matchMedia) {
            try {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                var onMqChange = function () {
                    if (getSavedPreference() === 'system') {
                        applyTheme('system', false);
                    }
                };
                if (mq.addEventListener) {
                    mq.addEventListener('change', onMqChange);
                } else if (mq.addListener) {
                    mq.addListener(onMqChange);
                }
            } catch (e) {}
        }

        // Event delegation for clicks
        document.addEventListener('click', function (e) {
            var pickBtn = e.target.closest('.js-theme-pick');
            if (pickBtn) {
                e.preventDefault();
                var val = pickBtn.getAttribute('data-theme-val');
                if (val) {
                    applyTheme(val, true);
                }
                return;
            }

            var cardBtn = e.target.closest('.js-theme-card');
            if (cardBtn) {
                e.preventDefault();
                var cardVal = cardBtn.getAttribute('data-theme-val');
                if (cardVal) {
                    applyTheme(cardVal, true);
                }
                return;
            }

            var cycleBtn = e.target.closest('.js-theme-cycle');
            if (cycleBtn) {
                e.preventDefault();
                var cur = document.documentElement.getAttribute('data-theme') || 'light';
                var order = ['light', 'dark', 'navy', 'emerald'];
                var idx = order.indexOf(cur);
                var next = order[(idx + 1) % order.length];
                applyTheme(next, true);
                return;
            }
        });

        updateActiveUI(currentPref, resolveEffective(currentPref));
    }

    window.ClinicTheme = {
        apply: applyTheme,
        getPreference: getSavedPreference,
        getEffective: function () { return resolveEffective(getSavedPreference()); },
        init: init
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);

