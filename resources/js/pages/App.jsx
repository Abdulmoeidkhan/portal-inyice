/** @jsxImportSource react */
import React, { lazy, Suspense, useCallback, useEffect, useState } from 'react';
import PageHelp from '../components/PageHelp';
import AppCalculator from '../components/AppCalculator';
import TawkToWidget from '../components/TawkToWidget';
import { Routes, Route, Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Layout, Spin, Menu, Button, Dropdown, Avatar, Space, Typography, FloatButton, Drawer, Alert } from 'antd';
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
  MenuOutlined,
  CompressOutlined,
  ExpandOutlined,
  VerticalAlignTopOutlined,
  CalculatorOutlined,
  SearchOutlined,
  SwapOutlined,
} from '@ant-design/icons';
import { message } from '../services/feedback';
import { paidFeatureAccess } from '../services/featureAccess';
import { clearDeveloperCache } from '../services/developerApi';
import {
  cachedResponse,
  offlineBlockedResponse,
  readCachedApiResponse,
  requestMethod,
  writeCachedApiResponse,
} from '../services/offlineCache';
import ThemeMenuButton from '../components/ThemeMenuButton';

// Components
import InvoiceList from './InvoiceList';
import ApiDocumentation from './ApiDocumentation';
import DeveloperData from './DeveloperData';
import { accountingSections } from './accounting/sections';

import OrderList from './OrderList';
import OrderEdit from './OrderEdit';
import AgingReport from './AgingReport';
import RevenueReport from './RevenueReport';
import PerformanceReport from './PerformanceReport';
import ProfitReport from './ProfitReport';
import DiscountReport from './DiscountReport';
import TaxReport from './TaxReport';
import VatCalculator from './VatCalculator';
import ProfitShares from './ProfitShares';
import PaymentReport from './PaymentReport';
import InvoiceDetail from './InvoiceDetail';
import VoucherDetail from './VoucherDetail';
import QuotationDetail from './QuotationDetail';
import CounterpartyTransaction from './CounterpartyTransaction';
import Login from './Login';
import Register from './Register';
import ForgotPassword from './ForgotPassword';
import ResetPassword from './ResetPassword';
import Dashboard from './Dashboard';
import CompanyProfile from './CompanyProfile';
import CompanyUsers from './CompanyUsers';
import InternalPortal from './InternalPortal';
import UserProfile from './UserProfile';
import SalesFlow from './SalesFlow';
import Payments from './Payments';
import PaymentHistory from './PaymentHistory';
import Receivings from './Receivings';
import CustomerList from './CustomerList';
import VendorList from './VendorList';
import CustomerStatement from './CustomerStatement';
import VendorStatement from './VendorStatement';
import VendorPayments from './VendorPayments';
import CancelledReport from './CancelledReport';
import ReferenceSearch from './ReferenceSearch';
import CustomerRefundAllocation from './CustomerRefundAllocation';

const accountingPages = {
  accounts: lazy(() => import('./accounting/ChartOfAccounts')),
  journals: lazy(() => import('./accounting/Journals')),
  reports: lazy(() => import('./accounting/FinancialReports')),
  period: lazy(() => import('./accounting/PeriodClose')),
  audit: lazy(() => import('./accounting/AuditLog')),
};

const accountingMenu = {
  key: 'accounting-menu',
  icon: <BankOutlined />,
  label: 'Accounting',
  children: accountingSections.map(({ key, label }) => ({
    key: `/accounting/${key}`,
    label: <Link to={`/accounting/${key}`}>{label}</Link>,
  })),
};
const developerMenuItems = [
  { key: '/', icon: <DashboardOutlined />, label: <Link to="/">Company Data</Link> },
  { key: '/api-documentation', icon: <FileTextOutlined />, label: <Link to="/api-documentation">API Documentation</Link> },
];

