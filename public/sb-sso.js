/*!
 * Scarlet Beast estate SSO widget.
 * Drop this on ANY *.scarletbeast.com page (static, forum, anywhere):
 *   <script src="https://poker.scarletbeast.com/sb-sso.js" defer></script>
 *
 * It asks the identity authority (poker.scarletbeast.com) who holds the shared
 * .scarletbeast.com session and reflects it into the page:
 *   - elements with [data-sso-name]   ← filled with the user's name
 *   - elements with [data-sso-avatar] ← filled with the user's avatar glyph
 *   - elements with [data-sso-in]      shown only when signed in
 *   - elements with [data-sso-out]     shown only when signed out
 *   - a global  window.SBSSO = { user, authenticated, login(), logout() }
 * If the page has no hooks, it injects a small fixed-corner chip automatically.
 */
(function () {
  var AUTH = 'https://poker.scarletbeast.com';
  var LOGIN = AUTH + '/login?return=' + encodeURIComponent(location.href);

  function apply(state) {
    window.SBSSO = {
      authenticated: state.authenticated,
      user: state.user,
      login: function () { location.href = LOGIN; },
      logout: function () { location.href = AUTH + '/logout?return=' + encodeURIComponent(location.href); },
    };
    var u = state.user || {};
    document.querySelectorAll('[data-sso-name]').forEach(function (el) { el.textContent = u.name || u.username || ''; });
    document.querySelectorAll('[data-sso-avatar]').forEach(function (el) { el.textContent = u.avatar || ''; });
    document.querySelectorAll('[data-sso-in]').forEach(function (el) { el.style.display = state.authenticated ? '' : 'none'; });
    document.querySelectorAll('[data-sso-out]').forEach(function (el) { el.style.display = state.authenticated ? 'none' : ''; });

    var hasHooks = document.querySelector('[data-sso-in],[data-sso-out],[data-sso-name]');
    if (!hasHooks) injectChip(state);
    document.dispatchEvent(new CustomEvent('sb-sso', { detail: state }));
  }

  function injectChip(state) {
    var c = document.createElement('div');
    c.style.cssText = 'position:fixed;top:12px;right:12px;z-index:99999;font:600 13px/1 ui-monospace,Menlo,Consolas,monospace;' +
      'background:#0a0707;color:#ede8df;border:1px solid rgba(255,36,24,.4);border-radius:10px;padding:8px 12px;' +
      'box-shadow:0 6px 22px rgba(0,0,0,.5);display:flex;align-items:center;gap:8px;text-decoration:none;cursor:pointer';
    if (state.authenticated) {
      c.innerHTML = '<span style="font-size:16px">' + (state.user.avatar || '👤') + '</span><span>' +
        (state.user.name || state.user.username) + '</span>';
      c.title = 'Signed in across the Scarlet Beast estate';
      c.onclick = function () { location.href = AUTH + '/networkedin'; };
    } else {
      c.textContent = '⛧ Sign in';
      c.onclick = function () { location.href = LOGIN; };
    }
    document.body.appendChild(c);
  }

  function boot() {
    fetch(AUTH + '/api/sso/whoami', { credentials: 'include' })
      .then(function (r) { return r.json(); })
      .then(apply)
      .catch(function () { apply({ authenticated: false, user: null }); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
