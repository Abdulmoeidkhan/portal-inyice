import React from 'react';
import AccountingPage from './AccountingPage';
import PeriodTab from './PeriodTab';

export default function PeriodClose() {
  return <AccountingPage title="Period Close" description="Review and close an accounting period.">
    {({ book, isOwner, refresh }) => <PeriodTab book={book} isOwner={isOwner} onChange={refresh} />}
  </AccountingPage>;
}
