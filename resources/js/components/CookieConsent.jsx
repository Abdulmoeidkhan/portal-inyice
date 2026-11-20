import React, { useState } from 'react';
import { Button, Modal, Typography } from 'antd';

const STORAGE_KEY = 'inyice_necessary_cookies_v1';
const CONSENT_LIFETIME = 180 * 24 * 60 * 60 * 1000;

function needsConsent() {
  try {
    const acceptedAt = Number(localStorage.getItem(STORAGE_KEY));
    const age = Date.now() - acceptedAt;
    return !acceptedAt || age < 0 || age >= CONSENT_LIFETIME;
  } catch {
    return true;
  }
}

export default function CookieConsent() {
  const [open, setOpen] = useState(needsConsent);

  const accept = () => {
    try {
      localStorage.setItem(STORAGE_KEY, String(Date.now()));
    } catch {
      // Still let the user continue when browser storage is unavailable.
    }
    setOpen(false);
  };

  return (
    <Modal
      title="Necessary cookies"
      open={open}
      centered
      closable={false}
      keyboard={false}
      mask={{ closable: false }}
      width={480}
      footer={<Button type="primary" onClick={accept}>Accept necessary cookies</Button>}
    >
      <Typography.Paragraph>
        InYice uses necessary cookies to keep you signed in and protect your account.
        These cookies are required to use the portal and cannot be switched off while signing in.
      </Typography.Paragraph>
      <Typography.Paragraph>
        This choice covers necessary cookies only, not advertising or analytics cookies.
        We remember your acceptance on this browser for six months.
      </Typography.Paragraph>
    </Modal>
  );
}
