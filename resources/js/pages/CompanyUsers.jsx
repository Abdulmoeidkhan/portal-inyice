import React, { useEffect, useMemo, useState } from 'react';
import { Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Tag, Tooltip, Typography, Grid } from 'antd';
import { CheckCircleOutlined, CrownOutlined, DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined, StopOutlined, TeamOutlined, UserOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;

const authHeaders = (json = false) => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
};

export default function CompanyUsers() {
  const [form] = Form.useForm();
  const [editForm] = Form.useForm();
  const screens = Grid.useBreakpoint();
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [users, setUsers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [limits, setLimits] = useState({ current: 0, max: 4, remaining: 0 });
  const [editing, setEditing] = useState(null);

  const roleOptions = useMemo(
    () => roles.map((role) => ({ value: role.code, label: role.name })),
    [roles],
  );

  const canCreate = limits.remaining > 0;
  const compactActions = !screens.sm;
  const compactUserTable = !screens.md;

  const fetchCompanyUsers = async () => {
    setLoading(true);

    try {
      const response = await fetch('/api/v1/company-users', {
        headers: authHeaders(),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to load company users');
      }

      setUsers(data.users || []);
      setRoles(data.roles || []);
      setLimits(data.limits || { current: 0, max: 4, remaining: 0 });
    } catch (error) {
      message.error(error.message || 'Unable to load company users');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCompanyUsers();
  }, []);

  const handleCreate = async (values) => {
    setSaving(true);

    try {
      const response = await fetch('/api/v1/company-users', {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to create company user');
      }

      message.success(data.message || 'Company user created');
      form.resetFields();
      await fetchCompanyUsers();
    } catch (error) {
      message.error(error.message || 'Unable to create company user');
    } finally {
      setSaving(false);
    }
  };

  const openEdit = (record) => {
    setEditing(record);
    editForm.resetFields();
    editForm.setFieldsValue({
      name: record.name,
      email: record.email,
      role: record.role,
      is_active: record.is_active,
      password: undefined,
      password_confirmation: undefined,
    });
  };

  const handleUpdate = async () => {
    if (!editing) return;

    setSaving(true);

    try {
      const values = await editForm.validateFields();
      if (!values.password) {
        delete values.password;
        delete values.password_confirmation;
      }
      const response = await fetch(`/api/v1/company-users/${editing.uid}`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to update company user');
      }

      message.success(data.message || 'Company user updated');
      setEditing(null);
      editForm.resetFields();
      await fetchCompanyUsers();
    } catch (error) {
      if (!error?.errorFields) {
        message.error(error.message || 'Unable to update company user');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (record) => {
    setSaving(true);

    try {
      const response = await fetch(`/api/v1/company-users/${record.uid}`, {
        method: 'DELETE',
        headers: authHeaders(),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to delete company user');
      }

      message.success(data.message || 'Company user deleted');
      await fetchCompanyUsers();
    } catch (error) {
      message.error(error.message || 'Unable to delete company user');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    {
      title: 'Name',
      dataIndex: 'name',
      key: 'name',
      width: compactUserTable ? 210 : undefined,
      render: (name, record) => (
        <Space className="company-user-identity" direction="vertical" size={0}>
          <Text strong>{name}</Text>
          <Text type="secondary">{record.email}</Text>
        </Space>
      ),
    },
    {
      title: compactUserTable ? <UserOutlined /> : 'Role',
      dataIndex: 'role_name',
      key: 'role',
      width: compactUserTable ? 58 : 120,
      align: 'center',
      render: (roleName, record) => {
        const label = roleName || record.role;
        const isOwner = record.role === 'owner';

        return (
          <Tooltip title={label}>
            <span className={`company-user-icon-badge ${isOwner ? 'is-owner' : 'is-user'}`} aria-label={`Role: ${label}`}>
              {isOwner ? <CrownOutlined /> : <UserOutlined />}
            </span>
          </Tooltip>
        );
      },
    },
    {
      title: compactUserTable ? <CheckCircleOutlined /> : 'Status',
      dataIndex: 'is_active',
      key: 'status',
      width: compactUserTable ? 58 : 120,
      align: 'center',
      render: (isActive) => (
        <Tooltip title={isActive ? 'Active' : 'Inactive'}>
          <span className={`company-user-icon-badge ${isActive ? 'is-active' : 'is-inactive'}`} aria-label={isActive ? 'Status: Active' : 'Status: Inactive'}>
            {isActive ? <CheckCircleOutlined /> : <StopOutlined />}
          </span>
        </Tooltip>
      ),
    },
    {
      title: 'Actions',
      key: 'actions',
      width: 132,
      fixed: 'right',
      render: (_, record) => {
        const protectedUser = record.role === 'owner';

        return (
          <Space>
            <Button
              size="small"
              icon={<EditOutlined />}
              disabled={protectedUser}
              onClick={() => openEdit(record)}
            >
              {compactActions ? null : 'Edit'}
            </Button>
            <Popconfirm
              title="Delete this company user?"
              description="Their active sessions will be revoked."
              okText="Delete"
              okButtonProps={{ danger: true }}
              disabled={protectedUser}
              onConfirm={() => handleDelete(record)}
            >
              <Button danger size="small" icon={<DeleteOutlined />} disabled={protectedUser}>
                {compactActions ? null : 'Delete'}
              </Button>
            </Popconfirm>
          </Space>
        );
      },
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Space align="start" style={{ justifyContent: 'space-between', width: '100%' }}>
          <div>
            <Title level={2} style={{ margin: 0 }}>Company Users</Title>
            <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
              Create up to three extra users for this company and assign their access role.
            </Paragraph>
          </div>
          <Tag color={canCreate ? 'processing' : 'default'}>
            {limits.remaining} of {Math.max(limits.max - 1, 0)} seats left
          </Tag>
        </Space>
      </div>

      <Card
        className="border-beam-aurora company-users-card"
        title={(
          <Space>
            <TeamOutlined />
            Users
          </Space>
        )}
        extra={<Button icon={<ReloadOutlined />} onClick={fetchCompanyUsers} loading={loading} />}
        style={{ marginBottom: 16 }}
      >
        <Table
          rowKey="uid"
          columns={columns}
          dataSource={users}
          loading={loading}
          pagination={false}
          scroll={{ x: compactUserTable ? 520 : true }}
        />
      </Card>

      <Card className="border-beam-aurora" title="Create User">
        <Form
          form={form}
          layout="vertical"
          name="company-user-create"
          initialValues={{ role: 'sales' }}
          onFinish={handleCreate}
          disabled={!canCreate}
        >
          <Form.Item name="name" label="Name" rules={[{ required: true, message: 'Please enter the user name' }]}>
            <Input placeholder="Full name" />
          </Form.Item>
          <Form.Item
            name="email"
            label="Email"
            rules={[
              { required: true, message: 'Please enter the user email' },
              { type: 'email', message: 'Please enter a valid email' },
            ]}
          >
            <Input placeholder="user@example.com" />
          </Form.Item>
          <Form.Item name="role" label="Role" rules={[{ required: true, message: 'Please select a role' }]}>
            <Select options={roleOptions} placeholder="Select role" />
          </Form.Item>
          <Form.Item
            name="password"
            label="Password"
            rules={[{ required: true, min: 8, message: 'Use at least 8 characters' }]}
          >
            <Input.Password placeholder="Temporary password" autoComplete="new-password" />
          </Form.Item>
          <Form.Item
            name="password_confirmation"
            label="Confirm Password"
            dependencies={['password']}
            rules={[
              { required: true, message: 'Please confirm the password' },
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (!value || getFieldValue('password') === value) {
                    return Promise.resolve();
                  }

                  return Promise.reject(new Error('Passwords do not match'));
                },
              }),
            ]}
          >
            <Input.Password placeholder="Repeat temporary password" autoComplete="new-password" />
          </Form.Item>
          <Button type="primary" htmlType="submit" icon={<PlusOutlined />} loading={saving} disabled={!canCreate}>
            {compactActions ? null : 'Create User'}
          </Button>
        </Form>
      </Card>

      <Modal
        title={`Edit ${editing?.name || 'company user'}`}
        open={!!editing}
        onOk={handleUpdate}
        onCancel={() => { setEditing(null); editForm.resetFields(); }}
        cancelButtonProps={{ danger: true }}
        confirmLoading={saving}
        okText="Save changes"
      >
        <Form form={editForm} layout="vertical" name="company-user-edit">
          <Form.Item name="name" label="Name" rules={[{ required: true, message: 'Please enter the user name' }]}>
            <Input placeholder="Full name" />
          </Form.Item>
          <Form.Item
            name="email"
            label="Email"
            rules={[
              { required: true, message: 'Please enter the user email' },
              { type: 'email', message: 'Please enter a valid email' },
            ]}
          >
            <Input placeholder="user@example.com" />
          </Form.Item>
          <Form.Item name="role" label="Role" rules={[{ required: true, message: 'Please select a role' }]}>
            <Select options={roleOptions} placeholder="Select role" />
          </Form.Item>
          <Form.Item name="is_active" label="Status" rules={[{ required: true, message: 'Please select a status' }]}>
            <Select options={[{ value: true, label: 'Active' }, { value: false, label: 'Inactive' }]} />
          </Form.Item>
          <Form.Item name="password" label="New Password" rules={[{ min: 8, message: 'Use at least 8 characters' }]}>
            <Input.Password placeholder="Leave blank to keep current password" autoComplete="new-password" />
          </Form.Item>
          <Form.Item
            name="password_confirmation"
            label="Confirm New Password"
            dependencies={['password']}
            rules={[
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (!getFieldValue('password') || getFieldValue('password') === value) {
                    return Promise.resolve();
                  }

                  return Promise.reject(new Error('Passwords do not match'));
                },
              }),
            ]}
          >
            <Input.Password placeholder="Repeat new password" autoComplete="new-password" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
