import React, { useEffect, useState } from 'react';
import { Alert, Button, Col, Form, Input, InputNumber, Modal, Row, Select, Space, Tag, Typography } from 'antd';
import Table from '../../components/CsvTable';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import { accountingApi, lineTotals, localDate, money } from './api';
import { dialog, message } from '../../services/feedback';

const blankLine = () => ({ debit: '0', credit: '0' });
const kinds = ['general', 'opening', 'expense', 'transfer'];

function JournalEditor({ journal, accounts, book, onClose, onSaved }) {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [requestId] = useState(() => crypto.randomUUID());
  const totals = lineTotals(Form.useWatch('lines', form) || []);
  const options = accounts.filter((account) => account.is_active).map((account) => ({ value: account.id, label: `${account.code} · ${account.name}` }));
  return <Modal open title={journal?.uid ? 'Edit draft journal' : 'New draft journal'} width={1000}
    onCancel={onClose} footer={null} mask={{ closable: !saving }} closable={!saving}>
    <Form form={form} layout="vertical" initialValues={journal || {
      entry_date: localDate() < book.start_date ? book.start_date : localDate(), kind: 'general', lines: [blankLine(), blankLine()],
    }} onFinish={async (values) => {
      setSaving(true); setError('');
      try {
        await accountingApi(journal?.uid ? `/journals/${journal.uid}` : '/journals', journal?.uid ? 'PATCH' : 'POST', {
          ...values, currency_code: book.currency_code, request_id: requestId, version: journal?.version,
          lines: values.lines.map((line) => ({ ...line, debit: String(line.debit ?? '0'), credit: String(line.credit ?? '0') })),
        });
        message.success('Draft saved. It affects reports only after posting.'); onSaved();
      } catch (e) { setError(e.message); } finally { setSaving(false); }
    }}>
      <Alert type="info" showIcon title={`Amounts in ${book.currency_code}`} style={{ marginBottom: 16 }}
        description="Opening: balance-sheet accounts on the start date. Expense: debit an expense, credit cash/bank. Transfer: debit the receiving asset, credit the sending asset. These entries affect this accounting ledger only." />
      {error && <Alert type="error" title={error} style={{ marginBottom: 16 }} />}
      <Row gutter={16}>
        <Col xs={24} sm={8}><Form.Item name="entry_date" label="Entry date" rules={[{ required: true }]}><Input type="date" min={book.start_date} /></Form.Item></Col>
        <Col xs={24} sm={8}><Form.Item name="kind" label="Entry type" rules={[{ required: true }]}><Select options={kinds.map((value) => ({ value, label: value[0].toUpperCase() + value.slice(1) }))} /></Form.Item></Col>
        <Col xs={24} sm={8}><Form.Item name="reference" label="Reference"><Input maxLength={160} placeholder="Receipt or supporting document reference" /></Form.Item></Col>
      </Row>
      <Form.Item name="description" label="Description" rules={[{ required: true, whitespace: true }]}><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
      <Form.List name="lines">{(fields, { add, remove }) => <>
        {fields.map((field) => <Row key={field.key} gutter={10} align="top">
          <Col xs={24} md={8}><Form.Item name={[field.name, 'account_id']} label="Account" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={options} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name={[field.name, 'description']} label="Line note"><Input maxLength={255} /></Form.Item></Col>
          <Col xs={10} md={4}><Form.Item name={[field.name, 'debit']} label="Debit"><InputNumber stringMode min="0" max="99999999999.9999" precision={4} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={10} md={4}><Form.Item name={[field.name, 'credit']} label="Credit"><InputNumber stringMode min="0" max="99999999999.9999" precision={4} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={4} md={2}><Button danger aria-label={`Remove line ${field.name + 1}`} disabled={fields.length <= 2} onClick={() => remove(field.name)} style={{ marginTop: 30 }}>×</Button></Col>
        </Row>)}
        <Button onClick={() => add(blankLine())} disabled={fields.length >= 100}>Add line</Button>
      </>}</Form.List>
      <Space wrap style={{ margin: '20px 0' }}><Tag color={totals.balanced ? 'green' : 'orange'}>{totals.balanced ? 'Balanced' : 'Debits and credits must match'}</Tag>
        <span>Debit {money(totals.debit)} · Credit {money(totals.credit)} {book.currency_code}</span></Space>
      <div><Space><Button type="primary" htmlType="submit" loading={saving} disabled={!totals.balanced}>Save draft</Button><Button onClick={onClose} disabled={saving}>Cancel</Button></Space></div>
    </Form>
  </Modal>;
}

