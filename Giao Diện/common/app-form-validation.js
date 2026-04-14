(function (global) {
  'use strict';

  if (global.AppFormValidator) {
    return;
  }

  var STYLE_ID = 'app-form-validator-style';
  var POPUP_ID = 'app-form-validator-popup';

  function normalizeText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function normalizePhone(value) {
    return String(value || '').replace(/\D/g, '');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escapeSelector(value) {
    var text = String(value || '');
    if (global.CSS && typeof global.CSS.escape === 'function') {
      return global.CSS.escape(text);
    }
    return text.replace(/([ #;?%&,.+*~\':\"!^$\[\]()=>|\/@])/g, '\\$1');
  }

  function detectRule(input) {
    if (!input || !input.tagName) {
      return null;
    }

    var type = String(input.getAttribute('type') || input.type || '').toLowerCase();
    var hint = [
      input.id || '',
      input.name || '',
      input.placeholder || '',
      input.getAttribute('aria-label') || ''
    ].join(' ').toLowerCase();

    if (type === 'email' || hint.indexOf('email') >= 0 || hint.indexOf('gmail') >= 0) {
      return 'email';
    }

    if (
      type === 'tel' ||
      hint.indexOf('phone') >= 0 ||
      hint.indexOf('sdt') >= 0 ||
      hint.indexOf('so dien thoai') >= 0 ||
      hint.indexOf('sodienthoai') >= 0 ||
      hint.indexOf('dien thoai') >= 0
    ) {
      return 'phone';
    }

    return null;
  }

  function getFieldLabel(doc, input) {
    if (!input) {
      return 'Trường dữ liệu';
    }

    var labelText = '';

    if (input.id) {
      var explicitLabel = doc.querySelector('label[for="' + escapeSelector(input.id) + '"]');
      if (explicitLabel) {
        labelText = normalizeText(explicitLabel.textContent || '');
      }
    }

    if (!labelText) {
      var wrappingLabel = input.closest('label');
      if (wrappingLabel) {
        labelText = normalizeText(wrappingLabel.textContent || '');
      }
    }

    if (!labelText) {
      labelText = normalizeText(input.getAttribute('aria-label') || input.placeholder || input.name || input.id || 'Trường dữ liệu');
    }

    return labelText || 'Trường dữ liệu';
  }

  function validateInput(doc, input, options) {
    var opts = options || {};
    if (!input || input.disabled || input.readOnly) {
      return null;
    }

    var rawValue = String(input.value || '');
    var value = normalizeText(rawValue);
    var label = getFieldLabel(doc, input);

    if (!opts.skipRequired && input.required && value === '') {
      return {
        field: label,
        detail: 'Không được để trống.',
        guide: 'Vui lòng nhập đầy đủ thông tin bắt buộc trước khi lưu.'
      };
    }

    if (value === '') {
      return null;
    }

    var rule = detectRule(input);
    if (rule === 'phone') {
      var digits = normalizePhone(value);
      if (!/^0\d{9}$/.test(digits)) {
        return {
          field: label,
          detail: 'Số điện thoại không hợp lệ.',
          guide: 'Số điện thoại phải bắt đầu bằng số 0 và có đúng 10 chữ số. Ví dụ: 0912345678.'
        };
      }
    }

    if (rule === 'email') {
      var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
      if (!emailPattern.test(value)) {
        return {
          field: label,
          detail: 'Email không đúng định dạng.',
          guide: 'Email phải có ký tự @ và tên miền hợp lệ. Ví dụ: ten@gmail.com.'
        };
      }
    }

    return null;
  }

  function ensurePopup(doc) {
    if (!doc.getElementById(STYLE_ID)) {
      var style = doc.createElement('style');
      style.id = STYLE_ID;
      style.textContent = '' +
        '.afv-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;display:none;align-items:center;justify-content:center;padding:16px;}' +
        '.afv-backdrop.show{display:flex;}' +
        '.afv-dialog{width:min(500px,96vw);background:#fff;border:1px solid #d9eee1;border-radius:16px;box-shadow:0 20px 50px rgba(15,23,42,.28);overflow:hidden;font-family:inherit;}' +
        '.afv-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;}' +
        '.afv-title{margin:0;font-size:17px;font-weight:700;color:#0f172a;}' +
        '.afv-close{border:0;width:30px;height:30px;border-radius:8px;background:#e2e8f0;color:#1f2937;font-size:20px;line-height:1;cursor:pointer;}' +
        '.afv-body{padding:16px;display:grid;gap:10px;}' +
        '.afv-line{margin:0;color:#334155;font-size:14px;line-height:1.6;}' +
        '.afv-line strong{color:#0f172a;}' +
        '.afv-actions{padding:0 16px 16px;display:flex;justify-content:flex-end;gap:8px;}' +
        '.afv-ok{border-radius:10px;font-size:13px;font-weight:700;padding:8px 14px;cursor:pointer;border:1px solid #16a34a;background:#16a34a;color:#fff;}' +
        '.afv-input-error{border-color:#dc2626 !important;box-shadow:0 0 0 2px rgba(220,38,38,.13) !important;}';
      doc.head.appendChild(style);
    }

    var root = doc.getElementById(POPUP_ID);
    if (root) {
      return root;
    }

    root = doc.createElement('div');
    root.id = POPUP_ID;
    root.className = 'afv-backdrop';
    root.setAttribute('hidden', 'hidden');
    root.innerHTML = '' +
      '<div class="afv-dialog" role="alertdialog" aria-modal="true" aria-labelledby="afvTitle">' +
      '  <div class="afv-head">' +
      '    <h4 id="afvTitle" class="afv-title">Thông tin chưa hợp lệ</h4>' +
      '  </div>' +
      '  <div class="afv-body">' +
      '    <p class="afv-line" id="afvDetail"></p>' +
      '    <p class="afv-line" id="afvGuide"></p>' +
      '  </div>' +
      '  <div class="afv-actions">' +
      '    <button type="button" class="afv-ok" data-afv-close>Đóng</button>' +
      '  </div>' +
      '</div>';

    doc.body.appendChild(root);

    function closePopup() {
      root.classList.remove('show');
      root.setAttribute('hidden', 'hidden');
    }

    root.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-afv-close]');
      if (trigger) {
        closePopup();
      }
    });

    root.__close = closePopup;
    return root;
  }

  function showPopup(doc, payload) {
    var popup = ensurePopup(doc);
    var detail = popup.querySelector('#afvDetail');
    var guide = popup.querySelector('#afvGuide');

    if (detail) {
      detail.innerHTML = '<strong>Lỗi:</strong> ' + escapeHtml(payload.field || 'Trường dữ liệu') + ' - ' + escapeHtml(payload.detail || 'Không hợp lệ.');
    }

    if (guide) {
      guide.innerHTML = '<strong>Nhập đúng:</strong> ' + escapeHtml(payload.guide || 'Vui lòng kiểm tra lại định dạng dữ liệu.');
    }

    popup.removeAttribute('hidden');
    popup.classList.add('show');

    var okBtn = popup.querySelector('.afv-ok');
    if (okBtn) {
      okBtn.focus();
    }
  }

  function setFieldError(input, hasError) {
    if (!input || !input.classList) {
      return;
    }
    input.classList.toggle('afv-input-error', !!hasError);
  }

  function bindDocument(doc) {
    if (!doc || doc.__appFormValidatorBound === true) {
      return;
    }

    doc.__appFormValidatorBound = true;

    doc.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.querySelectorAll) {
        return;
      }

      var fields = form.querySelectorAll('input, textarea, select');
      for (var i = 0; i < fields.length; i += 1) {
        var input = fields[i];
        var error = validateInput(doc, input, { skipRequired: false });
        setFieldError(input, !!error);

        if (error) {
          event.preventDefault();
          event.stopPropagation();
          showPopup(doc, error);
          try {
            input.focus();
          } catch (e) {
            // ignore focus errors
          }
          return;
        }
      }
    }, true);

    doc.addEventListener('blur', function (event) {
      var input = event.target;
      if (!input || !input.matches || !input.matches('input, textarea, select')) {
        return;
      }
      var error = validateInput(doc, input, { skipRequired: true });
      setFieldError(input, !!error);
    }, true);

    doc.addEventListener('input', function (event) {
      var input = event.target;
      if (!input || !input.matches || !input.matches('input, textarea, select')) {
        return;
      }
      if (input.classList.contains('afv-input-error')) {
        var error = validateInput(doc, input, { skipRequired: true });
        setFieldError(input, !!error);
      }
    }, true);
  }

  global.AppFormValidator = {
    attachToDocument: bindDocument
  };

  if (global.document) {
    bindDocument(global.document);
  }
})(window);
