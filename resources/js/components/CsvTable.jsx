import React from 'react';
import { DownloadOutlined } from '@ant-design/icons';
import { Button, Space, Table as AntTable } from 'antd';

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

const flattenColumns = (columns = []) => columns.flatMap((column) => {
  if (column.hidden) return [];
  if (Array.isArray(column.children)) return flattenColumns(column.children);
  if (!column.dataIndex && !column.key) return [];

  return [column];
});

const escapeCsvValue = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;

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
  const exportColumns = flattenColumns(columns).filter((column) => columnTitle(column));
  if (!exportColumns.length || !dataSource?.length) return;

  const rows = [
    exportColumns.map(columnTitle),
    ...dataSource.map((record, rowIndex) => exportColumns.map((column) => {
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

const defaultFileName = () => {
  const pageName = (document.title || 'table').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'table';
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');

  return `${pageName}-${timestamp}.csv`;
};

function CsvTable({ title, columns = [], dataSource = [], csvFileName, csvDownload = true, ...props }) {
  const rows = Array.isArray(dataSource) ? dataSource : [];
  const enhancedColumns = React.useMemo(() => sortableColumns(columns, rows), [columns, rows]);
  const showCsvButton = csvDownload && isPaidAgency() && rows.length > 0;

  const renderTitle = showCsvButton || title
    ? (currentPageData) => (
      <Space style={{ width: '100%', justifyContent: 'space-between' }} align="center">
        <span>{typeof title === 'function' ? title(currentPageData) : title}</span>
        {showCsvButton && (
          <Button
            className="csv-table-download-button"
            size="small"
            icon={<DownloadOutlined />}
            onClick={() => downloadCsv({ columns, dataSource: rows, fileName: csvFileName || defaultFileName() })}
          >
            CSV
          </Button>
        )}
      </Space>
    )
    : undefined;

  return <AntTable {...props} columns={enhancedColumns} dataSource={dataSource} title={renderTitle} />;
}

CsvTable.Summary = AntTable.Summary;
CsvTable.Column = AntTable.Column;
CsvTable.ColumnGroup = AntTable.ColumnGroup;

export default CsvTable;

