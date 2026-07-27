import React, { useEffect, useMemo, useState } from 'react';
import {
  Avatar,
  Button,
  Card,
  Descriptions,
  Divider,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Segmented,
  Space,
  Switch,
  Table,
  Tabs,
  Tag,
  Tooltip,
  Typography,
} from 'antd';
import {
  BankOutlined,
  CheckCircleOutlined,
  CloseCircleOutlined,
  DollarCircleOutlined,
  LockOutlined,
  LogoutOutlined,
  MoonOutlined,
  PlusOutlined,
  ReloadOutlined,
  SafetyCertificateOutlined,
  SearchOutlined,
  StopOutlined,
  SunOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { message } from '../services/feedback';
import VoucherPreview from './sales-flow/VoucherPreview';
import CancelledReport from './CancelledReport';

const { Title, Text, Paragraph } = Typography;

const authHeaders = (json = false) => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
};

const money = (value, currency = '') => `${currency ? `${currency} ` : ''}${Number(value || 0).toLocaleString()}`;
const limitLabel = (value) => value ?? 'Unlimited';

function RecordTable({ data, columns }) {
  return (
    <Table
      rowKey={(record) => record.uid || record.id}
      size="small"
      columns={columns}
      dataSource={data || []}
      pagination={false}
      scroll={{ x: true }}
    />
  );
}

