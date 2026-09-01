import React, { useEffect, useState } from 'react';
import { Badge, Button, Card, Col, Empty, Grid, Layout, Row, Skeleton, Space, Statistic, Tag, Typography, theme } from 'antd';
import {
  ArrowRightOutlined,
  DollarOutlined,
  LoginOutlined,
  LogoutOutlined,
  ReloadOutlined,
  RocketOutlined,
  SwapOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Column, Pie } from '@ant-design/plots';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';
import { openRoute } from '../services/navigation';

const { Text, Title } = Typography;

const DEMO_MODE = false;

const demoReport = {
  finance: {
    summary: {
      invoiced: 2487500,
      collected: 1935000,
      purchase: 1718000,
      refund: 126500,
      paid: 1492000,
    },

    outstanding: {
      summary: {
        customer_total: 782500,
        vendor_total: 516250,
      },

      customers: [
        { name: 'Horizon Travel', amount: 245000 },
        { name: 'Blue Sky Holidays', amount: 184500 },
        { name: 'Al Noor Tours', amount: 151000 },
        { name: 'Citywide Travels', amount: 112000 },
        { name: 'Walk-in Customers', amount: 90000 },
      ],

      vendors: [
        { name: 'Airline Supplier', amount: 168000 },
        { name: 'Hotel Partner', amount: 132500 },
        { name: 'Visa Vendor', amount: 87500 },
        { name: 'Transport Partner', amount: 74250 },
        { name: 'Other Services', amount: 54000 },
      ],
    },

    expenses: [
      { type: 'Flights', value: 1160000 },
      { type: 'Hotels', value: 435000 },
      { type: 'Visas', value: 187500 },
      { type: 'Transfers', value: 94500 },
      { type: 'Activities', value: 62500 },
      { type: 'Other', value: 38500 },
    ],

    performance: [
      { period: '2026-03', label: 'Mar 26', sales: 2140000, purchase: 1575000, profit_loss: 565000 },
      { period: '2026-04', label: 'Apr 26', sales: 2350000, purchase: 1690000, profit_loss: 660000 },
      { period: '2026-05', label: 'May 26', sales: 1960000, purchase: 1510000, profit_loss: 450000 },
      { period: '2026-06', label: 'Jun 26', sales: 2865000, purchase: 1940000, profit_loss: 925000 },
      { period: '2026-07', label: 'Jul 26', sales: 2470000, purchase: 1710000, profit_loss: 760000 },
      { period: '2026-08', label: 'Aug 26', sales: 3120000, purchase: 2180000, profit_loss: 940000 },
      { period: '2026-09', label: 'Sep 26', sales: 2487500, purchase: 1718000, profit_loss: 769500 },
    ],
  },

  checkins: [
    {
      key: 'demo-checkin-1',
      title: 'Makkah Hotel Check-in',
      date: '27 Aug 2026',
      time: '14:00',
      relative_label: 'Tomorrow',
      days_until: 1,
      customer_name: 'Ahmed Khan',
      order_number: 'ORD-1048',
      booking_reference: 'HTL8X2',
      description: '3 nights · Double room · Breakfast included',
      order_uid: null,
    },
    {
      key: 'demo-checkin-2',
      title: 'Madinah Hotel Check-in',
      date: '29 Aug 2026',
      time: '15:00',
      relative_label: 'In 3 days',
      days_until: 3,
      customer_name: 'Fatima Ali',
      order_number: 'ORD-1053',
      booking_reference: 'MDN4Q7',
      description: '4 nights · Triple room',
      order_uid: null,
    },
    {
      key: 'demo-checkin-3',
      title: 'Dubai Hotel Check-in',
      date: '01 Sep 2026',
      time: '14:00',
      relative_label: 'In 6 days',
      days_until: 6,
      customer_name: 'Usman Raza',
      order_number: 'ORD-1061',
      booking_reference: 'DXB7P9',
      description: '2 rooms · 5 nights',
      order_uid: null,
    },
  ],

  checkouts: [
    {
      key: 'demo-checkout-1',
      title: 'Makkah Hotel Check-out',
      date: '28 Aug 2026',
      time: '12:00',
      relative_label: 'In 2 days',
      days_until: 2,
      customer_name: 'Sara Malik',
      order_number: 'ORD-1039',
      booking_reference: 'MKK2J6',
      description: 'Airport transfer scheduled after check-out',
      order_uid: null,
    },
    {
      key: 'demo-checkout-2',
      title: 'Madinah Hotel Check-out',
      date: '31 Aug 2026',
      time: '12:00',
      relative_label: 'In 5 days',
      days_until: 5,
      customer_name: 'Imran Siddiqui',
      order_number: 'ORD-1044',
      booking_reference: 'MED5A3',
      description: 'Late check-out requested',
      order_uid: null,
    },
  ],

  departures: [
    {
      key: 'demo-departure-1',
      title: 'Karachi → Jeddah',
      date: '27 Aug 2026',
      time: '08:35',
      relative_label: 'Tomorrow',
      days_until: 1,
      customer_name: 'Ahmed Khan',
      order_number: 'ORD-1048',
      booking_reference: 'PNR7KQ',
      description: 'SV 701 · Economy · 30kg baggage',
      order_uid: null,
    },
    {
      key: 'demo-departure-2',
      title: 'Lahore → Dubai',
      date: '30 Aug 2026',
      time: '21:10',
      relative_label: 'In 4 days',
      days_until: 4,
      customer_name: 'Usman Raza',
      order_number: 'ORD-1061',
      booking_reference: 'PNR3DX',
      description: 'EK 623 · Economy · 25kg baggage',
      order_uid: null,
    },
    {
      key: 'demo-departure-3',
      title: 'Islamabad → Doha',
      date: '02 Sep 2026',
      time: '03:40',
      relative_label: 'In 7 days',
      days_until: 7,
      customer_name: 'Fatima Ali',
      order_number: 'ORD-1053',
      booking_reference: 'PNR9QA',
      description: 'QR 633 · Economy · 30kg baggage',
      order_uid: null,
    },
  ],
};


