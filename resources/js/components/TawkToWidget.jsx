import { useEffect } from 'react';

const TAWK_SCRIPT_ID = 'tawk-to-script';

const TAWK_EMBED_URL = 'https://embed.tawk.to/6a95b872ba626134459bf86f/1k1cdgvqk';

const TAWK_MOBILE_QUERY = '(max-width: 720px)';

export default function TawkToWidget() {
  useEffect(() => {
    window.Tawk_API = window.Tawk_API || {};
    window.Tawk_LoadStart = window.Tawk_LoadStart || new Date();
    const mobileQuery = window.matchMedia(TAWK_MOBILE_QUERY);
    const applyMobileVisibility = () => {
      const isMobile = mobileQuery.matches;

      document.body.classList.toggle('tawk-mobile-hidden', isMobile);

      if (isMobile) {
        window.Tawk_API?.minimize?.();
        window.Tawk_API?.hideWidget?.();
        return;
      }

      window.Tawk_API?.showWidget?.();
    };

    window.Tawk_API.customStyle = {
      ...(window.Tawk_API.customStyle || {}),
      zIndex: 999,
      visibility: {
        desktop: {
          position: 'br',
          xOffset: 24,
          yOffset: 152,
        },
        mobile: {
          position: 'br',
          xOffset: 16,
          yOffset: 142,
        },
        bubble: {
          rotate: '0deg',
          xOffset: 0,
          yOffset: 0,
        },
      },
    };

    const previousOnLoad = window.Tawk_API.onLoad;
    const handleLoad = () => {
      previousOnLoad?.();
      applyMobileVisibility();
    };

    window.Tawk_API.onLoad = handleLoad;
    applyMobileVisibility();
    if (mobileQuery.addEventListener) {
      mobileQuery.addEventListener('change', applyMobileVisibility);
    } else {
      mobileQuery.addListener?.(applyMobileVisibility);
    }

    if (!document.getElementById(TAWK_SCRIPT_ID)) {
      const script = document.createElement('script');
      const firstScript = document.getElementsByTagName('script')[0];

      script.id = TAWK_SCRIPT_ID;
      script.async = true;
      script.src = TAWK_EMBED_URL;
      script.charset = 'UTF-8';
      script.setAttribute('crossorigin', '*');

      firstScript?.parentNode?.insertBefore(script, firstScript) || document.head.appendChild(script);
    }

    applyMobileVisibility();

    return () => {
      if (window.Tawk_API?.onLoad === handleLoad) {
        window.Tawk_API.onLoad = previousOnLoad;
      }

      if (mobileQuery.removeEventListener) {
        mobileQuery.removeEventListener('change', applyMobileVisibility);
      } else {
        mobileQuery.removeListener?.(applyMobileVisibility);
      }
      document.body.classList.remove('tawk-mobile-hidden');
    };
  }, []);

  return null;
}
