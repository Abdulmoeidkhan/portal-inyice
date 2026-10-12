export function paidFeatureAccess(user) {
  const paid = user?.company_is_paid === true && !user?.is_system_user;
  return {
    canAccessAccounting: paid && ['owner', 'admin', 'accounts'].includes(user?.role),
    canAccessApiDocumentation: paid && ['owner', 'dev'].includes(user?.role),
  };
}
