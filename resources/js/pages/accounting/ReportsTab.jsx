import React, { useState } from 'react';
import { Alert, Button, Card, Col, Form, Input, Row, Space, Statistic, Tabs, Typography } from 'antd';
import Table from '../../components/CsvTable';
import { ReloadOutlined } from '@ant-design/icons';
import { accountingApi, localDate, money } from './api';

const accountColumn = { title: 'Account', key: 'account', render: (_, row) => `${row.code} · ${row.name}` };
const amountColumn = (title, dataIndex) => ({ title, dataIndex, align: 'right', render: money });

export default function ReportsTab({ book }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  return <Space orientation="vertical" style={{ width: '100%' }} size="middle">
    <Form layout="vertical" className="accounting-filter-form" initialValues={{ from_date: book.start_date, to_date: localDate() < book.start_date ? book.start_date : localDate() }} onFinish={async (values) => {
      setLoading(true); setError(''); setData(null);
      try { setData(await accountingApi(`/reports?${new URLSearchParams(values)}`)); }
      catch (e) { setError(e.message); } finally { setLoading(false); }
    }}>
      <Form.Item name="from_date" label="From" rules={[{ required: true }]}><Input type="date" min={book.start_date} /></Form.Item>
      <Form.Item name="to_date" label="To / as of" rules={[{ required: true }]}><Input type="date" min={book.start_date} /></Form.Item>
      <Button type="primary" icon={<ReloadOutlined />} htmlType="submit" loading={loading}>Run reports</Button>
    </Form>
    {error && <Alert type="error" title={error} />}
    {!data && !error && <Typography.Paragraph type="secondary">Choose dates to produce reports from posted accounting journals.</Typography.Paragraph>}
    {data && <>
      <Alert type="info" showIcon title={`Accounting ledger only · ${data.currency_code}`} description={data.coverage} />
      <Typography.Text>Period: {data.from_date} to {data.to_date}. Trial balance and balance sheet include all posted entries through {data.to_date}.</Typography.Text>
      <Tabs items={[
        { key: 'trial', label: 'Trial balance', children: <>
          <Table csvFileName="accounting-report.csv" rowKey="account_id" size="small" dataSource={data.accounts} pagination={false} scroll={{ x: 800 }} columns={[
            accountColumn, amountColumn('Opening (Dr − Cr)', 'opening'), amountColumn('Period debits', 'period_debit'),
            amountColumn('Period credits', 'period_credit'), amountColumn('Closing debit', 'debit'), amountColumn('Closing credit', 'credit'),
          ]} />
          <Alert style={{ marginTop: 16 }} type={data.trial_balance.balanced ? 'success' : 'error'} title={`Total debit ${money(data.trial_balance.debit)} · Total credit ${money(data.trial_balance.credit)}`} />
        </> },
        { key: 'profit', label: 'Profit and loss', children: <Space orientation="vertical" style={{ width: '100%' }}>
          <Row gutter={[16, 16]}>
            {[['Income', data.profit_loss.income], ['Expenses', data.profit_loss.expenses], ['Net profit / loss', data.profit_loss.net_profit]].map(([label, amount]) =>
              <Col xs={24} md={8} key={label}><Card size="small" className="border-beam-aurora"><Statistic title={label} value={amount} precision={2} prefix={book.currency_code} /></Card></Col>)}
          </Row>
          <Table csvFileName="accounting-report.csv" rowKey="account_id" size="small" pagination={false} scroll={{ x: 600 }} dataSource={data.accounts.filter((account) => ['income', 'expense'].includes(account.type))}
            columns={[accountColumn, { title: 'Type', dataIndex: 'type' }, amountColumn('Period amount', 'period_balance')]} />
        </Space> },
        { key: 'balance', label: 'Balance sheet', children: <Space orientation="vertical" style={{ width: '100%' }}>
          <Table csvFileName="accounting-report.csv" rowKey="account_id" size="small" pagination={false} scroll={{ x: 600 }} dataSource={data.accounts.filter((account) => ['asset', 'liability', 'equity'].includes(account.type))}
            columns={[accountColumn, { title: 'Type', dataIndex: 'type' }, amountColumn('Balance', 'balance')]} />
          <Typography.Paragraph>Assets: {money(data.balance_sheet.assets)} · Liabilities: {money(data.balance_sheet.liabilities)} · Equity: {money(data.balance_sheet.equity)} · Unclosed earnings: {money(data.balance_sheet.unclosed_earnings)}</Typography.Paragraph>
          <Typography.Text type="secondary">Unclosed earnings include income less expenses since the ledger start date; they are included in the balance-sheet equation.</Typography.Text>
          <Alert type={data.balance_sheet.balanced ? 'success' : 'error'} title={`Assets ${money(data.balance_sheet.assets)} = liabilities + equity + earnings ${money(data.balance_sheet.liabilities_and_equity)}`} />
        </Space> },
      ]} />
    </>}
  </Space>;
}
