/** @jsxImportSource react */
import React, { useEffect, useState } from 'react';
import { Routes, Route, Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Layout, Spin, Menu, Button, Dropdown, Avatar, Space, Typography, Segmented, FloatButton } from 'antd';
import {
  DashboardOutlined,
  FileTextOutlined,
  BarChartOutlined,
  DollarOutlined,
  BankOutlined,
  ApartmentOutlined,
  IdcardOutlined,
  ShoppingCartOutlined,
  LogoutOutlined,
  UserOutlined,
  SunOutlined,
  MoonOutlined,
} from '@ant-design/icons';

// Components
import InvoiceList from './InvoiceList';
import AgingReport from './AgingReport';
import RevenueReport from './RevenueReport';
import Login from './Login';
import Register from './Register';
import Dashboard from './Dashboard';
import CompanyProfile from './CompanyProfile';
import UserProfile from './UserProfile';
import SalesFlow from './SalesFlow';
import Payments from './Payments';

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

  return (
    <Layout className="app-shell">
      <Sider
        theme={themeMode === 'dark' ? 'dark' : 'light'}
        breakpoint="lg"
        collapsedWidth={0}
        width={256}
        className="app-sider"
      >
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
        />
      </Sider>

      <Layout className="app-main">
        <Header className="app-header page-fade-down">
          <Space>
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
              type="default"
              icon={themeMode === 'dark' ? <SunOutlined /> : <MoonOutlined />}
              onClick={onToggleTheme}
            >
              {themeMode === 'dark' ? 'Light' : 'Dark'}
            </Button>
            <Dropdown menu={{ items: profileMenu }} trigger={['click']}>
              <Button type="text" className="profile-btn">
                <Space>
                  <Avatar size="small" icon={<UserOutlined />} />
                  <span>{user?.name || 'Account'}</span>
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
              <Route path="/sales-flow" element={<SalesFlow />} />
              <Route path="/payments" element={<Payments />} />
              <Route path="/profile/company" element={<CompanyProfile />} />
              <Route path="/profile/user" element={<UserProfile />} />
              <Route path="/reports/aging" element={<AgingReport />} />
              <Route path="/reports/revenue" element={<RevenueReport />} />
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
          label: <Link to="/sales-flow">Quotes & GDS</Link>,
          icon: <ShoppingCartOutlined />,
        },
        {
          key: '/invoices',
          label: <Link to="/invoices">Invoices</Link>,
          icon: <FileTextOutlined />,
        },
        {
          key: '/payments',
          label: <Link to="/payments">Payments</Link>,
          icon: <BankOutlined />,
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
