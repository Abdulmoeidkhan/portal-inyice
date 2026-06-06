/** @jsxImportSource react */
import React, { useEffect, useMemo, useState } from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App as AntdApp, ConfigProvider, theme as antdTheme } from 'antd';
import enUS from 'antd/locale/en_US';
import '../css/app.css';
import MainApp from './pages/App';

const root = ReactDOM.createRoot(document.getElementById('root'));

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
        },
      }}
    >
      <AntdApp>
        <BrowserRouter>
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
