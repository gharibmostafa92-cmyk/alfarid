const targets = [
  ['api', 'http://localhost:8000/api/v1/store/branding'],
  ['office', 'http://localhost:5173/'],
  ['web', 'http://localhost:3001/auth/login'],
];
(async () => {
  for (const [name, url] of targets) {
    try {
      const r = await fetch(url);
      console.log(name + ': ' + r.status);
    } catch (e) {
      console.log(name + ' DOWN ' + (e.cause?.code || e.message));
    }
  }
})();
