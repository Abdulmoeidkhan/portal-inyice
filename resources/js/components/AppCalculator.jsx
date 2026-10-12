import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Button, Modal, Typography } from 'antd';
import { ArrowLeftOutlined, CopyOutlined, DeleteOutlined, DragOutlined, SnippetsOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';

const { Text } = Typography;

const CALCULATOR_HISTORY_KEY = 'calculator_history';

const CALCULATOR_DRAG_MARGIN = 12;

const clamp = (value, min, max) => Math.min(Math.max(value, Math.min(min, max)), Math.max(min, max));

const calculate = (left, operator, right) => {
  if (operator === '+') return left + right;
  if (operator === '-') return left - right;
  if (operator === '*') return left * right;
  if (operator === '/') return right === 0 ? null : left / right;

  return right;
};

const formatCalculatorValue = (value) => {
  const number = Number(value);
  if (!Number.isFinite(number)) return '0';

  return Number(number.toFixed(10)).toString();
};

export default function AppCalculator({ open, onClose }) {
  const dragRef = useRef(null);
  const dragStateRef = useRef(null);
  const [display, setDisplay] = useState('0');
  const [storedValue, setStoredValue] = useState(null);
  const [operator, setOperator] = useState(null);
  const [waitingForValue, setWaitingForValue] = useState(false);
  const [dragPosition, setDragPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [history, setHistory] = useState(() => {
    try {
      const savedHistory = JSON.parse(localStorage.getItem(CALCULATOR_HISTORY_KEY) || '[]');
      return Array.isArray(savedHistory) ? savedHistory : [];
    } catch {
      return [];
    }
  });

  useEffect(() => {
    localStorage.setItem(CALCULATOR_HISTORY_KEY, JSON.stringify(history.slice(0, 50)));
  }, [history]);

  const getDragBounds = useCallback(() => {
    const node = dragRef.current;
    if (!node) {
      return { left: 0, right: 0, top: 0, bottom: 0 };
    }

    const rect = node.getBoundingClientRect();
    const baseLeft = rect.left - dragPosition.x;
    const baseTop = rect.top - dragPosition.y;

    return {
      left: CALCULATOR_DRAG_MARGIN - baseLeft,
      right: window.innerWidth - CALCULATOR_DRAG_MARGIN - baseLeft - rect.width,
      top: CALCULATOR_DRAG_MARGIN - baseTop,
      bottom: window.innerHeight - CALCULATOR_DRAG_MARGIN - baseTop - rect.height,
    };
  }, [dragPosition]);

  const handleDragStart = useCallback((event) => {
    if (event.button !== undefined && event.button !== 0) return;

    dragStateRef.current = {
      bounds: getDragBounds(),
      pointerId: event.pointerId,
      startPointerX: event.clientX,
      startPointerY: event.clientY,
      startX: dragPosition.x,
      startY: dragPosition.y,
    };
    setIsDragging(true);
    event.preventDefault();
  }, [dragPosition, getDragBounds]);

  useEffect(() => {
    if (!isDragging) return undefined;

    const handlePointerMove = (event) => {
      const dragState = dragStateRef.current;
      if (!dragState || (event.pointerId !== undefined && event.pointerId !== dragState.pointerId)) return;

      const nextX = dragState.startX + event.clientX - dragState.startPointerX;
      const nextY = dragState.startY + event.clientY - dragState.startPointerY;

      setDragPosition({
        x: clamp(nextX, dragState.bounds.left, dragState.bounds.right),
        y: clamp(nextY, dragState.bounds.top, dragState.bounds.bottom),
      });
    };

    const handlePointerUp = (event) => {
      const dragState = dragStateRef.current;
      if (dragState && event.pointerId !== undefined && event.pointerId !== dragState.pointerId) return;

      dragStateRef.current = null;
      setIsDragging(false);
    };

    window.addEventListener('pointermove', handlePointerMove);
    window.addEventListener('pointerup', handlePointerUp);
    window.addEventListener('pointercancel', handlePointerUp);

    return () => {
      window.removeEventListener('pointermove', handlePointerMove);
      window.removeEventListener('pointerup', handlePointerUp);
      window.removeEventListener('pointercancel', handlePointerUp);
    };
  }, [isDragging]);

  useEffect(() => {
    if (!open) return undefined;

    const keepInViewport = () => {
      setDragPosition((current) => {
        const node = dragRef.current;
        if (!node) return current;

        const rect = node.getBoundingClientRect();
        const baseLeft = rect.left - current.x;
        const baseTop = rect.top - current.y;

        return {
          x: clamp(
            current.x,
            CALCULATOR_DRAG_MARGIN - baseLeft,
            window.innerWidth - CALCULATOR_DRAG_MARGIN - baseLeft - rect.width,
          ),
          y: clamp(
            current.y,
            CALCULATOR_DRAG_MARGIN - baseTop,
            window.innerHeight - CALCULATOR_DRAG_MARGIN - baseTop - rect.height,
          ),
        };
      });
    };

    window.addEventListener('resize', keepInViewport);
    keepInViewport();

    return () => {
      window.removeEventListener('resize', keepInViewport);
    };
  }, [open]);

  const addHistory = (entry) => {
    setHistory((current) => [entry, ...current].slice(0, 50));
  };

  const inputDigit = (digit) => {
    setDisplay((current) => {
      if (waitingForValue) {
        setWaitingForValue(false);
        return digit;
      }

      return current === '0' ? digit : current + digit;
    });
  };

  const inputDecimal = () => {
    setDisplay((current) => {
      if (waitingForValue) {
        setWaitingForValue(false);
        return '0.';
      }

      return current.includes('.') ? current : current + '.';
    });
  };

  const clear = () => {
    setDisplay('0');
    setStoredValue(null);
    setOperator(null);
    setWaitingForValue(false);
  };

  const backspace = () => {
    if (waitingForValue) return;
    setDisplay((current) => (current.length > 1 ? current.slice(0, -1) : '0'));
  };

  const percent = () => {
    const next = formatCalculatorValue(Number(display) / 100);
    setDisplay(next);
    addHistory(`${display}% = ${next}`);
    setWaitingForValue(true);
  };

  const chooseOperator = (nextOperator) => {
    const inputValue = Number(display);

    if (storedValue === null) {
      setStoredValue(inputValue);
    } else if (operator) {
      const result = calculate(storedValue, operator, inputValue);
      if (result === null) {
        message.error('Cannot divide by zero');
        clear();
        return;
      }
      const formatted = formatCalculatorValue(result);
      addHistory(`${formatCalculatorValue(storedValue)} ${operator} ${formatCalculatorValue(inputValue)} = ${formatted}`);
      setStoredValue(result);
      setDisplay(formatted);
    }

    setOperator(nextOperator);
    setWaitingForValue(true);
  };

  const equals = () => {
    if (storedValue === null || !operator) return;

    const inputValue = Number(display);
    const result = calculate(storedValue, operator, inputValue);
    if (result === null) {
      message.error('Cannot divide by zero');
      clear();
      return;
    }

    const formatted = formatCalculatorValue(result);
    addHistory(`${formatCalculatorValue(storedValue)} ${operator} ${formatCalculatorValue(inputValue)} = ${formatted}`);
    setDisplay(formatted);
    setStoredValue(null);
    setOperator(null);
    setWaitingForValue(true);
  };

  const copyValue = async (value = display) => {
    try {
      await navigator.clipboard.writeText(String(value));
      message.success('Calculator value copied');
    } catch {
      window.prompt('Copy calculator value:', value);
    }
  };

  const pasteValue = async () => {
    try {
      const clipboardValue = await navigator.clipboard.readText();
      const numericValue = Number(String(clipboardValue).replace(/,/g, '').trim());

      if (!Number.isFinite(numericValue)) {
        message.error('Clipboard does not contain a number');
        return;
      }

      setDisplay(formatCalculatorValue(numericValue));
      setWaitingForValue(false);
    } catch {
      message.error('Could not read clipboard');
    }
  };

  const buttons = [
    ['C', 'Back', '%', '/'],
    ['7', '8', '9', '*'],
    ['4', '5', '6', '-'],
    ['1', '2', '3', '+'],
    ['.', '0', 'Paste', '='],
  ];

  const handleButton = (label) => {
    if (/^\d$/.test(label)) return inputDigit(label);
    if (label === '.') return inputDecimal();
    if (label === 'C') return clear();
    if (label === 'Back') return backspace();
    if (label === '%') return percent();
    if (['+', '-', '*', '/'].includes(label)) return chooseOperator(label);
    if (label === '=') return equals();
    if (label === 'Copy') return copyValue();
    if (label === 'Paste') return pasteValue();
  };

  useEffect(() => {
    if (!open) return undefined;

    const handleKeyDown = (event) => {
      const keyMap = {
        Enter: '=',
        '=': '=',
        Backspace: 'Back',
        Delete: 'C',
        Escape: 'Escape',
        ',': '.',
        x: '*',
        X: '*',
      };
      const key = keyMap[event.key] || event.key;

      if (/^\d$/.test(key) || ['+', '-', '*', '/', '.', '%', '='].includes(key)) {
        event.preventDefault();
        handleButton(key);
        return;
      }

      if (key === 'Back' || key === 'C') {
        event.preventDefault();
        handleButton(key);
        return;
      }

      if (key === 'Escape') {
        event.preventDefault();
        onClose();
      }
    };

    window.addEventListener('keydown', handleKeyDown);

    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [display, open, operator, storedValue, waitingForValue, onClose]);

  const renderButtonContent = (label) => {
    if (label === 'Back') return <ArrowLeftOutlined />;
    if (label === 'Paste') return <SnippetsOutlined />;
    if (label === 'C') return <DeleteOutlined />;
    if (label === '*') return 'x';
    return label;
  };

  const getButtonTitle = (label) => {
    if (label === 'Back') return 'Backspace';
    if (label === 'Paste') return 'Paste value';
    if (label === 'C') return 'Clear';
    if (label === '*') return 'Multiply';
    if (label === '/') return 'Divide';
    if (label === '+') return 'Add';
    if (label === '-') return 'Subtract';
    if (label === '=') return 'Equals';
    if (label === '%') return 'Percent';
    if (label === '.') return 'Decimal point';
    return label;
  };

  return (
    <Modal
      className="app-calculator-modal"
      title={(
        <div className="app-calculator-title">
          <button
            type="button"
            className="app-calculator-drag-handle"
            title="Drag calculator"
            onPointerDown={handleDragStart}
            onDoubleClick={() => setDragPosition({ x: 0, y: 0 })}
          >
            <DragOutlined />
            <span>Calculator</span>
          </button>
          <Button size="small" type="text" onClick={() => setDragPosition({ x: 0, y: 0 })}>
            Center
          </Button>
        </div>
      )}
      open={open}
      onCancel={onClose}
      footer={null}
      width={380}
      destroyOnHidden={false}
      mask={false}
      modalRender={(modal) => (
        <div
          ref={dragRef}
          className={`app-calculator-drag-shell${isDragging ? ' is-dragging' : ''}`}
          style={{ transform: `translate(${dragPosition.x}px, ${dragPosition.y}px)` }}
        >
          {modal}
        </div>
      )}
    >
      <div className="app-calculator-display">
        <div className="app-calculator-display-meta">
          <Text type="secondary">{operator && storedValue !== null ? `${formatCalculatorValue(storedValue)} ${operator}` : 'Ready'}</Text>
          <Button
            size="small"
            type="text"
            icon={<CopyOutlined />}
            title="Copy value"
            aria-label="Copy calculator value"
            onClick={() => copyValue()}
          />
        </div>
        <strong title={display}>{display}</strong>
      </div>
      <div className="app-calculator-grid">
        {buttons.flat().map((label) => (
          <Button
            key={label}
            className="app-calculator-key"
            type={['=', '+', '-', '*', '/'].includes(label) ? 'primary' : 'default'}
            danger={label === 'C'}
            title={getButtonTitle(label)}
            aria-label={getButtonTitle(label)}
            onClick={() => handleButton(label)}
          >
            {renderButtonContent(label)}
          </Button>
        ))}
      </div>
      <div className="app-calculator-history">
        <div className="app-calculator-history-head">
          <Text strong>History</Text>
          <Button size="small" type="link" disabled={!history.length} onClick={() => setHistory([])}>Clear</Button>
        </div>
        <div className="app-calculator-history-list">
          {history.length ? history.map((entry, index) => {
            const value = entry.split('=').pop().trim();
            return (
              <button type="button" key={`${entry}-${index}`} onClick={() => copyValue(value)}>
                {entry}
              </button>
            );
          }) : <Text type="secondary">No calculations yet.</Text>}
        </div>
      </div>
    </Modal>
  );
}
