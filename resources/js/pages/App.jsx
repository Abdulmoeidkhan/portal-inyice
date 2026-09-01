/** @jsxImportSource react */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Routes, Route, Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Layout, Spin, Menu, Button, Dropdown, Avatar, Space, Typography, FloatButton, Drawer, Modal, Alert } from 'antd';
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
  ArrowLeftOutlined,
  CopyOutlined,
  DeleteOutlined,
  DragOutlined,
  SnippetsOutlined,
} from '@ant-design/icons';
import { message } from '../services/feedback';
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
import OrderList from './OrderList';
import OrderEdit from './OrderEdit';
import AgingReport from './AgingReport';
import RevenueReport from './RevenueReport';
import PerformanceReport from './PerformanceReport';
import ProfitReport from './ProfitReport';
import DiscountReport from './DiscountReport';
import ProfitShares from './ProfitShares';
import PaymentReport from './PaymentReport';
import InvoiceDetail from './InvoiceDetail';
import VoucherDetail from './VoucherDetail';
import QuotationDetail from './QuotationDetail';
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
import Receivings from './Receivings';
import CustomerList from './CustomerList';
import VendorList from './VendorList';
import CustomerStatement from './CustomerStatement';
import VendorStatement from './VendorStatement';
import VendorPayments from './VendorPayments';
import CancelledReport from './CancelledReport';
import ReferenceSearch from './ReferenceSearch';
import CustomerRefundAllocation from './CustomerRefundAllocation';

const { Header, Sider, Content } = Layout;
const { Text } = Typography;
const AUTH_IDLE_TIMEOUT_MS = 4 * 60 * 60 * 1000;
const AUTH_LAST_ACTIVITY_KEY = 'auth_last_activity_at';
const AUTH_ACTIVITY_THROTTLE_MS = 60 * 1000;
const AUTH_UNAUTHORIZED_EVENT = 'inyice:auth-unauthorized';
const USER_UPDATED_EVENT = 'inyice:user-updated';
const CALCULATOR_HISTORY_KEY = 'calculator_history';
const CALCULATOR_DRAG_MARGIN = 12;
const TAWK_SCRIPT_ID = 'tawk-to-script';
const TAWK_EMBED_URL = 'https://embed.tawk.to/6a95b872ba626134459bf86f/1k1cdgvqk';
const TAWK_MOBILE_QUERY = '(max-width: 720px)';

const clamp = (value, min, max) => Math.min(Math.max(value, Math.min(min, max)), Math.max(min, max));

const calculate = (left, operator, right) => {
  if (operator === '+') return left + right;
  if (operator === '-') return left - right;
  if (operator === '*') return left * right;
  if (operator === '/') return right === 0 ? null : left / right;

  return right;
};

const formatCalculatorValue = (value) => {
  const number = Number(value);
  if (!Number.isFinite(number)) return '0';

  return Number(number.toFixed(10)).toString();
};

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

function TawkToWidget() {
  useEffect(() => {
    window.Tawk_API = window.Tawk_API || {};
    window.Tawk_LoadStart = window.Tawk_LoadStart || new Date();
    const mobileQuery = window.matchMedia(TAWK_MOBILE_QUERY);
    const applyMobileVisibility = () => {
      const isMobile = mobileQuery.matches;

      document.body.classList.toggle('tawk-mobile-hidden', isMobile);

      if (isMobile) {
        window.Tawk_API?.minimize?.();
        window.Tawk_API?.hideWidget?.();
        return;
      }

      window.Tawk_API?.showWidget?.();
    };

    window.Tawk_API.customStyle = {
      ...(window.Tawk_API.customStyle || {}),
      zIndex: 999,
      visibility: {
        desktop: {
          position: 'br',
          xOffset: 24,
          yOffset: 152,
        },
        mobile: {
          position: 'br',
          xOffset: 16,
          yOffset: 142,
        },
        bubble: {
          rotate: '0deg',
          xOffset: 0,
          yOffset: 0,
        },
      },
    };

    const previousOnLoad = window.Tawk_API.onLoad;
    const handleLoad = () => {
      previousOnLoad?.();
      applyMobileVisibility();
    };

    window.Tawk_API.onLoad = handleLoad;
    applyMobileVisibility();
    if (mobileQuery.addEventListener) {
      mobileQuery.addEventListener('change', applyMobileVisibility);
    } else {
      mobileQuery.addListener?.(applyMobileVisibility);
    }

    if (!document.getElementById(TAWK_SCRIPT_ID)) {
      const script = document.createElement('script');
      const firstScript = document.getElementsByTagName('script')[0];

      script.id = TAWK_SCRIPT_ID;
      script.async = true;
      script.src = TAWK_EMBED_URL;
      script.charset = 'UTF-8';
      script.setAttribute('crossorigin', '*');

      firstScript?.parentNode?.insertBefore(script, firstScript) || document.head.appendChild(script);
    }

    applyMobileVisibility();

    return () => {
      if (window.Tawk_API?.onLoad === handleLoad) {
        window.Tawk_API.onLoad = previousOnLoad;
      }

      if (mobileQuery.removeEventListener) {
        mobileQuery.removeEventListener('change', applyMobileVisibility);
      } else {
        mobileQuery.removeListener?.(applyMobileVisibility);
      }
      document.body.classList.remove('tawk-mobile-hidden');
    };
  }, []);

  return null;
}

