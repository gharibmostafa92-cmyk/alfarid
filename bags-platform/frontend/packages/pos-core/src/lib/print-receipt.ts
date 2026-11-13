export function printReceipt(receipt: HTMLElement | null): void {
  if (!receipt) return;
  const host = document.createElement('div');
  host.className = 'print-receipt-host';
  host.dir = 'rtl';
  host.setAttribute('aria-hidden', 'true');
  host.appendChild(receipt.cloneNode(true));
  document.body.appendChild(host);

  const cleanup = () => {
    clearTimeout(timer);
    window.removeEventListener('afterprint', cleanup);
    host.remove();
  };

  window.addEventListener('afterprint', cleanup, { once: true });
  const timer = setTimeout(cleanup, 60_000);

  try {
    window.print();
  } catch {
    cleanup();
  }
}
