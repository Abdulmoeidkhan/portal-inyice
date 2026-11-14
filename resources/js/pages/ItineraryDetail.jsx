import React, { useEffect, useState } from 'react';
import { Button, Result, Skeleton, Space } from 'antd';
import { ArrowLeftOutlined, DownloadOutlined, FileTextOutlined, MailOutlined, PhoneOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { airlineLogoFallback, fetchItineraryOrder, itineraryShareUrl, flightDateLabel, flightTimeReminder, itineraryData, itineraryNotice, shareItinerary } from '../services/itinerary';
import ItineraryQr from '../components/ItineraryQr';
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
      if (action === 'download') await downloadItinerary(order, shared ? token : undefined, shareUrl);
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
      <Button icon={<DownloadOutlined />} loading={busy === 'download'} disabled={!!busy} onClick={() => run('download')}>Download PDF</Button>
      <Button type="primary" icon={<ShareAltOutlined />} loading={busy === 'share'} disabled={!!busy} onClick={() => run('share')}>Copy share link</Button>
      <Button icon={<FileTextOutlined />} onClick={(event) => openRoute(navigate, shared ? `/shared/vouchers/${encodeURIComponent(token)}` : `/orders/${encodeURIComponent(uid)}/voucher`, event)}>Voucher</Button>
    </Space>
    {qrError && <p role="alert">Share QR unavailable: {qrError}</p>}
    <article className="itinerary-paper">
      <div className="itinerary-heading itinerary-heading-with-qr">
      <div className="itinerary-qrs">
        {shareUrl && <div title="Open this itinerary"><ItineraryQr value={shareUrl} logo={data.logoUrl} /><small>Itinerary</small></div>}
        {data.footerLogoUrl && <div><img className="itinerary-qr" src={data.footerLogoUrl} alt={`${data.agency} QR code`} /><small>Agency</small></div>}
      </div>
      <h2>{data.quote ? 'Proposed travel itinerary' : 'Travel itinerary'}</h2>
      <div className="itinerary-trip-title">{data.tripTitle}</div>
      </div>
      <header className="itinerary-summary">
        <div>
          <section className="itinerary-passengers"><h3>Prepared for</h3>{data.passengers.map((p, i) => <div key={i}><strong>{p.name}</strong>{p.ticket && <span>Ticket: {p.ticket}</span>}</div>)}</section>
          <div className="itinerary-references">
            <div>Reservation code: <strong>{data.reservationCodes || 'Not provided'}</strong></div>
            <div>Airline reservation code: <strong>{data.airlineReservationCodes || 'Not provided'}</strong></div>
            <div>Status: <strong>{data.status}</strong></div>
          </div>
        </div>
        <div className="itinerary-agency">
        <div className="itinerary-brand">
          {data.logoUrl && <img src={data.logoUrl} alt={`${data.agency} logo`} />}
          <div><h1 title={data.agency}>{data.agency}</h1>{data.contacts.map(({ type, value }, i) => <div className="itinerary-contact" key={i}>
            {type === 'email' ? <MailOutlined aria-label="Email" /> : <PhoneOutlined aria-label="Phone" />}<span>{value}</span>
          </div>)}</div>
        </div>
        {data.address && <div className="itinerary-address">{data.address}</div>}
        </div>
      </header>
      {data.flights.map((f, i) => <React.Fragment key={i}><section className="itinerary-flight">
        <h3><span>DEPARTURE: {flightDateLabel(f.date)}</span><span style={{ fontWeight: 400 }}>{flightTimeReminder}</span></h3>
        <div className="itinerary-airline"><div className="itinerary-airline-details"><div className="itinerary-airline-name">
          <img src={f.logoUrl} alt="" onError={(e) => { if (!e.currentTarget.src.endsWith(airlineLogoFallback)) e.currentTarget.src = airlineLogoFallback; }} />
          <span title={f.airline || 'Airline not provided'}>{(f.airline || 'Airline not provided').toUpperCase()}</span>
        </div><strong>{f.number || 'Flight number not provided'}</strong></div><div className="itinerary-duration"><small>{f.durationLabel}</small><strong>{f.estimatedDuration || 'Unavailable'}</strong></div></div>
        <div className="itinerary-route">{[
          ['Departure', f.from, f.fromLabel, f.departure, f.date],
          ['Arrival', f.to, f.toLabel, f.arrival, f.arrivalDate],
        ].map(([label, code, airport, time, date]) => <div key={label}>
          <small className="itinerary-direction"><img src={`/images/icons/${label === 'Departure' ? 'takeoff' : 'landing'}.png`} alt="" aria-hidden="true" />{label}</small><strong className="itinerary-airport">{code || '—'}</strong><div>{airport}</div><strong className="itinerary-time">{time || 'Time not provided'}</strong>{date && <strong>{flightDateLabel(date)}</strong>}
        </div>)}</div>
        <dl className="itinerary-facts">{[
          ['Status', data.status], ['Cabin', [f.cabin, f.bookingClass].filter(Boolean).join(' / ')], ['Baggage', f.baggage],
          ['Booking reference', f.booking], ['Airline reference', f.airlineReference],
        ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || 'Not provided'}</dd></div>)}</dl>
      </section>{f.transit && <div className="itinerary-transit">{f.transit}</div>}</React.Fragment>)}
      <footer>{data.emergency && <p><strong>Emergency contact:</strong> {data.emergency}</p>}<p>{itineraryNotice}</p>{data.unpaid && <p>Powered by InYice</p>}</footer>
    </article>
  </div>;
}
