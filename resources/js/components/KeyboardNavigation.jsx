import React, { useEffect, useRef, useState } from 'react';
import { Button, Input, Modal } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { useLocation, useNavigate } from 'react-router-dom';

const labelText = (label) => {
  if (typeof label === 'string' || typeof label === 'number') return String(label);
  if (Array.isArray(label)) return label.map(labelText).join('');
  return React.isValidElement(label) ? labelText(label.props.children) : '';
};

const destinations = (items) => items.flatMap((item) => {
  if (!item || item.disabled) return [];
  if (item.children) return destinations(item.children);
  return item.key?.startsWith('/') ? [{ path: item.key, label: labelText(item.label) }] : [];
});

export default function KeyboardNavigation({ items }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const input = useRef(null);
  const results = useRef(null);
  const navigating = useRef(false);
  const navigate = useNavigate();
  const location = useLocation();
  const matches = destinations(items).filter(({ label }) => label.toLowerCase().includes(query.trim().toLowerCase()));

  useEffect(() => { setOpen(false); }, [location.pathname]);
  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.defaultPrevented || event.repeat || event.isComposing || event.altKey || event.shiftKey) return;
      if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k') return;
      // Leave active dialogs and drawers in control of their own keyboard input.
      if ([...document.querySelectorAll('[role="dialog"][aria-modal="true"]')]
        .some((dialog) => dialog.getClientRects().length > 0 && getComputedStyle(dialog).visibility !== 'hidden')) return;
      event.preventDefault();
      setQuery('');
      setOpen(true);
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, []);

  const go = (path) => {
    navigating.current = true;
    setOpen(false);
    navigate(path);
  };

  return <>
    <Button icon={<SearchOutlined />} aria-label="Go to page" title="Go to page (Ctrl+K / Cmd+K)" aria-keyshortcuts="Control+k Meta+k"
      onClick={() => { setQuery(''); setOpen(true); }}>Go to page</Button>
    <Modal title="Go to page" open={open} footer={null} onCancel={() => setOpen(false)}
      afterOpenChange={(visible) => { if (visible) input.current?.focus(); }}
      afterClose={() => {
        if (navigating.current) {
          document.getElementById('main-content')?.focus();
          navigating.current = false;
        }
      }}>
      <p>Ctrl/Cmd+K opens this menu. Type a page name, then press Enter for the first match or use Down Arrow to browse.</p>
      <Input ref={input} aria-label="Find a page" value={query} allowClear
        onChange={(event) => setQuery(event.target.value)}
        onKeyDown={(event) => {
          if (event.nativeEvent.isComposing) return;
          if (event.key === 'Enter' && matches.length) { event.preventDefault(); go(matches[0].path); }
          if (event.key === 'ArrowDown') { event.preventDefault(); results.current?.querySelector('button')?.focus(); }
        }} />
      <div className="keyboard-page-results" ref={results} onKeyDown={(event) => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        const buttons = [...results.current.querySelectorAll('button')];
        const index = buttons.indexOf(event.target);
        if (index < 0) return;
        event.preventDefault();
        if (event.key === 'ArrowUp' && index === 0) { input.current?.focus(); return; }
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : Math.min(buttons.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1));
        buttons[next]?.focus();
      }}>
        {matches.map(({ path, label }) => <Button key={path} block onClick={() => go(path)}>{label}</Button>)}
        {!matches.length && <p role="status">No matching pages.</p>}
      </div>
      <p>Tab / Shift+Tab: next / previous control. Enter or Space: activate buttons. Arrow keys: choose menu and dropdown options. Escape: close this menu.</p>
    </Modal>
  </>;
}
