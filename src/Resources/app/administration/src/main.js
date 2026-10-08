import frFR from './snippet/fr-FR.json';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('fr-FR', frFR);
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

import FlutterwaveRefundService from './service/flutterwave-refund.service';

Shopware.Service().register('flutterwaveRefundService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new FlutterwaveRefundService(initContainer.httpClient, container.loginService);
});

import './acl';
import './module/sw-order/page/sw-order-detail';
import './view/kmh-flutterwave-detail';

Shopware.Module.register('kmh-flutterwave-detail', {
    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            const flutterwaveRoute = 'kmh.flutterwave.detail';

            if (!currentRoute.children.some((child) => child.name === flutterwaveRoute)) {
                currentRoute.children.push({
                    name: flutterwaveRoute,
                    path: 'kmh/flutterwave',
                    component: 'kmh-flutterwave-detail',
                    meta: {
                        parentPath: 'sw.order.detail',
                        privilege: 'order.viewer',
                    },
                    props: {
                        default: ($route) => {
                            return { orderId: $route.params.id };
                        },
                    },
                });
            }
        }
        next(currentRoute);
    },
});