export default function JournalsTab({ book, accounts, canWrite, canPost, onChange }) {
  const [data, setData] = useState(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [editor, setEditor] = useState(null);
  const [detail, setDetail] = useState(null);
  const [action, setAction] = useState(null);
  const [busy, setBusy] = useState(false);
  const [actionForm] = Form.useForm();
  const refresh = () => { setRevision((value) => value + 1); onChange(); };

  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    accountingApi(`/journals?page=${page}${status ? `&status=${status}` : ''}`, 'GET', undefined, controller.signal)
      .then(setData).catch((e) => { if (e.name !== 'AbortError') setError(e.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [page, status, revision]);

  const inspect = async (uid) => {
    setBusy(true);
    try { const payload = await accountingApi(`/journals/${uid}`); setDetail(payload.journal); }
    catch (e) { setError(e.message); } finally { setBusy(false); }
  };
  const post = () => dialog.confirm({
    title: `Post ${detail.number || 'this journal'}?`,
    content: 'Posting affects the accounting reports and makes these lines immutable. Corrections require a separate reversal.',
    onOk: async () => {
      try {
        const payload = await accountingApi(`/journals/${detail.uid}/post`, 'POST', { version: detail.version });
        setDetail(payload.journal); refresh(); message.success('Journal posted.');
      } catch (e) { setError(e.message); throw e; }
    },
  });

  return <Space orientation="vertical" style={{ width: '100%' }} size="middle">
    {error && <Alert type="error" title={error} closable onClose={() => setError('')} />}
    <div className="accounting-toolbar">
      {canWrite && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditor({})}>New journal / expense</Button>}
      <Space wrap><Select aria-label="Journal status" value={status} style={{ width: 170 }} onChange={(value) => { setStatus(value); setPage(1); }}
        options={[{ value: '', label: 'All statuses' }, ...['draft', 'posted', 'discarded'].map((value) => ({ value, label: value }))]} />
      <Button icon={<ReloadOutlined />} onClick={refresh} loading={loading}>Refresh</Button></Space>
    </div>
    <Table csvDownload={false} rowKey="uid" size="small" loading={loading || busy} dataSource={data?.data || []} scroll={{ x: 800 }}
      pagination={{ current: page, total: data?.total || 0, pageSize: 25, showSizeChanger: false, onChange: setPage }}
      columns={[
        { title: 'Number', dataIndex: 'number', render: (value, row) => <Button type="link" onClick={() => inspect(row.uid)}>{value || `Draft ${row.uid.slice(-6)}`}</Button> },
        { title: 'Date', dataIndex: 'entry_date' }, { title: 'Type', dataIndex: 'kind' },
        { title: 'Description', dataIndex: 'description', ellipsis: true }, { title: 'Reference', dataIndex: 'reference' },
        { title: 'Status', dataIndex: 'status', render: (value) => <Tag color={value === 'posted' ? 'green' : value === 'draft' ? 'gold' : 'default'}>{value[0].toUpperCase() + value.slice(1)}</Tag> },
      ]} />
    {editor && <JournalEditor journal={editor.uid ? editor : null} accounts={accounts} book={book} onClose={() => setEditor(null)} onSaved={() => { setEditor(null); setDetail(null); refresh(); }} />}
    <Modal open={!!detail} title={detail?.number || 'Journal draft'} width={900} onCancel={() => setDetail(null)} footer={null}>
      {detail && <Space orientation="vertical" style={{ width: '100%' }} size="middle">
        <Typography.Text>{detail.entry_date} · {detail.kind} · {detail.status} · {book.currency_code}</Typography.Text>
        <Typography.Paragraph>{detail.description}</Typography.Paragraph>
        {detail.reversed_by_uid && <Alert type="info" title="A separate reversal journal has been posted for this entry." />}
        <Table csvFileName="journal-lines.csv" scroll={{ x: 600 }} rowKey={(_, index) => index} size="small" pagination={false} dataSource={detail.lines} columns={[
          { title: 'Account', key: 'account', render: (_, row) => `${row.code} · ${row.name}` }, { title: 'Note', dataIndex: 'description' },
          { title: 'Debit', dataIndex: 'debit', align: 'right', render: money }, { title: 'Credit', dataIndex: 'credit', align: 'right', render: money },
        ]} />
        <Space wrap>
          {canWrite && detail.status === 'draft' && <Button onClick={() => { setEditor(detail); setDetail(null); }}>Edit draft</Button>}
          {canPost && detail.status === 'draft' && <Button type="primary" onClick={post}>Post journal</Button>}
          {canWrite && detail.status === 'draft' && <Button danger onClick={() => { actionForm.resetFields(); setAction('discard'); }}>Discard draft</Button>}
          {canPost && detail.status === 'posted' && !detail.reversed_by_uid && !detail.reversal_of_id && <Button onClick={() => { actionForm.resetFields(); setAction('reverse'); }}>Reverse journal</Button>}
        </Space>
      </Space>}
    </Modal>
    <Modal open={!!action} title={action === 'reverse' ? 'Reverse journal' : 'Discard draft'} onCancel={() => !busy && setAction(null)} footer={null}>
      <Form form={actionForm} layout="vertical" initialValues={{ entry_date: localDate() }} onFinish={async (values) => {
        setBusy(true);
        try {
          await accountingApi(`/journals/${detail.uid}/${action}`, 'POST', { ...values, version: detail.version });
          setAction(null); setDetail(null); refresh(); message.success('Journal updated.');
        } catch (e) { message.error(e.message); } finally { setBusy(false); }
      }}>
        {action === 'reverse' && <Form.Item name="entry_date" label="Reversal date (open period)" rules={[{ required: true }]}><Input type="date" /></Form.Item>}
        <Form.Item name="reason" label="Reason" rules={[{ required: true, whitespace: true }]}><Input.TextArea maxLength={2000} /></Form.Item>
        <Button type="primary" htmlType="submit" loading={busy}>{action === 'reverse' ? 'Post reversal' : 'Discard draft'}</Button>
      </Form>
    </Modal>
  </Space>;
}
