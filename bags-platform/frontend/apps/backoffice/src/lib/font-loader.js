/** Install one shared stylesheet link for a typography family for page lifetime. */
export function ensureStylesheet(id, href) {
  const existing = document.getElementById(id);
  if (existing) return existing;
  const link = document.createElement('link');
  link.id = id;
  link.rel = 'stylesheet';
  link.href = href;
  document.head.append(link);
  return link;
}
