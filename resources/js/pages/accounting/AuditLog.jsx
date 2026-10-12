import React from 'react';
import AccountingPage from './AccountingPage';
import AuditTab from './AuditTab';

export default function AuditLog() {
  return <AccountingPage title="Audit Log" description="Review accounting changes and the users who made them.">
    {({ revision }) => <AuditTab revision={revision} />}
  </AccountingPage>;
}
