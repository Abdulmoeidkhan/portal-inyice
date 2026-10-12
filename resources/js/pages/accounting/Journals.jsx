import React from 'react';
import AccountingPage from './AccountingPage';
import JournalsTab from './JournalsTab';

export default function Journals() {
  return <AccountingPage needsAccounts title="Journals and Expenses" description="Prepare drafts, post balanced journals and record expenses.">
    {({ accounts, book, canPost, canWrite, refresh }) => <JournalsTab accounts={accounts} book={book} canPost={canPost} canWrite={canWrite} onChange={refresh} />}
  </AccountingPage>;
}
