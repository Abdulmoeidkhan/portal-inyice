/** @jsxImportSource react */
import React, { useEffect, useState } from 'react';
import { Routes, Route, Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Layout, Spin, Menu, Button, Dropdown, Avatar, Space, Typography, Segmented, FloatButton, Drawer } from 'antd';
import {
  DashboardOutlined,
  FileTextOutlined,
  BarChartOutlined,
  DollarOutlined,
  BankOutlined,
  ApartmentOutlined,
  IdcardOutlined,
  ShoppingCartOutlined,
  TeamOutlined,
  LogoutOutlined,
  UserOutlined,
  SunOutlined,
  MoonOutlined,
  MenuOutlined,
} from '@ant-design/icons';

// Components
import InvoiceList from './InvoiceList';
import OrderList from './OrderList';
import AgingReport from './AgingReport';
import RevenueReport from './RevenueReport';
import Login from './Login';
import Register from './Register';
import Dashboard from './Dashboard';
import CompanyProfile from './CompanyProfile';
import UserProfile from './UserProfile';
import SalesFlow from './SalesFlow';
import Payments from './Payments';
import CustomerList from './CustomerList';
import VendorList from './VendorList';
import CustomerStatement from './CustomerStatement';
import VendorStatement from './VendorStatement';
import VendorPayments from './VendorPayments';

const { Header, Sider, Content } = Layout;
const { Text } = Typography;

const NotFound = () => (
  <div className="page-shell page-fade-up">
    <div className="elevated-card">
      <h1>404 - Page Not Found</h1>
      <p>This page does not exist.</p>
      <Link to="/">Go back to dashboard</Link>
    </div>
  </div>
);

function useAuthToken() {
  return localStorage.getItem('auth_token') || localStorage.getItem('token');
}

function AuthenticatedLayout({ menuItems, onLogout, themeMode, themeStyle, onChangeThemeStyle, onToggleTheme }) {
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const selectedKey = menuItems
    .flatMap((item) => (item.children ? item.children : [item]))
    .find((item) => item.key === location.pathname)?.key || '/';

  const user = JSON.parse(localStorage.getItem('user') || '{}');

  const profileMenu = [
    {
      key: 'name',
      label: <span>{user?.name || 'User'}</span>,
      disabled: true,
    },
    {
      key: 'email',
      label: <span>{user?.email || '-'}</span>,
      disabled: true,
    },
    {
      type: 'divider',
    },
    {
      key: 'profile',
      icon: <UserOutlined />,
      label: 'User Profile',
      onClick: () => navigate('/profile/user'),
    },
    {
      key: 'logout',
      icon: <LogoutOutlined />,
      label: 'Logout',
      onClick: onLogout,
    },
  ];

  const navigation = (
    <>
      <div className="brand-block">
        <div className="brand-glow" />
        <h2>inYice Lite</h2>
        <Text className="brand-caption">Travel Finance OS</Text>
      </div>
      <Menu
        className="app-nav-menu"
        theme={themeMode === 'dark' ? 'dark' : 'light'}
        mode="inline"
        selectedKeys={[selectedKey]}
        items={menuItems}
        onClick={() => setMobileNavOpen(false)}
      />
    </>
  );

  return (
    <Layout className="app-shell">
      <Sider
        theme={themeMode === 'dark' ? 'dark' : 'light'}
        breakpoint="lg"
        collapsedWidth={0}
        width={256}
        className="app-sider"
      >
        {navigation}
      </Sider>

      <Drawer
        rootClassName="mobile-nav-drawer"
        placement="left"
        size="default"
        open={mobileNavOpen}
        onClose={() => setMobileNavOpen(false)}
        styles={{ body: { padding: 0 } }}
        title={null}
        closeIcon={false}
      >
        {navigation}
      </Drawer>

      <Layout className="app-main">
        <Header className="app-header page-fade-down">
          <Button
            className="mobile-menu-btn"
            type="text"
            aria-label="Open navigation menu"
            icon={<MenuOutlined />}
            onClick={() => setMobileNavOpen(true)}
          />
          <Space className="header-actions" size="small">
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
              type="default"
              icon={themeMode === 'dark' ? <SunOutlined /> : <MoonOutlined />}
              onClick={onToggleTheme}
            >
              <span className="button-label">{themeMode === 'dark' ? 'Light' : 'Dark'}</span>
            </Button>
            <Dropdown menu={{ items: profileMenu }} trigger={['click']}>
              <Button type="text" className="profile-btn">
                <Space>
                  <Avatar size="small" icon={<UserOutlined />} />
                  <span className="account-name">{user?.name || 'Account'}</span>
                </Space>
              </Button>
            </Dropdown>
          </Space>
        </Header>

        <Content className="app-content">
          <div key={location.pathname} className="route-transition">
            <Routes>
              <Route path="/" element={<Dashboard />} />
              <Route path="/invoices" element={<InvoiceList />} />
              <Route path="/orders" element={<OrderList />} />
              <Route path="/sales-flow" element={<SalesFlow />} />
              <Route path="/payments" element={<Payments />} />
              <Route path="/vendor-payments" element={<VendorPayments />} />
              <Route path="/customers" element={<CustomerList />} />
              <Route path="/vendors" element={<VendorList />} />
              <Route path="/profile/company" element={<CompanyProfile />} />
              <Route path="/profile/user" element={<UserProfile />} />
              <Route path="/reports/aging" element={<AgingReport />} />
              <Route path="/reports/revenue" element={<RevenueReport />} />
              <Route path="/statements/customers" element={<CustomerStatement />} />
              <Route path="/statements/vendors" element={<VendorStatement />} />
              <Route path="*" element={<NotFound />} />
            </Routes>
          </div>
          <FloatButton.BackTop visibilityHeight={280} />
        </Content>
      </Layout>
    </Layout>
  );
}

