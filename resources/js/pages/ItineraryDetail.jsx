import React, { useEffect, useState } from 'react';
import { Button, Result, Skeleton, Space } from 'antd';
import { ArrowLeftOutlined, DownloadOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { fetchItineraryOrder, flightDateLabel, flightTimeReminder, itineraryData, itineraryNotice, shareItinerary } from '../services/itinerary';
import { downloadItinerary } from '../components/ItineraryButton';
import { getAirportLabel } from './sales-flow/airportLookup';
import { backToRoute } from '../services/navigation';
import { message } from '../services/feedback';
import '../../css/itinerary.css';

export default function ItineraryDetail({ shared = false }) {
  const { uid, token } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [order, setOrder] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  useEffect(() => {
    let active = true;
    setOrder(null); setError('');
    fetchItineraryOrder(uid, shared ? token : undefined).then((data) => { if (active) setOrder(data); })
      .catch((err) => { if (active) setError(err.message); });
    return () => { active = false; };
  }, [uid, token, shared]);
  const run = async (action) => {
    setBusy(action);
    try {
      if (action === 'download') await downloadItinerary(order);
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
    </Space>
    <article className="itinerary-paper">
      <header className="itinerary-brand">
        <div><h1>{data.agency}</h1>{data.contact.map((line, i) => <div key={i}>{line}</div>)}</div>
        {data.logoUrl && <img src={data.logoUrl} alt={`${data.agency} logo`} />}
      </header>
      <h2>{data.quote ? 'Proposed travel itinerary' : 'Travel itinerary'}</h2>
      {data.booking && <div className="itinerary-references"><span>Booking reference: <strong>{data.booking}</strong></span></div>}
      {!!data.passengers.length && <section className="itinerary-passengers"><h3>Prepared for</h3>{data.passengers.map((p, i) => <div key={i}><strong>{p.name}</strong>{p.ticket && <span>Ticket: {p.ticket}</span>}</div>)}</section>}
      {data.flights.map((f, i) => <section className="itinerary-flight" key={i}>
        <h3><span>DEPARTURE: {flightDateLabel(f.date)}</span><span style={{ fontWeight: 400 }}>{flightTimeReminder}</span></h3>
        <div className="itinerary-airline"><span>{(f.airline || 'Airline not provided').toUpperCase()}</span><strong>{f.number || 'Flight number not provided'}</strong></div>
        <div className="itinerary-route">{[
          ['Departure', f.from, f.fromLabel, f.departure, f.date],
          ['Arrival', f.to, f.toLabel, f.arrival, f.arrivalDate],
        ].map(([label, code, airport, time, date]) => <div key={label}>
          <small className="itinerary-direction"><img src={`/images/icons/${label === 'Departure' ? 'takeoff' : 'landing'}.png`} alt="" aria-hidden="true" />{label}</small><strong className="itinerary-airport">{code || '—'}</strong><div>{airport}</div><strong className="itinerary-time">{time || 'Time not provided'}</strong>{date && <strong>{flightDateLabel(date)}</strong>}
        </div>)}</div>
        <dl className="itinerary-facts">{[
          ['Cabin', [f.cabin, f.bookingClass].filter(Boolean).join(' / ')], ['Baggage', f.baggage],
          ['Booking reference', f.booking], ['Airline reference', f.airlineReference],
        ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || 'Not provided'}</dd></div>)}</dl>
      </section>)}
      <footer>{data.emergency && <p><strong>Emergency contact:</strong> {data.emergency}</p>}<p>{itineraryNotice}</p>{data.unpaid && <p>Powered by InYice</p>}</footer>
    </article>
  </div>;
}
