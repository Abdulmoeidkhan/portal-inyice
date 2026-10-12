import React from 'react';
import AccountingPage from './AccountingPage';
import ReportsTab from './ReportsTab';

export default function FinancialReports() {
  return <AccountingPage title="Financial Reports" description="Review trial balance, profit and loss, and the balance sheet from posted journals.">
    {({ book, revision }) => <ReportsTab book={book} key={revision} />}
  </AccountingPage>;
}
