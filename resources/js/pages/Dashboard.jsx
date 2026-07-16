import React, { useEffect, useMemo, useState } from 'react';
import { Badge, Button, Card, Col, Empty, Layout, Row, Skeleton, Space, Statistic, Tag, Typography, theme } from 'antd';
import {
  ArrowRightOutlined,
  BellOutlined,
  CalendarOutlined,
  LoginOutlined,
  LogoutOutlined,
  ReloadOutlined,
  RocketOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';

const { Text, Title } = Typography;

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
  const { token } = theme.useToken();
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState(null);

  const loadDashboard = async () => {
    setLoading(true);

    try {
      const response = await fetch('/api/v1/reports/dashboard-upcoming?days=30', {
        headers: authHeaders(),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || 'Could not load dashboard updates');
      }

      setReport(data);
    } catch (error) {
      message.error(error.message || 'Could not load dashboard updates');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadDashboard();
  }, []);

  const stats = useMemo(() => [
    {
      title: 'Check-ins',
      value: report?.summary?.checkins || 0,
      icon: <LoginOutlined />,
      color: token.colorSuccess,
    },
    {
      title: 'Check-outs',
      value: report?.summary?.checkouts || 0,
      icon: <LogoutOutlined />,
      color: token.colorWarning,
    },
    {
      title: 'Departures',
      value: report?.summary?.departures || 0,
      icon: <RocketOutlined />,
      color: token.colorInfo,
    },
    {
      title: 'Updates',
      value: report?.summary?.notifications || 0,
      icon: <BellOutlined />,
      color: token.colorPrimary,
    },
  ], [report, token]);

  const openVoucher = (event) => {
    if (event?.order_uid) {
      navigate(`/orders/${event.order_uid}/voucher`);
    }
  };

  return (
    <div className="page-shell page-fade-up dashboard-page">
      <Layout.Content>
        <div className="elevated-card border-beam-aurora dashboard-hero">
          <div>
            <Title level={1}>Dashboard</Title>
            <Text type="secondary">
              Upcoming travel movements and company updates for the next {report?.period?.days || 30} days.
            </Text>
          </div>
          <Space>
            <Button icon={<CalendarOutlined />} onClick={() => navigate('/orders')}>
              Orders
            </Button>
            <Button icon={<ReloadOutlined />} onClick={loadDashboard} loading={loading}>
              Refresh
            </Button>
          </Space>
        </div>

        <Row gutter={[16, 16]} className="dashboard-summary-row">
          {stats.map((stat, index) => (
            <Col xs={12} lg={6} key={stat.title}>
              <Card className={`dashboard-stat-card border-beam-aurora stagger-${(index % 3) + 1}`}>
                <Statistic title={stat.title} value={stat.value} />
                <div className="dashboard-stat-icon" style={{ color: stat.color }}>{stat.icon}</div>
              </Card>
            </Col>
          ))}
        </Row>

        <Row gutter={[16, 16]}>
          <Col xs={24} xl={16}>
            <Row gutter={[16, 16]}>
              <Col xs={24} lg={12}>
                <EventListCard type="checkin" events={report?.checkins || []} loading={loading} onOpen={openVoucher} />
              </Col>
              <Col xs={24} lg={12}>
                <EventListCard type="checkout" events={report?.checkouts || []} loading={loading} onOpen={openVoucher} />
              </Col>
              <Col xs={24}>
                <EventListCard type="departure" events={report?.departures || []} loading={loading} onOpen={openVoucher} />
              </Col>
            </Row>
          </Col>

          <Col xs={24} xl={8}>
            <Card
              className="dashboard-notification-card border-beam-aurora"
              title={<Space><BellOutlined /><span>Notification Panel</span></Space>}
              extra={<Badge count={report?.notifications?.length || 0} showZero />}
            >
              {loading ? (
                <Skeleton active paragraph={{ rows: 8 }} />
              ) : !report?.notifications?.length ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No upcoming updates" />
              ) : (
                <div className="dashboard-notification-list">
                  {report.notifications.map((item) => {
                    const urgency = getEventUrgency(item);

                    return (
                      <button key={item.key} type="button" className={`dashboard-notification-item ${urgency.className}`} onClick={() => openVoucher(item)}>
                        <span className="dashboard-item-main">
                          <span className="dashboard-item-title">
                            <span>{item.message}</span>
                            <Tag color={urgency.color}>{item.relative_label}</Tag>
                          </span>
                          <span className="dashboard-item-description">
                            <Text type="secondary">{eventDateLabel(item)}</Text>
                            <Text type="secondary">{[item.title, item.order_number].filter(Boolean).join(' - ')}</Text>
                          </span>
                        </span>
                      </button>
                    );
                  })}
                </div>
              )}
            </Card>
          </Col>
        </Row>
      </Layout.Content>
    </div>
  );
}
