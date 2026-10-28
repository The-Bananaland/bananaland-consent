/* Bananaland Consent admin: services repeater and site scan. */
(function () {
  'use strict';
  var data = window.blcAdmin || {};

  /* ---------- Services repeater ---------- */

  var list = document.querySelector('[data-blc-services]');
  var template = document.querySelector('[data-blc-service-template]');

  function syncRow(row) {
    var select = row.querySelector('[data-blc-preset]');
    var preset = select ? select.value : 'custom';
    var info = (data.presets || {})[preset] || { fields: {} };
    row.setAttribute('data-preset', preset);
    var hasId = info.fields && info.fields.id;
    row.querySelectorAll('[data-blc-preset-field]').forEach(function (el) {
      el.hidden = !hasId;
    });
    row.querySelectorAll('[data-blc-custom-field]').forEach(function (el) {
      el.hidden = preset !== 'custom';
    });
    var label = row.querySelector('[data-blc-id-label]');
    if (label && hasId) {
      label.textContent = info.fields.id;
    }
  }

  if (list && template) {
    var next = list.querySelectorAll('[data-blc-service-row]').length;
    list.querySelectorAll('[data-blc-service-row]').forEach(syncRow);

    document.addEventListener('change', function (e) {
      if (e.target.matches('[data-blc-preset]')) {
        var row = e.target.closest('[data-blc-service-row]');
        var info = (data.presets || {})[e.target.value];
        var category = row.querySelector('[data-blc-category]');
        if (info && category) {
          category.value = info.category;
        }
        syncRow(row);
      }
    });

    document.addEventListener('click', function (e) {
      if (e.target.matches('[data-blc-add-service]')) {
        var html = template.innerHTML.replace(/__INDEX__/g, String(next++));
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var row = holder.firstElementChild;
        list.appendChild(row);
        syncRow(row);
        var first = row.querySelector('select');
        if (first) {
          first.focus();
        }
      }
      if (e.target.matches('[data-blc-remove-service]')) {
        if (window.confirm(data.i18n.remove)) {
          e.target.closest('[data-blc-service-row]').remove();
        }
      }
    });
  }

  /* ---------- Scan ---------- */

  var scanButton = document.querySelector('[data-blc-scan]');
  var results = document.querySelector('[data-blc-scan-results]');

  function scanUrl(url) {
    return new Promise(function (resolve) {
      var frame = document.createElement('iframe');
      frame.style.cssText = 'position:absolute;width:1280px;height:800px;left:-99999px;top:0;';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      var done = false;
      function finish() {
        if (done) {
          return;
        }
        done = true;
        var found = { url: url, hosts: [], cookies: [] };
        try {
          var win = frame.contentWindow;
          var home = new URL(data.home).hostname;
          win.performance.getEntriesByType('resource').forEach(function (entry) {
            var host = new URL(entry.name).hostname;
            if (host !== home && found.hosts.indexOf(host) < 0) {
              found.hosts.push(host);
            }
          });
          found.cookies = win.document.cookie
            .split('; ')
            .map(function (c) {
              return c.split('=')[0];
            })
            .filter(Boolean);
        } catch (err) {
          found.error = String(err);
        }
        frame.remove();
        resolve(found);
      }
      frame.addEventListener('load', function () {
        // Give late scripts a moment to run.
        setTimeout(finish, 4000);
      });
      setTimeout(finish, 15000);
      frame.src = url;
      document.body.appendChild(frame);
    });
  }

  function declaredCookie(name) {
    var cookies = (data.declared && data.declared.cookies) || [];
    if (/^(wp-|wordpress_|wp_|PHPSESSID$)/.test(name)) {
      return true;
    }
    return cookies.some(function (pattern) {
      return pattern.slice(-1) === '*' ? name.indexOf(pattern.slice(0, -1)) === 0 : pattern === name;
    });
  }

  function render(reports) {
    var hosts = {};
    var cookies = {};
    reports.forEach(function (r) {
      r.hosts.forEach(function (h) {
        (hosts[h] = hosts[h] || []).push(r.url);
      });
      r.cookies.forEach(function (c) {
        cookies[c] = true;
      });
    });
    var declaredHosts = (data.declared && data.declared.hosts) || [];
    var rows = '';
    Object.keys(hosts).forEach(function (h) {
      var known = declaredHosts.indexOf(h) > -1;
      rows += '<tr><td><code>' + h + '</code></td><td>' + (known ? 'Declared service (loads before consent: check it is meant to, e.g. Tag Manager)' : '<strong>Not declared</strong>') + '</td></tr>';
    });
    Object.keys(cookies).forEach(function (c) {
      if (!declaredCookie(c)) {
        rows += '<tr><td><code>' + c + '</code> (cookie)</td><td><strong>Not declared.</strong> It may have been set earlier in your own browser; re-check in a private window.</td></tr>';
      }
    });
    results.innerHTML = rows
      ? '<table class="widefat striped"><thead><tr><th>Item</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table>'
      : '<div class="notice notice-success inline"><p>' + data.i18n.clean + '</p></div>';
    results.insertAdjacentHTML('beforeend', '<p class="description">Pages scanned: ' + reports.length + '</p>');
  }

  if (scanButton && results) {
    scanButton.addEventListener('click', function () {
      scanButton.disabled = true;
      results.textContent = data.i18n.scanning;
      var urls = data.scanUrls || [];
      var reports = [];
      urls
        .reduce(function (chain, url) {
          return chain.then(function () {
            return scanUrl(url).then(function (r) {
              reports.push(r);
            });
          });
        }, Promise.resolve())
        .then(function () {
          render(reports);
          scanButton.disabled = false;
        });
    });
  }
})();
