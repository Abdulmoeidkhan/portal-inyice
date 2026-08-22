const isModifiedNavigationClick = (event) => Boolean(event?.ctrlKey || event?.metaKey);

export const openRoute = (navigate, path, event) => {
  if (isModifiedNavigationClick(event)) {
    event.preventDefault?.();
    const opened = window.open(path, '_blank');
    if (opened) {
      opened.opener = null;
    } else {
      navigate(path);
    }
    return;
  }

  navigate(path);
};

export const createDeferredRouteOpener = (event) => {
  if (!isModifiedNavigationClick(event)) return null;

  event.preventDefault?.();
  const tab = window.open('', '_blank');
  if (tab) tab.opener = null;

  return {
    close: () => {
      if (tab && !tab.closed) tab.close();
    },
    open: (navigate, path) => {
      if (tab && !tab.closed) {
        tab.location.href = path;
        return;
      }

      openRoute(navigate, path, event);
    },
  };
};
