import { useCallback, useMemo, useState } from 'react';

export default function useReportTablePagination(defaultPageSize = 25) {
  const [pagination, setPagination] = useState({
    current: 1,
    pageSize: defaultPageSize,
  });

  const handleChange = useCallback((current, pageSize) => {
    setPagination({ current, pageSize });
  }, []);

  const resetPagination = useCallback(() => {
    setPagination((current) => ({ ...current, current: 1 }));
  }, []);

  const tablePagination = useMemo(() => ({
    ...pagination,
    showSizeChanger: true,
    onChange: handleChange,
  }), [handleChange, pagination]);

  return [tablePagination, resetPagination];
}