const { Header, Sider, Content } = Layout;
const { Text } = Typography;
const AUTH_IDLE_TIMEOUT_MS = 4 * 60 * 60 * 1000;
const AUTH_LAST_ACTIVITY_KEY = 'auth_last_activity_at';
const AUTH_ACTIVITY_THROTTLE_MS = 60 * 1000;
const AUTH_UNAUTHORIZED_EVENT = 'inyice:auth-unauthorized';
const USER_UPDATED_EVENT = 'inyice:user-updated';

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
  clearDeveloperCache();
  localStorage.removeItem('auth_token');
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  localStorage.removeItem(AUTH_LAST_ACTIVITY_KEY);
}

function isProtectedApiRequest(input) {
  const url = input instanceof URL ? input.toString() : typeof input === 'string' ? input : input?.url;
  if (!url) return false;

  let path = url;

  try {
    path = new URL(url, window.location.origin).pathname;
  } catch {
    return false;
  }

  return path.startsWith('/api/v1/')
    && !path.startsWith('/api/v1/auth/login')
    && !path.startsWith('/api/v1/auth/logout')
    && !path.startsWith('/api/v1/auth/forgot-password')
    && !path.startsWith('/api/v1/auth/reset-password')
    && !path.startsWith('/api/v1/auth/email/')
    && !path.startsWith('/api/v1/shared-');
}

function offlineReadMessage() {
  return 'You are offline and this data has not been synced on this device yet.';
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

function getAuthToken() {
  return localStorage.getItem('auth_token') || localStorage.getItem('token');
}

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('user') || '{}');
  } catch {
    return {};
  }
}

