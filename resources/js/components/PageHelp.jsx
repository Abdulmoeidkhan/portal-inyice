import React from 'react';
import { Button, Tooltip } from 'antd';
import { QuestionCircleOutlined } from '@ant-design/icons';
import { matchPath, useLocation } from 'react-router-dom';
import { getPageHelp } from '../services/pageHelp';

export default function PageHelp({ standalone = false }) {
  const { pathname } = useLocation();
  const help = getPageHelp(pathname);
  const outsideWorkspace = ['/login', '/register', '/forgot-password', '/reset-password', '/shared/*', '/internal/*']
    .some((path) => matchPath({ path, end: true }, pathname));

  if (standalone && !outsideWorkspace) return null;

  return (
    <span className={standalone ? 'page-help-standalone' : 'page-help-inline'}>
      <Tooltip title={`Help with ${help.title} (opens in a new tab)`} trigger={['hover', 'focus']}>
        <Button
          className="page-help-button"
          shape="round"
          icon={<QuestionCircleOutlined />}
          href={help.href}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={`Help with ${help.title} (opens in a new tab)`}
        >
          <span className="page-help-label">Help</span>
        </Button>
      </Tooltip>
    </span>
  );
}
