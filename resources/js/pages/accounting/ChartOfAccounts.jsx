import React from 'react';
import AccountingPage from './AccountingPage';
import AccountsTab from './AccountsTab';

export default function ChartOfAccounts() {
  return <AccountingPage needsAccounts title="Chart of Accounts" description="Create and maintain accounts for your accounting ledger.">
    {({ accounts, book, canPost, refresh }) => <AccountsTab accounts={accounts} book={book} canManage={canPost} onChange={refresh} />}
  </AccountingPage>;
}
