(function (global) {
  'use strict';

  var EVENT_KEY = 'app_realtime_event';
  var CHANNEL_NAME = 'app-realtime-channel';
  var AUTH_USER_KEY = 'authUser';
  var SESSION_KEY = 'userSession';
  var LOGIN_FLAG_KEY = 'isLoggedIn';
  var SCOPE_KEY = 'app_session_scope';

  function getScope() {
    try {
      var scope = sessionStorage.getItem(SCOPE_KEY);
      if (scope) {
        return scope;
      }

      scope = 'tab_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
      sessionStorage.setItem(SCOPE_KEY, scope);
      return scope;
    } catch (e) {
      return 'tab_fallback';
    }
  }

  function readJson(storage, key) {
    try {
      var raw = storage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function normalizeRole(role) {
    var value = String(role || '').trim().toLowerCase();
    if (value === 'admin' || value === 'administrator' || value === 'quantri' || value === 'quan tri' || value === 'quan_tri') {
      return 'admin';
    }

    if (
      value === 'staff' ||
      value === 'nhanvien' ||
      value === 'nhan vien' ||
      value === 'nhan_vien'
    ) {
      return 'staff';
    }

    return 'user';
  }

  function getLoginUrl() {
    var path = String(global.location.pathname || '').toLowerCase();
    if (path.indexOf('/user/pages/') >= 0) {
      return '../../dang-nhap.html';
    }

    if (path.indexOf('/admin/') >= 0 || path.indexOf('/staff/') >= 0 || path.indexOf('/user/') >= 0) {
      return '../dang-nhap.html';
    }

    return 'Giao%20Di%E1%BB%87n/dang-nhap.html';
  }

  function getSession() {
    return readJson(sessionStorage, SESSION_KEY) || readJson(localStorage, SESSION_KEY);
  }

  function getAuthUser() {
    var parsedAuth = readJson(sessionStorage, AUTH_USER_KEY) || readJson(localStorage, AUTH_USER_KEY);
    if (parsedAuth && typeof parsedAuth === 'object') {
      parsedAuth.role = normalizeRole(parsedAuth.role || parsedAuth.vaitronguoidung || parsedAuth.vaitro);
      return parsedAuth;
    }

    var session = getSession();
    var sessionUser = session && session.user ? session.user : null;
    if (!sessionUser) {
      return null;
    }

    return {
      id: Number(sessionUser.id || 0),
      tennguoidung: String(sessionUser.name || sessionUser.tennguoidung || ''),
      emailnguoidung: String(sessionUser.identifier || sessionUser.emailnguoidung || ''),
      role: normalizeRole(sessionUser.role || sessionUser.vaitronguoidung || 'user'),
      identifier: String(sessionUser.identifier || sessionUser.emailnguoidung || '')
    };
  }

  function setSessionLoginFlag(value) {
    try {
      sessionStorage.setItem(LOGIN_FLAG_KEY, JSON.stringify(!!value));
    } catch (e) {
      // ignore
    }

    if (value === true) {
      try {
        localStorage.setItem(LOGIN_FLAG_KEY, JSON.stringify(true));
      } catch (e) {
        // ignore
      }
    }
  }

  function setAuthState(userPayload, token) {
    var normalizedRole = normalizeRole(userPayload.role || userPayload.vaitronguoidung || 'user');
    var authUser = {
      id: Number(userPayload.id || 0),
      tennguoidung: String(userPayload.tennguoidung || userPayload.name || ''),
      emailnguoidung: String(userPayload.emailnguoidung || userPayload.identifier || ''),
      vaitronguoidung: normalizedRole,
      role: normalizedRole,
      identifier: String(userPayload.identifier || userPayload.emailnguoidung || userPayload.tennguoidung || ''),
      loggedAt: new Date().toISOString()
    };

    sessionStorage.setItem(AUTH_USER_KEY, JSON.stringify(authUser));
    sessionStorage.setItem(SESSION_KEY, JSON.stringify({
      loggedIn: true,
      token: String(token || ''),
      user: {
        id: authUser.id,
        name: authUser.tennguoidung,
        identifier: authUser.identifier,
        role: normalizedRole
      },
      loggedAt: authUser.loggedAt
    }));
    setSessionLoginFlag(true);

    return authUser;
  }

  function clearAuthState() {
    sessionStorage.removeItem(AUTH_USER_KEY);
    sessionStorage.removeItem(SESSION_KEY);
    setSessionLoginFlag(false);
  }

  function getCurrentRole() {
    var user = getAuthUser();
    return user ? normalizeRole(user.role) : 'guest';
  }

  function isLoggedIn() {
    var user = getAuthUser();
    if (user) {
      return true;
    }

    try {
      var raw = sessionStorage.getItem(LOGIN_FLAG_KEY);
      if (raw) {
        return JSON.parse(raw) === true;
      }

      raw = localStorage.getItem(LOGIN_FLAG_KEY);
      return raw ? JSON.parse(raw) === true : false;
    } catch (e) {
      return false;
    }
  }

  function publish(type, data) {
    var payload = {
      type: String(type || ''),
      data: data || {},
      source: 'web',
      scope: getScope(),
      timestamp: Date.now()
    };

    try {
      localStorage.setItem(EVENT_KEY, JSON.stringify(payload));
    } catch (e) {
      // ignore storage errors
    }

    if ('BroadcastChannel' in global) {
      try {
        var channel = new BroadcastChannel(CHANNEL_NAME);
        channel.postMessage(payload);
        channel.close();
      } catch (e) {
        // ignore channel errors
      }
    }

    return payload;
  }

  function subscribe(callback) {
    if (typeof callback !== 'function') {
      return function () {};
    }

    var onStorage = function (event) {
      if (event.key !== EVENT_KEY || !event.newValue) {
        return;
      }

      try {
        var payload = JSON.parse(event.newValue);
        if (payload && payload.type === 'auth_logout' && payload.scope !== getScope()) {
          return;
        }
        callback(payload);
      } catch (e) {
        // ignore invalid payload
      }
    };

    global.addEventListener('storage', onStorage);

    var channel = null;
    if ('BroadcastChannel' in global) {
      try {
        channel = new BroadcastChannel(CHANNEL_NAME);
        channel.onmessage = function (event) {
          var payload = event.data || {};
          if (payload && payload.type === 'auth_logout' && payload.scope !== getScope()) {
            return;
          }
          callback(payload);
        };
      } catch (e) {
        channel = null;
      }
    }

    return function () {
      global.removeEventListener('storage', onStorage);
      if (channel) {
        channel.close();
      }
    };
  }

  function requireRole(allowedRoles) {
    var roles = Array.isArray(allowedRoles) ? allowedRoles.map(normalizeRole) : [];
    var currentRole = getCurrentRole();

    if (!isLoggedIn()) {
      global.location.href = getLoginUrl();
      return false;
    }

    if (roles.length > 0 && roles.indexOf(currentRole) === -1) {
      if (currentRole === 'admin') {
        global.location.href = '../admin/admin.html';
        return false;
      }

      if (currentRole === 'staff') {
        global.location.href = '../staff/admin.html';
        return false;
      }

      global.location.href = '../user/home.html';
      return false;
    }

    return true;
  }

  function logout() {
    clearAuthState();
    global.location.href = getLoginUrl();
  }

  function bindDefaultActions(root) {
    var container = root || document;
    container.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-app-action]');
      if (!trigger) {
        return;
      }

      var action = String(trigger.getAttribute('data-app-action') || '').trim();
      if (action === 'logout') {
        event.preventDefault();
        if (global.confirm('Ban co chac chan muon dang xuat?')) {
          logout();
        }
        return;
      }

      if (action === 'refresh') {
        event.preventDefault();
        publish('manual_refresh', { path: global.location.pathname });
      }
    });
  }

  global.AppSessionRealtime = {
    normalizeRole: normalizeRole,
    getAuthUser: getAuthUser,
    setAuthState: setAuthState,
    clearAuthState: clearAuthState,
    getCurrentRole: getCurrentRole,
    isLoggedIn: isLoggedIn,
    publish: publish,
    subscribe: subscribe,
    requireRole: requireRole,
    logout: logout,
    bindDefaultActions: bindDefaultActions
  };
})(window);
