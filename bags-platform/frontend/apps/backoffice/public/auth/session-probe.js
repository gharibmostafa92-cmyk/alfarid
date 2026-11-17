(function () {
  if (window.parent === window) return;

  var nonce = new URLSearchParams(window.location.hash.slice(1)).get('nonce');
  var parentOrigin;
  try {
    parentOrigin = new URL(document.referrer).origin;
  } catch (error) {
    return;
  }
  if (!nonce || !parentOrigin || parentOrigin === 'null') return;

  try {
    if (localStorage.getItem('backoffice_token')) {
      window.location.replace('/auth/session' + window.location.hash);
      return;
    }
  } catch (error) {
    // An unavailable storage area is indistinguishable from no staff session.
  }

  window.parent.postMessage({
    type: 'alfarid:staff-session',
    nonce: nonce,
    authenticated: false,
    path: null,
  }, parentOrigin);
})();
