( function () {
    const registry = window.wc && window.wc.wcBlocksRegistry;
    const settingsApi = window.wc && window.wc.wcSettings;
    const element = window.wp && window.wp.element;
    const htmlEntities = window.wp && window.wp.htmlEntities;

    if ( ! registry || ! settingsApi || ! element ) {
        return;
    }

    const settings = settingsApi.getSetting(
        'codigle_paytr_direct_data',
        {}
    );
    const decode = htmlEntities
        ? htmlEntities.decodeEntities
        : ( value ) => value;
    const createElement = element.createElement;

    const label = createElement(
        'span',
        { className: 'cdg-block-payment-label' },
        decode( settings.title || 'Credit or debit card' )
    );
    const content = createElement(
        'div',
        { className: 'cdg-block-payment-content' },
        decode(
            settings.description
            || 'Secure 3D payment through PayTR. Card details are entered on the next secure step.'
        )
    );

    registry.registerPaymentMethod( {
        name: 'codigle_paytr_direct',
        paymentMethodId: 'codigle_paytr_direct',
        gatewayId: 'codigle_paytr_direct',
        label,
        content,
        edit: content,
        canMakePayment: () => true,
        ariaLabel: decode(
            settings.title || 'Credit or debit card'
        ),
        supports: {
            features: settings.supports || [ 'products' ],
        },
    } );
} )();
