import React from 'react';
import { DownOutlined, DownloadOutlined, FilePdfOutlined } from '@ant-design/icons';
import { Button, Dropdown, Space, Table as AntTable } from 'antd';
import { message } from '../services/feedback';

const getCurrentUser = () => {
  try {
    return JSON.parse(localStorage.getItem('user') || '{}');
  } catch {
    return {};
  }
};

const isPaidAgency = () => getCurrentUser().company_is_paid === true;

const valueAtPath = (record, dataIndex) => {
  if (Array.isArray(dataIndex)) {
    return dataIndex.reduce((current, key) => current?.[key], record);
  }

  if (typeof dataIndex === 'string' && dataIndex.includes('.')) {
    return dataIndex.split('.').reduce((current, key) => current?.[key], record);
  }

  return dataIndex ? record?.[dataIndex] : undefined;
};

const nodeToText = (node) => {
  if (node === null || node === undefined || typeof node === 'boolean') return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(nodeToText).filter(Boolean).join(' ');
  if (React.isValidElement(node)) return nodeToText(node.props?.children);
  if (typeof node === 'object' && 'children' in node) return nodeToText(node.children);

  return String(node);
};

const columnTitle = (column) => {
  const title = typeof column.title === 'function' ? column.key || column.dataIndex : column.title;
  const text = nodeToText(title);

  if (text) return text;
  if (Array.isArray(column.dataIndex)) return column.dataIndex.join('.');

  return column.dataIndex || column.key || '';
};

const appendClassName = (current, next) => [current, next].filter(Boolean).join(' ');
const columnIdentifier = (value) => {
  if (Array.isArray(value)) return value.join('.');

  return String(value || '');
};

const isActionColumn = (column) => {
  const title = columnTitle(column).trim().toLowerCase();
  const key = columnIdentifier(column.key).trim().toLowerCase();
  const dataIndex = columnIdentifier(column.dataIndex).trim().toLowerCase();

  return title === 'action'
    || title === 'actions'
    || key === 'action'
    || key === 'actions'
    || dataIndex === 'action'
    || dataIndex === 'actions';
};

const markActionColumn = (column, parentActionColumn = false) => {
  if (column.hidden) return column;
  const actionColumn = parentActionColumn || isActionColumn(column);
  const markColumn = (targetColumn) => {
    if (!actionColumn) return targetColumn;

    return {
      ...targetColumn,
      className: appendClassName(targetColumn.className, 'csv-table-action-column'),
      onHeaderCell: (...args) => {
        const headerProps = typeof column.onHeaderCell === 'function' ? column.onHeaderCell(...args) : {};

        return {
          ...headerProps,
          className: appendClassName(headerProps?.className, 'csv-table-action-column'),
        };
      },
    };
  };

  if (Array.isArray(column.children)) {
    return markColumn({
      ...column,
      children: column.children.map((child) => markActionColumn(child, actionColumn)),
    });
  }

  if (!actionColumn) return column;

  return markColumn({
    ...column,
    onCell: (record, rowIndex) => {
      const cellProps = typeof column.onCell === 'function' ? column.onCell(record, rowIndex) : {};

      return {
        ...cellProps,
        className: appendClassName(cellProps?.className, 'csv-table-action-column'),
      };
    },
  });
};

const flattenColumns = (columns = [], parentActionColumn = false) => columns.flatMap((column) => {
  if (column.hidden) return [];
  const actionColumn = parentActionColumn || isActionColumn(column);
  if (Array.isArray(column.children)) return flattenColumns(column.children, actionColumn);
  if (!column.dataIndex && !column.key) return [];
  if (actionColumn) return [];

  return [column];
});

const exportColumns = (columns = []) => flattenColumns(columns)
  .filter((column) => columnTitle(column));

const escapeCsvValue = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('"', '&quot;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;');

const normalizeSortValue = (value) => {
  const text = nodeToText(value).trim();
  if (!text) return { type: 'empty', value: '' };

  const numericText = text.replace(/,/g, '');
  if (/^-?\d+(\.\d+)?$/.test(numericText)) {
    return { type: 'number', value: Number(numericText) };
  }

  const timestamp = Date.parse(text);
  if (!Number.isNaN(timestamp) && /[a-zA-Z]|\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[/-]\d{1,2}[/-]\d{2,4}/.test(text)) {
    return { type: 'date', value: timestamp };
  }

  return { type: 'text', value: text.toLowerCase() };
};

