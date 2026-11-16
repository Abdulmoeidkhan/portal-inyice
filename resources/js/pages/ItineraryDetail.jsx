import React, { useEffect, useState } from 'react';
import { Button, Result, Skeleton, Space } from 'antd';
import { ArrowLeftOutlined, DownloadOutlined, PictureOutlined, FileTextOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { fetchItineraryOrder, itineraryShareUrl, itineraryData, shareItinerary } from '../services/itinerary';
import ItineraryDocument from '../components/ItineraryDocument';
import { downloadItinerary } from '../components/ItineraryButton';
import { getAirportLabel } from './sales-flow/airportLookup';
import { backToRoute, openRoute } from '../services/navigation';
import { message } from '../services/feedback';
import '../../css/itinerary.css';

export default function ItineraryDetail({ shared = false }) {
  const { uid, token } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [order, setOrder] = useState(null);
  const [error, setError] = useState('');
  const [shareUrl, setShareUrl] = useState('');
  const [qrError, setQrError] = useState('');
  const [busy, setBusy] = useState('');
  useEffect(() => {
    let active = true;
    setOrder(null); setError('');
    fetchItineraryOrder(uid, shared ? token : undefined).then((data) => { if (active) setOrder(data); })
      .catch((err) => { if (active) setError(err.message); });
    return () => { active = false; };
  }, [uid, token, shared]);
  useEffect(() => {
    let active = true;
    setShareUrl(''); setQrError('');
    if (order) itineraryShareUrl(order.uid || uid, shared ? token : order.share_token)
      .then((url) => { if (active) setShareUrl(url); })
      .catch((err) => { if (active) setQrError(err.message); });
    return () => { active = false; };
  }, [order, uid, token, shared]);
  const run = async (action) => {
    setBusy(action);
    try {
      if (action === 'download' || action === 'image') await downloadItinerary(order, shared ? token : undefined, shareUrl, action === 'image' ? 'image' : 'pdf');
      else if (await shareItinerary(uid, shared ? token : undefined)) message.success('Itinerary share link copied');
    } catch (err) { message.error(err.message || 'Could not prepare itinerary'); }
    finally { setBusy(''); }
  };
  if (error) return <Result status="404" title="Itinerary unavailable" subTitle={error} />;
  if (!order) return <div className="page-shell"><Skeleton active /></div>;
  const data = itineraryData(order, getAirportLabel);
  return <div className="page-shell itinerary-page">
    <Space wrap style={{ marginBottom: 16 }}>
      {!shared && <Button icon={<ArrowLeftOutlined />} onClick={() => backToRoute(navigate, location, '/orders')}>Back</Button>}
      <Button icon={<PictureOutlined />} loading={busy === 'image'} disabled={!!busy} onClick={() => run('image')}>Download image</Button>
      <Button icon={<DownloadOutlined />} loading={busy === 'download'} disabled={!!busy} onClick={() => run('download')}>Download PDF</Button>
      <Button type="primary" icon={<ShareAltOutlined />} loading={busy === 'share'} disabled={!!busy} onClick={() => run('share')}>Copy share link</Button>
      <Button icon={<FileTextOutlined />} onClick={(event) => openRoute(navigate, shared ? `/shared/vouchers/${encodeURIComponent(token)}` : `/orders/${encodeURIComponent(uid)}/voucher`, event)}>Voucher</Button>
    </Space>
    {qrError && <p role="alert">Share QR unavailable: {qrError}</p>}
    <ItineraryDocument data={data} shareUrl={shareUrl} />
  </div>;
}
