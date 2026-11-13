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
    dialog.url = url;
    dialog.content.classList.add('dk-dialog-loading');
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
        if (current !== dialog) {
          return;
        }
        dialog.content.innerHTML = html;
        dialog.content.classList.remove('dk-dialog-loading');
        dialog.modal.updateSizeAndPosition();
      })
      .catch(function () {
        // whatever went wrong: the page itself still works
        window.location.href = url;
      });
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
    if (!current || !current.content.contains(form) || (form.getAttribute('method') || 'get').toLowerCase() !== 'get') {
      return;
    }
    event.preventDefault();
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
    load(url.toString());
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
