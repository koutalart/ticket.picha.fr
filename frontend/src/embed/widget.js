/* eslint-disable lingui/no-unlocalized-strings */
(function(scriptElement) {
    const PREFIXES = ['data-picha-', 'data-hievents-'];
    const getAttr = (widget, name) => {
        for (const prefix of PREFIXES) {
            const value = widget.getAttribute(prefix + name);
            if (value !== null) {
                return value;
            }
        }
        return null;
    };

    const isScriptLoaded = () => !!window.hiEventWidgetLoaded;

    const loadWidget = () => {
        window.hiEventWidgetLoaded = true;

        let scriptOrigin;
        try {
            const scriptURL = scriptElement.src;
            scriptOrigin = new URL(scriptURL).origin;
        } catch (e) {
            console.error('PICHA Ticket widget error: Invalid script URL');
            return;
        }

        const widgets = document.querySelectorAll('.picha-widget, .hievents-widget');
        widgets.forEach((widget, index) => {
            const eventId = getAttr(widget, 'id');
            if (!eventId) {
                console.error('PICHA Ticket widget error: data-picha-id is required');
                return;
            }

            const iframe = document.createElement('iframe');
            iframe.setAttribute('sandbox', '' +
                'allow-forms' +
                ' allow-scripts' +
                ' allow-same-origin' +
                ' allow-popups' +
                ' allow-popups-to-escape-sandbox' +
                ' allow-top-navigation' +
                ' allow-top-navigation-by-user-activation' +
                ' allow-downloads' +
                ' allow-modals' +
                ' allow-orientation-lock' +
                ' allow-pointer-lock' +
                ' allow-popups-to-escape-sandbox' +
                ' allow-presentation'
            );

            iframe.setAttribute('title', 'PICHA Ticket');
            iframe.style.border = 'none';
            iframe.style.width = '100%';

            const iframeId = `picha-iframe-${index}`;
            iframe.id = iframeId;

            let src = `${scriptOrigin}/widget/${encodeURIComponent(eventId)}?iframeId=${iframeId}&`;
            const params = [];
            Array.from(widget.attributes).forEach(attr => {
                const prefix = PREFIXES.find((candidate) => attr.name.startsWith(candidate));
                if (prefix && attr.name !== prefix + 'id') {
                    const paramName = attr.name.substring(prefix.length - 1).replace(/-([a-z])/g, (g) => g[1].toUpperCase());
                    params.push(`${paramName}=${encodeURIComponent(attr.value)}`);
                }
            });

            iframe.src = src + params.join('&');

            widget.appendChild(iframe);

            const autoResize = getAttr(widget, 'autoresize') !== 'false';

            if (autoResize) {
                window.addEventListener('message', (event) => {
                    if (event.origin !== scriptOrigin) {
                        return;
                    }

                    const { type, height, iframeId: messageIframeId } = event.data;
                    if (type === 'resize' && height && messageIframeId === iframeId) {
                        const targetIframe = document.getElementById(messageIframeId);
                        if (targetIframe) {
                            targetIframe.style.height = `${height}px`;
                        }
                    }
                });
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadWidget);
    } else {
        if (!isScriptLoaded()) {
            loadWidget();
        }
    }
})(document.currentScript);
