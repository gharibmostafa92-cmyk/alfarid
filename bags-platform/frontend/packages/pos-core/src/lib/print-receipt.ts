export function printReceipt(receipt: HTMLElement | null): void {
  if (!receipt) return;
  const host = document.createElement('div');
  host.className = 'print-receipt-host';
  host.dir = 'rtl';
  host.setAttribute('aria-hidden', 'true');
  host.appendChild(receipt.cloneNode(true));
  document.body.appendChild(host);
  try {
    window.print();
  } finally {
    host.remove();
  }
}
