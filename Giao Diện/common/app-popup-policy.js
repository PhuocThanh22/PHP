(function (global) {
  'use strict';

  if (global.AppPopupPolicy) {
    return;
  }

  var STYLE_ID = 'app-popup-policy-style';

  function ensureStyles(doc) {
    if (!doc || !doc.head || doc.getElementById(STYLE_ID)) {
      return;
    }

    var style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = '' +
      '.btn-close,' +
      '.host-popup-close,' +
      '.customer-confirm-close,' +
      '.home-overlay-close,' +
      '[class*="close"][aria-label="Đóng"]{' +
      'display:none !important;' +
      'visibility:hidden !important;' +
      'opacity:0 !important;' +
      'pointer-events:none !important;' +
      '}' +
      '.modal{overflow-y:auto;}' +
      '.app-popup-policy-actions{display:flex;justify-content:flex-end;gap:8px;padding:12px 14px 14px;}' +
      '.app-popup-policy-close-btn{border:1px solid #cbd5e1;background:#ffffff;color:#475569;border-radius:10px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;line-height:1.2;}';

    doc.head.appendChild(style);
  }

  function hardenBootstrapModals(doc) {
    if (!doc || !doc.querySelectorAll) {
      return;
    }

    var modals = doc.querySelectorAll('.modal');
    modals.forEach(function (modal) {
      modal.setAttribute('data-bs-backdrop', 'static');
      modal.setAttribute('data-bs-keyboard', 'false');
      modal.dataset.bsBackdrop = 'static';
      modal.dataset.bsKeyboard = 'false';
    });
  }

  function isBlockedBackdrop(target) {
    if (!target || !target.matches) {
      return false;
    }

    if (target.closest('button, a, input, select, textarea, label, [role="button"], [data-popup-action], [data-close], [data-dismiss]')) {
      return false;
    }

    var className = String(target.className || '').toLowerCase();
    var idName = String(target.id || '').toLowerCase();

    var hasModalLikeClass = /modal|overlay|backdrop/.test(className);
    var hasModalLikeId = /modal|overlay|backdrop/.test(idName);

    if (hasModalLikeClass || hasModalLikeId) {
      return true;
    }

    return target.matches(
      '.modal, .modal-backdrop, .host-popup-backdrop, .customer-confirm-backdrop, .home-overlay, .afv-backdrop, ' +
      '.voucher-hunt-backdrop, .user-notice-overlay, .booking-popup-overlay, .u-auth-overlay, .featured-info-modal-overlay, .notify-popup-backdrop'
    );
  }

  function bindNoBackdropClose(doc) {
    if (!doc || doc.__appPopupPolicyBound === true) {
      return;
    }

    doc.__appPopupPolicyBound = true;

    var stopBackdropClose = function (event) {
      var target = event.target;
      if (!target) {
        return;
      }

      if (!isBlockedBackdrop(target)) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
    };

    doc.addEventListener('click', stopBackdropClose, true);
    doc.addEventListener('mousedown', stopBackdropClose, true);
    doc.addEventListener('pointerdown', stopBackdropClose, true);

    doc.addEventListener('click', function (event) {
      var closeBtn = event.target && event.target.closest ? event.target.closest('[data-app-popup-close]') : null;
      if (!closeBtn) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      closePopupFromTrigger(closeBtn);
    }, true);
  }

  function hasCloseAction(dialog) {
    if (!dialog || !dialog.querySelectorAll) {
      return false;
    }

    var actions = dialog.querySelectorAll('button, [role="button"], a');
    for (var i = 0; i < actions.length; i += 1) {
      var action = actions[i];
      if (action.hasAttribute('data-app-popup-close')) {
        return true;
      }

      var text = String(action.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (
        text === 'đóng' ||
        text === 'hủy' ||
        text === 'huy' ||
        text === 'cancel' ||
        text === 'close' ||
        text === 'đồng ý' ||
        text === 'dong y' ||
        text === 'đã hiểu' ||
        text === 'da hieu' ||
        text === 'tiếp tục mua sắm' ||
        text === 'tiep tuc mua sam' ||
        text === 'quay về trang chủ' ||
        text === 'quay ve trang chu'
      ) {
        return true;
      }
    }

    return false;
  }

  function findFooterContainer(dialog) {
    if (!dialog || !dialog.querySelector) {
      return null;
    }

    return dialog.querySelector(
      '.modal-footer, .host-popup-footer, .service-modal-footer, .home-overlay-footer, .checkout-success-actions, ' +
      '.review-modal-actions, .customer-confirm-actions, .afv-actions, .voucher-head-actions'
    );
  }

  function findPopupRoot(startNode) {
    var node = startNode;
    var lastMatch = null;

    while (node && node.nodeType === 1 && node.tagName !== 'BODY' && node.tagName !== 'HTML') {
      var className = String(node.className || '').toLowerCase();
      var idName = String(node.id || '').toLowerCase();
      var role = String(node.getAttribute('role') || '').toLowerCase();
      var ariaModal = String(node.getAttribute('aria-modal') || '').toLowerCase() === 'true';

      if (
        /modal|overlay|backdrop|popup|dialog/.test(className) ||
        /modal|overlay|backdrop|popup|dialog/.test(idName) ||
        role === 'dialog' ||
        role === 'alertdialog' ||
        ariaModal
      ) {
        lastMatch = node;
      }

      node = node.parentElement;
    }

    return lastMatch || startNode;
  }

  function closePopupFromTrigger(trigger) {
    if (!trigger || !trigger.ownerDocument) {
      return;
    }

    var doc = trigger.ownerDocument;
    var root = findPopupRoot(trigger.closest('[role="dialog"], [role="alertdialog"], [aria-modal="true"], .modal, .service-modal, .order-detail-modal, .checkout-success-modal, .host-popup-backdrop, .home-overlay, .voucher-hunt-backdrop, .customer-confirm-backdrop, .notify-popup-backdrop') || trigger.parentElement);
    if (!root) {
      return;
    }

    root.classList.remove('show');
    root.classList.remove('open');
    root.classList.remove('active');
    root.setAttribute('aria-hidden', 'true');
    if (root.hasAttribute('hidden')) {
      root.hidden = true;
    }
    if (root.style && root.style.display && root.style.display !== 'none') {
      root.style.display = 'none';
    }

    doc.documentElement.classList.remove('checkout-modal-open');
    doc.body.classList.remove('checkout-modal-open');

    try {
      if (doc.defaultView && typeof doc.defaultView.__unlockBackgroundScroll === 'function') {
        doc.defaultView.__unlockBackgroundScroll();
      }
    } catch (e) {
      // ignore scroll unlock failures
    }
  }

  function ensureCloseButtons(doc) {
    if (!doc || !doc.querySelectorAll) {
      return;
    }

    var dialogs = doc.querySelectorAll('[role="dialog"], [role="alertdialog"], [aria-modal="true"]');
    dialogs.forEach(function (dialog) {
      if (!dialog || dialog.dataset.appPopupCloseInjected === '1') {
        return;
      }

      if (dialog.getAttribute('data-app-popup-skip-auto-close') === '1') {
        dialog.dataset.appPopupCloseInjected = '1';
        return;
      }

      if (hasCloseAction(dialog)) {
        dialog.dataset.appPopupCloseInjected = '1';
        return;
      }

      var footer = findFooterContainer(dialog);
      if (!footer) {
        footer = doc.createElement('div');
        footer.className = 'app-popup-policy-actions';
        dialog.appendChild(footer);
      }

      var closeBtn = doc.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'app-popup-policy-close-btn';
      closeBtn.textContent = 'Đóng';
      closeBtn.setAttribute('data-app-popup-close', '1');
      footer.appendChild(closeBtn);

      dialog.dataset.appPopupCloseInjected = '1';
    });
  }

  function watchModalMutations(doc) {
    if (!doc || !doc.body || doc.__appPopupPolicyObserver) {
      return;
    }

    var observer = new MutationObserver(function () {
      hardenBootstrapModals(doc);
      ensureStyles(doc);
      ensureCloseButtons(doc);
    });

    observer.observe(doc.body, {
      childList: true,
      subtree: true
    });

    doc.__appPopupPolicyObserver = observer;
  }

  function attachToDocument(doc) {
    if (!doc) {
      return;
    }

    ensureStyles(doc);
    hardenBootstrapModals(doc);
    bindNoBackdropClose(doc);
    ensureCloseButtons(doc);
    watchModalMutations(doc);
  }

  global.AppPopupPolicy = {
    attachToDocument: attachToDocument
  };

  if (global.document) {
    attachToDocument(global.document);
  }
})(window);
