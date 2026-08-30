( function () {
    const root = document.querySelector( '.cdg-pay-shell' );

    if ( ! root ) {
        return;
    }

    document.querySelectorAll( '[data-card-number]' ).forEach(
        ( input ) => {
            input.addEventListener( 'input', () => {
                const digits = input.value.replace( /\D/g, '' ).slice( 0, 19 );
                input.value = digits.replace( /(.{4})/g, '$1 ' ).trim();
            } );
        }
    );

    document.querySelectorAll( '.cdg-paytr-form' ).forEach(
        ( form ) => {
            form.addEventListener( 'submit', () => {
                const cardNumber = form.querySelector( '[data-card-number]' );

                if ( cardNumber ) {
                    cardNumber.value = cardNumber.value.replace( /\D/g, '' );
                }

                form.querySelectorAll( 'button[type="submit"]' ).forEach(
                    ( button ) => {
                        button.disabled = true;
                        button.textContent = 'Redirecting securely…';
                    }
                );
            } );
        }
    );

    const result = root.dataset.returnResult || '';

    if ( result !== 'success' ) {
        return;
    }

    const status = root.querySelector( '.cdg-pay-status' );

    if ( status ) {
        status.hidden = false;
        status.textContent = 'Payment received. Waiting for secure confirmation…';
    }

    const orderId = root.dataset.orderId;
    const orderKey = root.dataset.orderKey;
    const baseUrl = window.CodiglePaytrDirect
        && window.CodiglePaytrDirect.statusUrl;

    if ( ! baseUrl || ! orderId || ! orderKey ) {
        return;
    }

    let checks = 0;
    const poll = () => {
        checks += 1;
        const url = new URL( baseUrl );
        url.searchParams.set( 'order_id', orderId );
        url.searchParams.set( 'key', orderKey );

        fetch( url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        } )
            .then( ( response ) => response.json() )
            .then( ( data ) => {
                if ( data.paid && data.redirect ) {
                    window.location.assign( data.redirect );
                    return;
                }

                if ( status ) {
                    status.textContent = data.status === 'failed'
                        ? 'Payment was not approved. You can try again below.'
                        : 'Payment is still being confirmed securely…';
                }

                if ( checks < 30 && data.status !== 'failed' ) {
                    window.setTimeout( poll, 2000 );
                }
            } )
            .catch( () => {
                if ( checks < 30 ) {
                    window.setTimeout( poll, 2500 );
                }
            } );
    };

    window.setTimeout( poll, 800 );
} )();