function AppCalculator({ open, onClose }) {
  const dragRef = useRef(null);
  const dragStateRef = useRef(null);
  const [display, setDisplay] = useState('0');
  const [storedValue, setStoredValue] = useState(null);
  const [operator, setOperator] = useState(null);
  const [waitingForValue, setWaitingForValue] = useState(false);
  const [dragPosition, setDragPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [history, setHistory] = useState(() => {
    try {
      const savedHistory = JSON.parse(localStorage.getItem(CALCULATOR_HISTORY_KEY) || '[]');
      return Array.isArray(savedHistory) ? savedHistory : [];
    } catch {
      return [];
    }
  });

  useEffect(() => {
    localStorage.setItem(CALCULATOR_HISTORY_KEY, JSON.stringify(history.slice(0, 50)));
  }, [history]);

  const getDragBounds = useCallback(() => {
    const node = dragRef.current;
    if (!node) {
      return { left: 0, right: 0, top: 0, bottom: 0 };
    }

    const rect = node.getBoundingClientRect();
    const baseLeft = rect.left - dragPosition.x;
    const baseTop = rect.top - dragPosition.y;

    return {
      left: CALCULATOR_DRAG_MARGIN - baseLeft,
      right: window.innerWidth - CALCULATOR_DRAG_MARGIN - baseLeft - rect.width,
      top: CALCULATOR_DRAG_MARGIN - baseTop,
      bottom: window.innerHeight - CALCULATOR_DRAG_MARGIN - baseTop - rect.height,
    };
  }, [dragPosition]);

  const handleDragStart = useCallback((event) => {
    if (event.button !== undefined && event.button !== 0) return;

    dragStateRef.current = {
      bounds: getDragBounds(),
      pointerId: event.pointerId,
      startPointerX: event.clientX,
      startPointerY: event.clientY,
      startX: dragPosition.x,
      startY: dragPosition.y,
    };
    setIsDragging(true);
    event.preventDefault();
  }, [dragPosition, getDragBounds]);

  useEffect(() => {
    if (!isDragging) return undefined;

    const handlePointerMove = (event) => {
      const dragState = dragStateRef.current;
      if (!dragState || (event.pointerId !== undefined && event.pointerId !== dragState.pointerId)) return;

      const nextX = dragState.startX + event.clientX - dragState.startPointerX;
      const nextY = dragState.startY + event.clientY - dragState.startPointerY;

      setDragPosition({
        x: clamp(nextX, dragState.bounds.left, dragState.bounds.right),
        y: clamp(nextY, dragState.bounds.top, dragState.bounds.bottom),
      });
    };

    const handlePointerUp = (event) => {
      const dragState = dragStateRef.current;
      if (dragState && event.pointerId !== undefined && event.pointerId !== dragState.pointerId) return;

      dragStateRef.current = null;
      setIsDragging(false);
    };

    window.addEventListener('pointermove', handlePointerMove);
    window.addEventListener('pointerup', handlePointerUp);
    window.addEventListener('pointercancel', handlePointerUp);

    return () => {
      window.removeEventListener('pointermove', handlePointerMove);
      window.removeEventListener('pointerup', handlePointerUp);
      window.removeEventListener('pointercancel', handlePointerUp);
    };
  }, [isDragging]);

  useEffect(() => {
    if (!open) return undefined;

    const keepInViewport = () => {
      setDragPosition((current) => {
        const node = dragRef.current;
        if (!node) return current;

        const rect = node.getBoundingClientRect();
        const baseLeft = rect.left - current.x;
        const baseTop = rect.top - current.y;

        return {
          x: clamp(
            current.x,
            CALCULATOR_DRAG_MARGIN - baseLeft,
            window.innerWidth - CALCULATOR_DRAG_MARGIN - baseLeft - rect.width,
          ),
          y: clamp(
            current.y,
            CALCULATOR_DRAG_MARGIN - baseTop,
            window.innerHeight - CALCULATOR_DRAG_MARGIN - baseTop - rect.height,
          ),
        };
      });
    };

    window.addEventListener('resize', keepInViewport);
    keepInViewport();

    return () => {
      window.removeEventListener('resize', keepInViewport);
    };
  }, [open]);

  const addHistory = (entry) => {
    setHistory((current) => [entry, ...current].slice(0, 50));
  };

  const inputDigit = (digit) => {
    setDisplay((current) => {
      if (waitingForValue) {
        setWaitingForValue(false);
        return digit;
      }

      return current === '0' ? digit : current + digit;
    });
  };

  const inputDecimal = () => {
    setDisplay((current) => {
      if (waitingForValue) {
        setWaitingForValue(false);
        return '0.';
      }

      return current.includes('.') ? current : current + '.';
    });
  };

  const clear = () => {
    setDisplay('0');
    setStoredValue(null);
    setOperator(null);
    setWaitingForValue(false);
  };

  const backspace = () => {
    if (waitingForValue) return;
    setDisplay((current) => (current.length > 1 ? current.slice(0, -1) : '0'));
  };

  const percent = () => {
    const next = formatCalculatorValue(Number(display) / 100);
    setDisplay(next);
    addHistory(`${display}% = ${next}`);
    setWaitingForValue(true);
  };

  const chooseOperator = (nextOperator) => {
    const inputValue = Number(display);

    if (storedValue === null) {
      setStoredValue(inputValue);
    } else if (operator) {
      const result = calculate(storedValue, operator, inputValue);
      if (result === null) {
        message.error('Cannot divide by zero');
        clear();
        return;
      }
      const formatted = formatCalculatorValue(result);
      addHistory(`${formatCalculatorValue(storedValue)} ${operator} ${formatCalculatorValue(inputValue)} = ${formatted}`);
      setStoredValue(result);
      setDisplay(formatted);
    }

    setOperator(nextOperator);
    setWaitingForValue(true);
  };

  const equals = () => {
    if (storedValue === null || !operator) return;

    const inputValue = Number(display);
    const result = calculate(storedValue, operator, inputValue);
    if (result === null) {
      message.error('Cannot divide by zero');
      clear();
      return;
    }

    const formatted = formatCalculatorValue(result);
    addHistory(`${formatCalculatorValue(storedValue)} ${operator} ${formatCalculatorValue(inputValue)} = ${formatted}`);
    setDisplay(formatted);
    setStoredValue(null);
    setOperator(null);
    setWaitingForValue(true);
  };

  const copyValue = async (value = display) => {
    try {
      await navigator.clipboard.writeText(String(value));
      message.success('Calculator value copied');
    } catch {
      window.prompt('Copy calculator value:', value);
    }
  };

  const pasteValue = async () => {
    try {
      const clipboardValue = await navigator.clipboard.readText();
      const numericValue = Number(String(clipboardValue).replace(/,/g, '').trim());

      if (!Number.isFinite(numericValue)) {
        message.error('Clipboard does not contain a number');
        return;
      }

      setDisplay(formatCalculatorValue(numericValue));
      setWaitingForValue(false);
    } catch {
      message.error('Could not read clipboard');
    }
  };

  const buttons = [
    ['C', 'Back', '%', '/'],
    ['7', '8', '9', '*'],
    ['4', '5', '6', '-'],
    ['1', '2', '3', '+'],
    ['.', '0', 'Paste', '='],
  ];

  const handleButton = (label) => {
    if (/^\d$/.test(label)) return inputDigit(label);
    if (label === '.') return inputDecimal();
    if (label === 'C') return clear();
    if (label === 'Back') return backspace();
    if (label === '%') return percent();
    if (['+', '-', '*', '/'].includes(label)) return chooseOperator(label);
    if (label === '=') return equals();
    if (label === 'Copy') return copyValue();
    if (label === 'Paste') return pasteValue();
  };

  useEffect(() => {
    if (!open) return undefined;

    const handleKeyDown = (event) => {
      const keyMap = {
        Enter: '=',
        '=': '=',
        Backspace: 'Back',
        Delete: 'C',
        Escape: 'Escape',
        ',': '.',
        x: '*',
        X: '*',
      };
      const key = keyMap[event.key] || event.key;

      if (/^\d$/.test(key) || ['+', '-', '*', '/', '.', '%', '='].includes(key)) {
        event.preventDefault();
        handleButton(key);
        return;
      }

      if (key === 'Back' || key === 'C') {
        event.preventDefault();
        handleButton(key);
        return;
      }

      if (key === 'Escape') {
        event.preventDefault();
        onClose();
      }
    };

    window.addEventListener('keydown', handleKeyDown);

    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [display, open, operator, storedValue, waitingForValue, onClose]);

  const renderButtonContent = (label) => {
    if (label === 'Back') return <ArrowLeftOutlined />;
    if (label === 'Paste') return <SnippetsOutlined />;
    if (label === 'C') return <DeleteOutlined />;
    if (label === '*') return 'x';
    return label;
  };

  const getButtonTitle = (label) => {
    if (label === 'Back') return 'Backspace';
    if (label === 'Paste') return 'Paste value';
    if (label === 'C') return 'Clear';
    if (label === '*') return 'Multiply';
    if (label === '/') return 'Divide';
    if (label === '+') return 'Add';
    if (label === '-') return 'Subtract';
    if (label === '=') return 'Equals';
    if (label === '%') return 'Percent';
    if (label === '.') return 'Decimal point';
    return label;
  };

  return (
    <Modal
      className="app-calculator-modal"
      title={(
        <div className="app-calculator-title">
          <button
            type="button"
            className="app-calculator-drag-handle"
            title="Drag calculator"
            onPointerDown={handleDragStart}
            onDoubleClick={() => setDragPosition({ x: 0, y: 0 })}
          >
            <DragOutlined />
            <span>Calculator</span>
          </button>
          <Button size="small" type="text" onClick={() => setDragPosition({ x: 0, y: 0 })}>
            Center
          </Button>
        </div>
      )}
      open={open}
      onCancel={onClose}
      footer={null}
      width={380}
      destroyOnClose={false}
      mask={false}
      maskClosable={false}
      modalRender={(modal) => (
        <div
          ref={dragRef}
          className={`app-calculator-drag-shell${isDragging ? ' is-dragging' : ''}`}
          style={{ transform: `translate(${dragPosition.x}px, ${dragPosition.y}px)` }}
        >
          {modal}
        </div>
      )}
    >
      <div className="app-calculator-display">
        <div className="app-calculator-display-meta">
          <Text type="secondary">{operator && storedValue !== null ? `${formatCalculatorValue(storedValue)} ${operator}` : 'Ready'}</Text>
          <Button
            size="small"
            type="text"
            icon={<CopyOutlined />}
            title="Copy value"
            aria-label="Copy calculator value"
            onClick={() => copyValue()}
          />
        </div>
        <strong title={display}>{display}</strong>
      </div>
      <div className="app-calculator-grid">
        {buttons.flat().map((label) => (
          <Button
            key={label}
            className="app-calculator-key"
            type={['=', '+', '-', '*', '/'].includes(label) ? 'primary' : 'default'}
            danger={label === 'C'}
            title={getButtonTitle(label)}
            aria-label={getButtonTitle(label)}
            onClick={() => handleButton(label)}
          >
            {renderButtonContent(label)}
          </Button>
        ))}
      </div>
      <div className="app-calculator-history">
        <div className="app-calculator-history-head">
          <Text strong>History</Text>
          <Button size="small" type="link" disabled={!history.length} onClick={() => setHistory([])}>Clear</Button>
        </div>
        <div className="app-calculator-history-list">
          {history.length ? history.map((entry, index) => {
            const value = entry.split('=').pop().trim();
            return (
              <button type="button" key={`${entry}-${index}`} onClick={() => copyValue(value)}>
                {entry}
              </button>
            );
          }) : <Text type="secondary">No calculations yet.</Text>}
        </div>
      </div>
    </Modal>
  );
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

    fetch('/api/v1/company-profile', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok) return;

        const paid = data.company?.is_paid === true;
        const storedUser = JSON.parse(localStorage.getItem('user') || '{}');
        localStorage.setItem('user', JSON.stringify({
          ...storedUser,
          company_name: data.company?.display_name || storedUser.company_name,
          company_is_paid: paid,
          company_sales_can_edit_cost: data.company?.sales_can_edit_cost === true,
        }));
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
            <Routes>
              <Route path="/" element={<Dashboard />} />
              <Route path="/invoices" element={<InvoiceList />} />
              <Route path="/invoices/:uid" element={<InvoiceDetail />} />
              <Route path="/orders" element={<OrderList />} />
              <Route path="/orders/:uid/voucher" element={<VoucherDetail />} />
              <Route path="/orders/:uid/quotation" element={<QuotationDetail />} />
              <Route path="/orders/:uid/edit" element={<OrderEdit />} />
              <Route path="/reference-search" element={<ReferenceSearch />} />
              <Route path="/sales-flow" element={<SalesFlow />} />
              <Route path="/receivings" element={<Receivings />} />
              <Route path="/payments" element={canAccessPayments ? <Payments /> : <Navigate to="/" replace />} />
              <Route path="/profit-shares" element={canAccessPayments ? <ProfitShares /> : <Navigate to="/" replace />} />
              <Route path="/customer-payments" element={canAccessPayments ? <CounterpartyTransaction direction="payment" partyType="customer" /> : <Navigate to="/" replace />} />
              <Route path="/vendor-payments" element={canAccessPayments ? <VendorPayments /> : <Navigate to="/" replace />} />
              <Route path="/vendor-receipts" element={canAccessPayments ? <CounterpartyTransaction direction="receipt" partyType="vendor" /> : <Navigate to="/" replace />} />
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
              <Route path="/reports/discounts" element={canAccessReports ? <DiscountReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/payments" element={canAccessReports ? <PaymentReport /> : <Navigate to="/" replace />} />
              <Route path="/reports/receipts" element={canAccessReports ? <PaymentReport direction="receipt" /> : <Navigate to="/" replace />} />
              <Route path="/reports/cancelled" element={canAccessCancelledReport ? <CancelledReport /> : <Navigate to="/" replace />} />
              <Route path="/statements/customers" element={canAccessReports ? <CustomerStatement /> : <Navigate to="/" replace />} />
              <Route path="/statements/vendors" element={canAccessReports ? <VendorStatement /> : <Navigate to="/" replace />} />
              <Route path="*" element={<NotFound />} />
            </Routes>
          </div>
          <FloatButton.Group
            className={`app-utility-float${floatControlsVisible ? '' : ' is-idle-hidden'}`}
            trigger="click"
            type="primary"
            placement='left'
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
      message.info('You were signed out because this account is active on another device.');
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

      if (protectedRequest && !navigator.onLine) {
        if (method !== 'GET') {
          return offlineBlockedResponse();
        }

        const cached = await readCachedApiResponse(input, init);

        return cached ? cachedResponse(cached) : offlineBlockedResponse(offlineReadMessage());
      }

      let response;

      try {
        response = await originalFetch(...args);
      } catch (error) {
        if (protectedRequest && method === 'GET') {
          const cached = await readCachedApiResponse(input, init);

          return cached ? cachedResponse(cached) : offlineBlockedResponse(offlineReadMessage());
        }

        throw error;
      }

      if (protectedRequest && method === 'GET') {
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
  ];

  if (!isAuthenticated) {
    return (
      <Routes>
        <Route path="/shared/invoices/:token" element={<InvoiceDetail shared />} />
        <Route path="/shared/vouchers/:token" element={<VoucherDetail shared />} />
        <Route path="/shared/quotations/:token" element={<QuotationDetail shared />} />
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
      <Route path="*" element={<AuthenticatedLayout menuItems={menuItems} onLogout={handleLogout} themeMode={themeMode} themeStyle={themeStyle} compactTheme={compactTheme} onChangeThemeMode={onChangeThemeMode} onChangeThemeStyle={onChangeThemeStyle} onToggleCompactTheme={onToggleCompactTheme} offline={offline} />} />
    </Routes>
  );
}