function AuthenticatedLayout({ menuItems, onLogout, themeMode, themeStyle, compactTheme, onChangeThemeMode, onChangeThemeStyle, onToggleCompactTheme, offline }) {
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [floatControlsVisible, setFloatControlsVisible] = useState(true);
  const [showMoveTop, setShowMoveTop] = useState(false);
  const [calculatorOpen, setCalculatorOpen] = useState(false);
  const [user, setUser] = useState(getStoredUser);
  const selectedKey = menuItems
    .flatMap((item) => (item.children ? item.children : [item]))
    .find((item) => item.key === location.pathname)?.key || '/';

  const canAccessPayments = ['owner', 'admin', 'accounts'].includes(user?.role);
  const canAccessReports = user?.role !== 'sales';
  const canAccessCancelledReport = ['owner', 'admin'].includes(user?.role);
  const { canAccessAccounting, canAccessApiDocumentation } = paidFeatureAccess(user);
  const accountingRoutes = [
    <Route
      key="accounting"
      path="/accounting"
      element={<Navigate to={canAccessAccounting ? '/accounting/accounts' : '/'} replace />}
    />,
    ...Object.entries(accountingPages).map(([path, Page]) => (
      <Route
        key={`accounting-${path}`}
        path={`/accounting/${path}`}
        element={canAccessAccounting ? (
          <Suspense fallback={<div className="center-screen" role="status" aria-label="Loading accounting page"><Spin /></div>}>
            <Page />
          </Suspense>
        ) : <Navigate to="/" replace />}
      />
    )),
  ];

  useEffect(() => {
    const syncUser = (event) => {
      setUser(event.detail || getStoredUser());
    };
    const syncUserFromStorage = (event) => {
      if (event.key === 'user') {
        setUser(getStoredUser());
      }
    };

    window.addEventListener(USER_UPDATED_EVENT, syncUser);
    window.addEventListener('storage', syncUserFromStorage);

    return () => {
      window.removeEventListener(USER_UPDATED_EVENT, syncUser);
      window.removeEventListener('storage', syncUserFromStorage);
    };
  }, []);

  useEffect(() => {
    let idleTimer;

    const showControls = () => {
      setFloatControlsVisible(true);
      window.clearTimeout(idleTimer);
      idleTimer = window.setTimeout(() => setFloatControlsVisible(false), 4000);
    };

    const activityEvents = ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'];

    showControls();
    activityEvents.forEach((eventName) => {
      window.addEventListener(eventName, showControls);
    });

    return () => {
      window.clearTimeout(idleTimer);
      activityEvents.forEach((eventName) => {
        window.removeEventListener(eventName, showControls);
      });
    };
  }, []);

  useEffect(() => {
    const content = document.querySelector('.app-content');
    const scrollTarget = content || window;

    const getScrollTop = () => content?.scrollTop || window.scrollY || document.documentElement.scrollTop || 0;
    const handleScroll = () => setShowMoveTop(getScrollTop() > 120);

    handleScroll();
    scrollTarget.addEventListener('scroll', handleScroll, { passive: true });

    return () => {
      scrollTarget.removeEventListener('scroll', handleScroll);
    };
  }, [location.pathname]);

  useEffect(() => {
    const token = getAuthToken();
    if (!token) return;

    const profileRequest = fetch('/api/v1/company-profile', {
      cache: 'no-store',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    profileRequest
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok) return;

        const paid = data.company?.is_paid === true;
        const storedUser = JSON.parse(localStorage.getItem('user') || '{}');
        const updatedUser = {
          ...storedUser,
          company_name: data.company?.display_name || storedUser.company_name,
          company_is_paid: paid,
          company_sales_can_edit_cost: data.company?.sales_can_edit_cost === true,
        };
        localStorage.setItem('user', JSON.stringify(updatedUser));
        setUser(updatedUser);
      })
      .catch(() => { });
  }, []);

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
      label: `${user?.name || 'User '} Profile`,
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
        <h2>InYice OS</h2>
        <Text className="brand-caption">Travel Finance OS</Text>
      </div>
      <Menu
        className="app-nav-menu"
        theme={themeMode === 'dark' ? 'dark' : 'light'}
        mode="inline"
        selectedKeys={[selectedKey]}
        defaultOpenKeys={location.pathname.startsWith('/accounting') ? ['accounting-menu'] : []}
        items={menuItems.filter((item) => {
          if (item.key === 'accounting-menu') return canAccessAccounting;
          if (item.key === '/api-documentation') return canAccessApiDocumentation;
          return true;
        })}
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
            <PageHelp />
            <ThemeMenuButton
              themeMode={themeMode}
              themeStyle={themeStyle}
              onChangeThemeMode={onChangeThemeMode}
              onChangeThemeStyle={onChangeThemeStyle}
            />
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
          {offline && (
            <Alert
              banner
              type="warning"
              message="Offline mode"
              description="Showing synced data only. Updates are disabled until the connection is restored."
              style={{ marginBottom: 16 }}
            />
          )}
          <div key={location.pathname} className="route-transition">
            {user?.role === 'dev' ? <Routes>
              {accountingRoutes}
              <Route path="/" element={<DeveloperData />} />
              <Route path="/profile/user" element={<UserProfile />} />
              <Route path="/api-documentation" element={canAccessApiDocumentation ? <ApiDocumentation /> : <Navigate to="/" replace />} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes> : <Routes>
              {accountingRoutes}
              <Route path="/api-documentation" element={canAccessApiDocumentation ? <ApiDocumentation /> : <Navigate to="/" replace />} />
              <Route path="/" element={<Dashboard />} />
              <Route path="/invoices" element={<InvoiceList />} />
              <Route path="/vat-calculator" element={<VatCalculator />} />
              <Route path="/invoices/:uid" element={<InvoiceDetail />} />
              <Route path="/orders" element={<OrderList />} />
              <Route path="/orders/:uid/voucher" element={<VoucherDetail />} />
              <Route path="/orders/:uid/quotation" element={<QuotationDetail />} />
              <Route path="/orders/:uid/edit" element={<OrderEdit />} />
              <Route path="/reference-search" element={<ReferenceSearch />} />
              <Route path="/sales-flow" element={<SalesFlow />} />
              <Route path="/receivings" element={<Receivings />} />
              <Route path="/payments" element={canAccessPayments ? <Payments /> : user?.role === 'sales' ? <PaymentHistory key="payments" direction="receipt" partyType="customer" /> : <Navigate to="/" replace />} />
              <Route path="/profit-shares" element={canAccessPayments ? <ProfitShares /> : <Navigate to="/" replace />} />
              <Route path="/customer-payments" element={canAccessPayments ? <CounterpartyTransaction direction="payment" partyType="customer" /> : user?.role === 'sales' ? <PaymentHistory key="customer-payments" direction="payment" partyType="customer" /> : <Navigate to="/" replace />} />
              <Route path="/vendor-payments" element={canAccessPayments ? <VendorPayments /> : user?.role === 'sales' ? <PaymentHistory key="vendor-payments" direction="payment" partyType="vendor" /> : <Navigate to="/" replace />} />
              <Route path="/vendor-receipts" element={canAccessPayments ? <CounterpartyTransaction direction="receipt" partyType="vendor" /> : user?.role === 'sales' ? <PaymentHistory key="vendor-receipts" direction="receipt" partyType="vendor" /> : <Navigate to="/" replace />} />
              <Route path="/refund-allocations" element={canAccessPayments ? <CustomerRefundAllocation /> : <Navigate to="/" replace />} />
              <Route path="/customers" element={<CustomerList />} />
              <Route path="/vendors" element={<VendorList />} />
              <Route path="/profile/company" element={<CompanyProfile />} />
              <Route path="/profile/company-users" element={<CompanyUsers />} />
              <Route path="/profile/user" element={<UserProfile />} />
              <Route path="/reports/aging" element={canAccessReports ? <AgingReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/revenue" element={canAccessReports ? <RevenueReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/performance" element={canAccessReports ? <PerformanceReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/profit" element={canAccessReports ? <ProfitReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/tax" element={canAccessReports ? <TaxReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/discounts" element={canAccessReports ? <DiscountReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/payments" element={canAccessReports ? <PaymentReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/receipts" element={canAccessReports ? <PaymentReport direction="receipt" /> : <Navigate to="/" replace />} />
              <Route path="/reports/cancelled" element={canAccessCancelledReport ? <CancelledReport /> : <Navigate to="/" replace />} />
              <Route path="/statements/customers" element={canAccessReports ? <CustomerStatement /> : <Navigate to="/" replace />} />
              <Route path="/statements/vendors" element={canAccessReports ? <VendorStatement /> : <Navigate to="/" replace />} />
              <Route path="*" element={<NotFound />} />
            </Routes>}
          </div>
          <footer className="app-connection-footer">
            <a href="/connect/ai">Connect an AI assistant via MCP</a> · <a href="/llms.txt">AI connection instructions</a>
          </footer>
          <FloatButton.Group
            className={`app-utility-float${floatControlsVisible ? '' : ' is-idle-hidden'}`}
            trigger="click"
            type="primary"
            placement="left"
            icon={<MenuOutlined />}
          >
            <FloatButton
              icon={compactTheme ? <ExpandOutlined /> : <CompressOutlined />}
              tooltip={compactTheme ? 'Comfortable theme' : 'Compact theme'}
              type="default"
              onClick={onToggleCompactTheme}
            />
            <FloatButton
              icon={<CalculatorOutlined />}
              tooltip="Calculator"
              onClick={() => setCalculatorOpen(true)}
            />
          </FloatButton.Group>
          {showMoveTop && (
            <FloatButton
              className={`app-move-top-float${floatControlsVisible ? '' : ' is-idle-hidden'}`}
              icon={<VerticalAlignTopOutlined />}
              type="primary"
              tooltip="Move to top"
              onClick={() => {
                document.querySelector('.app-content')?.scrollTo({ top: 0, behavior: 'smooth' });
                window.scrollTo({ top: 0, behavior: 'smooth' });
              }}
            />
          )}
          <TawkToWidget />
          <AppCalculator open={calculatorOpen} onClose={() => setCalculatorOpen(false)} />
        </Content>
      </Layout>
    </Layout>
  );
}

export default function App({ themeMode, themeStyle, compactTheme, onChangeThemeMode, onChangeThemeStyle, onToggleCompactTheme }) {
  const [loading, setLoading] = useState(true);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [offline, setOffline] = useState(() => !navigator.onLine);
  const navigate = useNavigate();

  useEffect(() => {
    if (getAuthToken() && hasAuthIdleExpired()) {
      clearAuthStorage();
    } else if (getAuthToken()) {
      markAuthActivity(true);
    }

    setIsAuthenticated(!!getAuthToken());
    setLoading(false);
  }, []);

  const signOutLocally = useCallback(({ notify = false } = {}) => {
    clearAuthStorage();
    setIsAuthenticated(false);

    if (notify) {
      message.info('Your session could not be verified. Please sign in again.');
    }

    navigate('/login', { replace: true });
  }, [navigate]);

  const handleLogout = useCallback(async ({ revokeToken = true } = {}) => {
    const token = getAuthToken();

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
      signOutLocally();
    }
  }, [signOutLocally]);

  useEffect(() => {
    const originalFetch = window.fetch;

    window.fetch = async (...args) => {
      const [input, init = {}] = args;
      const method = requestMethod(input, init);
      const protectedRequest = isProtectedApiRequest(input);
      const requiresLiveRequest = init.cache === 'no-store';

      if (protectedRequest && !navigator.onLine) {
        if (method !== 'GET' || requiresLiveRequest) {
          return offlineBlockedResponse();
        }

        const cached = await readCachedApiResponse(input, init);

        return cached ? cachedResponse(cached) : offlineBlockedResponse(offlineReadMessage());
      }

      let response;

      try {
        response = await originalFetch(...args);
      } catch (error) {
        if (protectedRequest && method === 'GET' && !requiresLiveRequest) {
          const cached = await readCachedApiResponse(input, init);

          return cached ? cachedResponse(cached) : offlineBlockedResponse(offlineReadMessage());
        }

        throw error;
      }

      if (protectedRequest && method === 'GET' && !requiresLiveRequest) {
        writeCachedApiResponse(input, init, response);
      }

      if (
        getAuthToken()
        && isProtectedApiRequest(args[0])
        && [401, 419].includes(response.status)
      ) {
        window.dispatchEvent(new CustomEvent(AUTH_UNAUTHORIZED_EVENT));
      }

      return response;
    };

    return () => {
      window.fetch = originalFetch;
    };
  }, []);

  useEffect(() => {
    const handleOnline = () => setOffline(false);
    const handleOffline = () => setOffline(true);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  useEffect(() => {
    const handleUnauthorized = () => signOutLocally({ notify: true });

    window.addEventListener(AUTH_UNAUTHORIZED_EVENT, handleUnauthorized);

    return () => {
      window.removeEventListener(AUTH_UNAUTHORIZED_EVENT, handleUnauthorized);
    };
  }, [signOutLocally]);

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
  }, [handleLogout, isAuthenticated, navigate, signOutLocally]);

  if (loading) {
    return (
      <div className="center-screen">
        <Spin size="large" />
      </div>
    );
  }

  const signedInUser = JSON.parse(localStorage.getItem('user') || '{}');
  const canAccessPayments = ['owner', 'admin', 'accounts'].includes(signedInUser.role);
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
          key: '/vat-calculator',
          label: <Link to="/vat-calculator">VAT Calculator</Link>,
          icon: <CalculatorOutlined />,
        },
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
          key: '/receivings',
          label: <Link to="/receivings">Receivings</Link>,
          icon: <DollarOutlined />,
        },
        ...(signedInUser.role === 'sales' ? [
          { key: '/payments', label: <Link to="/payments">Customer Receipts</Link>, icon: <BankOutlined /> },
          { key: '/customer-payments', label: <Link to="/customer-payments">Customer Payments</Link>, icon: <BankOutlined /> },
          { key: '/vendor-receipts', label: <Link to="/vendor-receipts">Vendor Receipts</Link>, icon: <BankOutlined /> },
          { key: '/vendor-payments', label: <Link to="/vendor-payments">Vendor Payments</Link>, icon: <BankOutlined /> },
        ] : []),
        ...(canAccessPayments ? [{
          key: '/profit-shares',
          label: <Link to="/profit-shares">Profit Shares</Link>,
          icon: <SwapOutlined />,
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
        {
          key: '/refund-allocations',
          label: <Link to="/refund-allocations">Refund Allocation</Link>,
          icon: <SwapOutlined />,
        }] : []),
      ],
    },
    ...(canAccessPayments ? [accountingMenu] : []),
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
          key: '/reports/performance',
          label: <Link to="/reports/performance">Performance Report</Link>,
        },
        {
          key: '/reports/profit',
          label: <Link to="/reports/profit">Profit Report</Link>,
        },
        {
          key: '/reports/tax',
          label: <Link to="/reports/tax">Tax Report</Link>,
        },
        {
          key: '/reports/discounts',
          label: <Link to="/reports/discounts">Discount Report</Link>,
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
    {
      key: '/api-documentation',
      icon: <FileTextOutlined />,
      label: <Link to="/api-documentation">API Documentation</Link>,
    },
  ];

  if (!isAuthenticated) {
    return (
      <Routes>
        <Route path="/shared/invoices/:token" element={<InvoiceDetail shared />} />
        <Route path="/shared/vouchers/:token" element={<VoucherDetail shared />} />
        <Route path="/shared/quotations/:token" element={<QuotationDetail shared />} />
        <Route path="/login" element={<Login onLoginSuccess={() => { markAuthActivity(true); setIsAuthenticated(true); }} />} />
        <Route path="/register" element={<Register onRegistered={() => { markAuthActivity(true); setIsAuthenticated(true); }} />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
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
        onChangeThemeMode={onChangeThemeMode}
        onChangeThemeStyle={onChangeThemeStyle}
      />
    );

    return (
      <Routes>
        <Route path="/shared/invoices/:token" element={<InvoiceDetail shared />} />
        <Route path="/shared/vouchers/:token" element={<VoucherDetail shared />} />
        <Route path="/shared/quotations/:token" element={<QuotationDetail shared />} />
        <Route path="/login" element={<Navigate to="/internal" replace />} />
        <Route path="/register" element={<Navigate to="/internal" replace />} />
        <Route path="/forgot-password" element={<Navigate to="/internal" replace />} />
        <Route path="/reset-password" element={<Navigate to="/internal" replace />} />
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
      <Route path="/shared/quotations/:token" element={<QuotationDetail shared />} />
      <Route path="/login" element={<Navigate to="/" replace />} />
      <Route path="/register" element={<Navigate to="/" replace />} />
      <Route path="/forgot-password" element={<Navigate to="/" replace />} />
      <Route path="/reset-password" element={<Navigate to="/" replace />} />
      <Route
        path="*"
        element={
          <AuthenticatedLayout
            menuItems={signedInUser.role === 'dev' ? developerMenuItems : menuItems}
            onLogout={handleLogout}
            themeMode={themeMode}
            themeStyle={themeStyle}
            compactTheme={compactTheme}
            onChangeThemeMode={onChangeThemeMode}
            onChangeThemeStyle={onChangeThemeStyle}
            onToggleCompactTheme={onToggleCompactTheme}
            offline={offline}
          />
        }
      />
    </Routes>
  );
}