const money = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
const percent = (value, total) => {
  const safeTotal = Number(total || 0);

  return safeTotal > 0 ? Math.round((Number(value || 0) / safeTotal) * 100) : 0;
};

const storedUser = () => {
  try {
    return JSON.parse(localStorage.getItem('user') || '{}');
  } catch {
    return {};
  }
};

const dayGreeting = () => {
  const hour = new Date().getHours();

  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
};

const authHeaders = () => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
};

const eventConfig = {
  checkin: {
    title: 'Upcoming Check-ins',
    empty: 'No upcoming check-ins',
    icon: <LoginOutlined />,
    color: 'green',
  },
  checkout: {
    title: 'Upcoming Check-outs',
    empty: 'No upcoming check-outs',
    icon: <LogoutOutlined />,
    color: 'orange',
  },
  departure: {
    title: 'Upcoming Departures',
    empty: 'No upcoming departures',
    icon: <RocketOutlined />,
    color: 'blue',
  },
};

const urgencyBuckets = [
  { maxDays: 2, color: 'red', className: 'urgency-red' },
  { maxDays: 7, color: 'gold', className: 'urgency-yellow' },
  { maxDays: 12, color: 'green', className: 'urgency-green' },
  { maxDays: Infinity, color: 'blue', className: 'urgency-blue' },
];

function getEventUrgency(event) {
  const daysUntil = Number(event?.days_until);
  const bucket = urgencyBuckets.find((item) => daysUntil <= item.maxDays);

  return bucket || urgencyBuckets[urgencyBuckets.length - 1];
}

function eventDateLabel(event) {
  const date = event.time ? `${event.date} ${event.time}` : event.date;

  return `${date} - ${event.relative_label}`;
}