const compareSortValues = (left, right) => {
  if (left.type === 'empty' && right.type === 'empty') return 0;
  if (left.type === 'empty') return 1;
  if (right.type === 'empty') return -1;

  if (left.type === right.type && (left.type === 'number' || left.type === 'date')) {
    return left.value - right.value;
  }

  return String(left.value).localeCompare(String(right.value), undefined, { numeric: true, sensitivity: 'base' });
};

const columnSortValue = (column, record, rowIndex) => {
  const rawValue = valueAtPath(record, column.dataIndex);
  const renderedValue = typeof column.render === 'function'
    ? column.render(rawValue, record, rowIndex)
    : rawValue;

  return normalizeSortValue(renderedValue);
};

const sortableColumn = (column, rows) => {
  if (column.hidden || column.sorter !== undefined) return column;
  if (isActionColumn(column)) return column;

  if (Array.isArray(column.children)) {
    return {
      ...column,
      children: column.children.map((child) => sortableColumn(child, rows)),
    };
  }

  return {
    ...column,
    sorter: (left, right) => compareSortValues(
      columnSortValue(column, left, rows.indexOf(left)),
      columnSortValue(column, right, rows.indexOf(right)),
    ),
    sortDirections: column.sortDirections || ['ascend', 'descend'],
  };
};

const sortableColumns = (columns = [], rows = []) => columns.map((column) => sortableColumn(column, rows));

