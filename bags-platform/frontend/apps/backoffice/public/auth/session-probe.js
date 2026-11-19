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
      var hashParams = new URLSearchParams(window.location.hash.slice(1));
      try {
        var parsedParent = new URL(parentOrigin);
        if (
          parsedParent.protocol === 'http:' &&
          (parsedParent.hostname === 'localhost' || parsedParent.hostname === '127.0.0.1' || parsedParent.hostname === '[::1]')
        ) {
          hashParams.set('origin', parentOrigin);
        }
      } catch (e) {
        // Ignore unparseable parent origin.
      }
      window.location.replace('/auth/session#' + hashParams.toString());
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