function EventListCard({ type, events, loading, onOpen }) {
  const config = eventConfig[type];

  return (
    <Card
      className="dashboard-event-card border-beam-aurora"
      title={<Space>{config.icon}<span>{config.title}</span></Space>}
      extra={<Badge count={events.length} showZero color={config.color} />}
    >
      {loading ? (
        <Skeleton active paragraph={{ rows: 5 }} />
      ) : events.length === 0 ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={config.empty} />
      ) : (
        <div className="dashboard-event-list">
          {events.slice(0, 7).map((event) => {
            const urgency = getEventUrgency(event);

            return (
              <button key={event.key} type="button" className={`dashboard-event-item ${urgency.className}`} onClick={() => onOpen(event)}>
                <span className="dashboard-item-main">
                  <span className="dashboard-item-title">
                    <span>{event.title}</span>
                    <Tag color={urgency.color}>{event.relative_label}</Tag>
                  </span>
                  <span className="dashboard-item-description">
                    <Text type="secondary">{eventDateLabel(event)}</Text>
                    <Text type="secondary">
                      {[event.customer_name, event.order_number, event.booking_reference].filter(Boolean).join(' - ')}
                    </Text>
                    {event.description && <Text type="secondary">{event.description}</Text>}
                  </span>
                </span>
                <span className="dashboard-row-arrow" aria-hidden="true">
                  <ArrowRightOutlined />
                </span>
              </button>
            );
          })}
        </div>
      )}
    </Card>
  );
}