const downloadCsv = ({ columns, dataSource, fileName }) => {
  const columnsForExport = exportColumns(columns);
  if (!columnsForExport.length || !dataSource?.length) return;

  const rows = [
    columnsForExport.map(columnTitle),
    ...dataSource.map((record, rowIndex) => columnsForExport.map((column) => {
      const rawValue = valueAtPath(record, column.dataIndex);
      const renderedValue = typeof column.render === 'function'
        ? column.render(rawValue, record, rowIndex)
        : rawValue;

      return nodeToText(renderedValue);
    })),
  ];

  const blob = new Blob([rows.map((row) => row.map(escapeCsvValue).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = fileName;
  anchor.click();
  URL.revokeObjectURL(url);
};

const printTablePdf = ({ columns, dataSource, title }) => {
  const columnsForExport = exportColumns(columns);
  if (!columnsForExport.length || !dataSource?.length) return;

  const heading = nodeToText(title) || document.title || 'Table export';
  const rows = dataSource.map((record, rowIndex) => columnsForExport.map((column) => {
    const rawValue = valueAtPath(record, column.dataIndex);
    const renderedValue = typeof column.render === 'function'
      ? column.render(rawValue, record, rowIndex)
      : rawValue;

    return nodeToText(renderedValue);
  }));

  document.getElementById('csv-table-pdf-frame')?.remove();

  const frame = document.createElement('iframe');
  frame.id = 'csv-table-pdf-frame';
  frame.title = 'PDF table export';
  frame.style.position = 'fixed';
  frame.style.left = '0';
  frame.style.top = '0';
  frame.style.width = '1px';
  frame.style.height = '1px';
  frame.style.border = '0';
  frame.style.opacity = '0';
  frame.style.pointerEvents = 'none';
  frame.style.zIndex = '-1';
  document.body.appendChild(frame);

  const printWindow = frame.contentWindow;
  const printDocumentRef = frame.contentDocument || printWindow?.document;
  if (!printWindow || !printDocumentRef) return;

  printDocumentRef.open();
  printDocumentRef.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${escapeHtml(heading)}</title>
  <style>
    @page { size: landscape; margin: 10mm; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; overflow: visible !important; background: #fff; color: #111827; font-family: Arial, sans-serif; }
    body { padding: 12px; }
    h1 { margin: 0 0 12px; font-size: 18px; line-height: 1.25; }
    table { width: 100%; border-collapse: collapse; table-layout: auto; font-size: 10px; }
    th, td { border: 1px solid #d1d5db; padding: 5px 6px; text-align: left; vertical-align: top; overflow: visible; word-break: break-word; }
    th { background: #f3f4f6; font-weight: 700; color: #111827; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    tr { break-inside: avoid; page-break-inside: avoid; }
  </style>
</head>
<body>
  <h1>${escapeHtml(heading)}</h1>
  <table>
    <thead><tr>${columnsForExport.map((column) => `<th>${escapeHtml(columnTitle(column))}</th>`).join('')}</tr></thead>
    <tbody>${rows.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('')}</tbody>
  </table>
</body>
</html>`);
  printDocumentRef.close();

  printWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
  printWindow.focus();
  printWindow.setTimeout(() => printWindow.print(), 100);
};

const defaultFileName = () => {
  const pageName = (document.title || 'table').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'table';
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');

  return `${pageName}-${timestamp}.csv`;
};

const defaultPageSizeOptions = [10, 20, 50, 100];
const defaultShowTotal = (total, range) => `${range[0]}-${range[1]} of ${total}`;

function CsvTable({
  title,
  columns = [],
  dataSource = [],
  csvFileName,
  csvDownload = true,
  sortable = true,
  exportAllDataSource,
  pagination,
  ...props
}) {
  const [exportingAll, setExportingAll] = React.useState(false);
  const rows = Array.isArray(dataSource) ? dataSource : [];
  const enhancedColumns = React.useMemo(() => {
    const printableColumns = columns.map(markActionColumn);

    return sortable ? sortableColumns(printableColumns, rows) : printableColumns;
  }, [columns, rows, sortable]);
  const tablePagination = React.useMemo(() => {
    if (pagination === false) return false;

    const paginationProps = pagination && typeof pagination === 'object' ? pagination : {};

    return {
      showSizeChanger: true,
      pageSizeOptions: defaultPageSizeOptions,
      showTotal: defaultShowTotal,
      ...paginationProps,
    };
  }, [pagination]);
  const showCsvButton = csvDownload && isPaidAgency() && rows.length > 0;

  const renderTitle = showCsvButton || title
    ? (currentPageData) => {
      const titleNode = typeof title === 'function' ? title(currentPageData) : title;
      const fileName = csvFileName || defaultFileName();
      const exportItems = [
        { key: 'csv', icon: <DownloadOutlined />, label: 'CSV' },
        { key: 'pdf', icon: <FilePdfOutlined />, label: 'PDF' },
      ];
      const resolveExportRows = async (data) => (typeof data === 'function' ? data() : data);
      const resolveExportTitle = (exportTitle, data) => (typeof exportTitle === 'function' ? exportTitle(data) : exportTitle);
      const handleExport = (data, exportTitle, isAllExport = false) => async ({ key }) => {
        if (isAllExport) {
          setExportingAll(true);
        }

        try {
          const exportRows = await resolveExportRows(data);
          const rowsForExport = Array.isArray(exportRows) ? exportRows : [];
          if (!rowsForExport.length) {
            message.info('No rows available to export');
            return;
          }

          const resolvedTitle = resolveExportTitle(exportTitle, rowsForExport);
          if (key === 'csv') {
            downloadCsv({ columns, dataSource: rowsForExport, fileName });
            return;
          }

          printTablePdf({ columns, dataSource: rowsForExport, title: resolvedTitle });
        } catch (error) {
          message.error(error.message || 'Export failed');
        } finally {
          if (isAllExport) {
            setExportingAll(false);
          }
        }
      };

      return (
        <Space style={{ width: '100%', justifyContent: 'space-between' }} align="center">
          <span>{titleNode}</span>
          {showCsvButton && (
            <Space size={8}>
              <Dropdown
                trigger={['click']}
                menu={{ items: exportItems, onClick: handleExport(currentPageData, titleNode) }}
              >
                <Button className="csv-table-download-button" size="small" icon={<DownloadOutlined />}>
                  CSV/PDF <DownOutlined />
                </Button>
              </Dropdown>
              <Dropdown
                trigger={['click']}
                menu={{ items: exportItems, onClick: handleExport(exportAllDataSource || rows, title, true) }}
              >
                <Button className="csv-table-download-button" size="small" icon={<DownloadOutlined />} loading={exportingAll}>
                  ALL CSV/PDF <DownOutlined />
                </Button>
              </Dropdown>
            </Space>
          )}
        </Space>
      );
    }
    : undefined;

  return <AntTable {...props} columns={enhancedColumns} dataSource={dataSource} pagination={tablePagination} title={renderTitle} />;
}

CsvTable.Summary = AntTable.Summary;
CsvTable.Column = AntTable.Column;
CsvTable.ColumnGroup = AntTable.ColumnGroup;

export default CsvTable;

