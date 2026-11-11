const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const statementDate = (value, fallback = '-') => {
  if (!value) return fallback;
  const [year, month, day] = String(value).slice(0, 10).split('-');
  return `${day}/${months[Number(month) - 1]}/${year}`;
};

export function statementMetadata(statement, kind, invoices = false) {
  const party = statement[kind];
  return {
    title: `${kind === 'customer' ? 'Customer' : 'Vendor'} ${invoices ? 'Invoice Activity' : 'Ledger'}`,
    account: party.name,
    company: statement.company,
    from: statement.period.from,
    to: statement.period.to,
    currency: invoices ? statement.customer_currency : statement.base_currency,
    opening: kind === 'vendor' ? Number(statement.summary.opening_balance || 0) : null,
    closing: Number(kind === 'vendor' ? statement.summary.outstanding_balance : statement.summary.total_outstanding),
    invoices,
  };
}

export function createStatementExport(meta, columns, rows, now = new Date()) {
  const generated = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Karachi', day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).format(now);
  const company = meta.company || {};
  const branding = [company.display_name || company.legal_name, company.address,
    [company.email, company.phone].filter(Boolean).join(' | ')].filter(Boolean).map(value => [value]);
  const header = [...branding, [meta.title], ['Account', meta.account],
    ['From', statementDate(meta.from, 'Beginning'), 'To', statementDate(meta.to, 'Latest')],
    ['Currency', meta.currency]];
  if (meta.opening !== null && !meta.invoices) header.push(['Opening Balance', meta.opening]);
  const summary = Array(columns.length).fill('');
  summary[0] = 'Period Total';
  const sumTitles = meta.invoices ? ['Amount', 'Paid', 'Outstanding'] : ['Debit', 'Credit'];
  columns.forEach((column, index) => {
    if (sumTitles.includes(column)) summary[index] = rows.reduce((sum, row) => sum + Number(row[index] || 0), 0);
  });
  const closing = Array(columns.length).fill('');
  closing[0] = 'Final Closing Balance';
  closing[Math.max(1, columns.indexOf('Balance'))] = meta.closing;
  return { meta, header, brandingRows: branding.length, columns, rows, summary: meta.invoices ? [summary] : [summary, closing], generated };
}

// Normalize the agency's supported image formats (including WebP) for both exporters.
async function loadAgencyLogo(url) {
  if (!url) return null;
  const image = new Image();
  image.crossOrigin = 'anonymous';
  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Agency logo could not be loaded. Check the logo in agency settings and try again.')), 10000);
    image.onload = () => { clearTimeout(timeout); resolve(); };
    image.onerror = () => { clearTimeout(timeout); reject(new Error('Agency logo could not be loaded. Check the logo in agency settings and try again.')); };
    image.src = url;
  });
  const scale = Math.min(1, 800 / image.naturalWidth, 400 / image.naturalHeight);
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
  canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
  canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
  return { data: canvas.toDataURL('image/png'), width: canvas.width, height: canvas.height };
}

