import React, { useEffect, useState } from 'react';
import { Button, Card, Result, Skeleton, Space } from 'antd';
import { ArrowLeftOutlined, DownloadOutlined, PrinterOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';
import { printDocument } from '../services/printDocument';
import { backToRoute } from '../services/navigation';
import OrderInternalNotes from '../components/OrderInternalNotes';
import VoucherPreview from './sales-flow/VoucherPreview';

const authHeaders = () => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
};

const copyLink = async (url) => {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(url);
    return;
  }

  window.prompt('Copy this voucher link:', url);
};

export default function VoucherDetail({ shared = false }) {
  const navigate = useNavigate();
  const location = useLocation();
  const { uid, token } = useParams();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sharing, setSharing] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    const endpoint = shared ? `/api/v1/shared-vouchers/${token}` : `/api/v1/orders/${uid}`;
    setLoading(true);
    setError('');

    fetch(endpoint, {
      headers: shared ? { Accept: 'application/json' } : authHeaders(),
    })
      .then(async (response) => {
        const data = await response.json();

        if (!response.ok) {
          throw new Error(data?.message || 'Voucher not found');
        }

        setOrder(data);
      })
      .catch((loadError) => {
        setError(loadError.message || 'Voucher not found');
      })
      .finally(() => setLoading(false));
  }, [shared, token, uid]);

  const shareVoucher = async () => {
    if (shared) {
      await copyLink(window.location.href);
      message.success('Voucher link copied');
      return;
    }

    setSharing(true);
    try {
      const response = await fetch(`/api/v1/orders/${uid}/share`, {
        method: 'POST',
        headers: authHeaders(),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || 'Could not create share link');
      }

      await copyLink(data.share_url);
      message.success('Shareable voucher link copied');
    } catch (shareError) {
      message.error(shareError.message || 'Could not create share link');
    } finally {
      setSharing(false);
    }
  };

  if (loading) {
    return (
      <div className="page-shell voucher-detail-page">
        <Card className="border-beam-aurora">
          <Skeleton active paragraph={{ rows: 8 }} />
        </Card>
      </div>
    );
  }

  if (error || !order) {
    return (
      <div className="page-shell voucher-detail-page">
        <Result status="404" title="Voucher not found" subTitle={error || 'This voucher could not be loaded.'} />
      </div>
    );
  }

  return (
    <div className="page-shell page-fade-up voucher-detail-page">
      <Space className="voucher-screen-actions">
        {!shared && <Button icon={<ArrowLeftOutlined />} onClick={() => backToRoute(navigate, location, '/orders')}>Back</Button>}
        <Button icon={<PrinterOutlined />} onClick={() => printDocument('.voucher-preview', 'Voucher')}>Print</Button>
        <Button icon={<DownloadOutlined />} onClick={() => printDocument('.voucher-preview', 'Voucher')}>Download PDF</Button>
        <Button type="primary" icon={<ShareAltOutlined />} loading={sharing} onClick={shareVoucher}>
          Copy share link
        </Button>
      </Space>
      <VoucherPreview order={order} />
      {!shared && <OrderInternalNotes order={order} onOrderChange={setOrder} />}
    </div>
  );
}
