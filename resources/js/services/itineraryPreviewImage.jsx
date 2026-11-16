import React from 'react';
import { createRoot } from 'react-dom/client';
import { flushSync } from 'react-dom';
import ItineraryDocument from '../components/ItineraryDocument';

// A separate desktop viewport keeps mobile images identical to the desktop preview.
// Render the shared component with the application's actual styles, not a separate image layout.
export async function previewItineraryImage(data, shareUrl, qrImage) {
  const { toCanvas, getFontEmbedCSS } = await import('html-to-image');
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.tabIndex = -1;
  frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1000px;height:1200px;border:0;pointer-events:none;';
  document.body.append(frame);
  let root;
  try {
    const doc = frame.contentDocument;
    const base = doc.createElement('base');
    base.href = document.baseURI;
    doc.head.append(base);
    const stylesReady = [...document.querySelectorAll('style, link[rel="stylesheet"]')].map((style) => {
      const copy = style.cloneNode(true);
      const ready = copy.tagName === 'LINK' ? new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Itinerary styles could not be loaded. Please try again.')), 15000);
        copy.onload = () => { clearTimeout(timer); resolve(); };
        // A missing application stylesheet must not produce an unstyled export.
        // External font stylesheets may fall back, as they do in the preview.
        copy.onerror = () => {
          clearTimeout(timer);
          copy.remove();
          if (new URL(copy.href, document.baseURI).origin === window.location.origin) {
            reject(new Error('Itinerary styles could not be loaded. Please refresh and try again.'));
          } else resolve();
        };
      }) : Promise.resolve();
      doc.head.append(copy);
      return ready;
    });
    await Promise.all(stylesReady);
    doc.documentElement.className = document.documentElement.className;
    for (const [key, value] of Object.entries(document.documentElement.dataset)) doc.documentElement.dataset[key] = value;
    doc.body.style.cssText = 'margin:0;background:white;';
    doc.body.style.fontFamily = getComputedStyle(document.querySelector('.itinerary-paper') || document.body).fontFamily;
    const viewport = doc.createElement('div');
    viewport.className = 'page-shell itinerary-page';
    viewport.style.cssText = 'width:850px;padding:0;margin:0;overflow:hidden;background:white;position:relative;';
    const app = doc.createElement('div');
    app.className = document.querySelector('.ant-app')?.className || '';
    doc.body.append(app);
    app.append(viewport);
    root = createRoot(viewport);
    flushSync(() => root.render(<ItineraryDocument data={data} shareUrl={shareUrl} qrImage={qrImage} />));
    const paper = viewport.querySelector('.itinerary-paper');
    paper.style.width = '850px';
    paper.style.boxSizing = 'border-box';
    paper.style.margin = '0';
    await doc.fonts.ready;
    await Promise.all([...paper.querySelectorAll('img')].map((image) => image.decode().catch(() => {
      throw new Error('An itinerary image could not be rendered. Please try again.');
    })));
    const fontEmbedCSS = await getFontEmbedCSS(paper);
    const totalHeight = Math.ceil(paper.getBoundingClientRect().height);
    // Keep a single PNG within browser canvas limits for very long itineraries.
    const pixelRatio = Math.min(2, 16000 / totalHeight, Math.sqrt(32000000 / (850 * totalHeight)));
    viewport.style.height = `${totalHeight}px`;
    const canvas = await toCanvas(viewport, {
      pixelRatio, width: 850, height: totalHeight,
      backgroundColor: '#ffffff', fontEmbedCSS,
    });
    try {
      return await new Promise((resolve, reject) => canvas.toBlob((blob) => {
        if (blob) resolve(blob);
        else reject(new Error('Could not prepare itinerary image.'));
      }, 'image/png'));
    } finally {
      canvas.width = 0;
      canvas.height = 0;
    }
  } finally {
    root?.unmount();
    frame.remove();
  }
}
