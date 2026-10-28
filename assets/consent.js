/*!
 * Bananaland Consent
 * https://github.com/The-Bananaland/bananaland-consent
 *
 * Resolves the visitor's consent model, shows the banner, stores the choice,
 * releases held scripts and embeds, and keeps Google Consent Mode in sync.
 */
(function () {
  'use strict';

  var cfg = window.blcConfig;
  if (!cfg || window.blConsent) {
    return;
  }

  var COOKIE = 'blc_consent';
  var REGION_COOKIE = 'blc_region';
  var OPTIONAL = cfg.categories.filter(function (c) {
    return c !== 'necessary';
  });
  var gpc = navigator.globalPrivacyControl === true;

  var state = null; // saved choice
  var previous = null; // stale choice being re-prompted
  var granted = []; // categories in effect on this page (saved or region default)
  var applied = {}; // categories whose scripts were released on this page
  var mode = null;
  var region = '';
  var listeners = [];
  var ui = {};
  var lastFocus = null;

  /* ---------- Utilities ---------- */

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return match ? match[1] : null;
  }

  function writeCookie(name, value, days) {
    document.cookie =
      name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + Math.round(days * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function expireCookie(name) {
    var parts = location.hostname.split('.');
    var domains = [''];
    for (var i = 0; i < parts.length - 1; i++) {
      domains.push('; domain=.' + parts.slice(i).join('.'));
    }
    domains.forEach(function (domain) {
      document.cookie = name + '=; path=/; max-age=0' + domain;
    });
  }

  /** Delete the first-party cookies declared for a category. Supports trailing * wildcards. */
  function clearCategoryCookies(category) {
    var patterns = (cfg.cookies && cfg.cookies[category]) || [];
    if (!patterns.length) {
      return;
    }
    var present = document.cookie.split('; ').map(function (pair) {
      return pair.split('=')[0];
    });
    patterns.forEach(function (pattern) {
      if (pattern.slice(-1) === '*') {
        var prefix = pattern.slice(0, -1);
        present.forEach(function (name) {
          if (name.indexOf(prefix) === 0) {
            expireCookie(name);
          }
        });
      } else if (present.indexOf(pattern) > -1) {
        expireCookie(pattern);
      }
    });
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    var b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) {
      return ('0' + x.toString(16)).slice(-2);
    }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function unique(list) {
    return list.filter(function (v, i) {
      return list.indexOf(v) === i;
    });
  }

  function servicesFor(categories) {
    var out = [];
    categories.forEach(function (c) {
      out = out.concat((cfg.services && cfg.services[c]) || []);
    });
    return out;
  }

  /* ---------- Stored choice ---------- */

  function loadState() {
    if (cfg.scan) {
      return null;
    }
    var raw = readCookie(COOKIE);
    if (!raw) {
      return null;
    }
    var saved;
    try {
      saved = JSON.parse(decodeURIComponent(raw));
    } catch (e) {
      return null;
    }
    if (!saved || !Array.isArray(saved.c) || !saved.id) {
      return null;
    }
    if (String(saved.pv) !== cfg.policyVersion) {
      previous = saved;
      return null;
    }
    // A service added to a category the visitor accepted needs fresh consent.
    var known = saved.s || [];
    var unseen = servicesFor(saved.c).some(function (uid) {
      return known.indexOf(uid) < 0;
    });
    if (unseen) {
      previous = saved;
      return null;
    }
    return saved;
  }

  function save(categories, method) {
    categories = unique(categories.filter(function (c) {
      return OPTIONAL.indexOf(c) > -1;
    }));
    var id = (state && state.id) || (previous && previous.id) || uuid();
    state = {
      id: id,
      pv: cfg.policyVersion,
      c: categories,
      m: mode,
      t: Math.floor(Date.now() / 1000),
      g: gpc ? 1 : 0,
      s: servicesFor(categories),
    };
    writeCookie(COOKIE, JSON.stringify(state), (cfg.expiry && cfg.expiry[mode]) || 365);
    log(categories, method);
    return categories;
  }

  function log(categories, method) {
    if (!cfg.log) {
      return;
    }
    var body = JSON.stringify({
      id: state.id,
      categories: categories,
      mode: mode,
      method: method,
      gpc: gpc,
      policyVersion: cfg.policyVersion,
      region: region,
      url: location.href,
    });
    try {
      fetch(cfg.rest.consent, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
        credentials: 'omit',
      }).catch(function () {});
    } catch (e) {
      /* logging must never break the page */
    }
  }

  /* ---------- Region ---------- */

  var US_ZONES = /^(America\/(New_York|Chicago|Denver|Phoenix|Los_Angeles|Anchorage|Adak|Boise|Detroit|Juneau|Sitka|Metlakatla|Yakutat|Nome|Menominee|Indiana\/.+|Kentucky\/.+|North_Dakota\/.+)|Pacific\/Honolulu)$/;
  var BR_ZONES = /^America\/(Sao_Paulo|Bahia|Fortaleza|Recife|Belem|Manaus|Cuiaba|Campo_Grande|Porto_Velho|Boa_Vista|Rio_Branco|Maceio|Araguaina|Santarem|Noronha|Eirunepe)$/;
  var EEA_ATLANTIC = /^Atlantic\/(Reykjavik|Canary|Madeira|Azores|Faroe)$/;

  /** Rough fallback when the host gives no location. Ambiguous zones return null (strict default). */
  function timezoneMode() {
    if (!cfg.timezoneFallback) {
      return null;
    }
    var tz = '';
    try {
      tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) {
      return null;
    }
    if (/^Europe\//.test(tz) || EEA_ATLANTIC.test(tz) || BR_ZONES.test(tz)) {
      return 'opt-in';
    }
    if (US_ZONES.test(tz)) {
      return 'opt-out';
    }
    return null;
  }

  function resolveMode() {
    // Local/dev testing: ?blc_test_region=DE on the page is passed through and bypasses the cached region.
    var testRegion = (location.search.match(/[?&]blc_test_region=([A-Za-z0-9-]{2,6})/) || [])[1];
    var regionUrl = cfg.rest.region + (testRegion ? (cfg.rest.region.indexOf('?') > -1 ? '&' : '?') + 'blc_test_region=' + testRegion : '');
    try {
      if (testRegion) {
        throw new Error('test region');
      }
      var cached = JSON.parse(decodeURIComponent(readCookie(REGION_COOKIE) || ''));
      if (cached && ['opt-in', 'opt-out', 'notice'].indexOf(cached.m) > -1) {
        region = cached.r || '';
        return Promise.resolve(cached.m);
      }
    } catch (e) {
      /* fetch below */
    }

    function remember(m) {
      if (!testRegion) {
        writeCookie(REGION_COOKIE, JSON.stringify({ m: m, r: region }), 1);
      }
      return m;
    }

    return fetch(regionUrl, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: '{}' })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('region ' + r.status);
        }
        return r.json();
      })
      .then(function (d) {
        region = d.country ? d.country + (d.region ? '-' + d.region : '') : '';
        var m = d.mode === 'unknown' ? timezoneMode() || d.fallback || cfg.unknownMode : d.mode;
        return remember(m);
      })
      .catch(function () {
        return timezoneMode() || cfg.unknownMode || 'opt-in';
      });
  }

  /* ---------- Applying consent ---------- */

  function consentModeUpdate(categories) {
    if (!cfg.consentMode || !cfg.consentMode.enabled || typeof window.gtag !== 'function') {
      return;
    }
    var g = function (c) {
      return categories.indexOf(c) > -1 ? 'granted' : 'denied';
    };
    window.gtag('consent', 'update', {
      ad_storage: g('marketing'),
      ad_user_data: g('marketing'),
      ad_personalization: g('marketing'),
      analytics_storage: g('analytics'),
      functionality_storage: g('preferences'),
      personalization_storage: g('preferences'),
    });
  }

  /** Swap held <script type="text/plain"> tags for live ones, in document order. */
  function releaseScripts(categories) {
    var held = Array.prototype.slice.call(document.querySelectorAll('script[type="text/plain"][data-consent]')).filter(function (el) {
      return categories.indexOf(el.getAttribute('data-consent')) > -1;
    });
    var chain = Promise.resolve();
    held.forEach(function (el) {
      chain = chain.then(function () {
        return new Promise(function (resolve) {
          var live = document.createElement('script');
          Array.prototype.forEach.call(el.attributes, function (attr) {
            if (['type', 'data-consent', 'data-blc-type'].indexOf(attr.name) < 0) {
              live.setAttribute(attr.name, attr.value);
            }
          });
          var type = el.getAttribute('data-blc-type');
          if (type) {
            live.type = type;
          }
          live.setAttribute('data-blc-released', el.getAttribute('data-consent'));
          var waits = el.src && !el.async && !el.defer && type !== 'module';
          if (el.src) {
            live.async = el.async;
            if (waits) {
              live.onload = live.onerror = function () {
                resolve();
              };
            }
          } else {
            live.text = el.text;
          }
          el.parentNode.replaceChild(live, el);
          if (!waits) {
            resolve();
          }
        });
      });
    });

    document.querySelectorAll('[data-blc-src][data-consent]').forEach(function (el) {
      if (categories.indexOf(el.getAttribute('data-consent')) > -1) {
        el.setAttribute('src', el.getAttribute('data-blc-src'));
        el.removeAttribute('data-blc-src');
      }
    });

    document.querySelectorAll('[data-blc="embed"][data-consent]').forEach(function (el) {
      if (categories.indexOf(el.getAttribute('data-consent')) > -1) {
        loadEmbed(el);
      }
    });
    return chain;
  }

  function loadEmbed(placeholder) {
    var source = placeholder.querySelector('template[data-blc="embed-source"]');
    if (!source) {
      return;
    }
    var fragment = source.content.cloneNode(true);
    placeholder.parentNode.replaceChild(fragment, placeholder);
  }

  function apply(categories, isDefault) {
    granted = categories.slice();
    consentModeUpdate(categories);
    var fresh = categories.filter(function (c) {
      return !applied[c];
    });
    fresh.forEach(function (c) {
      applied[c] = true;
    });
    releaseScripts(fresh);

    if (window.clarity) {
      window.clarity('consentv2', {
        ad_Storage: categories.indexOf('marketing') > -1 ? 'granted' : 'denied',
        analytics_Storage: categories.indexOf('analytics') > -1 ? 'granted' : 'denied',
      });
    }

    var detail = { categories: categories.slice(), mode: mode, isDefault: !!isDefault, consentId: state ? state.id : null };
    var push = { event: 'blc_consent_update', blc_categories: categories.join(','), blc_mode: mode };
    OPTIONAL.forEach(function (c) {
      push['blc_' + c] = categories.indexOf(c) > -1;
    });
    (window.dataLayer = window.dataLayer || []).push(push);
    document.dispatchEvent(new CustomEvent('blc:change', { detail: detail }));
    listeners.forEach(function (fn) {
      try {
        fn(detail);
      } catch (e) {
        if (window.console) {
          console.error(e);
        }
      }
    });
  }

  /** Record a choice, then apply it. Withdrawing something already running reloads the page. */
  function commit(categories, method) {
    categories = save(categories, method);
    hideBanner();
    closePreferences();
    showReentry();

    var revoked = OPTIONAL.filter(function (c) {
      return categories.indexOf(c) < 0;
    });
    revoked.forEach(clearCategoryCookies);
    if (
      revoked.some(function (c) {
        return applied[c];
      })
    ) {
      // Scripts can't be unloaded; a reload starts clean with the new choice.
      setTimeout(function () {
        location.reload();
      }, 150);
      return;
    }
    apply(categories, false);
  }

  /* ---------- UI ---------- */

  function setMode(m) {
    mode = m;
    if (ui.root) {
      ui.root.setAttribute('data-blc-mode', m);
      ui.root.querySelectorAll('[data-blc-show-for]').forEach(function (el) {
        el.hidden = el.getAttribute('data-blc-show-for').split(/\s+/).indexOf(m) < 0;
      });
    }
    document.querySelectorAll('[data-blc="preferences-link"]').forEach(function (link) {
      // "Your Privacy Choices" wording applies where advertising services could sell or share data.
      var optOut = m === 'opt-out' && !!cfg.sharesData;
      var label = link.querySelector('.blc-link__label');
      var icon = link.querySelector('.blc-link__icon');
      if (label) {
        label.textContent = link.getAttribute(optOut ? 'data-blc-label-opt-out' : 'data-blc-label-default');
      }
      if (icon) {
        icon.hidden = !optOut;
      }
    });
  }

  function isDialog(el) {
    return el && typeof el.showModal === 'function';
  }

  function placeBanner() {
    // Put the banner right after the skip link so keyboard users reach it first.
    var skip = document.querySelector('.skip-link, .skip-to-content, .screen-reader-shortcut, a[href="#main"], a[href="#content"], a[href="#main-content"]');
    if (skip && skip.parentNode) {
      skip.insertAdjacentElement('afterend', ui.root);
    } else if (document.body.firstChild !== ui.root) {
      document.body.insertBefore(ui.root, document.body.firstChild);
    }
  }

  function showBanner() {
    var banner = ui.banner;
    if (!banner) {
      return;
    }
    if (isDialog(banner)) {
      if (mode === 'opt-in') {
        banner.showModal();
        document.documentElement.classList.add('blc-modal-open');
      } else {
        banner.classList.add('blc-banner--nonmodal');
        banner.show();
      }
    } else {
      placeBanner();
      banner.hidden = false;
    }
    document.documentElement.classList.add('blc-banner-open');
  }

  function hideBanner() {
    var banner = ui.banner;
    if (!banner) {
      return;
    }
    if (isDialog(banner)) {
      if (banner.open) {
        banner.close();
      }
    } else {
      banner.hidden = true;
    }
    document.documentElement.classList.remove('blc-banner-open', 'blc-modal-open');
  }

  function showReentry() {
    if (ui.floating && cfg.floatingButton) {
      ui.floating.hidden = false;
    }
  }

  function bannerVisible() {
    return ui.banner && (isDialog(ui.banner) ? ui.banner.open : !ui.banner.hidden);
  }

  function openPreferences() {
    var prefs = ui.prefs;
    if (!prefs) {
      return;
    }
    lastFocus = document.activeElement;
    var current = state ? state.c : previous && mode === 'opt-in' ? previous.c : granted;
    prefs.querySelectorAll('[data-blc="toggle"]').forEach(function (input) {
      input.checked = current.indexOf(input.getAttribute('data-category')) > -1;
    });
    var idEl = prefs.querySelector('[data-blc="consent-id"]');
    if (idEl) {
      idEl.textContent = state ? state.id : '—';
    }
    var gpcEl = prefs.querySelector('[data-blc="gpc-notice"]');
    if (gpcEl) {
      gpcEl.hidden = !gpc;
    }
    if (isDialog(prefs)) {
      if (!prefs.open) {
        prefs.showModal();
      }
    } else {
      prefs.hidden = false;
    }
    document.documentElement.classList.add('blc-modal-open');
  }

  function closePreferences() {
    var prefs = ui.prefs;
    if (!prefs) {
      return;
    }
    if (isDialog(prefs)) {
      if (prefs.open) {
        prefs.close();
      }
    } else if (!prefs.hidden) {
      prefs.hidden = true;
      onPreferencesClosed();
    }
  }

  function onPreferencesClosed() {
    if (!(ui.banner && isDialog(ui.banner) && ui.banner.open && mode === 'opt-in')) {
      document.documentElement.classList.remove('blc-modal-open');
    }
    var target = lastFocus && document.contains(lastFocus) ? lastFocus : null;
    if (!target && bannerVisible()) {
      target = ui.banner.querySelector('button:not([hidden])');
    }
    if (target && typeof target.focus === 'function') {
      target.focus();
    }
    lastFocus = null;
  }

  function selectedCategories() {
    var out = [];
    if (ui.prefs) {
      ui.prefs.querySelectorAll('[data-blc="toggle"]').forEach(function (input) {
        if (input.checked) {
          out.push(input.getAttribute('data-category'));
        }
      });
    }
    return out;
  }

  function handleAction(action, target, event) {
    switch (action) {
      case 'accept-all':
        commit(OPTIONAL.slice(), 'accept-all');
        break;
      case 'reject-all':
        commit([], 'reject-all');
        break;
      case 'opt-out':
        commit(
          OPTIONAL.filter(function (c) {
            return c !== 'marketing';
          }),
          'opt-out'
        );
        break;
      case 'save':
        commit(selectedCategories(), 'custom');
        break;
      case 'open-preferences':
        if (event) {
          event.preventDefault();
        }
        openPreferences();
        break;
      case 'close':
        closePreferences();
        break;
      case 'embed-once':
        loadEmbed(target.closest('[data-blc="embed"]'));
        break;
      case 'embed-always': {
        var embed = target.closest('[data-blc="embed"]');
        var category = embed ? embed.getAttribute('data-consent') : 'preferences';
        commit(unique(granted.concat([category])), 'embed');
        break;
      }
    }
  }

  function bindUI() {
    ui.root = document.querySelector('[data-blc="root"]');
    ui.banner = document.querySelector('[data-blc="banner"]');
    ui.prefs = document.querySelector('[data-blc="preferences"]');
    ui.floating = document.querySelector('[data-blc="floating-button"]');

    document.addEventListener('click', function (event) {
      var target = event.target.closest && event.target.closest('[data-blc-action]');
      if (target) {
        handleAction(target.getAttribute('data-blc-action'), target, event);
      }
    });

    if (isDialog(ui.prefs)) {
      ui.prefs.addEventListener('close', onPreferencesClosed);
      // Click on the backdrop closes the panel.
      ui.prefs.addEventListener('click', function (event) {
        if (event.target === ui.prefs) {
          closePreferences();
        }
      });
    }
    if (isDialog(ui.banner)) {
      // In opt-in regions, dismissing the modal is not a choice.
      ui.banner.addEventListener('cancel', function (event) {
        if (mode === 'opt-in') {
          event.preventDefault();
        }
      });
    }
    if (location.hash === '#blc-preferences') {
      setTimeout(openPreferences, 0);
    }
  }

  /* ---------- Boot ---------- */

  var readyResolve;
  var ready = new Promise(function (resolve) {
    readyResolve = resolve;
  });

  function init() {
    bindUI();
    if (cfg.scan) {
      readyResolve();
      return;
    }

    state = loadState();
    if (state) {
      setMode(state.m || 'opt-in');
      apply(state.c, false);
      showReentry();
      readyResolve();
      return;
    }

    resolveMode().then(function (m) {
      setMode(m);
      if (m === 'opt-in') {
        granted = [];
        showBanner();
      } else if (gpc) {
        // A Global Privacy Control signal is an opt-out of sale/sharing and targeted ads.
        commit(
          OPTIONAL.filter(function (c) {
            return c !== 'marketing';
          }),
          'gpc'
        );
      } else {
        apply(OPTIONAL.slice(), true);
        showBanner();
      }
      readyResolve();
    });
  }

  window.blConsent = {
    /** Whether a category is currently allowed on this page. */
    has: function (category) {
      return category === 'necessary' || granted.indexOf(category) > -1;
    },
    /** Saved choice (or null) plus the current mode. */
    get: function () {
      return { mode: mode, categories: granted.slice(), saved: state, gpc: gpc };
    },
    /** Subscribe to changes. Called immediately if consent is already resolved. */
    on: function (event, fn) {
      if (typeof event === 'function') {
        fn = event;
      }
      listeners.push(fn);
      if (mode) {
        fn({ categories: granted.slice(), mode: mode, isDefault: !state, consentId: state ? state.id : null });
      }
    },
    open: openPreferences,
    acceptAll: function () {
      handleAction('accept-all');
    },
    rejectAll: function () {
      handleAction('reject-all');
    },
    ready: ready,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
