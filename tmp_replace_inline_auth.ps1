$path = '.\Giao Diện\user\home.html'
$content = Get-Content -Path $path -Raw -Encoding Unicode

$replacement = @'
<script>
  (function () {
    const AUTH_STORAGE_KEYS = {
      authUser: 'authUser',
      userSession: 'userSession',
      isLoggedIn: 'isLoggedIn',
      pendingAuthPrompt: 'pendingAuthPromptV2'
    };

    const AUTH_MODAL_ID = 'userInlineAuthModal';
    const AUTH_STYLE_ID = 'userInlineAuthStyle';

    const hasCustomerSession = () => {
      try {
        if (sessionStorage.getItem(AUTH_STORAGE_KEYS.authUser)) return true;
      } catch (e) {}

      try {
        if (localStorage.getItem(AUTH_STORAGE_KEYS.userSession)) return true;
      } catch (e) {}

      return localStorage.getItem(AUTH_STORAGE_KEYS.isLoggedIn) === 'true';
    };

    const saveCustomerSession = (payload) => {
      const basePayload = {
        role: 'user',
        fullName: payload.fullName || payload.email || 'Khach hang',
        email: payload.email || '',
        createdAt: new Date().toISOString()
      };

      try {
        sessionStorage.setItem(AUTH_STORAGE_KEYS.authUser, JSON.stringify(basePayload));
      } catch (e) {}

      try {
        localStorage.setItem(AUTH_STORAGE_KEYS.userSession, JSON.stringify(basePayload));
      } catch (e) {}

      localStorage.setItem(AUTH_STORAGE_KEYS.isLoggedIn, 'true');
      return basePayload;
    };

    const ensureAuthStyle = () => {
      if (document.getElementById(AUTH_STYLE_ID)) return;

      const style = document.createElement('style');
      style.id = AUTH_STYLE_ID;
      style.textContent =
        '.u-auth-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:3000;padding:16px;}' +
        '.u-auth-card{width:min(420px,100%);background:#fff;border-radius:14px;box-shadow:0 24px 60px rgba(15,23,42,.22);overflow:hidden;font-family:Arial,sans-serif;}' +
        '.u-auth-head{padding:16px 20px 10px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;gap:12px;}' +
        '.u-auth-title{margin:0;font-size:18px;font-weight:700;color:#1f2937;}' +
        '.u-auth-close{border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:#6b7280;padding:4px;}' +
        '.u-auth-body{padding:18px 20px 20px;}' +
        '.u-auth-msg{margin:0 0 12px;color:#4b5563;font-size:13px;}' +
        '.u-auth-group{margin:0 0 10px;}' +
        '.u-auth-label{display:block;margin-bottom:6px;font-size:13px;color:#374151;font-weight:600;}' +
        '.u-auth-input{width:100%;height:42px;border:1px solid #d1d5db;border-radius:10px;padding:0 12px;font-size:14px;outline:none;}' +
        '.u-auth-input:focus{border-color:#f87537;box-shadow:0 0 0 3px rgba(248,117,55,.18);}' +
        '.u-auth-submit{width:100%;height:44px;border:none;border-radius:10px;background:linear-gradient(135deg,#f87537 0%,#fba81f 100%);color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:8px;}' +
        '.u-auth-switch{margin-top:10px;font-size:13px;color:#4b5563;text-align:center;}' +
        '.u-auth-link{color:#f87537;font-weight:700;cursor:pointer;text-decoration:none;}' +
        '.u-auth-error{display:none;margin-bottom:10px;padding:8px 10px;border-radius:8px;background:#fef2f2;color:#b91c1c;font-size:13px;}';
      document.head.appendChild(style);
    };

    const ensureAuthModal = () => {
      if (document.getElementById(AUTH_MODAL_ID)) return;

      const shell = document.createElement('div');
      shell.id = AUTH_MODAL_ID;
      shell.className = 'u-auth-overlay';
      shell.innerHTML =
        '<div class="u-auth-card" role="dialog" aria-modal="true" aria-labelledby="uAuthTitle">' +
          '<div class="u-auth-head">' +
            '<h3 id="uAuthTitle" class="u-auth-title">Dang nhap tai khoan</h3>' +
            '<button type="button" class="u-auth-close" id="uAuthClose" aria-label="Dong">&times;</button>' +
          '</div>' +
          '<div class="u-auth-body">' +
            '<p class="u-auth-msg" id="uAuthReason">Vui long dang nhap de tiep tuc.</p>' +
            '<div class="u-auth-error" id="uAuthError"></div>' +
            '<form id="uAuthForm">' +
              '<div class="u-auth-group">' +
                '<label class="u-auth-label" for="uAuthName">Ho va ten</label>' +
                '<input class="u-auth-input" id="uAuthName" type="text" autocomplete="name" placeholder="Nguyen Van A">' +
              '</div>' +
              '<div class="u-auth-group">' +
                '<label class="u-auth-label" for="uAuthEmail">Email</label>' +
                '<input class="u-auth-input" id="uAuthEmail" type="email" required autocomplete="email" placeholder="ban@example.com">' +
              '</div>' +
              '<div class="u-auth-group">' +
                '<label class="u-auth-label" for="uAuthPassword">Mat khau</label>' +
                '<input class="u-auth-input" id="uAuthPassword" type="password" required minlength="6" autocomplete="current-password" placeholder="Nhap mat khau">' +
              '</div>' +
              '<button type="submit" class="u-auth-submit" id="uAuthSubmit">Dang nhap</button>' +
            '</form>' +
            '<div class="u-auth-switch">Chua co tai khoan? <a href="#" class="u-auth-link" id="uAuthModeToggle">Dang ky</a></div>' +
          '</div>' +
        '</div>';

      document.body.appendChild(shell);

      const closeBtn = shell.querySelector('#uAuthClose');
      closeBtn.addEventListener('click', () => {
        shell.style.display = 'none';
      });

      shell.addEventListener('click', (event) => {
        if (event.target === shell) {
          shell.style.display = 'none';
        }
      });
    };

    const getAuthRefs = () => {
      const modal = document.getElementById(AUTH_MODAL_ID);
      return {
        modal,
        title: modal.querySelector('#uAuthTitle'),
        reason: modal.querySelector('#uAuthReason'),
        error: modal.querySelector('#uAuthError'),
        form: modal.querySelector('#uAuthForm'),
        name: modal.querySelector('#uAuthName'),
        email: modal.querySelector('#uAuthEmail'),
        password: modal.querySelector('#uAuthPassword'),
        submit: modal.querySelector('#uAuthSubmit'),
        modeToggle: modal.querySelector('#uAuthModeToggle')
      };
    };

    const showAuthError = (refs, message) => {
      refs.error.textContent = message;
      refs.error.style.display = 'block';
    };

    const clearAuthError = (refs) => {
      refs.error.textContent = '';
      refs.error.style.display = 'none';
    };

    const openAuthModal = (mode, options) => {
      ensureAuthStyle();
      ensureAuthModal();

      const refs = getAuthRefs();
      const authMode = mode === 'register' ? 'register' : 'login';
      const reason = options && options.reason ? options.reason : 'Vui long dang nhap de tiep tuc.';
      const postLoginAction = options && typeof options.postLoginAction === 'function' ? options.postLoginAction : null;

      refs.title.textContent = authMode === 'register' ? 'Dang ky tai khoan' : 'Dang nhap tai khoan';
      refs.reason.textContent = reason;
      refs.submit.textContent = authMode === 'register' ? 'Dang ky' : 'Dang nhap';
      refs.modeToggle.textContent = authMode === 'register' ? 'Dang nhap' : 'Dang ky';
      refs.modeToggle.dataset.mode = authMode === 'register' ? 'login' : 'register';

      refs.modal.style.display = 'flex';
      refs.form.dataset.mode = authMode;
      clearAuthError(refs);
      refs.form.reset();
      refs.name.focus();

      refs.modeToggle.onclick = (event) => {
        event.preventDefault();
        openAuthModal(refs.modeToggle.dataset.mode, {
          reason,
          postLoginAction
        });
      };

      refs.form.onsubmit = (event) => {
        event.preventDefault();
        clearAuthError(refs);

        const fullName = refs.name.value.trim();
        const email = refs.email.value.trim();
        const password = refs.password.value;

        if (!email) {
          showAuthError(refs, 'Vui long nhap email.');
          return;
        }

        if (password.length < 6) {
          showAuthError(refs, 'Mat khau phai tu 6 ky tu tro len.');
          return;
        }

        saveCustomerSession({ fullName, email });
        refs.modal.style.display = 'none';
        localStorage.removeItem(AUTH_STORAGE_KEYS.pendingAuthPrompt);

        if (postLoginAction) {
          postLoginAction();
        } else if (typeof window.routeToPage === 'function') {
          window.routeToPage('user');
        } else if (typeof window.loadPage === 'function') {
          window.loadPage('user');
        }
      };
    };

    window.openAuthModal = openAuthModal;

    const openInlineLogin = () => {
      window.openAuthModal('login', {
        reason: 'Vui long dang nhap de truy cap trang khach hang',
        postLoginAction: () => {
          if (typeof window.routeToPage === 'function') {
            window.routeToPage('user');
          } else if (typeof window.loadPage === 'function') {
            window.loadPage('user');
          }
        }
      });
    };

    const bindHeaderUserIcon = () => {
      const icon = document.getElementById('headerUserIcon');
      if (!icon || icon.dataset.inlineAuthBound === '1') {
        return;
      }

      icon.dataset.inlineAuthBound = '1';
      icon.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        if (hasCustomerSession()) {
          if (typeof window.routeToPage === 'function') {
            window.routeToPage('user');
          } else if (typeof window.loadPage === 'function') {
            window.loadPage('user');
          }
          return;
        }

        openInlineLogin();
      }, true);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bindHeaderUserIcon);
    } else {
      bindHeaderUserIcon();
    }
  })();
</script>
</body>
'@

$pattern = '(?s)<script\s+src="inline-auth-v2\.js"></script>\s*<script>\s*\(function\s*\(\)\s*\{.*?\}\)\(\);\s*</script>\s*</body>'
$newContent = [regex]::Replace($content, $pattern, $replacement)
if ($newContent -eq $content) {
  throw 'No replacement was made. Pattern not found.'
}

Set-Content -Path $path -Value $newContent -Encoding Unicode
Write-Output 'Replaced external auth script with inline auth block.'
