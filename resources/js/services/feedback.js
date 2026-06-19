let messageApi = null;

export const setFeedbackMessage = (api) => {
  messageApi = api;
};

const fallback = (type, content) => {
  const prefix = type === 'error' ? 'Error' : type === 'success' ? 'Success' : 'Info';
  console[type === 'error' ? 'error' : 'log'](`${prefix}:`, content);
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