const save = (data, type, name) => {
  const url = URL.createObjectURL(new Blob([data], { type }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = name;
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};
const csvCell = (value) => {
  if (typeof value === 'number') return `"${value.toFixed(2)}"`;
  const safe = typeof value === 'string' && /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
  return `"${String(safe ?? '').replaceAll('"', '""')}"`;
};
export const statementCsv = (report) => '\uFEFF' + [
  ...report.header, [], report.columns, ...report.rows, ...report.summary, [],
  ['Powered by Inyice', 'Generated (Asia/Karachi)', report.generated],
].map(row => row.map(csvCell).join(',')).join('\r\n');

export async function statementWorkbook(report) {
  const { default: ExcelJS } = await import('exceljs');
  const workbook = new ExcelJS.Workbook();
  const sheet = workbook.addWorksheet('Statement');
  report.header.forEach(row => sheet.addRow(row));
  sheet.addRow([]);
  const heading = sheet.addRow(report.columns);
  report.rows.forEach(row => sheet.addRow(row));
  report.summary.forEach(row => { sheet.addRow(row).font = { bold: true }; });
  sheet.addRow([]);
  sheet.addRow(['Powered by Inyice', 'Generated (Asia/Karachi)', report.generated]);
  sheet.getRow(1).font = { bold: true, size: 16 };
  heading.font = { bold: true };
  sheet.columns.forEach((column, index) => {
    column.width = index === 0 ? 26 : /Description|Narration/.test(report.columns[index]) ? 55 : 24;
    column.eachCell(cell => {
      cell.alignment = { vertical: 'top', wrapText: true };
      if (typeof cell.value === 'number') {
        cell.numFmt = '#,##0.00;[Red]-#,##0.00';
        cell.alignment.horizontal = 'right';
      }
    });
  });
  report.header.forEach((row, index) => {
    if (row.length === 1) {
      sheet.mergeCells(index + 1, 1, index + 1, Math.max(1, report.columns.length - (report.logo ? 2 : 0)));
      sheet.getRow(index + 1).height = index === 0 ? 30 : 28;
    }
  });
  if (report.logo) {
    const imageId = workbook.addImage({ base64: report.logo.data, extension: 'png' });
    const scale = Math.min(140 / report.logo.width, 65 / report.logo.height);
    sheet.addImage(imageId, { tl: { col: report.columns.length - 2, row: 0 }, ext: { width: report.logo.width * scale, height: report.logo.height * scale } });
  }
  sheet.views = [{ state: 'frozen', ySplit: heading.number }];
  sheet.pageSetup = { orientation: 'landscape', paperSize: 9, fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: `1:${heading.number}` };
  sheet.headerFooter.oddFooter = `&LPowered by Inyice&CPage &P of &N&R${report.generated} (Asia/Karachi)`;
  return workbook.xlsx.writeBuffer();
}

export async function statementPdf(report) {
  const [{ jsPDF }, { autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
  const doc = new jsPDF({ orientation: 'landscape' });
  const number = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const display = value => typeof value === 'number' ? number.format(value) : value;
  const headerLines = [];
  const addHeaderColumn = (rows, x, width) => {
    let y = 12;
    rows.forEach((row, index) => {
      const fontSize = index === 0 ? 12 : 9;
      const lineHeight = fontSize * 0.3528 * 1.15;
      doc.setFontSize(fontSize);
      const lines = doc.splitTextToSize(row.map(display).join('   '), width);
      headerLines.push({ lines, x, y, fontSize });
      y += lines.length * lineHeight + (index === 0 ? 1.5 : 0.8);
    });
    return y;
  };
  const companyBottom = addHeaderColumn(report.header.slice(0, report.brandingRows), 10, 126);
  const details = report.header.slice(report.brandingRows);
  // Keep currency and opening balance together, leaving room for long account names.
  if (details.length > 4) details.splice(3, 2, [...details[3], ...details[4]]);
  const detailsBottom = addHeaderColumn(details, 144, report.logo ? 94 : 143);
  const headerHeight = Math.max(companyBottom, detailsBottom, report.logo ? 30 : 0) + 3;
  autoTable(doc, {
    head: [report.columns], body: [...report.rows, ...report.summary].map(row => row.map(display)),
    startY: headerHeight, margin: { top: headerHeight, bottom: 18, left: 10, right: 10 },
    styles: { fontSize: 8, overflow: 'linebreak' },
    columnStyles: Object.fromEntries(report.columns.flatMap((title, index) =>
      /^(Debit|Credit|Balance|Amount|Paid|Outstanding)$/.test(title) ? [[index, { halign: 'right' }]] : [])),
    didParseCell: data => {
      if (data.section === 'body' && data.row.index >= report.rows.length) data.cell.styles.fontStyle = 'bold';
    },
    willDrawPage: () => {
      headerLines.forEach(({ lines, x, y, fontSize }) => {
        doc.setFontSize(fontSize);
        doc.text(lines, x, y, { lineHeightFactor: 1.15 });
      });
      if (report.logo) {
        const scale = Math.min(40 / report.logo.width, 22 / report.logo.height);
        doc.addImage(report.logo.data, 'PNG', 247, 8, report.logo.width * scale, report.logo.height * scale);
      }
    },
  });
  const count = doc.getNumberOfPages();
  for (let page = 1; page <= count; page++) {
    doc.setPage(page);
    doc.setFontSize(8);
    const y = doc.internal.pageSize.getHeight() - 8;
    doc.text('Powered by Inyice', 10, y);
    doc.text(`Page ${page} of ${count}`, 148, y, { align: 'center' });
    doc.text(`${report.generated} (Asia/Karachi)`, 287, y, { align: 'right' });
  }
  return doc;
}

export async function downloadStatement(report, format) {
  if (format !== 'csv') report.logo = await loadAgencyLogo(report.meta.company?.logo_url);
  const name = `${report.meta.title}-${report.meta.account}`.replace(/[^\p{L}\p{N}._-]+/gu, '-');
  if (format === 'csv') save(statementCsv(report), 'text/csv;charset=utf-8', `${name}.csv`);
  else if (format === 'xlsx') save(await statementWorkbook(report), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', `${name}.xlsx`);
  else (await statementPdf(report)).save(`${name}.pdf`);
}
