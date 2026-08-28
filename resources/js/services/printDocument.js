const escapeHtml = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('"', '&quot;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;');

const cloneStyles = () => Array.from(document.querySelectorAll('link[rel="stylesheet"], style'))
  .map((node) => node.outerHTML)
  .join('\n');

const waitForImages = async (container) => {
  const images = Array.from(container.querySelectorAll('img'));

  await Promise.all(images.map((image) => {
    if (image.complete) return Promise.resolve();

    return new Promise((resolve) => {
      image.addEventListener('load', resolve, { once: true });
      image.addEventListener('error', resolve, { once: true });
    });
  }));
};

const waitForStylesheets = async (container, timerWindow = window) => {
  const links = Array.from(container.querySelectorAll('link[rel="stylesheet"]'));

  await Promise.all(links.map((link) => {
    if (link.sheet) return Promise.resolve();

    return new Promise((resolve) => {
      link.addEventListener('load', resolve, { once: true });
      link.addEventListener('error', resolve, { once: true });
      timerWindow.setTimeout(resolve, 1200);
    });
  }));
};

const removeExistingPrintFrame = () => {
  document.getElementById('inyice-print-frame')?.remove();
};

const createPrintFrame = () => {
  removeExistingPrintFrame();

  const frame = document.createElement('iframe');
  frame.id = 'inyice-print-frame';
  frame.setAttribute('title', 'Print document');
  frame.style.position = 'fixed';
  frame.style.left = '0';
  frame.style.top = '0';
  frame.style.width = '794px';
  frame.style.height = '1123px';
  frame.style.border = '0';
  frame.style.opacity = '0';
  frame.style.pointerEvents = 'none';
  frame.style.zIndex = '-1';

  document.body.appendChild(frame);

  return frame;
};

