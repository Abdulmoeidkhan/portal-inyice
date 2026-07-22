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
  UsergroupAddOutlined,
  LogoutOutlined,
  UserOutlined,
  SunOutlined,
  MoonOutlined,
  MenuOutlined,
  SearchOutlined,
} from '@ant-design/icons';

// Components
import InvoiceList from './InvoiceList';
import OrderList from './OrderList';
import OrderEdit from './OrderEdit';
import AgingReport from './AgingReport';
import RevenueReport from './RevenueReport';
import ProfitReport from './ProfitReport';
import PaymentReport from './PaymentReport';
import InvoiceDetail from './InvoiceDetail';
import VoucherDetail from './VoucherDetail';
import CounterpartyTransaction from './CounterpartyTransaction';
import Login from './Login';
import Register from './Register';
import Dashboard from './Dashboard';
import CompanyProfile from './CompanyProfile';
import CompanyUsers from './CompanyUsers';
import InternalPortal from './InternalPortal';
import UserProfile from './UserProfile';
import SalesFlow from './SalesFlow';
import Payments from './Payments';
import CustomerList from './CustomerList';
import VendorList from './VendorList';
import CustomerStatement from './CustomerStatement';
import VendorStatement from './VendorStatement';
import VendorPayments from './VendorPayments';
import CancelledReport from './CancelledReport';
import ReferenceSearch from './ReferenceSearch';

const { Header, Sider, Content } = Layout;
const { Text } = Typography;
const AUTH_IDLE_TIMEOUT_MS = 4 * 60 * 60 * 1000;
const AUTH_LAST_ACTIVITY_KEY = 'auth_last_activity_at';
const AUTH_ACTIVITY_THROTTLE_MS = 60 * 1000;

function getLastAuthActivity() {
  return Number(localStorage.getItem(AUTH_LAST_ACTIVITY_KEY) || 0);
}

function markAuthActivity(force = false) {
  const now = Date.now();
  const lastActivity = getLastAuthActivity();

  if (force || !lastActivity || now - lastActivity > AUTH_ACTIVITY_THROTTLE_MS) {
    localStorage.setItem(AUTH_LAST_ACTIVITY_KEY, String(now));
  }
}

function hasAuthIdleExpired() {
  const lastActivity = getLastAuthActivity();

  return !!lastActivity && Date.now() - lastActivity >= AUTH_IDLE_TIMEOUT_MS;
}

