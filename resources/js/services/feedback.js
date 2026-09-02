let messageApi = null;
let modalApi = null;

export const setFeedbackMessage = (api) => {
  messageApi = api;
};

export const setFeedbackModal = (api) => {
  modalApi = api;
};

const fallback = (type, content) => {
  const prefix = type === 'error' ? 'Error' : type === 'success' ? 'Success' : 'Info';
  console[type === 'error' ? 'error' : 'log'](`${prefix}:`, content);
};

const textFromDialogOptions = (options) => {
  if (!options || typeof options !== 'object') {
    return String(options || '');
  }

  return [options.title, typeof options.content === 'string' ? options.content : '']
    .filter(Boolean)
    .join('\n');
};

export const message = {
  success(content) {
    return messageApi ? messageApi.success(content) : fallback('success', content);
  },
  error(content) {
    return messageApi ? messageApi.error(content) : fallback('error', content);
  },
  info(content) {
    return messageApi ? messageApi.info(content) : fallback('info', content);
  },
};

export const dialog = {
  confirm(options) {
    if (modalApi) {
      return modalApi.confirm(options);
    }

    if (window.confirm(textFromDialogOptions(options))) {
      return options?.onOk?.();
    }

    return undefined;
  },
  warning(options) {
    if (modalApi) {
      return modalApi.warning(options);
    }

    window.alert(textFromDialogOptions(options));
    return undefined;
  },
};
