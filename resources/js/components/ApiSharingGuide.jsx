import React from 'react';
import { Alert, Card, Space, Table, Tabs, Typography } from 'antd';

const codeStyle = { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' };
const resources = [
  { key: 'voucher', label: 'Voucher', resource: 'orders', publicResource: 'vouchers', roles: 'owner, admin, sales' },
  { key: 'invoice', label: 'Invoice', resource: 'invoices', publicResource: 'invoices', roles: 'owner, admin, sales, accounts' },
];

export default function ApiSharingGuide() {
  const origin = window.location.origin;
  return <Card title="Voucher and invoice sharing">
    <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
      <Typography.Paragraph>The existing share endpoints generate a public link automatically. Use the record’s uid, not its numeric id or invoice/order number.</Typography.Paragraph>
      <Table size="small" rowKey="type" pagination={false} dataSource={[
        { type: 'Login token', purpose: 'Authenticates your user and role in the Authorization header. Portal tokens also require their matching cookie. Keep this token private.' },
        { type: 'Share token', purpose: 'Lets anyone holding the link read one shared document without signing in. Give customers the returned share_url.' },
      ]} columns={[{ title: 'Token', dataIndex: 'type', width: 150 }, { title: 'Purpose', dataIndex: 'purpose' }]} />
      <Alert type="info" showIcon title="Dev is read-only" description="Dev cannot create or revoke share links (403). The access_mode: api login option currently issues dev read-only tokens only. There is no self-service external write-token endpoint for other roles. Allowed roles manage sharing with their authenticated portal session." />
      <Typography.Paragraph>For a client using portal sign-in, save the cookie and token returned by POST /api/v1/auth/login. A 409 response means another session is active; sign out that session before continuing.</Typography.Paragraph>
      <pre style={codeStyle}>{[
        `curl -c portal-cookies.txt -X POST '${origin}/api/v1/auth/login'`,
        "  -H 'Accept: application/json'",
        "  -H 'Content-Type: application/json'",
        "  --data '{\"email\":\"user@example.com\",\"password\":\"YOUR_PASSWORD\",\"access_mode\":\"portal\"}'",
      ].join(' \\\n')}</pre>
      <Tabs items={resources.map(({ key, label, resource, publicResource, roles }) => ({
        key, label,
        children: <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
          <Typography.Text strong>Allowed roles: {roles}</Typography.Text>
          <Typography.Paragraph>1. Find the record’s uid using GET /api/v1/{resource}. Replace PORTAL_TOKEN with the login response’s token.</Typography.Paragraph>
          <Typography.Paragraph>2. Create or retrieve its share link. No request body is required.</Typography.Paragraph>
          <pre style={codeStyle}>{[
            `curl -b portal-cookies.txt -X POST '${origin}/api/v1/${resource}/{uid}/share'`,
            "  -H 'Authorization: Bearer PORTAL_TOKEN'",
            "  -H 'Accept: application/json'",
          ].join(' \\\n')}</pre>
          <Typography.Text strong>200 response (illustrative token)</Typography.Text>
          <pre style={codeStyle}>{JSON.stringify({ share_url: `${origin}/shared/${publicResource}/GENERATED_SHARE_TOKEN`, share_token: 'GENERATED_SHARE_TOKEN' }, null, 2)}</pre>
          <Typography.Paragraph>3. Send share_url to the recipient for browser viewing. For public JSON, use GET /api/v1/shared-{publicResource}/{'{token}'} without a login token.</Typography.Paragraph>
          <Typography.Paragraph>4. Disable the link using DELETE /api/v1/{resource}/{'{uid}'}/share with the same permitted role and authentication. The response is {'{"success":true}'}. Unknown or revoked share tokens return 404.</Typography.Paragraph>
        </Space>,
      }))} />
      <Typography.Paragraph>Share tokens are random 64-character values. Repeated share requests reuse the current token. Links have no automatic expiry; revoke them explicitly. Sharing again after revocation creates a new token. A voucher token also opens its quotation at /shared/quotations/{'{token}'}, so revocation disables both views.</Typography.Paragraph>
      <Typography.Paragraph type="secondary">Shared pages show current data, not frozen snapshots. Customer links require a publicly reachable application address; a local .test address works only in your development environment.</Typography.Paragraph>
    </Space>
  </Card>;
}
