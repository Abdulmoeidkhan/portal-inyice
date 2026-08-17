import React from 'react';
import { Card, Typography } from 'antd';
import Table from '../../components/CsvTable';
import { calculateVoucherTotals, costAmount, firstFilled, salesAmount, toAmount } from './voucherTotals';

const { Text } = Typography;

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
      cost: 0,
      profit: 0,
    });
  }

  const row = rows.get(passengerName);
  row[key] += amount;
  row.total += amount;
};

const addProfitAmount = (rows, name, revenue, cost) => {
  const passengerName = String(name || '').trim() || 'Unassigned';
  if (!rows.has(passengerName)) {
    addAmount(rows, passengerName, 'flights', 0);
  }

  const row = rows.get(passengerName);
  row.cost += cost;
  row.profit += revenue - cost;
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
    addProfitAmount(rows, name || firstPassenger, amount, toAmount(row.flight_cost));
  });

  (voucher.visa || []).forEach((row, idx) => {
    const name = firstFilled(row.passenger_name, passengers[idx]?.name);
    const amount = salesAmount(row);
    addAmount(rows, name || firstPassenger, 'visa', amount);
    addProfitAmount(rows, name || firstPassenger, amount, costAmount(row));
  });

  (voucher.transfers || []).forEach((row) => {
    const amount = salesAmount(row);
    addAmount(rows, firstPassenger, 'transfer', amount);
    addProfitAmount(rows, firstPassenger, amount, costAmount(row));
  });

  (voucher.city_tours || []).forEach((row) => {
    const amount = salesAmount(row);
    addAmount(rows, firstPassenger, 'ziarat', amount);
    addProfitAmount(rows, firstPassenger, amount, costAmount(row));
  });

  (voucher.hotels || []).forEach((row) => {
    const name = firstFilled(row.lead_passenger, firstPassenger);
    const amount = salesAmount(row);
    addAmount(rows, name, 'hotels', amount);
    addProfitAmount(rows, name, amount, costAmount(row));
  });

  (voucher.other_services || []).forEach((row) => {
    const amount = salesAmount(row);
    addAmount(rows, firstPassenger, 'others', amount);
    addProfitAmount(rows, firstPassenger, amount, costAmount(row));
  });

  const totals = calculateVoucherTotals(voucher);
  if (totals.discountTotal > 0) {
    rows.set('__discounts__', {
      key: '__discounts__',
      passenger_name: 'Discounts',
      flights: 0,
      visa: 0,
      transfer: 0,
      ziarat: 0,
      hotels: 0,
      others: 0,
      total: -totals.discountTotal,
      cost: 0,
      profit: -totals.discountTotal,
    });
  }

  return Array.from(rows.values());
}

export default function VoucherSummaryCard({ voucher, canViewCostProfit = true, embedded = false }) {
  const data = buildVoucherSummaryRows(voucher);
  const grandTotal = data.reduce((sum, row) => sum + row.total, 0);
  const grandProfit = data.reduce((sum, row) => sum + row.profit, 0);
  const passengerNameColumnWidth = 200;
  const passengerNameColumnStyle = {
    minWidth: passengerNameColumnWidth,
    width: passengerNameColumnWidth,
  };
  const columns = [
    {
      title: 'Passenger Name',
      dataIndex: 'passenger_name',
      key: 'passenger_name',
      fixed: 'left',
      width: passengerNameColumnWidth,
      onCell: () => ({ style: passengerNameColumnStyle }),
      onHeaderCell: () => ({ style: passengerNameColumnStyle }),
    },
    { title: 'Flights', dataIndex: 'flights', key: 'flights', align: 'right', render: money },
    { title: 'Visa', dataIndex: 'visa', key: 'visa', align: 'right', render: money },
    { title: 'Transfer', dataIndex: 'transfer', key: 'transfer', align: 'right', render: money },
    { title: 'Ziarat', dataIndex: 'ziarat', key: 'ziarat', align: 'right', render: money },
    { title: 'Hotels', dataIndex: 'hotels', key: 'hotels', align: 'right', render: money },
    { title: 'Others', dataIndex: 'others', key: 'others', align: 'right', render: money },
    ...(canViewCostProfit ? [{ title: 'Profit', dataIndex: 'profit', key: 'profit', align: 'right', render: (value) => <Text strong>{money(value)}</Text> }] : []),
    { title: 'Total', dataIndex: 'total', key: 'total', align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
  ];
  const totalColumnIndex = columns.findIndex((column) => column.key === 'total');
  const profitColumnIndex = columns.findIndex((column) => column.key === 'profit');

  const content = (
    <Table
      size="small"
      rowKey="key"
      columns={columns}
      dataSource={data}
      pagination={false}
      tableLayout="fixed"
      scroll={{ x: canViewCostProfit ? 1040 : 920 }}
      summary={() => (
        <Table.Summary.Row>
          <Table.Summary.Cell index={0}><Text strong>Total</Text></Table.Summary.Cell>
          <Table.Summary.Cell index={1} colSpan={6} />
          {canViewCostProfit && (
            <Table.Summary.Cell index={profitColumnIndex} align="right"><Text strong>{money(grandProfit)}</Text></Table.Summary.Cell>
          )}
          <Table.Summary.Cell index={totalColumnIndex} align="right"><Text strong>{money(grandTotal)}</Text></Table.Summary.Cell>
        </Table.Summary.Row>
      )}
    />
  );

  if (embedded) {
    return content;
  }

  return (
    <Card className="border-beam-aurora" style={{ marginTop: 16 }} title="Order Summary">
      {content}
    </Card>
  );
}
