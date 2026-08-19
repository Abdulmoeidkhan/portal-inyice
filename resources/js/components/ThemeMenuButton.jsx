import React from 'react';
import { Button, Dropdown, Space } from 'antd';
import { BgColorsOutlined, CheckOutlined, DownOutlined, MoonOutlined, SunOutlined } from '@ant-design/icons';
import { THEME_STYLE_OPTIONS } from '../themeOptions';

const ThemeSwatch = ({ color }) => (
  <span className="theme-menu-swatch" style={{ '--theme-swatch-color': color }} />
);

const ThemeMenuLabel = ({ active, children }) => (
  <span className="theme-menu-label">
    <span>{children}</span>
    {active ? <CheckOutlined /> : null}
  </span>
);

export default function ThemeMenuButton({ themeMode, themeStyle, onChangeThemeMode, onChangeThemeStyle }) {
  const activeStyle = THEME_STYLE_OPTIONS.find((option) => option.value === themeStyle) || THEME_STYLE_OPTIONS[0];
  const modeIcon = themeMode === 'dark' ? <MoonOutlined /> : <SunOutlined />;

  const items = [
    {
      key: 'light',
      icon: <SunOutlined />,
      label: <ThemeMenuLabel active={themeMode === 'light'}>Light</ThemeMenuLabel>,
      onClick: () => onChangeThemeMode('light'),
    },
    {
      key: 'dark',
      icon: <MoonOutlined />,
      label: <ThemeMenuLabel active={themeMode === 'dark'}>Dark</ThemeMenuLabel>,
      onClick: () => onChangeThemeMode('dark'),
    },
    { type: 'divider' },
    ...THEME_STYLE_OPTIONS.map((option) => ({
      key: `style-${option.value}`,
      icon: <ThemeSwatch color={option.color} />,
      label: <ThemeMenuLabel active={themeStyle === option.value}>{option.label}</ThemeMenuLabel>,
      onClick: () => onChangeThemeStyle(option.value),
    })),
  ];

  return (
    <Dropdown menu={{ items }} trigger={['click']} placement="bottomRight">
      <Button className="theme-menu-btn" type="default" icon={modeIcon}>
        <Space size={6}>
          <span className="button-label">{activeStyle.label}</span>
          <BgColorsOutlined className="theme-menu-style-icon" />
          <DownOutlined className="button-label theme-menu-caret" />
        </Space>
      </Button>
    </Dropdown>
  );
}
