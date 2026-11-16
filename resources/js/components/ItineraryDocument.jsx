import React from 'react';
import { MailOutlined, PhoneOutlined } from '@ant-design/icons';
import { flightDateLabel, flightTimeReminder, itineraryNotice } from '../services/itinerary';
import ItineraryQr from './ItineraryQr';
import '../../css/itinerary.css';

// One document for the on-screen preview and PDF export.
export default function ItineraryDocument({ data, shareUrl, qrImage }) {
  return <article className="itinerary-paper">
      <header className="itinerary-top-header">
        <div className="itinerary-agency">
        <div className="itinerary-brand">
          {data.logoUrl && <img src={data.logoUrl} alt={`${data.agency} logo`} />}
          <div><h1 title={data.agency}>{data.agency}</h1>{data.contacts.map(({ type, value }, i) => <div className="itinerary-contact" key={i}>
            {type === 'email' ? <MailOutlined aria-label="Email" /> : <PhoneOutlined aria-label="Phone" />}<span>{value}</span>
          </div>)}</div>
        </div>
        {data.address && <div className="itinerary-address">{data.address}</div>}
        </div>
        <div className="itinerary-qrs">
          {shareUrl && <div title="Open this itinerary">{qrImage ? <img className="itinerary-qr" src={qrImage} alt="Itinerary QR code" /> : <ItineraryQr value={shareUrl} logo={data.logoUrl} />}<small>Itinerary</small></div>}
          {data.footerLogoUrl && <div><img className="itinerary-qr" src={data.footerLogoUrl} alt={`${data.agency} QR code`} /><small>Agency</small></div>}
        </div>
      </header>
      <div className="itinerary-heading">
        <h2 className="itinerary-trip-title">{data.tripTitle}</h2>
      </div>
      <header className="itinerary-summary">
        <div>
          <section className="itinerary-passengers"><h3>Prepared for</h3>{data.passengers.map((p, i) => <div key={i}><span><strong>{p.name}</strong>{p.ticket && <> — {p.ticket}</>}</span></div>)}</section>
        </div>
          <div className="itinerary-references">
            <div>Reservation code: <strong>{data.reservationCodes || 'Not provided'}</strong></div>
            <div>Airline reservation code: <strong>{data.airlineReservationCodes || 'Not provided'}</strong></div>
            <div>Status: <strong>{data.status}</strong></div>
          </div>
      </header>
      {data.flights.map((f, i) => <React.Fragment key={i}>
        <section className="itinerary-segment">
          <div className="itinerary-segment-heading">
            {f.airlineCode && <img src={f.logoUrl} alt={f.airline || 'Airline'} onError={(e) => { e.currentTarget.style.display = 'none'; }} />}
            <div><strong>DEPARTURE: {flightDateLabel(f.date)}</strong>
              {f.arrivalDate && f.arrivalDate !== f.date && <strong> / ARRIVAL: {flightDateLabel(f.arrivalDate)}</strong>}
              <small>{flightTimeReminder}</small>
            </div>
          </div>
          <div className="itinerary-segment-grid">
            <div className="itinerary-segment-airline">
              <div>{(f.airline || 'Airline not provided').toUpperCase()}</div>
              <strong>{f.number || 'Flight number not provided'}</strong>
              <dl><dt>{f.durationLabel}</dt><dd>{f.estimatedDuration}</dd>
                <dt>Cabin</dt><dd>{[f.cabin, f.bookingClass].filter(Boolean).join(' / ') || 'Not provided'}</dd>
                <dt>Status</dt><dd>{data.status}</dd></dl>
            </div>
            {[
              ['Departing at', f.from, f.fromLabel, f.departure, f.date],
              ['Arriving at', f.to, f.toLabel, f.arrival, f.arrivalDate],
            ].map(([label, code, airport, time, date]) => <div className="itinerary-segment-airport" key={label}>
              <div><strong>{code || '-'}</strong><span>{airport}</span></div>
              <div><small>{label}</small><strong>{time || 'Not provided'}</strong>
                {f.arrivalDate !== f.date && <span>{flightDateLabel(date)}</span>}
              </div>
            </div>)}
          </div>
          <p className="itinerary-segment-baggage"><strong>Baggage:</strong> {f.baggage || 'Not provided'}</p>
        </section>
        {f.transit && <div className="itinerary-transit">{f.transit}</div>}
      </React.Fragment>)}
      {data.notes && <section className="itinerary-notes"><h3>Notes</h3><div>{data.notes}</div></section>}
      <footer>{data.emergency && <p><strong>Emergency contact:</strong> {data.emergency}</p>}<p>{itineraryNotice}</p>{data.unpaid && <p>Powered by InYice</p>}</footer>
    </article>;
}
