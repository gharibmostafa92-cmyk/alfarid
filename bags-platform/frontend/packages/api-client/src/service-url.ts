// Local development services share the machine serving the page, including on LAN devices.
export function resolveServiceUrl(configured: string): string {
  if (typeof window === 'undefined') return configured;
  const hostname = window.location.hostname;
  const parts = hostname?.split('.').map(Number);
  const privateIpv4 = parts?.length === 4 && parts.every(part => Number.isInteger(part) && part >= 0 && part <= 255)
    && (parts[0] === 10 || (parts[0] === 192 && parts[1] === 168) || (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31));
  if (!privateIpv4) return configured;
  try {
    const url = new URL(configured);
    if (!['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)) return configured;
    url.hostname = hostname;
    return url.href.replace(/\/$/, '');
  } catch {
    return configured;
  }
}
