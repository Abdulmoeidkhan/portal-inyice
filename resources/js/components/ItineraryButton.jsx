import React, { useState } from 'react';
import { Button, Dropdown, Grid } from 'antd';
import { DownloadOutlined, PictureOutlined, EyeOutlined, SendOutlined, ShareAltOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';
import { fetchItineraryOrder, hasItinerary, itineraryShareUrl, itineraryData, itineraryFilename, itineraryPdf, shareItinerary } from '../services/itinerary';
import { loadAgencyLogo } from '../services/statementExport';
import { getAirportLabel } from '../pages/sales-flow/airportLookup';
import { itineraryQrImage } from './ItineraryQr';
import { previewItineraryImage } from '../services/itineraryPreviewImage';
import { openRoute } from '../services/navigation';

export async function downloadItinerary(order, sharedToken, existingShareUrl, format = 'pdf') {
  const data = itineraryData(order, getAirportLabel);
  const [logo, footerLogo] = await Promise.all([loadAgencyLogo(data.logoUrl), loadAgencyLogo(data.footerLogoUrl)]);
  const url = existingShareUrl || await itineraryShareUrl(order.uid, sharedToken || order.share_token);
  const shareQr = await itineraryQrImage(url, logo);
  const airlineEntries = await Promise.all([...new Map(data.flights.map((f) => [f.airlineCode, f.logoUrl]))].map(async ([code, url]) => {
    const image = code ? await loadAgencyLogo(url).catch(() => null) : null;
    return [code, image];
  }));
  const airlineImages = Object.fromEntries(airlineEntries);
  if (format === 'pdf') {
    const pdf = await itineraryPdf(data, logo, { airlines: airlineImages }, footerLogo, shareQr);
    pdf.save(itineraryFilename(data));
    return;
  }
  const blob = await previewItineraryImage({
    ...data,
    logoUrl: logo?.data,
    footerLogoUrl: footerLogo?.data,
    flights: data.flights.map((flight) => ({ ...flight,
      logoUrl: airlineImages[flight.airlineCode]?.data || '',
      airlineCode: airlineImages[flight.airlineCode] ? flight.airlineCode : '',
    })),
  }, url, shareQr.data);
  const imageUrl = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = imageUrl;
  anchor.download = itineraryFilename(data).replace(/\.pdf$/i, '.png');
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  setTimeout(() => URL.revokeObjectURL(imageUrl), 60000);
}

export default function ItineraryButton({ order, sharedToken, size = 'small' }) {
  const screens = Grid.useBreakpoint();
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
        await downloadItinerary(await fetchItineraryOrder(order.uid, sharedToken), sharedToken, undefined, key === 'image' ? 'image' : 'pdf');
      }
    } catch (error) { message.error(error.message || 'Could not prepare itinerary'); }
    finally { setBusy(false); }
  };
  return <Dropdown trigger={['click']} menu={{ onClick: ({ key }) => action(key), items: [
    { key: 'view', label: 'View itinerary', icon: <EyeOutlined /> },
    { key: 'image', label: 'Download image', icon: <PictureOutlined /> },
    { key: 'download', label: 'Download PDF', icon: <DownloadOutlined /> },
    { key: 'share', label: 'Copy share link', icon: <ShareAltOutlined /> },
  ] }}>
    <Button size={size} icon={<SendOutlined />} loading={busy} aria-label="Itinerary" title="Itinerary">{screens.sm ? 'Itinerary' : null}</Button>
  </Dropdown>;
}
