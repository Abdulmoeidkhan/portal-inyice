import test from 'node:test';
import assert from 'node:assert/strict';
import { paidFeatureAccess } from '../../resources/js/services/featureAccess.js';

test('paid features use the exact role and plan matrix', () => {
  for (const role of ['owner', 'admin', 'accounts', 'dev', 'sales', 'api', 'super-admin', undefined]) {
    for (const paid of [true, false, undefined, 'true']) {
      const access = paidFeatureAccess({ role, company_is_paid: paid });
      assert.equal(access.canAccessAccounting, paid === true && ['owner', 'admin', 'accounts'].includes(role));
      assert.equal(access.canAccessApiDocumentation, paid === true && ['owner', 'dev'].includes(role));
    }
  }
  assert.deepEqual(paidFeatureAccess(null), { canAccessAccounting: false, canAccessApiDocumentation: false });
  assert.deepEqual(paidFeatureAccess({ role: 'owner', company_is_paid: true, is_system_user: true }), {
    canAccessAccounting: false, canAccessApiDocumentation: false,
  });
});