export default function App({ themeMode, themeStyle, onChangeThemeStyle, onToggleTheme }) {
  const [loading, setLoading] = useState(true);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    setIsAuthenticated(!!useAuthToken());
    setLoading(false);
  }, []);

  const handleLogout = async () => {
    const token = useAuthToken();

    try {
      if (token) {
        await fetch('/api/v1/auth/logout', {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
          },
        });
      }
    } finally {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      setIsAuthenticated(false);
      navigate('/login');
    }
  };

  if (loading) {
    return (
      <div className="center-screen">
        <Spin size="large" />
      </div>
    );
  }

  const menuItems = [
    {
      key: '/',
      icon: <DashboardOutlined />,
      label: <Link to="/">Dashboard</Link>,
    },
    {
      key: 'invoicing',
      label: 'Finance',
      icon: <DollarOutlined />,
      children: [
        {
          key: '/sales-flow',
          label: <Link to="/sales-flow">Create Order</Link>,
          icon: <ShoppingCartOutlined />,
        },
        {
          key: '/orders',
          label: <Link to="/orders">Orders</Link>,
          icon: <FileTextOutlined />,
        },
        {
          key: '/invoices',
          label: <Link to="/invoices">Invoices</Link>,
          icon: <FileTextOutlined />,
        },
        {
          key: '/payments',
          label: <Link to="/payments">Customer Receipts</Link>,
          icon: <BankOutlined />,
        },
        {
          key: '/vendor-payments',
          label: <Link to="/vendor-payments">Vendor Payments</Link>,
          icon: <BankOutlined />,
        },
      ],
    },
    {
      key: 'master-data',
      label: 'Master Data',
      icon: <TeamOutlined />,
      children: [
        {
          key: '/customers',
          label: <Link to="/customers">Customers</Link>,
        },
        {
          key: '/vendors',
          label: <Link to="/vendors">Vendors</Link>,
        },
      ],
    },
    {
      key: 'profiles',
      label: 'Profiles',
      icon: <IdcardOutlined />,
      children: [
        {
          key: '/profile/company',
          label: <Link to="/profile/company">Company Profile</Link>,
          icon: <ApartmentOutlined />,
        },
      ],
    },
    {
      key: 'reports',
      label: 'Reports',
      icon: <BarChartOutlined />,
      children: [
        {
          key: '/reports/aging',
          label: <Link to="/reports/aging">Aging Report</Link>,
        },
        {
          key: '/reports/revenue',
          label: <Link to="/reports/revenue">Revenue Report</Link>,
        },
        {
          key: '/statements/customers',
          label: <Link to="/statements/customers">Customer Statement</Link>,
        },
        {
          key: '/statements/vendors',
          label: <Link to="/statements/vendors">Vendor Statement</Link>,
        },
      ],
    },
  ];

  if (!isAuthenticated) {
    return (
      <Routes>
        <Route path="/login" element={<Login onLoginSuccess={() => setIsAuthenticated(true)} />} />
        <Route path="/register" element={<Register onRegistered={() => setIsAuthenticated(true)} />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    );
  }

  return (
    <AuthenticatedLayout
      menuItems={menuItems}
      onLogout={handleLogout}
      themeMode={themeMode}
      themeStyle={themeStyle}
      onChangeThemeStyle={onChangeThemeStyle}
      onToggleTheme={onToggleTheme}
    />
  );
}
