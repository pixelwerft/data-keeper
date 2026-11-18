/* Data Keeper – dialogs. A link with `data-dk-dialog` opens its page in a dialog on top of the overview instead of
   leaving it. Without JavaScript (or if loading fails) the link simply opens the page.
   Plain JavaScript on purpose: a plugin is installed with Composer, so there is no build step. */
(function () {
  'use strict';

  if (typeof Garnish === 'undefined' || typeof jQuery === 'undefined') {
    return;
  }

  var $ = jQuery;
  var current = null; // the open dialog: { modal, content, url }

  /** Loads a page into the open dialog. */
  function load(url) {
    var dialog = current;
    var round = (dialog.round = (dialog.round || 0) + 1);
    dialog.url = url;
    dialog.content.classList.add('dk-dialog-loading');
    // what the person is typing in and what they ticked stays as it is when the dialog is filled again
    var active = document.activeElement;
    var focus = active && dialog.content.contains(active) && active.name ? active.name : null;
    var remember = dialog.content.querySelector('input[type="checkbox"][name="remember"]');
    var remembered = remember ? remember.checked : false;
    if (!dialog.content.firstChild) {
      // nothing to show yet: say that something is coming
      dialog.content.appendChild(waiting());
    }
    return fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
      credentials: 'same-origin',
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.text();
      })
      .then(function (html) {
        if (current !== dialog || dialog.round !== round) {
          // closed in the meantime, or asked again with other values: this answer is out of date
          return;
        }
        dialog.content.innerHTML = html;
        dialog.content.classList.remove('dk-dialog-loading');
        dialog.modal.updateSizeAndPosition();
        var again = dialog.content.querySelector('input[type="checkbox"][name="remember"]');
        if (again && remembered) {
          again.checked = true;
        }
        var field = focus ? dialog.content.querySelector('.dk-options [name="' + focus + '"]') : null;
        if (field) {
          field.focus();
          if (field.type === 'number' || field.type === 'text') {
            // the cursor back to the end, where it was while typing
            var value = field.value;
            field.value = '';
            field.value = value;
          }
        }
      })
      .catch(function () {
        if (current !== dialog || dialog.round !== round) {
          return;
        }
        // whatever went wrong: the page itself still works
        window.location.href = url;
      });
  }

  /** Fills the dialog again with the values of a form that only asks (method GET). */
  function ask(form) {
    var url = new URL(form.getAttribute('action') || current.url, window.location.href);
    new FormData(form).forEach(function (value, name) {
      url.searchParams.set(name, value);
    });
    // a box that is not ticked is not in the form data: say so, or the page falls back to its default
    form.querySelectorAll('input[type="checkbox"][name]').forEach(function (box) {
      if (!box.checked) {
        url.searchParams.set(box.name, '0');
      }
    });
    return load(url.toString());
  }

  function asks(form) {
    return current && form && current.content.contains(form) && (form.getAttribute('method') || 'get').toLowerCase() === 'get';
  }

  /** What a dialog shows until its page is there: an empty box looks broken. */
  function waiting() {
    var box = document.createElement('div');
    box.className = 'dk-dialog-wait';
    box.setAttribute('role', 'status');
    var spin = document.createElement('span');
    spin.className = 'dk-spin';
    spin.setAttribute('aria-hidden', 'true');
    var text = document.createElement('span');
    text.textContent = (typeof Craft !== 'undefined' && Craft.t ? Craft.t('app', 'Loading') : 'Loading') + ' …';
    box.appendChild(spin);
    box.appendChild(text);
    return box;
  }

  function close() {
    if (current) {
      current.modal.hide();
    }
  }

  function open(url, closeLabel) {
    close();

    var $container = $(
      '<div class="modal fitted dk dk-dialog" role="dialog" aria-modal="true">' +
        '<div class="body"><div class="dk-dialog-content dk-dialog-loading"></div></div>' +
        '<div class="footer"><div class="buttons right"><button type="button" class="btn dk-dialog-close"></button></div></div>' +
        '</div>'
    ).appendTo(Garnish.$bod);
    $container.find('.dk-dialog-close').text(closeLabel || 'Close');

    var dialog = { content: $container.find('.dk-dialog-content')[0], url: url };
    // before the dialog is measured, so it opens in its width and says that it is loading
    dialog.content.appendChild(waiting());
    dialog.modal = new Garnish.Modal($container, {
      onFadeOut: function () {
        dialog.modal.destroy();
        if (current === dialog) {
          current = null;
        }
      },
    });
    current = dialog;
    $container.on('click', '.dk-dialog-close', close);
    load(url);
  }

  // a link that opens a dialog
  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[data-dk-dialog]') : null;
    if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
      return;
    }
    event.preventDefault();
    open(link.href, link.getAttribute('data-dk-close'));
  });

  // inside a dialog a form that only asks (method GET, e.g. other options) reloads the dialog; a form that changes
  // something (method POST) is sent as usual and brings the person back to the overview
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!asks(form)) {
      return;
    }
    event.preventDefault();
    window.clearTimeout(typing);
    ask(form);
  });

  // … and does so by itself when a value in it changes, so the numbers below (what would be deleted, the text of the
  // button) always belong to what is entered above. Until the new numbers are there, nothing can be sent.
  var typing = null;
  function changed(event) {
    var form = event.target && event.target.form;
    if (!asks(form)) {
      return;
    }
    current.content.querySelectorAll('.dk-run').forEach(function (box) {
      box.classList.add('dk-busy');
    });
    window.clearTimeout(typing);
    typing = window.setTimeout(function () {
      if (asks(form) && form.checkValidity()) {
        ask(form);
      }
    }, event.type === 'change' ? 0 : 450);
  }
  document.addEventListener('input', changed);
  document.addEventListener('change', changed);

  // a form that changes something (method POST) shows at once that the click was taken, and is not sent twice
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (event.defaultPrevented || !form.closest || !form.closest('.dk') || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') {
      return;
    }
    if (form.getAttribute('data-dk-sent')) {
      event.preventDefault();
      return;
    }
    form.setAttribute('data-dk-sent', '1');
    form.querySelectorAll('button[type="submit"], button.formsubmit').forEach(function (button) {
      button.classList.add('loading', 'dk-busy');
      button.setAttribute('aria-busy', 'true');
    });
  });

  // while an action runs in the background, the overview asks where it stands: it shows the progress and loads
  // itself again as soon as something changed (the action started, is through or failed)
  $(function () {
    var box = document.querySelector('[data-dk-state-url]');
    if (!box) {
      return;
    }
    var url = box.getAttribute('data-dk-state-url');
    var token = box.getAttribute('data-dk-state-token');
    var timer = window.setInterval(function () {
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (response) {
          return response.ok ? response.json() : null;
        })
        .then(function (state) {
          if (!state) {
            return;
          }
          (state.pending || []).forEach(function (entry) {
            var place = box.querySelector('[data-dk-progress="' + entry.id + '"]');
            if (place && entry.progress) {
              place.textContent = entry.progress + ' %';
            }
          });
          // not while a dialog is open: whoever is reading it would lose it
          if (state.token !== token && !current) {
            window.clearInterval(timer);
            window.location.replace(window.location.pathname + window.location.search + window.location.hash);
            if (window.location.hash) {
              window.location.reload();
            }
          }
        })
        .catch(function () {
          // the next round tries again
        });
    }, 3000);
  });

  // «?open=<name>» opens the dialog of the link with that name at once (after an action inside a dialog brought the
  // person back to the overview, for example after a file was deleted from the list)
  $(function () {
    var params = new URLSearchParams(window.location.search);
    var name = params.get('open');
    if (!name) {
      return;
    }
    var link = document.querySelector('a[data-dk-dialog][data-dk-name="' + name.replace(/[^a-z0-9-]/gi, '') + '"]');
    if (link) {
      params.delete('open');
      var query = params.toString();
      window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
      open(link.href, link.getAttribute('data-dk-close'));
    }
  });
})();