function clearAuthStorage() {
  localStorage.removeItem('auth_token');
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  localStorage.removeItem(AUTH_LAST_ACTIVITY_KEY);
}

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
  const canAccessReports = user?.role !== 'sales';
  const canAccessCancelledReport = ['owner', 'admin'].includes(user?.role);

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
        {/* <img className="brand-logo" src="/images/icons/icon-512x512.png" alt="InYice OS" /> */}
        <h2>InYice OS</h2>
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
              <Route path="/invoices/:uid" element={<InvoiceDetail />} />
              <Route path="/orders" element={<OrderList />} />
              <Route path="/orders/:uid/voucher" element={<VoucherDetail />} />
              <Route path="/orders/:uid/edit" element={<OrderEdit />} />
              <Route path="/reference-search" element={<ReferenceSearch />} />
              <Route path="/sales-flow" element={<SalesFlow />} />
              <Route path="/payments" element={<Payments />} />
              <Route path="/customer-payments" element={<CounterpartyTransaction direction="payment" partyType="customer" />} />
              <Route path="/vendor-payments" element={<VendorPayments />} />
              <Route path="/vendor-receipts" element={<CounterpartyTransaction direction="receipt" partyType="vendor" />} />
              <Route path="/customers" element={<CustomerList />} />
              <Route path="/vendors" element={<VendorList />} />
              <Route path="/profile/company" element={<CompanyProfile />} />
              <Route path="/profile/company-users" element={<CompanyUsers />} />
              <Route path="/profile/user" element={<UserProfile />} />
              <Route path="/reports/aging" element={canAccessReports ? <AgingReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/revenue" element={canAccessReports ? <RevenueReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/profit" element={canAccessReports ? <ProfitReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/payments" element={canAccessReports ? <PaymentReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/receipts" element={canAccessReports ? <PaymentReport direction="receipt" /> : <Navigate to="/" replace />} />
              <Route path="/reports/cancelled" element={canAccessCancelledReport ? <CancelledReport /> : <Navigate to="/" replace />} />
              <Route path="/statements/customers" element={canAccessReports ? <CustomerStatement /> : <Navigate to="/" replace />} />
              <Route path="/statements/vendors" element={canAccessReports ? <VendorStatement /> : <Navigate to="/" replace />} />
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
    if (useAuthToken() && hasAuthIdleExpired()) {
      clearAuthStorage();
    } else if (useAuthToken()) {
      markAuthActivity(true);
    }

    setIsAuthenticated(!!useAuthToken());
    setLoading(false);
  }, []);

  const handleLogout = async ({ revokeToken = true } = {}) => {
    const token = useAuthToken();

    try {
      if (token && revokeToken) {
        await fetch('/api/v1/auth/logout', {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
          },
        });
      }
    } finally {
      clearAuthStorage();
      setIsAuthenticated(false);
      navigate('/login');
    }
  };

  useEffect(() => {
    if (!isAuthenticated) {
      return undefined;
    }

    let timeoutId;

    const signOutIfIdle = () => {
      if (hasAuthIdleExpired()) {
        handleLogout();
        return true;
      }

      return false;
    };

    const scheduleIdleCheck = () => {
      window.clearTimeout(timeoutId);

      const lastActivity = getLastAuthActivity() || Date.now();
      const remainingMs = Math.max(AUTH_IDLE_TIMEOUT_MS - (Date.now() - lastActivity), 0);
      timeoutId = window.setTimeout(signOutIfIdle, remainingMs + 1000);
    };

    const handleActivity = () => {
      if (signOutIfIdle()) {
        return;
      }

      markAuthActivity();
      scheduleIdleCheck();
    };

    const handleStorage = (event) => {
      if (event.key === AUTH_LAST_ACTIVITY_KEY) {
        scheduleIdleCheck();
      }

      if ((event.key === 'auth_token' || event.key === 'token') && !event.newValue) {
        clearAuthStorage();
        setIsAuthenticated(false);
        navigate('/login');
      }
    };

    const activityEvents = ['click', 'keydown', 'mousemove', 'scroll', 'touchstart', 'visibilitychange'];

    activityEvents.forEach((eventName) => {
      window.addEventListener(eventName, handleActivity, { passive: true });
    });
    window.addEventListener('storage', handleStorage);

    if (!getLastAuthActivity()) {
      markAuthActivity(true);
    }

    scheduleIdleCheck();

    return () => {
      window.clearTimeout(timeoutId);
      activityEvents.forEach((eventName) => {
        window.removeEventListener(eventName, handleActivity);
      });
      window.removeEventListener('storage', handleStorage);
    };
  }, [isAuthenticated, navigate]);

  if (loading) {
    return (
      <div className="center-screen">
        <Spin size="large" />
      </div>
    );
  }

  const signedInUser = JSON.parse(localStorage.getItem('user') || '{}');
  const canAccessReports = signedInUser.role !== 'sales';
  const canAccessCancelledReport = ['owner', 'admin'].includes(signedInUser.role);

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
          key: '/reference-search',
          label: <Link to="/reference-search">Reference Search</Link>,
          icon: <SearchOutlined />,
        },
        {
          key: '/payments',
          label: <Link to="/payments">Customer Receipts</Link>,
          icon: <BankOutlined />,
        },
        {
          key: '/customer-payments',
          label: <Link to="/customer-payments">Customer Payments</Link>,
          icon: <BankOutlined />,
        },
        {
          key: '/vendor-receipts',
          label: <Link to="/vendor-receipts">Vendor Receipts</Link>,
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
        {
          key: '/profile/company-users',
          label: <Link to="/profile/company-users">Company Users</Link>,
          icon: <UsergroupAddOutlined />,
        },
      ],
    },
    ...(canAccessReports ? [{
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
          key: '/reports/profit',
          label: <Link to="/reports/profit">Profit Report</Link>,
        },
        {
          key: '/reports/receipts',
          label: <Link to="/reports/receipts">Receipt Report</Link>,
        },
        {
          key: '/reports/payments',
          label: <Link to="/reports/payments">Payment Report</Link>,
        },
        ...(canAccessCancelledReport ? [{
          key: '/reports/cancelled',
          label: <Link to="/reports/cancelled">Cancelled Report</Link>,
        }] : []),
        {
          key: '/statements/customers',
          label: <Link to="/statements/customers">Customer Statement</Link>,
        },
        {
          key: '/statements/vendors',
          label: <Link to="/statements/vendors">Vendor Statement</Link>,
        },
      ],
    }] : []),
  ];

  if (!isAuthenticated) {
    return (
      <Routes>
        <Route path="/login" element={<Login onLoginSuccess={() => { markAuthActivity(true); setIsAuthenticated(true); }} />} />
        <Route path="/register" element={<Register onRegistered={() => { markAuthActivity(true); setIsAuthenticated(true); }} />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    );
  }

  if (signedInUser.is_system_user) {
    const internalPortal = (
      <InternalPortal
        onLogout={handleLogout}
        themeMode={themeMode}
        themeStyle={themeStyle}
        onChangeThemeStyle={onChangeThemeStyle}
        onToggleTheme={onToggleTheme}
      />
    );

    return (
      <Routes>
        <Route path="/shared/invoices/:token" element={<InvoiceDetail shared />} />
        <Route path="/shared/vouchers/:token" element={<VoucherDetail shared />} />
        <Route path="/login" element={<Navigate to="/internal" replace />} />
        <Route path="/register" element={<Navigate to="/internal" replace />} />
        <Route path="/" element={<Navigate to="/internal" replace />} />
        <Route path="/internal/*" element={internalPortal} />
        <Route path="*" element={<Navigate to="/internal" replace />} />
      </Routes>
    );
  }

  return (
    <Routes>
      <Route path="/shared/invoices/:token" element={<InvoiceDetail shared />} />
      <Route path="/shared/vouchers/:token" element={<VoucherDetail shared />} />
      <Route path="/login" element={<Navigate to="/" replace />} />
      <Route path="/register" element={<Navigate to="/" replace />} />
      <Route path="*" element={<AuthenticatedLayout menuItems={menuItems} onLogout={handleLogout} themeMode={themeMode} themeStyle={themeStyle} onChangeThemeStyle={onChangeThemeStyle} onToggleTheme={onToggleTheme} />} />
    </Routes>
  );
}
