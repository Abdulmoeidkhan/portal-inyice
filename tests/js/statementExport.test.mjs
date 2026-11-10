import test from 'node:test';
import assert from 'node:assert/strict';
import { createStatementExport, statementCsv, statementWorkbook, statementPdf, statementMetadata } from '../../resources/js/services/statementExport.js';

const meta = { title: 'Vendor Ledger', account: 'Flight Smart', from: '2020-07-01', to: '2026-11-10', currency: 'PKR', opening: 100, closing: 113797.12 };
const columns = ['Date', 'Description', 'Debit', 'Credit', 'Balance'];
const rows = [['2026-11-10', 'Flight', 6167585.94, 6053888.82, 113797.12]];
const report = () => createStatementExport(meta, columns, rows, new Date('2026-11-10T08:30:18Z'));

test('agency details appear in CSV, Excel and every PDF page', async () => {
  const company = { display_name: 'Example Travel', legal_name: 'Example Ltd', address: '12 Travel Road', email: 'info@example.test', phone: '+92 123456' };
  const branded = createStatementExport({ ...meta, company }, columns, Array.from({ length: 100 }, () => rows[0]));
  for (const value of [company.display_name, company.address, company.email, company.phone]) assert.ok(statementCsv(branded).includes(value));
  const { default: ExcelJS } = await import('exceljs');
  const book = new ExcelJS.Workbook();
  await book.xlsx.load(await statementWorkbook(branded));
  assert.equal(book.getWorksheet('Statement').getCell('A1').value, company.display_name);
  const pdf = await statementPdf(branded);
  assert.equal(pdf.output().match(/Example Travel/g).length, pdf.getNumberOfPages());
  assert.ok(pdf.output().includes('Powered by Inyice'));
});

test('CSV contains account, selected dates, totals and a single closing row', () => {
  const csv = statementCsv(report());
  for (const text of ['Flight Smart', '01/Jul/2020', '10/Nov/2026', 'Period Total', 'Final Closing Balance', 'Powered by Inyice', '13:30:18']) assert.ok(csv.includes(text), text);
  assert.equal(csv.match(/Closing Balance/g).length, 1);
  assert.equal(report().summary[0][2], 6167585.94);
  assert.equal(report().summary[1][4], 113797.12);
});

test('agency logo is embedded in Excel and PDF', async () => {
  const branded = report();
  branded.logo = { width: 1, height: 1, data: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4XmNgYGD4DwABBAEA43qFxAAAAABJRU5ErkJggg==' };
  const { default: ExcelJS } = await import('exceljs');
  const book = new ExcelJS.Workbook();
  await book.xlsx.load(await statementWorkbook(branded));
  assert.equal(book.getWorksheet('Statement').getImages().length, 1);
  assert.ok((await statementPdf(branded)).output().includes('/Subtype /Image'));
});

test('CSV protects text cells and quotes account names', () => {
  const result = createStatementExport({ ...meta, account: '=1+1,"Test"' }, columns, []);
  assert.ok(statementCsv(result).includes('"\'=1+1,""Test"""'));
});

test('empty vendor statement retains opening and final balance', () => {
  const result = createStatementExport({ ...meta, closing: 100 }, columns, []);
  assert.equal(result.summary[0][2], 0);
  assert.equal(result.summary[1][4], 100);
});

test('Excel round trip preserves numeric amounts and print footer', async () => {
  const { default: ExcelJS } = await import('exceljs');
  const book = new ExcelJS.Workbook();
  await book.xlsx.load(await statementWorkbook(report()));
  const sheet = book.getWorksheet('Statement');
  assert.equal(sheet.getCell('B2').value, 'Flight Smart');
  assert.equal(sheet.getCell('C8').value, 6167585.94);
  assert.equal(sheet.getCell('E10').value, 113797.12);
  assert.ok(sheet.headerFooter.oddFooter.includes('Page &P of &N'));
});

test('PDF repeats account header and numbers every page of a long statement', async () => {
  const result = createStatementExport(meta, columns, Array.from({ length: 150 }, () => rows[0]));
  const doc = await statementPdf(result);
  assert.ok(doc.getNumberOfPages() > 1);
  const output = doc.output();
  assert.equal(output.match(/Powered by Inyice/g).length, doc.getNumberOfPages());
  assert.equal(output.match(/Flight Smart/g).length, doc.getNumberOfPages());
  assert.equal(output.match(/Final Closing Balance/g).length, 1);
});

test('metadata takes dates from the generated response and ledger currency from base currency', () => {
  const result = statementMetadata({ customer: { name: 'Customer A' }, period: { from: '2020-07-01', to: null }, base_currency: 'PKR', customer_currency: 'USD', summary: { total_outstanding: 20 } }, 'customer');
  assert.equal(result.from, '2020-07-01');
  assert.equal(result.currency, 'PKR');
  assert.equal(result.closing, 20);
});
