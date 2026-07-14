import React from 'react';
import { Card, Table, Typography } from 'antd';

const { Text } = Typography;

const toAmount = (value) => {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const normalized = String(value).replace(/[^0-9.-]/g, '');
  const parsed = Number(normalized);

  return Number.isFinite(parsed) ? parsed : 0;
};

const firstFilled = (...values) => values.find((value) => value !== null && value !== undefined && String(value).trim() !== '') || '';

const salesAmount = (row = {}) => toAmount(firstFilled(row.sales, row.amount));

const money = (value) => {
  const amount = Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
  return amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const addAmount = (rows, name, key, amount) => {
  const passengerName = String(name || '').trim() || 'Unassigned';
  if (!rows.has(passengerName)) {
    rows.set(passengerName, {
      key: passengerName,
      passenger_name: passengerName,
      flights: 0,
      visa: 0,
      transfer: 0,
      ziarat: 0,
      hotels: 0,
      others: 0,
      total: 0,
    });
  }

  const row = rows.get(passengerName);
  row[key] += amount;
  row.total += amount;
};

export function buildVoucherSummaryRows(voucher) {
  const rows = new Map();
  const passengers = Array.isArray(voucher.passengers) ? voucher.passengers : [];
  const firstPassenger = firstFilled(passengers[0]?.name, voucher.pricing?.[0]?.pax_name, voucher.visa?.[0]?.passenger_name, 'Unassigned');

  passengers.forEach((passenger, idx) => {
    const name = firstFilled(passenger.name, voucher.pricing?.[idx]?.pax_name, voucher.visa?.[idx]?.passenger_name);
    if (name) {
      addAmount(rows, name, 'flights', 0);
    }
  });

  (voucher.pricing || []).forEach((row, idx) => {
    const name = firstFilled(row.pax_name, passengers[idx]?.name);
    const amount = toAmount(firstFilled(row.flight_sales, row.flight_fare, row.total));
    addAmount(rows, name || firstPassenger, 'flights', amount);
  });

  (voucher.visa || []).forEach((row, idx) => {
    const name = firstFilled(row.passenger_name, passengers[idx]?.name);
    addAmount(rows, name || firstPassenger, 'visa', salesAmount(row));
  });

  (voucher.transfers || []).forEach((row) => {
    addAmount(rows, firstPassenger, 'transfer', salesAmount(row));
  });

  (voucher.city_tours || []).forEach((row) => {
    addAmount(rows, firstPassenger, 'ziarat', salesAmount(row));
  });

  (voucher.hotels || []).forEach((row) => {
    addAmount(rows, firstFilled(row.lead_passenger, firstPassenger), 'hotels', salesAmount(row));
  });

  (voucher.other_services || []).forEach((row) => {
    addAmount(rows, firstPassenger, 'others', salesAmount(row));
  });

  return Array.from(rows.values());
}

export default function VoucherSummaryCard({ voucher }) {
  const data = buildVoucherSummaryRows(voucher);
  const grandTotal = data.reduce((sum, row) => sum + row.total, 0);
  const columns = [
    { title: 'Passenger Name', dataIndex: 'passenger_name', key: 'passenger_name', fixed: 'left', width: 180 },
    { title: 'Flights', dataIndex: 'flights', key: 'flights', align: 'right', render: money },
    { title: 'Visa', dataIndex: 'visa', key: 'visa', align: 'right', render: money },
    { title: 'Transfer', dataIndex: 'transfer', key: 'transfer', align: 'right', render: money },
    { title: 'Ziarat', dataIndex: 'ziarat', key: 'ziarat', align: 'right', render: money },
    { title: 'Hotels', dataIndex: 'hotels', key: 'hotels', align: 'right', render: money },
    { title: 'Others', dataIndex: 'others', key: 'others', align: 'right', render: money },
    { title: 'Total', dataIndex: 'total', key: 'total', align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
  ];

  return (
    <Card className="border-beam-aurora" style={{ marginTop: 16 }} title="Order Summary">
      <Table
        size="small"
        rowKey="key"
        columns={columns}
        dataSource={data}
        pagination={false}
        tableLayout="fixed"
        scroll={{ x: 920 }}
        summary={() => (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0}><Text strong>Total</Text></Table.Summary.Cell>
            <Table.Summary.Cell index={1} colSpan={6} />
            <Table.Summary.Cell index={7} align="right"><Text strong>{money(grandTotal)}</Text></Table.Summary.Cell>
          </Table.Summary.Row>
        )}
      />
    </Card>
  );
}