export default function Dashboard() {
  const navigate = useNavigate();
  const screens = Grid.useBreakpoint();
  const { token: themeToken } = theme.useToken();
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState(null);

  const loadDashboard = async () => {
  setLoading(true);

  if (DEMO_MODE) {
    setReport(demoReport);
    setLoading(false);
    return;
  }

  try {
    const response = await fetch(
      '/api/v1/reports/dashboard-upcoming?days=7',
      {
        headers: authHeaders(),
      }
    );

    const data = await response.json();

    if (!response.ok) {
      throw new Error(
        data?.message || 'Could not load dashboard updates'
      );
    }

    setReport(data);
  } catch (error) {
    message.error(
      error.message || 'Could not load dashboard updates'
    );
  } finally {
    setLoading(false);
  }
};

  useEffect(() => {
    loadDashboard();
  }, []);

  const finance = report?.finance;
  const outstanding = finance?.outstanding;
  const user = storedUser();
  const firstName = (user?.name || user?.email || 'there').split(' ')[0];
  const expenses = (finance?.expenses?.length ? finance.expenses : finance?.mix || [])
    .filter((item) => Number(item.value) > 0);
  const expenseTotal = expenses.reduce((total, item) => total + Number(item.value || 0), 0);
  const performance = finance?.performance || [];
  const maxPerformance = Math.max(
    1,
    ...performance.flatMap((item) => [
      Math.abs(Number(item.sales || 0)),
      Math.abs(Number(item.purchase || 0)),
      Math.abs(Number(item.profit_loss || 0)),
    ])
  );
  const performancePoints = performance
    .map((item, index) => {
      const x = performance.length <= 1 ? 50 : (index / (performance.length - 1)) * 100;
      const net = Number(item.profit_loss || 0);
      const y = 78 - ((net + maxPerformance) / (maxPerformance * 2)) * 56;

      return `${x},${Math.min(86, Math.max(12, y))}`;
    })
    .join(' ');
  const pieConfig = {
    data: expenses,
    angleField: 'value',
    colorField: 'type',
    radius: 0.88,
    innerRadius: 0.62,
    height: 230,
    legend: false,
    label: false,
    color: ['#ffc233', '#ff7a1a', '#8a4df6', '#d899f9', '#5cc8ff', '#c4c9d4'],
    tooltip: {
      title: (item) => item.type,
      items: [{ field: 'value', name: 'Amount', valueFormatter: (value) => money(value) }],
    },
  };
  const customerOutstandingData = (outstanding?.customers || [])
    .filter((item) => Number(item.amount) > 0)
    .map((item) => ({
      ...item,
      label: item.name,
      amount: Number(item.amount || 0),
    }));
  const vendorOutstandingData = (outstanding?.vendors || [])
    .filter((item) => Number(item.amount) > 0)
    .map((item) => ({
      ...item,
      label: item.name,
      amount: Number(item.amount || 0),
    }));
  const outstandingChartConfig = (data, color) => ({
    data,
    autoFit: true,
    xField: 'label',
    yField: 'amount',
    height: screens.md ? 260 : 230,
    padding: screens.md ? 'auto' : [20, 16, 84, 44],
    color,
    axis: {
      x: {
        title: false,
        labelFill: themeToken.colorTextSecondary,
        labelFontSize: screens.md ? 12 : 11,
        labelAutoHide: true,
        labelAutoRotate: false,
      },
      y: {
        title: false,
        labelFill: themeToken.colorTextSecondary,
        labelFontSize: screens.md ? 12 : 11,
        grid: true,
        gridStroke: themeToken.colorSplit,
        gridLineDash: [4, 4],
      },
    },
    legend: false,
    label: {
      position: 'top',
      text: (item) => money(item.amount),
      fill: themeToken.colorText,
      fontSize: screens.md ? 12 : 11,
      fontWeight: 600,
    },
    tooltip: {
      title: (item) => item.name,
      items: [{ field: 'amount', name: 'Outstanding', valueFormatter: (value) => money(value) }],
    },
    theme: {
      view: {
        viewFill: 'transparent',
        plotFill: 'transparent',
      },
    },
  });

  const openVoucher = (event) => {
    if (event?.order_uid) {
      openRoute(navigate, `/orders/${event.order_uid}/voucher`);
    }
  };

  return (
    <div className="page-shell page-fade-up dashboard-page">
      <Layout.Content>
        <div className="dashboard-topbar">
          <Title level={1}>{dayGreeting()}, {firstName}</Title>
          <Button icon={<ReloadOutlined />} onClick={loadDashboard} loading={loading}>
            Refresh
          </Button>
        </div>

        <div className="dashboard-insights-head">
          <Title level={2}>Insights for you</Title>
        </div>

        {finance && (
          <>
            <Row gutter={[14, 14]} className="dashboard-finance-pill-row">
              <Col xs={24} sm={12} xl={5}>
                <Statistic title="Invoiced this month" value={finance.summary.invoiced} formatter={(value) => money(value)} prefix={<DollarOutlined />} />
              </Col>
              <Col xs={24} sm={12} xl={5}>
                <Statistic title="Collected this month" value={finance.summary.collected} formatter={(value) => money(value)} prefix={<WalletOutlined />} />
              </Col>
              <Col xs={24} sm={12} xl={5}>
                <Statistic title="Purchase this month" value={finance.summary.purchase} formatter={(value) => money(value)} prefix={<DollarOutlined />} />
              </Col>
              <Col xs={24} sm={12} xl={5}>
                <Statistic title="Refund this month" value={finance.summary.refund} formatter={(value) => money(value)} prefix={<SwapOutlined />} />
              </Col>
              <Col xs={24} sm={12} xl={4}>
                <Statistic title="Paid this month" value={finance.summary.paid} formatter={(value) => money(value)} prefix={<WalletOutlined />} />
              </Col>
            </Row>
            <Row gutter={[18, 18]} className="dashboard-outstanding-row">
              <Col xs={24} xl={12}>
                <Card
                  className="dashboard-insight-card dashboard-outstanding-card"
                  title="Customer outstanding"
                  extra={<Button size="small" onClick={() => navigate('/statements/customers')}>View statement</Button>}
                >
                  {customerOutstandingData.length ? (
                    <>
                      <Statistic
                        className="dashboard-outstanding-total"
                        title="Total receivable"
                        value={outstanding?.summary?.customer_total || 0}
                        formatter={(value) => money(value)}
                        prefix={<WalletOutlined />}
                      />
                      <Column {...outstandingChartConfig(customerOutstandingData, '#2f7d62')} />
                    </>
                  ) : (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No customer outstanding" />
                  )}
                </Card>
              </Col>
              <Col xs={24} xl={12}>
                <Card
                  className="dashboard-insight-card dashboard-outstanding-card"
                  title="Vendor outstanding"
                  extra={<Button size="small" onClick={() => navigate('/statements/vendors')}>View statement</Button>}
                >
                  {vendorOutstandingData.length ? (
                    <>
                      <Statistic
                        className="dashboard-outstanding-total"
                        title="Total payable"
                        value={outstanding?.summary?.vendor_total || 0}
                        formatter={(value) => money(value)}
                        prefix={<DollarOutlined />}
                      />
                      <Column {...outstandingChartConfig(vendorOutstandingData, '#c25c2b')} />
                    </>
                  ) : (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No vendor outstanding" />
                  )}
                </Card>
              </Col>
            </Row>
            <Row gutter={[18, 18]} className="dashboard-insights-grid">
              <Col xs={24} xl={12}>
                <Card
                  className="dashboard-insight-card"
                  title="Services breakdown"
                  extra={<Button size="small" onClick={() => navigate('/reports/profit')}>View report</Button>}
                >
                  {expenses.length ? (
                    <div className="dashboard-expense-layout">
                      <div className="dashboard-expense-legend">
                        {expenses.map((item, index) => (
                          <button key={item.type} type="button" className="dashboard-expense-row" onClick={() => navigate('/reports/profit')}>
                            <span className={`dashboard-expense-dot dot-${index % 6}`} />
                            <span>{percent(item.value, expenseTotal)}% {item.type}</span>
                          </button>
                        ))}
                      </div>
                      <Pie {...pieConfig} />
                    </div>
                  ) : (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No finance data" />
                  )}
                </Card>
              </Col>

              <Col xs={24} xl={12}>
                <Card
                  className="dashboard-insight-card"
                  title="Performance"
                  extra={<Button size="small" onClick={() => navigate('/reports/performance')}>View report</Button>}
                >
                  {performance.length ? (
                    <div className="dashboard-performance-chart">
                      <div className="dashboard-performance-legend">
                        <span><i className="performance-dot sales" />Sales</span>
                        <span><i className="performance-dot purchase" />Purchase</span>
                        <span><i className="performance-dot profit-loss" />Profit/Loss</span>
                      </div>
                      <div className="dashboard-performance-bars">
                        <svg className="dashboard-performance-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                          <polyline points={performancePoints} />
                        </svg>
                        {performance.map((item) => {
                          const sales = Number(item.sales || 0);
                          const purchase = Number(item.purchase || 0);

                          return (
                            <div key={item.period} className="dashboard-performance-month">
                              <div className="dashboard-performance-bars-stack">
                                <span className="bar sales" style={{ height: `${Math.max(6, (sales / maxPerformance) * 118)}px` }} title={`SALES ${money(sales)}`} />
                                <span className="bar purchase" style={{ height: `${Math.max(6, (purchase / maxPerformance) * 118)}px` }} title={`Purchase ${money(purchase)}`} />
                              </div>
                              <Text type="secondary">{item.label}</Text>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  ) : (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No performance data" />
                  )}
                </Card>
              </Col>
            </Row>
          </>
        )}

        <Row gutter={[16, 16]} className="dashboard-travel-row">
          <Col xs={24} lg={8}>
            <EventListCard type="checkin" events={report?.checkins || []} loading={loading} onOpen={openVoucher} />
          </Col>
          <Col xs={24} lg={8}>
            <EventListCard type="checkout" events={report?.checkouts || []} loading={loading} onOpen={openVoucher} />
          </Col>
          <Col xs={24} lg={8}>
            <EventListCard type="departure" events={report?.departures || []} loading={loading} onOpen={openVoucher} />
          </Col>
        </Row>
      </Layout.Content>
    </div>
  );
}
