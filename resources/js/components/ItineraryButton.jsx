import React, { useState } from 'react';
import { Button, Dropdown } from 'antd';
import { DownloadOutlined, EyeOutlined, SendOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';
import { airlineLogoFallback, fetchItineraryOrder, hasItinerary, itineraryShareUrl, itineraryData, itineraryFilename, itineraryPdf, shareItinerary } from '../services/itinerary';
import { loadAgencyLogo } from '../services/statementExport';
import { getAirportLabel } from '../pages/sales-flow/airportLookup';
import { itineraryQrImage } from './ItineraryQr';
import { openRoute } from '../services/navigation';

export async function downloadItinerary(order, sharedToken, existingShareUrl) {
  const data = itineraryData(order, getAirportLabel);
  const [logo, footerLogo] = await Promise.all([loadAgencyLogo(data.logoUrl), loadAgencyLogo(data.footerLogoUrl)]);
  const url = existingShareUrl || await itineraryShareUrl(order.uid, sharedToken || order.share_token);
  const shareQr = await itineraryQrImage(url, logo);
  const airlineEntries = await Promise.all([...new Map(data.flights.map((f) => [f.airlineCode, f.logoUrl]))].map(async ([code, url]) => {
    const image = await loadAgencyLogo(url).catch(() => loadAgencyLogo(airlineLogoFallback));
    return [code, image];
  }));
  const iconEntries = await Promise.all(['takeoff', 'landing'].map(async (name) => {
    const response = await fetch(`/images/icons/${name}.png`);
    if (!response.ok) throw new Error('Could not load flight icons. Please try again.');
    return [name, new Uint8Array(await response.arrayBuffer())];
  }));
  const pdf = await itineraryPdf(data, logo, { ...Object.fromEntries(iconEntries), airlines: Object.fromEntries(airlineEntries) }, footerLogo, shareQr);
  pdf.save(itineraryFilename(data));
}

export default function ItineraryButton({ order, sharedToken, size = 'small' }) {
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  if (!hasItinerary(order)) return null;
  const path = sharedToken ? `/shared/itineraries/${sharedToken}` : `/orders/${order.uid}/itinerary`;
  const action = async (key) => {
    if (key === 'view') { openRoute(navigate, path); return; }
    setBusy(true);
    try {
      if (key === 'share') {
        if (await shareItinerary(order.uid, sharedToken)) message.success('Itinerary share link copied');
      } else {
        await downloadItinerary(await fetchItineraryOrder(order.uid, sharedToken), sharedToken);
      }
    } catch (error) { message.error(error.message || 'Could not prepare itinerary'); }
    finally { setBusy(false); }
  };
  return <Dropdown trigger={['click']} menu={{ onClick: ({ key }) => action(key), items: [
    { key: 'view', label: 'View itinerary', icon: <EyeOutlined /> },
    { key: 'download', label: 'Download PDF', icon: <DownloadOutlined /> },
    { key: 'share', label: 'Copy share link', icon: <ShareAltOutlined /> },
  ] }}>
    <Button size={size} icon={<SendOutlined />} loading={busy}>Itinerary</Button>
  </Dropdown>;
}
