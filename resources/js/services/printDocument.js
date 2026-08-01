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
    .print-document-root > .voucher-preview {
      max-width: none !important;
      margin: 0 !important;
    }

    .print-document-root .ant-table-wrapper,
    .print-document-root .ant-table-container,
    .print-document-root .ant-table-content,
    .print-document-root .ant-table-body {
      overflow: visible !important;
      max-height: none !important;
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

    .print-document-root .ant-table-thead > tr > th,
    .print-document-root .ant-table-thead > tr > th *,
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

    .print-document-root .ant-table-tbody > tr > td,
    .print-document-root .ant-card-body,
    .print-document-root .voucher-preview,
    .print-document-root .invoice-paper {
      background: #ffffff !important;
      print-color-adjust: exact;
      -webkit-print-color-adjust: exact;
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
