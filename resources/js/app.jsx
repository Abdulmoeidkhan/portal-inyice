/** @jsxImportSource react */
import React, { useEffect, useMemo, useState } from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App as AntdApp, ConfigProvider, theme as antdTheme } from 'antd';
import enUS from 'antd/locale/en_US';
import '../css/app.css';
import MainApp from './pages/App';
import { setFeedbackMessage } from './services/feedback';

const container = document.getElementById('root');
const root = window.__inyiceRoot || ReactDOM.createRoot(container);
window.__inyiceRoot = root;

function FeedbackBridge() {
  const { message } = AntdApp.useApp();

  useEffect(() => {
    setFeedbackMessage(message);
  }, [message]);

  return null;
}

function RootApp() {
  const [themeMode, setThemeMode] = useState(() => localStorage.getItem('ui_theme') || 'light');
  const [themeStyle, setThemeStyle] = useState(() => localStorage.getItem('ui_style') || 'ocean');

  useEffect(() => {
    localStorage.setItem('ui_theme', themeMode);
    document.documentElement.setAttribute('data-theme', themeMode);
  }, [themeMode]);

  useEffect(() => {
    localStorage.setItem('ui_style', themeStyle);
    document.documentElement.setAttribute('data-style', themeStyle);
  }, [themeStyle]);

  const styleTokens = useMemo(() => {
    const tokenMap = {
      ocean: { colorPrimary: '#1f7ae0' },
      slate: { colorPrimary: '#64748b' },
      sand: { colorPrimary: '#b97316' },
    };

    return tokenMap[themeStyle] || tokenMap.ocean;
  }, [themeStyle]);

  const readabilityTokens = useMemo(() => {
    if (themeMode === 'dark') {
      return {};
    }

    return {
      colorText: '#0b1220',
      colorTextBase: '#0b1220',
      colorTextSecondary: '#334155',
      colorTextTertiary: '#475569',
      colorTextQuaternary: '#64748b',
    };
  }, [themeMode]);

  const currentAlgorithm = useMemo(() => {
    return themeMode === 'dark' ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm;
  }, [themeMode]);

  return (
    <ConfigProvider
      locale={enUS}
      theme={{
        algorithm: currentAlgorithm,
        token: {
          fontFamily: "'Plus Jakarta Sans', 'Segoe UI', sans-serif",
          borderRadius: 12,
          colorPrimary: styleTokens.colorPrimary,
          ...readabilityTokens,
        },
      }}
    >
      <AntdApp>
        <FeedbackBridge />
        <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
          <MainApp
            themeMode={themeMode}
            themeStyle={themeStyle}
            onChangeThemeStyle={setThemeStyle}
            onToggleTheme={() => setThemeMode((prev) => (prev === 'dark' ? 'light' : 'dark'))}
          />
        </BrowserRouter>
      </AntdApp>
    </ConfigProvider>
  );
}

root.render(
  <React.StrictMode>
    <RootApp />
  </React.StrictMode>
);
