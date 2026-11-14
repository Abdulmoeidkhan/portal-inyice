import React from 'react';
import { QRCode } from 'antd';
import { createRoot } from 'react-dom/client';
import { flushSync } from 'react-dom';
import { loadAgencyLogo } from '../services/statementExport';

export default function ItineraryQr({ value, logo, size = 112 }) {
  return <QRCode value={value} icon={logo || undefined} size={size} iconSize={size * 0.18}
    errorLevel="H" type="svg" marginSize={4} bordered={false} color="#000" bgColor="#fff" style={{ padding: 0 }} />;
}

// Export the same Ant Design QR as the preview, with an embedded logo for safe rasterization.
export async function itineraryQrImage(value, logo) {
  const host = document.createElement('div');
  const root = createRoot(host);
  try {
    flushSync(() => root.render(<ItineraryQr value={value} logo={logo?.data} size={512} />));
    const svg = host.querySelector('svg');
    if (!svg) throw new Error('Could not generate itinerary QR code.');
    svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    return await loadAgencyLogo(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(new XMLSerializer().serializeToString(svg))}`);
  } finally {
    root.unmount();
  }
}