export default function InternalPortal({ onLogout, themeMode, themeStyle, onChangeThemeStyle, onToggleTheme }) {
  const currentUser = JSON.parse(localStorage.getItem('user') || '{}');
  const [companies, setCompanies] = useState([]);
  const [selectedUid, setSelectedUid] = useState(null);
  const [detail, setDetail] = useState(null);
  const [records, setRecords] = useState({});
  const [staff, setStaff] = useState([]);
  const [staffRoles, setStaffRoles] = useState([]);
  const [loadingCompanies, setLoadingCompanies] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [savingLimits, setSavingLimits] = useState(false);
  const [staffModalOpen, setStaffModalOpen] = useState(false);
  const [resetPasswordModal, setResetPasswordModal] = useState({ open: false, user: null });
  const [savingStaff, setSavingStaff] = useState(false);
  const [recordModal, setRecordModal] = useState({ open: false, type: '', data: null, loading: false });
  const [savingPassword, setSavingPassword] = useState(false);
  const [savingResetPassword, setSavingResetPassword] = useState(false);
  const [savingManagementAction, setSavingManagementAction] = useState('');
  const [search, setSearch] = useState('');
  const [limitForm] = Form.useForm();
  const [staffForm] = Form.useForm();
  const [passwordForm] = Form.useForm();
  const [resetPasswordForm] = Form.useForm();

  const isSuperAdmin = currentUser.role === 'super-admin';

  const selectedCompany = useMemo(
    () => companies.find((company) => company.uid === selectedUid),
    [companies, selectedUid],
  );

  const summary = useMemo(() => ({
    companies: companies.length,
    activeCompanies: companies.filter((company) => company.is_active).length,
    monthlyInvoices: companies.reduce((total, company) => total + Number(company.counts?.monthly_invoices || 0), 0),
    users: companies.reduce((total, company) => total + Number(company.counts?.users || 0), 0),
  }), [companies]);

  const fetchCompanies = async (query = search) => {
    setLoadingCompanies(true);

    try {
      const qs = query ? `?search=${encodeURIComponent(query)}` : '';
      const response = await fetch(`/api/v1/internal/companies${qs}`, { headers: authHeaders() });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to load companies');
      }

      setCompanies(data.companies || []);
      if (!selectedUid && data.companies?.[0]) {
        setSelectedUid(data.companies[0].uid);
      }
    } catch (error) {
      message.error(error.message || 'Unable to load companies');
    } finally {
      setLoadingCompanies(false);
    }
  };

  const fetchCompanyDetail = async (uid) => {
    if (!uid) return;

    setLoadingDetail(true);

    try {
      const response = await fetch(`/api/v1/internal/companies/${uid}`, { headers: authHeaders() });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to load company details');
      }

      setDetail(data.company);
      setRecords(data.records || {});
      limitForm.setFieldsValue({
        user_limit: data.company.user_limit,
        is_paid: Boolean(data.company.is_paid),
      });
    } catch (error) {
      message.error(error.message || 'Unable to load company details');
    } finally {
      setLoadingDetail(false);
    }
  };

  const fetchStaff = async () => {
    try {
      const response = await fetch('/api/v1/internal/users', { headers: authHeaders() });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to load internal users');
      }

      setStaff(data.users || []);
      setStaffRoles(data.roles || []);
    } catch (error) {
      message.error(error.message || 'Unable to load internal users');
    }
  };

  useEffect(() => {
    fetchCompanies('');
    fetchStaff();
  }, []);

  useEffect(() => {
    fetchCompanyDetail(selectedUid);
  }, [selectedUid]);

  const saveLimits = async (values) => {
    if (!detail?.uid) return;

    setSavingLimits(true);

    try {
      const response = await fetch(`/api/v1/internal/companies/${detail.uid}/limits`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to update status');
      }

      message.success('Company status updated');
      setDetail(data.company);
      await fetchCompanies();
    } catch (error) {
      message.error(error.message || 'Unable to update status');
    } finally {
      setSavingLimits(false);
    }
  };

  const savePaidStatus = (checked) => {
    if (!detail?.uid || savingLimits) return;

    const values = {
      ...limitForm.getFieldsValue(),
      is_paid: checked,
    };

    limitForm.setFieldsValue({ is_paid: checked });
    saveLimits(values);
  };

  const createStaff = async (values) => {
    setSavingStaff(true);

    try {
      const response = await fetch('/api/v1/internal/users', {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to create internal user');
      }

      message.success('Internal user created');
      staffForm.resetFields();
      setStaffModalOpen(false);
      await fetchStaff();
    } catch (error) {
      message.error(error.message || 'Unable to create internal user');
    } finally {
      setSavingStaff(false);
    }
  };

  const updateManagedUser = (updatedUser) => {
    if (!updatedUser?.uid) return;

    setStaff((previous) => previous.map((user) => (user.uid === updatedUser.uid ? { ...user, ...updatedUser } : user)));
    setDetail((previous) => {
      if (!previous?.users?.some((user) => user.uid === updatedUser.uid)) {
        return previous;
      }

      return {
        ...previous,
        users: previous.users.map((user) => (user.uid === updatedUser.uid ? { ...user, ...updatedUser } : user)),
      };
    });
  };

  const openResetPassword = (user) => {
    resetPasswordForm.resetFields();
    setResetPasswordModal({ open: true, user });
  };

  const resetManagedUserPassword = async (values) => {
    const user = resetPasswordModal.user;
    if (!user?.uid) return;

    setSavingResetPassword(true);

    try {
      const response = await fetch(`/api/v1/internal/users/${user.uid}/password`, {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to reset password');
      }

      updateManagedUser(data.user);
      resetPasswordForm.resetFields();
      setResetPasswordModal({ open: false, user: null });
      message.success(data.message || 'Password reset');
    } catch (error) {
      message.error(error.message || 'Unable to reset password');
    } finally {
      setSavingResetPassword(false);
    }
  };

  const toggleManagedUserStatus = async (user) => {
    if (!user?.uid) return;

    const actionKey = `user:${user.uid}`;
    setSavingManagementAction(actionKey);

    try {
      const response = await fetch(`/api/v1/internal/users/${user.uid}/status`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify({ is_active: !user.is_active }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to update user status');
      }

      updateManagedUser(data.user);
      message.success(data.message || 'User status updated');
    } catch (error) {
      message.error(error.message || 'Unable to update user status');
    } finally {
      setSavingManagementAction('');
    }
  };

  const toggleCompanyStatus = async (company) => {
    if (!company?.uid) return;

    const actionKey = `company:${company.uid}`;
    setSavingManagementAction(actionKey);

    try {
      const response = await fetch(`/api/v1/internal/companies/${company.uid}/status`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify({ is_active: !company.is_active }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to update company status');
      }

      setCompanies((previous) => previous.map((item) => (item.uid === data.company.uid ? { ...item, ...data.company } : item)));
      setDetail((previous) => (previous?.uid === data.company.uid ? data.company : previous));
      message.success(data.message || 'Company status updated');
    } catch (error) {
      message.error(error.message || 'Unable to update company status');
    } finally {
      setSavingManagementAction('');
    }
  };

  const openRecord = async (type, uid) => {
    setRecordModal({ open: true, type, data: null, loading: true });

    try {
      const response = await fetch(`/api/v1/internal/${type}/${uid}`, {
        headers: authHeaders(),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || `Unable to load ${type === 'orders' ? 'order' : 'invoice'} details`);
      }

      setRecordModal({ open: true, type, data, loading: false });
    } catch (error) {
      message.error(error.message || 'Unable to load details');
      setRecordModal({ open: false, type: '', data: null, loading: false });
    }
  };

  const updatePassword = async (values) => {
    setSavingPassword(true);

    try {
      const response = await fetch('/api/v1/internal/profile/password', {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to update password');
      }

      passwordForm.resetFields();
      message.success(data.message || 'Password updated');
    } catch (error) {
      message.error(error.message || 'Unable to update password');
    } finally {
      setSavingPassword(false);
    }
  };

  const renderUserActions = (user) => {
    if (!isSuperAdmin) {
      return null;
    }

    const actionKey = `user:${user.uid}`;
    const blockLabel = user.is_active ? 'Block' : 'Unblock';

    return (
      <Space size={6}>
        <Button size="small" icon={<LockOutlined />} onClick={() => openResetPassword(user)}>
          Reset
        </Button>
        <Popconfirm
          title={`${blockLabel} user?`}
          description={`${user.name || user.email} ${user.is_active ? 'will not be able to sign in.' : 'will be allowed to sign in again.'}`}
          okText={blockLabel}
          okType={user.is_active ? 'danger' : 'primary'}
          onConfirm={() => toggleManagedUserStatus(user)}
        >
          <Button
            size="small"
            danger={user.is_active}
            icon={user.is_active ? <StopOutlined /> : <CheckCircleOutlined />}
            loading={savingManagementAction === actionKey}
          >
            {blockLabel}
          </Button>
        </Popconfirm>
      </Space>
    );
  };

  const companyColumns = [
    {
      title: 'Company',
      dataIndex: 'display_name',
      width: 88,
      render: (name, company) => (
        <Space className="internal-company-name-cell" direction="vertical" size={0}>
          <Text strong>{name}</Text>
          <Text type="secondary">{company.tenant?.name || '-'}</Text>
        </Space>
      ),
    },
    {
      title: 'Usage',
      key: 'usage',
      width: 118,
      render: (_, company) => (
        <div className="internal-usage-stack">
          <span><Text type="secondary">Inv</Text><Text strong>{company.counts?.monthly_invoices || 0}/{limitLabel(company.monthly_invoice_limit)}</Text></span>
          <span><Text type="secondary">Ord</Text><Text strong>{company.counts?.orders || 0}/{limitLabel(company.order_limit)}</Text></span>
          <span><Text type="secondary">Usr</Text><Text strong>{company.counts?.users || 0}/{company.user_limit}</Text></span>
        </div>
      ),
    },
    {
      title: <Tooltip title="Status"><CheckCircleOutlined /></Tooltip>,
      dataIndex: 'is_active',
      width: 48,
      align: 'center',
      render: (value) => (
        <Tooltip title={value ? 'Active' : 'Blocked'}>
          {value ? <CheckCircleOutlined className="internal-state-icon success" /> : <StopOutlined className="internal-state-icon muted" />}
        </Tooltip>
      ),
    },
    {
      title: <Tooltip title="Paid customer"><DollarCircleOutlined /></Tooltip>,
      dataIndex: 'is_paid',
      width: 48,
      align: 'center',
      render: (value) => (
        <Tooltip title={value ? 'Paid customer' : 'Unpaid customer'}>
          {value ? <DollarCircleOutlined className="internal-state-icon success" /> : <CloseCircleOutlined className="internal-state-icon warning" />}
        </Tooltip>
      ),
    },
    ...(isSuperAdmin ? [{
      title: <Tooltip title="Access"><StopOutlined /></Tooltip>,
      key: 'actions',
      width: 48,
      align: 'center',
      render: (_, company) => {
        const blockLabel = company.is_active ? 'Block' : 'Unblock';
        const actionKey = `company:${company.uid}`;

        return (
          <Popconfirm
            title={`${blockLabel} company?`}
            description={company.is_active ? 'All company users will be signed out and blocked from signing in.' : 'Company users with active accounts will be allowed to sign in again.'}
            okText={blockLabel}
            okType={company.is_active ? 'danger' : 'primary'}
            onConfirm={(event) => {
              event?.stopPropagation?.();
              toggleCompanyStatus(company);
            }}
          >
            <Button
              size="small"
              danger={company.is_active}
              icon={company.is_active ? <StopOutlined /> : <CheckCircleOutlined />}
              loading={savingManagementAction === actionKey}
              aria-label={blockLabel}
              onClick={(event) => event.stopPropagation()}
            />
          </Popconfirm>
        );
      },
    }] : []),
  ];

  const recordColumns = {
    orders: [
      { title: 'Order', dataIndex: 'order_number' },
      { title: 'Booking', dataIndex: 'booking_reference' },
      { title: 'Status', dataIndex: 'status', render: (value) => <Tag>{value}</Tag> },
      { title: 'Total', render: (_, row) => money(row.total_amount, row.currency_code) },
      { title: '', width: 92, render: (_, row) => <Button size="small" onClick={() => openRecord('orders', row.uid)}>View</Button> },
    ],
    invoices: [
      { title: 'Invoice', dataIndex: 'invoice_number' },
      { title: 'Date', dataIndex: 'invoice_date' },
      { title: 'Status', dataIndex: 'status', render: (value) => <Tag>{value}</Tag> },
      { title: 'Total', render: (_, row) => money(row.total_amount, row.currency_code) },
      { title: 'Outstanding', render: (_, row) => money(row.outstanding_amount, row.currency_code) },
      { title: '', width: 92, render: (_, row) => <Button size="small" onClick={() => openRecord('invoices', row.uid)}>View</Button> },
    ],
    payments: [
      { title: 'Payment', dataIndex: 'payment_number' },
      { title: 'Date', dataIndex: 'payment_date' },
      { title: 'Method', dataIndex: 'payment_method' },
      { title: 'Amount', render: (_, row) => money(row.amount, row.currency_code) },
    ],
    receipts: [
      { title: 'Receipt', dataIndex: 'receipt_number' },
      { title: 'Date', dataIndex: 'receipt_date' },
      { title: 'Method', dataIndex: 'payment_method' },
      { title: 'Amount', render: (_, row) => money(row.amount, row.currency_code) },
    ],
  };

  const staffColumns = [
    { title: 'Name', dataIndex: 'name' },
    { title: 'Email', dataIndex: 'email' },
    { title: 'Role', dataIndex: 'role_name', render: (value) => <Tag color="blue">{value}</Tag> },
    { title: 'Status', dataIndex: 'is_active', render: (value) => <Tag color={value ? 'success' : 'default'}>{value ? 'Active' : 'Inactive'}</Tag> },
    ...(isSuperAdmin ? [{ title: '', key: 'actions', width: 190, render: (_, user) => renderUserActions(user) }] : []),
  ];

  return (
    <div className="internal-portal page-fade-up">
      <header className="internal-header page-fade-down">
        <div className="internal-header-inner">
          <Space align="center" className="internal-brand">
            <Avatar className="internal-brand-mark" icon={<SafetyCertificateOutlined />} />
            <div>
              <Text className="internal-kicker">InYice Operations</Text>
              <Title level={3}>Maintenance Portal</Title>
            </div>
          </Space>
          <Space className="internal-header-actions">
            <Segmented
              size="small"
              value={themeStyle}
              onChange={onChangeThemeStyle}
              options={[
                { label: 'Ocean', value: 'ocean' },
                { label: 'Slate', value: 'slate' },
                { label: 'Sand', value: 'sand' },
              ]}
            />
            <Button
              className="theme-toggle-btn"
              icon={themeMode === 'dark' ? <SunOutlined /> : <MoonOutlined />}
              onClick={onToggleTheme}
            >
              <span className="button-label">{themeMode === 'dark' ? 'Light' : 'Dark'}</span>
            </Button>
            <Tag color="purple">{currentUser.role_name || currentUser.role}</Tag>
            <Button icon={<LogoutOutlined />} onClick={onLogout}>Logout</Button>
          </Space>
        </div>
      </header>

      <main className="internal-body">
        <section className="internal-hero border-beam-aurora">
          <div>
            <Title level={2}>Super Dashboard</Title>
            <Paragraph>
              View client companies, inspect records, and adjust support limits without transaction access.
            </Paragraph>
          </div>
          <div className="internal-summary-grid">
            <div className="internal-summary-card">
              <Text>Total companies</Text>
              <strong>{summary.companies}</strong>
            </div>
            <div className="internal-summary-card">
              <Text>Active companies</Text>
              <strong>{summary.activeCompanies}</strong>
            </div>
            <div className="internal-summary-card">
              <Text>This month invoices</Text>
              <strong>{summary.monthlyInvoices}</strong>
            </div>
            <div className="internal-summary-card">
              <Text>Company users</Text>
              <strong>{summary.users}</strong>
            </div>
          </div>
        </section>

      <Tabs
        className="internal-tabs"
        items={[
          {
            key: 'companies',
            label: 'Companies',
            icon: <BankOutlined />,
            children: (
              <div className="internal-company-grid">
                <Card
                  className="border-beam-aurora internal-company-list"
                  title="Companies"
                  extra={<Button icon={<ReloadOutlined />} onClick={() => fetchCompanies()} loading={loadingCompanies} />}
                >
                  <Space.Compact style={{ width: '100%', marginBottom: 12 }}>
                    <Input
                      prefix={<SearchOutlined />}
                      value={search}
                      onChange={(event) => setSearch(event.target.value)}
                      onPressEnter={() => fetchCompanies()}
                      placeholder="Search company or tenant"
                    />
                    <Button onClick={() => fetchCompanies()}>Search</Button>
                  </Space.Compact>
                  <Table
                    rowKey="uid"
                    size="small"
                    tableLayout="fixed"
                    columns={companyColumns}
                    dataSource={companies}
                    loading={loadingCompanies}
                    pagination={false}
                    scroll={{ x: 350 }}
                    rowClassName={(record) => (record.uid === selectedUid ? 'ant-table-row-selected' : '')}
                    onRow={(record) => ({ onClick: () => setSelectedUid(record.uid) })}
                  />
                </Card>

                <Space direction="vertical" size={16} className="internal-detail-stack">
                  <Card
                    className="border-beam-aurora"
                    loading={loadingDetail}
                    title={detail?.display_name || selectedCompany?.display_name || 'Company Details'}
                    extra={isSuperAdmin && detail ? (
                      <Popconfirm
                        title={`${detail.is_active ? 'Block' : 'Unblock'} company?`}
                        description={detail.is_active ? 'All company users will be signed out and blocked from signing in.' : 'Company users with active accounts will be allowed to sign in again.'}
                        okText={detail.is_active ? 'Block' : 'Unblock'}
                        okType={detail.is_active ? 'danger' : 'primary'}
                        onConfirm={() => toggleCompanyStatus(detail)}
                      >
                        <Button
                          danger={detail.is_active}
                          icon={detail.is_active ? <StopOutlined /> : <CheckCircleOutlined />}
                          loading={savingManagementAction === `company:${detail.uid}`}
                        >
                          {detail.is_active ? 'Block Company' : 'Unblock Company'}
                        </Button>
                      </Popconfirm>
                    ) : null}
                  >
                    {detail ? (
                      <>
                        <Descriptions
                          bordered
                          size="small"
                          column={2}
                          items={[
                            { key: 'tenant', label: 'Tenant', children: `${detail.tenant?.name || '-'} (${detail.tenant?.code || '-'})` },
                            { key: 'email', label: 'Email', children: detail.email || '-' },
                            { key: 'phone', label: 'Phone', children: detail.phone || '-' },
                            { key: 'currency', label: 'Currency', children: detail.base_currency_code },
                            { key: 'status', label: 'Status', children: <Tag color={detail.is_active ? 'success' : 'default'}>{detail.is_active ? 'Active' : 'Inactive'}</Tag> },
                            { key: 'paid', label: 'Paid customer', children: <Tag color={detail.is_paid ? 'success' : 'warning'}>{detail.is_paid ? 'Paid' : 'Unpaid'}</Tag> },
                            { key: 'address', label: 'Address', children: detail.address || '-', span: 2 },
                          ]}
                        />
                        <Form form={limitForm} layout="inline" className="internal-limit-form" onFinish={saveLimits}>
                          <Form.Item name="is_paid" label="Paid customer" valuePropName="checked">
                            <Switch
                              checkedChildren="Paid"
                              unCheckedChildren="Unpaid"
                              loading={savingLimits}
                              onChange={savePaidStatus}
                            />
                          </Form.Item>
                          <Button type="primary" htmlType="submit" loading={savingLimits}>Update Status</Button>
                        </Form>
                      </>
                    ) : (
                      <Text type="secondary">Select a company to view support details.</Text>
                    )}
                  </Card>

                  {detail && (
                    <>
                      <Card className="border-beam-aurora" title="Company Users">
                        <RecordTable
                          data={detail.users}
                          columns={[
                            { title: 'Name', dataIndex: 'name' },
                            { title: 'Email', dataIndex: 'email' },
                            { title: 'Role', dataIndex: 'role_name', render: (value) => <Tag>{value}</Tag> },
                            { title: 'Status', dataIndex: 'is_active', render: (value) => <Tag color={value ? 'success' : 'default'}>{value ? 'Active' : 'Inactive'}</Tag> },
                            ...(isSuperAdmin ? [{ title: '', key: 'actions', width: 190, render: (_, user) => renderUserActions(user) }] : []),
                          ]}
                        />
                      </Card>
                      <Card className="border-beam-aurora" title="Recent Records">
                        <Tabs
                          size="small"
                          items={[
                            { key: 'orders', label: 'Orders', children: <RecordTable data={records.orders} columns={recordColumns.orders} /> },
                            { key: 'invoices', label: 'Invoices', children: <RecordTable data={records.invoices} columns={recordColumns.invoices} /> },
                            { key: 'payments', label: 'Payments', children: <RecordTable data={records.payments} columns={recordColumns.payments} /> },
                            { key: 'receipts', label: 'Receipts', children: <RecordTable data={records.receipts} columns={recordColumns.receipts} /> },
                          ]}
                        />
                      </Card>
                    </>
                  )}
                </Space>
              </div>
            ),
          },
          {
            key: 'cancelled',
            label: 'Cancelled Report',
            icon: <StopOutlined />,
            children: (
              <Card className="border-beam-aurora">
                <CancelledReport embedded />
              </Card>
            ),
          },
          {
            key: 'profile',
            label: 'My Profile',
            icon: <UserOutlined />,
            children: (
              <Card className="border-beam-aurora" title="Profile & Password">
                <Descriptions
                  bordered
                  size="small"
                  column={1}
                  items={[
                    { key: 'name', label: 'Name', children: currentUser.name || '-' },
                    { key: 'email', label: 'Email', children: currentUser.email || '-' },
                    { key: 'role', label: 'Role', children: <Tag color="purple">{currentUser.role_name || currentUser.role}</Tag> },
                  ]}
                />
                <Divider />
                <Form form={passwordForm} layout="vertical" onFinish={updatePassword} style={{ maxWidth: 520 }}>
                  <Form.Item name="current_password" label="Current Password" rules={[{ required: true, message: 'Enter current password' }]}>
                    <Input.Password />
                  </Form.Item>
                  <Form.Item name="password" label="New Password" rules={[{ required: true, min: 8, message: 'Use at least 8 characters' }]}>
                    <Input.Password />
                  </Form.Item>
                  <Form.Item
                    name="password_confirmation"
                    label="Confirm New Password"
                    dependencies={['password']}
                    rules={[
                      { required: true, message: 'Confirm new password' },
                      ({ getFieldValue }) => ({
                        validator(_, value) {
                          if (!value || getFieldValue('password') === value) return Promise.resolve();
                          return Promise.reject(new Error('Passwords do not match'));
                        },
                      }),
                    ]}
                  >
                    <Input.Password />
                  </Form.Item>
                  <Button type="primary" htmlType="submit" loading={savingPassword}>Update Password</Button>
                </Form>
              </Card>
            ),
          },
          {
            key: 'staff',
            label: 'Internal Users',
            icon: <TeamOutlined />,
            children: (
              <Card
                className="border-beam-aurora"
                title="InYice Staff"
                extra={isSuperAdmin ? <Button type="primary" icon={<PlusOutlined />} onClick={() => setStaffModalOpen(true)}>Add User</Button> : null}
              >
                <Table rowKey="uid" columns={staffColumns} dataSource={staff} pagination={false} />
              </Card>
            ),
          },
        ]}
      />

      <Modal
        title="Add Internal User"
        open={staffModalOpen}
        onCancel={() => setStaffModalOpen(false)}
        onOk={() => staffForm.submit()}
        confirmLoading={savingStaff}
        destroyOnClose
      >
        <Form form={staffForm} layout="vertical" preserve={false} onFinish={createStaff}>
          <Form.Item name="name" label="Name" rules={[{ required: true, message: 'Enter a name' }]}>
            <Input />
          </Form.Item>
          <Form.Item name="email" label="Email" rules={[{ required: true }, { type: 'email' }]}>
            <Input />
          </Form.Item>
          <Form.Item name="role" label="Role" rules={[{ required: true, message: 'Select a role' }]}>
            <Select options={staffRoles.map((role) => ({ value: role.code, label: role.name }))} />
          </Form.Item>
          <Form.Item name="password" label="Password" rules={[{ required: true, min: 8 }]}>
            <Input.Password />
          </Form.Item>
          <Form.Item
            name="password_confirmation"
            label="Confirm Password"
            dependencies={['password']}
            rules={[
              { required: true },
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (!value || getFieldValue('password') === value) return Promise.resolve();
                  return Promise.reject(new Error('Passwords do not match'));
                },
              }),
            ]}
          >
            <Input.Password />
          </Form.Item>
        </Form>
      </Modal>
      <Modal
        title={`Reset Password${resetPasswordModal.user ? `: ${resetPasswordModal.user.name || resetPasswordModal.user.email}` : ''}`}
        open={resetPasswordModal.open}
        onCancel={() => {
          resetPasswordForm.resetFields();
          setResetPasswordModal({ open: false, user: null });
        }}
        onOk={() => resetPasswordForm.submit()}
        confirmLoading={savingResetPassword}
        destroyOnClose
      >
        <Form form={resetPasswordForm} layout="vertical" preserve={false} onFinish={resetManagedUserPassword}>
          <Form.Item name="password" label="New Password" rules={[{ required: true, min: 8, message: 'Use at least 8 characters' }]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
          <Form.Item
            name="password_confirmation"
            label="Confirm New Password"
            dependencies={['password']}
            rules={[
              { required: true, message: 'Confirm new password' },
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (!value || getFieldValue('password') === value) return Promise.resolve();
                  return Promise.reject(new Error('Passwords do not match'));
                },
              }),
            ]}
          >
            <Input.Password autoComplete="new-password" />
          </Form.Item>
        </Form>
      </Modal>
      <Modal
        title={recordModal.type === 'orders' ? 'Order / Voucher Details' : 'Invoice Details'}
        open={recordModal.open}
        onCancel={() => setRecordModal({ open: false, type: '', data: null, loading: false })}
        footer={null}
        width={recordModal.type === 'orders' ? 1120 : 920}
        destroyOnClose
      >
        {recordModal.loading && <Text type="secondary">Loading details...</Text>}
        {!recordModal.loading && recordModal.type === 'orders' && recordModal.data && (
          <div className="internal-record-detail">
            <Descriptions
              bordered
              size="small"
              column={2}
              items={[
                { key: 'company', label: 'Company', children: recordModal.data.company?.display_name || '-' },
                { key: 'customer', label: 'Customer', children: recordModal.data.customer?.name || '-' },
                { key: 'order', label: 'Order', children: recordModal.data.order_number || '-' },
                { key: 'booking', label: 'Booking Ref', children: recordModal.data.booking_reference || '-' },
                { key: 'status', label: 'Status', children: <Tag>{recordModal.data.status}</Tag> },
                { key: 'total', label: 'Total', children: money(recordModal.data.total_amount, recordModal.data.currency_code) },
              ]}
            />
            <Divider />
            <Title level={4}>Voucher Preview</Title>
            <VoucherPreview order={recordModal.data} />
          </div>
        )}
        {!recordModal.loading && recordModal.type === 'invoices' && recordModal.data && (
          <div className="internal-record-detail">
            <Descriptions
              bordered
              size="small"
              column={2}
              items={[
                { key: 'company', label: 'Company', children: recordModal.data.company?.display_name || '-' },
                { key: 'customer', label: 'Customer', children: recordModal.data.customer?.name || '-' },
                { key: 'invoice', label: 'Invoice', children: recordModal.data.invoice_number || '-' },
                { key: 'date', label: 'Date', children: String(recordModal.data.invoice_date || '').slice(0, 10) },
                { key: 'status', label: 'Status', children: <Tag>{recordModal.data.status}</Tag> },
                { key: 'outstanding', label: 'Outstanding', children: money(recordModal.data.outstanding_amount, recordModal.data.currency_code) },
              ]}
            />
            <Table
              rowKey={(row) => row.uid || row.id}
              size="small"
              pagination={false}
              style={{ marginTop: 16 }}
              dataSource={recordModal.data.lines || []}
              columns={[
                { title: 'Description', dataIndex: 'description' },
                { title: 'Qty', dataIndex: 'quantity', width: 80, align: 'right' },
                { title: 'Unit', dataIndex: 'unit_price', width: 130, align: 'right', render: (value) => money(value, recordModal.data.currency_code) },
                { title: 'Total', dataIndex: 'total_price', width: 130, align: 'right', render: (value) => money(value, recordModal.data.currency_code) },
              ]}
            />
          </div>
        )}
      </Modal>
      </main>
    </div>
  );
}