const buildPrintHtml = (source, title) => {
  const htmlAttrs = Array.from(document.documentElement.attributes)
    .map((attribute) => `${attribute.name}="${escapeHtml(attribute.value)}"`)
    .join(' ');
  const bodyClass = document.body.className ? ` class="${escapeHtml(document.body.className)}"` : '';

  return `<!doctype html>
<html ${htmlAttrs}>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <base href="${escapeHtml(document.baseURI)}" />
  <title>${escapeHtml(title)}</title>
  ${cloneStyles()}
  <style>
    @page {
      size: A4;
      margin: 14mm 12mm;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      width: auto !important;
      height: auto !important;
      min-height: 0 !important;
      margin: 0 !important;
      overflow: visible !important;
      background: #ffffff !important;
    }

    body {
      padding: 0 !important;
    }

    .print-document-root {
      width: auto !important;
      margin: 0 !important;
      padding: 0 !important;
      overflow: visible !important;
      background: #ffffff !important;
    }

    .print-document-root > .invoice-paper,
    .print-document-root > .receipt-slip,
    .print-document-root > .voucher-preview {
      width: 100% !important;
      max-width: none !important;
      margin: 0 !important;
      border: 0 !important;
      box-shadow: none !important;
    }

    .print-document-root .ant-table-wrapper,
    .print-document-root .ant-table-container,
    .print-document-root .ant-table-content,
    .print-document-root .ant-table-body {
      overflow: visible !important;
      max-height: none !important;
    }

    .print-document-root > .invoice-paper > .ant-card-body {
      padding: 0 !important;
      overflow: visible !important;
    }

    .print-document-root .invoice-paper .ant-table table,
    .print-document-root .receipt-slip .ant-table table {
      width: 100% !important;
    }

    .print-document-root .invoice-header,
    .print-document-root .invoice-meta-grid,
    .print-document-root .invoice-mini-section,
    .print-document-root .invoice-totals,
    .print-document-root .invoice-receipts,
    .print-document-root .invoice-notes,
    .print-document-root .ant-row,
    .print-document-root .invoice-lines-table tr,
    .print-document-root .ant-table-thead,
    .print-document-root .ant-table-tbody > tr {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .print-document-root .ant-table,
    .print-document-root .ant-table-wrapper,
    .print-document-root .ant-table-container,
    .print-document-root .ant-table-content {
      page-break-inside: auto;
    }

    .print-document-root .csv-table-download-button {
      display: none !important;
    }

    .print-document-root,
    .print-document-root *,
    .print-document-root .ant-typography,
    .print-document-root .ant-card,
    .print-document-root .ant-table,
    .print-document-root .ant-table-cell,
    .print-document-root .ant-descriptions-item-label,
    .print-document-root .ant-descriptions-item-content {
      color: #111827 !important;
      text-shadow: none !important;
    }

    .print-document-root .ant-typography-secondary,
    .print-document-root .invoice-balance-label,
    .print-document-root .voucher-preview-kicker,
    .print-document-root .voucher-preview-contact,
    .print-document-root .voucher-preview-header .ant-typography-secondary {
      color: #4b5563 !important;
    }

    .print-document-root .voucher-preview-document-title,
    .print-document-root .voucher-preview-section > .voucher-preview-section-title {
      color: #a46f15 !important;
    }

    .print-document-root .invoice-paper .ant-table-thead > tr > th,
    .print-document-root .invoice-paper .ant-table-thead > tr > th *,
    .print-document-root .receipt-slip .ant-table-thead > tr > th,
    .print-document-root .receipt-slip .ant-table-thead > tr > th *,
    .print-document-root .invoice-lines-table th,
    .print-document-root .invoice-lines-table th *,
    .print-document-root .invoice-lines-table .ant-table-thead > tr > th,
    .print-document-root .invoice-lines-table .ant-table-thead > tr > th * {
      color: #ffffff !important;
      background: #383a36 !important;
      print-color-adjust: exact;
      -webkit-print-color-adjust: exact;
    }

    .print-document-root .voucher-preview-section .ant-table-thead > tr > th,
    .print-document-root .voucher-preview-section .ant-table-thead > tr > th * {
      color: #102033 !important;
      background: #edf4fb !important;
      print-color-adjust: exact;
      -webkit-print-color-adjust: exact;
    }

    .print-document-root .invoice-paper .ant-table-tbody > tr > td,
    .print-document-root .receipt-slip .ant-table-tbody > tr > td,
    .print-document-root .invoice-lines-table td,
    .print-document-root .ant-card-body,
    .print-document-root .voucher-preview,
    .print-document-root .receipt-slip,
    .print-document-root .receipt-slip-lines .ant-table-tbody > tr > td,
    .print-document-root .invoice-paper {
      background: #ffffff !important;
      print-color-adjust: exact;
      -webkit-print-color-adjust: exact;
    }

    .print-document-root .invoice-header {
      margin-bottom: 38px !important;
    }

    .print-document-root .invoice-heading h1.ant-typography {
      font-size: 34px !important;
    }

    .print-document-root .invoice-meta-grid {
      grid-template-columns: minmax(0, 1fr) 290px !important;
      margin-bottom: 20px !important;
    }

    .print-document-root .invoice-paper .ant-table-thead > tr > th,
    .print-document-root .invoice-paper .ant-table-tbody > tr > td,
    .print-document-root .receipt-slip .ant-table-thead > tr > th,
    .print-document-root .receipt-slip .ant-table-tbody > tr > td,
    .print-document-root .invoice-lines-table th,
    .print-document-root .invoice-lines-table td {
      padding: 8px 9px !important;
      font-size: 11.5px !important;
      line-height: 1.35 !important;
    }

    .print-document-root h1.ant-typography,
    .print-document-root .ant-typography h1 {
      font-size: 26px !important;
      margin-bottom: 4px !important;
    }

    .print-document-root h2.ant-typography,
    .print-document-root .ant-typography h2 {
      font-size: 20px !important;
      margin-bottom: 6px !important;
    }

    .print-document-root h4.ant-typography,
    .print-document-root .ant-typography h4 {
      font-size: 14px !important;
      margin: 10px 0 8px !important;
    }

    .print-document-root .invoice-company-logo {
      width: 92px !important;
      height: 68px !important;
      object-fit: contain !important;
    }

    .print-document-root .ant-divider {
      margin: 12px 0 !important;
      border-color: #d1d5db !important;
    }

    .print-document-root .invoice-receipts,
    .print-document-root .invoice-notes {
      margin-top: 26px !important;
    }

    .print-document-root .invoice-balance-row {
      background: #f4f4f4 !important;
      print-color-adjust: exact;
      -webkit-print-color-adjust: exact;
    }

    .print-document-root > .voucher-preview {
      max-width: none !important;
      padding: 0 !important;
      border: 0 !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      overflow: visible !important;
    }

    .print-document-root .voucher-preview .ant-table-wrapper,
    .print-document-root .voucher-preview .ant-table-container,
    .print-document-root .voucher-preview .ant-table-content {
      width: 100% !important;
      overflow: visible !important;
    }

    .print-document-root .voucher-preview .ant-table,
    .print-document-root .voucher-preview .ant-table table {
      width: 100% !important;
    }

    .print-document-root .voucher-preview-table,
    .print-document-root .voucher-preview-table table {
      width: 100% !important;
      max-width: none !important;
    }

    .print-document-root .voucher-preview-table table {
      table-layout: fixed !important;
      border-collapse: collapse !important;
      color: #102033 !important;
      background: #ffffff !important;
    }

    .print-document-root .voucher-preview-header {
      grid-template-columns: minmax(0, 1fr) 70px !important;
      gap: 8px !important;
      padding: 10px 12px !important;
    }

    .print-document-root .voucher-preview-header h3 {
      font-size: 18px !important;
      margin-bottom: 3px !important;
    }

    .print-document-root .voucher-preview-header .ant-typography-secondary {
      font-size: 9px !important;
      line-height: 1.25 !important;
    }

    .print-document-root .voucher-preview-logo {
      width: 104px !important;
      height: 76px !important;
      padding: 3px !important;
    }

    .print-document-root .voucher-reference-table {
      margin-top: 5px !important;
    }

    .print-document-root .voucher-reference-table-primary {
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }

    .print-document-root .voucher-reference-table-secondary {
      grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    }

    .print-document-root .voucher-reference-cell {
      padding: 3px 5px !important;
    }

    .print-document-root .voucher-reference-cell .ant-typography {
      font-size: 8px !important;
    }

    .print-document-root .voucher-preview-contact {
      margin: 6px 0 7px !important;
      gap: 3px 10px !important;
      font-size: 10px !important;
    }

    .print-document-root .voucher-preview-section {
      margin-top: 6px !important;
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .print-document-root .voucher-preview-section-title {
      font-size: 9px !important;
    }

    .print-document-root .voucher-preview .ant-table-cell {
      padding: 3px 5px !important;
      font-size: 9px !important;
      line-height: 1.18 !important;
    }

    .print-document-root .voucher-preview-table tr {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .print-document-root .voucher-preview-table th,
    .print-document-root .voucher-preview-table td {
      padding: 3px 5px !important;
      color: #102033 !important;
      font-size: 9px !important;
      line-height: 1.18 !important;
      text-align: left;
      vertical-align: top;
      border-bottom: 1px solid rgba(15, 27, 45, 0.1) !important;
      background: #ffffff !important;
      overflow-wrap: anywhere;
    }

    .print-document-root .voucher-preview-table th {
      font-weight: 800 !important;
      border-bottom-color: rgba(15, 27, 45, 0.14) !important;
      background: #edf4fb !important;
      print-color-adjust: exact;
      -webkit-print-color-adjust: exact;
    }

    .print-document-root .voucher-preview-footer {
      margin-top: 7px !important;
      grid-template-columns: minmax(0, 1fr) 112px !important;
      gap: 8px !important;
    }

    .print-document-root .voucher-preview-footer-notes > div,
    .print-document-root .voucher-preview-footer-logo {
      padding: 7px !important;
    }

    .print-document-root .voucher-preview-footer p {
      font-size: 10px !important;
    }

    .print-document-root .voucher-preview-footer-logo img {
      width: 84px !important;
      height: 84px !important;
    }
  </style>
</head>
<body${bodyClass}>
  <div class="print-document-root">${source.outerHTML}</div>
</body>
</html>`;
};

export async function printDocument(selector, title = 'Document') {
  const source = document.querySelector(selector);

  if (!source) {
    window.print();
    return;
  }

  const frame = createPrintFrame();
  const printWindow = frame.contentWindow;
  const printDocumentRef = frame.contentDocument || printWindow?.document;

  if (!printWindow || !printDocumentRef) {
    window.print();
    return;
  }

  printDocumentRef.open();
  printDocumentRef.write(buildPrintHtml(source, title));
  printDocumentRef.close();

  await waitForStylesheets(printDocumentRef, printWindow);
  await waitForImages(printDocumentRef);

  printWindow.addEventListener('afterprint', removeExistingPrintFrame, { once: true });
  printWindow.focus();
  printWindow.setTimeout(() => {
    printWindow.print();
  }, 100);
}
