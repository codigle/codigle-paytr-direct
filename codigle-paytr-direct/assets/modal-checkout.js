( function () {
    'use strict';

    const config = window.CodigleCheckoutModal || {};
    const modal = document.querySelector( '[data-cdg-checkout-modal]' );

    if ( ! modal || ! config.prepareUrl ) {
        return;
    }

    const dialog = modal.querySelector( '.cdg-checkout-dialog' );
    const content = modal.querySelector( '[data-cdg-checkout-content]' );
    const state = {
        productId: 0,
        fallbackUrl: '',
        prepared: null,
        quote: null,
        orderId: 0,
        orderKey: '',
        previousFocus: null,
        profileDraft: null,
    };

    const escapeHtml = ( value ) => String( value ?? '' )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' )
        .replace( /'/g, '&#039;' );

    const request = async ( url, options = {} ) => {
        const response = await fetch( url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'X-WP-Nonce': config.nonce || '',
                ...( options.headers || {} ),
            },
        } );
        let payload = {};

        try {
            payload = await response.json();
        } catch ( error ) {
            payload = {};
        }

        if ( ! response.ok ) {
            const message = payload.message
                || payload.data?.message
                || 'Checkout could not continue.';
            const exception = new Error( message );
            exception.status = response.status;
            exception.code = payload.code || '';
            throw exception;
        }

        return payload;
    };

    const open = () => {
        state.previousFocus = document.activeElement;
        modal.hidden = false;
        document.documentElement.classList.add( 'cdg-checkout-open' );
        window.setTimeout( () => dialog?.focus(), 10 );
    };

    const close = () => {
        modal.hidden = true;
        document.documentElement.classList.remove( 'cdg-checkout-open' );
        state.previousFocus?.focus?.();
    };

    const loading = ( message = 'Preparing secure checkout…' ) => {
        content.innerHTML = `
            <div class="cdg-checkout-loading">
                <span class="cdg-checkout-spinner"></span>
                <p>${ escapeHtml( message ) }</p>
            </div>
        `;
    };

    const classicLink = () => state.fallbackUrl
        ? `<a class="cdg-checkout-classic" href="${ escapeHtml( state.fallbackUrl ) }">Continue with classic checkout</a>`
        : '';

    const showError = ( message, title = 'Checkout could not continue' ) => {
        content.innerHTML = `
            <div class="cdg-checkout-state">
                <span class="cdg-checkout-state-icon">!</span>
                <p class="cdg-checkout-kicker">CODIGLE CHECKOUT</p>
                <h2 id="cdg-checkout-title">${ escapeHtml( title ) }</h2>
                <p>${ escapeHtml( message ) }</p>
                <div class="cdg-checkout-actions">
                    <button type="button" class="cdg-button cdg-button-secondary" data-cdg-checkout-close>Close</button>
                    ${ classicLink() }
                </div>
            </div>
        `;
    };

    const loginScreen = () => {
        content.innerHTML = `
            <div class="cdg-checkout-state">
                <span class="cdg-checkout-state-icon">↗</span>
                <p class="cdg-checkout-kicker">ACCOUNT REQUIRED</p>
                <h2 id="cdg-checkout-title">Sign in to continue</h2>
                <p>Your billing profile, subscription and saved payment methods are linked to your Codigle account.</p>
                <a class="cdg-button" href="${ escapeHtml( config.loginUrl || '#' ) }">Sign in or create an account</a>
            </div>
        `;
    };

    const legalLink = ( docs, key, label ) => {
        const doc = docs?.[ key ];
        const url = doc?.url || '#';
        return `<a href="${ escapeHtml( url ) }" target="_blank" rel="noopener">${ escapeHtml( label ) }</a>`;
    };

    const durationMarkup = ( durations, selectedId ) => durations.map( ( item ) => {
        const savings = Number( item.savings || 0 );
        const selected = Number( item.id ) === Number( selectedId );
        return `
            <label class="cdg-duration-option${ selected ? ' is-selected' : '' }">
                <input type="radio" name="cdg_duration_product" value="${ Number( item.id ) }" ${ selected ? 'checked' : '' }>
                <span class="cdg-duration-radio"></span>
                <span class="cdg-duration-copy">
                    <strong>${ escapeHtml( item.duration_label ) }</strong>
                    ${ savings > 0 ? `<small>Save ${ item.savings_html }</small>` : '<small>Flexible billing</small>' }
                </span>
                <span class="cdg-duration-price">
                    ${ item.regular_price !== item.price ? `<del>${ item.regular_price_html }</del>` : '' }
                    <strong>${ item.price_html }</strong>
                </span>
            </label>
        `;
    } ).join( '' );

    const countryOptions = ( countries, selected ) => Object.entries( countries || {} )
        .map( ( [ code, name ] ) => `<option value="${ escapeHtml( code ) }" ${ code === selected ? 'selected' : '' }>${ escapeHtml( name ) }</option>` )
        .join( '' );

    const renderProfile = ( payload ) => {
        state.prepared = payload;
        const profile = state.profileDraft || payload.profile || {};
        const product = payload.product || {};
        const legal = payload.legal || {};

        if ( ! payload.email_verified ) {
            content.innerHTML = `
                <div class="cdg-checkout-state">
                    <span class="cdg-checkout-state-icon">✉</span>
                    <p class="cdg-checkout-kicker">EMAIL VERIFICATION</p>
                    <h2 id="cdg-checkout-title">Verify your email first</h2>
                    <p>We use the verified account email for invoices, payment notices and subscription security.</p>
                    <button type="button" class="cdg-button" data-cdg-send-verification>Send verification email</button>
                    <p class="cdg-checkout-inline-status" data-cdg-email-status></p>
                </div>
            `;
            return;
        }

        if ( ! legal.available ) {
            showError( 'Required legal pages are missing. Checkout has been stopped safely.' );
            return;
        }

        content.innerHTML = `
            <div class="cdg-checkout-layout">
                <section class="cdg-checkout-main">
                    <header class="cdg-checkout-header">
                        <div>
                            <p class="cdg-checkout-kicker">CODIGLE SECURE CHECKOUT</p>
                            <h2 id="cdg-checkout-title">Complete your purchase</h2>
                            <p>Choose the billing period and confirm your invoice details.</p>
                        </div>
                        <span class="cdg-checkout-secure">3D Secure</span>
                    </header>

                    <section class="cdg-checkout-section">
                        <div class="cdg-checkout-section-head">
                            <h3>Billing period</h3>
                            <span>${ escapeHtml( product.name || '' ) }</span>
                        </div>
                        <div class="cdg-duration-list" data-cdg-duration-list>
                            ${ durationMarkup( payload.durations || [ product ], product.id ) }
                        </div>
                    </section>

                    <section class="cdg-checkout-section">
                        <div class="cdg-checkout-section-head">
                            <h3>Billing information</h3>
                            <span>Saved securely to your account</span>
                        </div>
                        <form class="cdg-billing-form" data-cdg-billing-form novalidate>
                            <div class="cdg-field-grid two">
                                <label>First name<input name="first_name" value="${ escapeHtml( profile.first_name ) }" required autocomplete="given-name"></label>
                                <label>Last name<input name="last_name" value="${ escapeHtml( profile.last_name ) }" required autocomplete="family-name"></label>
                            </div>
                            <div class="cdg-field-grid two">
                                <label>Email<input name="email" type="email" value="${ escapeHtml( profile.email ) }" required readonly autocomplete="email"></label>
                                <label>Phone<input name="phone" value="${ escapeHtml( profile.phone ) }" required inputmode="tel" maxlength="20" autocomplete="tel" placeholder="+81…"></label>
                            </div>
                            <div class="cdg-field-grid two">
                                <label>Country<select name="country" required autocomplete="country">${ countryOptions( payload.countries, profile.country ) }</select></label>
                                <label>State / Province<input name="state" value="${ escapeHtml( profile.state ) }" autocomplete="address-level1"></label>
                            </div>
                            <label>Address<input name="address_1" value="${ escapeHtml( profile.address_1 ) }" required autocomplete="address-line1"></label>
                            <label>Apartment, suite, etc. <span>Optional</span><input name="address_2" value="${ escapeHtml( profile.address_2 ) }" autocomplete="address-line2"></label>
                            <div class="cdg-field-grid two">
                                <label>City<input name="city" value="${ escapeHtml( profile.city ) }" required autocomplete="address-level2"></label>
                                <label>Postal code<input name="postcode" value="${ escapeHtml( profile.postcode ) }" required autocomplete="postal-code"></label>
                            </div>
                            <label class="cdg-toggle-row"><input type="checkbox" name="company_invoice" ${ profile.company_invoice ? 'checked' : '' }><span>Invoice this purchase to a company</span></label>
                            <div class="cdg-company-fields" data-cdg-company-fields ${ profile.company_invoice ? '' : 'hidden' }>
                                <label>Company name<input name="company" value="${ escapeHtml( profile.company ) }" autocomplete="organization"></label>
                                <div class="cdg-field-grid two">
                                    <label>VAT / Tax number<input name="vat_id" value="${ escapeHtml( profile.vat_id ) }"></label>
                                    <label>Tax office <span>When applicable</span><input name="tax_office" value="${ escapeHtml( profile.tax_office ) }"></label>
                                </div>
                            </div>
                        </form>
                    </section>

                    <div class="cdg-checkout-error" data-cdg-modal-error hidden></div>
                    <button type="button" class="cdg-button cdg-button-wide" data-cdg-review-order>Review order</button>
                    <p class="cdg-checkout-footnote">No card data is sent to Codigle while you complete this form.</p>
                </section>

                <aside class="cdg-checkout-summary">
                    <p class="cdg-checkout-kicker">ORDER SUMMARY</p>
                    <h3>${ escapeHtml( product.name || 'Codigle subscription' ) }</h3>
                    <div class="cdg-summary-row"><span>Selected period</span><strong data-cdg-summary-duration>${ escapeHtml( product.duration_label ) }</strong></div>
                    <div class="cdg-summary-row"><span>Estimated total</span><strong data-cdg-summary-price>${ product.price_html || '' }</strong></div>
                    <p>Exact taxes and the next renewal date are calculated after your billing address is confirmed.</p>
                    <ul>
                        <li>First payment protected by 3D Secure</li>
                        <li>Card storage handled by PayTR</li>
                        <li>Cancel future renewal from your account</li>
                    </ul>
                </aside>
            </div>
        `;

        const companyToggle = content.querySelector( '[name="company_invoice"]' );
        companyToggle?.addEventListener( 'change', () => {
            content.querySelector( '[data-cdg-company-fields]' ).hidden = ! companyToggle.checked;
        } );

        content.querySelectorAll( '[name="cdg_duration_product"]' ).forEach( ( input ) => {
            input.addEventListener( 'change', () => {
                state.productId = Number( input.value );
                content.querySelectorAll( '.cdg-duration-option' ).forEach( ( option ) => option.classList.remove( 'is-selected' ) );
                input.closest( '.cdg-duration-option' )?.classList.add( 'is-selected' );
                const chosen = ( payload.durations || [] ).find( ( item ) => Number( item.id ) === state.productId );
                if ( chosen ) {
                    content.querySelector( '[data-cdg-summary-duration]' ).textContent = chosen.duration_label;
                    content.querySelector( '[data-cdg-summary-price]' ).innerHTML = chosen.price_html;
                }
            } );
        } );
    };

    const billingPayload = () => {
        const form = content.querySelector( '[data-cdg-billing-form]' );
        if ( ! form || ! form.reportValidity() ) {
            throw new Error( 'Complete all required billing fields.' );
        }
        const data = new FormData( form );
        const payload = {
            product_id: state.productId,
            order_id: state.orderId || 0,
            order_key: state.orderKey || '',
            first_name: data.get( 'first_name' ) || '',
            last_name: data.get( 'last_name' ) || '',
            email: data.get( 'email' ) || '',
            phone: data.get( 'phone' ) || '',
            country: data.get( 'country' ) || '',
            state: data.get( 'state' ) || '',
            city: data.get( 'city' ) || '',
            postcode: data.get( 'postcode' ) || '',
            address_1: data.get( 'address_1' ) || '',
            address_2: data.get( 'address_2' ) || '',
            company_invoice: data.get( 'company_invoice' ) === 'on',
            company: data.get( 'company' ) || '',
            vat_id: data.get( 'vat_id' ) || '',
            tax_office: data.get( 'tax_office' ) || '',
        };
        state.profileDraft = { ...payload };
        return payload;
    };

    const paymentCardsMarkup = ( cards ) => {
        const saved = ( cards || [] ).map( ( card, index ) => `
            <label class="cdg-payment-option${ index === 0 ? ' is-selected' : '' }">
                <input type="radio" name="cdg_payment_method" value="saved:${ Number( card.id ) }" ${ index === 0 ? 'checked' : '' }>
                <span class="cdg-payment-radio"></span>
                <span class="cdg-card-logo">${ escapeHtml( ( card.schema || card.brand || 'CARD' ).toUpperCase() ) }</span>
                <span class="cdg-payment-copy"><strong>•••• ${ escapeHtml( card.last_4 ) }</strong><small>${ escapeHtml( card.bank_name || 'Saved at PayTR' ) }${ card.expiry_month ? ` · ${ escapeHtml( card.expiry_month ) }/${ escapeHtml( card.expiry_year ) }` : '' }</small></span>
                ${ card.is_default ? '<span class="cdg-default-badge">Default</span>' : '' }
            </label>
        ` ).join( '' );
        const newChecked = cards?.length ? '' : 'checked';
        return `${ saved }
            <label class="cdg-payment-option${ newChecked ? ' is-selected' : '' }">
                <input type="radio" name="cdg_payment_method" value="new" ${ newChecked }>
                <span class="cdg-payment-radio"></span>
                <span class="cdg-card-logo">＋</span>
                <span class="cdg-payment-copy"><strong>Use a new card</strong><small>Saved securely at PayTR for renewals</small></span>
            </label>`;
    };

    const renderPayment = ( payload ) => {
        state.quote = payload;
        state.orderId = Number( payload.order.id );
        state.orderKey = payload.order.key;
        const order = payload.order;
        const product = payload.product;
        const docs = payload.legal?.documents || {};
        const cards = payload.cards || [];

        content.innerHTML = `
            <div class="cdg-checkout-layout">
                <section class="cdg-checkout-main">
                    <header class="cdg-checkout-header">
                        <div>
                            <p class="cdg-checkout-kicker">PAYMENT METHOD</p>
                            <h2 id="cdg-checkout-title">Choose how to pay</h2>
                            <p>Your exact total is ready. The first payment uses 3D Secure.</p>
                        </div>
                        <span class="cdg-checkout-secure">Secure</span>
                    </header>

                    <section class="cdg-checkout-section">
                        <div class="cdg-payment-list" data-cdg-payment-list>
                            ${ paymentCardsMarkup( cards ) }
                        </div>
                        <div class="cdg-new-card-fields" data-cdg-new-card-fields ${ cards.length ? 'hidden' : '' } data-private data-1p-ignore data-lpignore="true">
                            <label>Name on card<input name="cc_owner" maxlength="50" autocomplete="cc-name"></label>
                            <label>Card number<input name="card_number" inputmode="numeric" maxlength="23" autocomplete="cc-number" data-cdg-card-number></label>
                            <div class="cdg-field-grid three">
                                <label>Month<select name="expiry_month" autocomplete="cc-exp-month"><option value="">MM</option>${ Array.from( { length: 12 }, ( _, index ) => `<option value="${ index + 1 }">${ String( index + 1 ).padStart( 2, '0' ) }</option>` ).join( '' ) }</select></label>
                                <label>Year<select name="expiry_year" autocomplete="cc-exp-year"><option value="">YY</option>${ Array.from( { length: 16 }, ( _, index ) => { const year = ( new Date().getUTCFullYear() + index ) % 100; return `<option value="${ String( year ).padStart( 2, '0' ) }">${ String( year ).padStart( 2, '0' ) }</option>`; } ).join( '' ) }</select></label>
                                <label>CVV<input name="cvv" type="password" inputmode="numeric" maxlength="4" autocomplete="cc-csc"></label>
                            </div>
                        </div>
                        <div class="cdg-saved-cvv" data-cdg-saved-cvv hidden data-private>
                            <label>CVV for the selected card<input name="saved_cvv" type="password" inputmode="numeric" maxlength="4" autocomplete="cc-csc"></label>
                        </div>
                    </section>

                    <section class="cdg-checkout-section cdg-consent-list">
                        <label><input type="checkbox" name="cdg_terms"><span>I accept the ${ legalLink( docs, 'terms', 'Terms and Conditions' ) }, ${ legalLink( docs, 'refund', 'Refund Policy' ) } and ${ legalLink( docs, 'subscription', 'Subscription Policy' ) }.</span></label>
                        <label><input type="checkbox" name="cdg_renewal"><span>I authorize automatic renewal for this selected billing period and future charges to the saved payment method until cancellation.</span></label>
                        <label><input type="checkbox" name="cdg_marketing"><span>Send me optional product and promotional emails.</span></label>
                        <p>Personal data is handled under the ${ legalLink( docs, 'privacy', 'Privacy Policy' ) }.</p>
                    </section>

                    <div class="cdg-checkout-error" data-cdg-modal-error hidden></div>
                    <div class="cdg-checkout-actions split">
                        <button type="button" class="cdg-button cdg-button-secondary" data-cdg-back-profile>Back</button>
                        <button type="button" class="cdg-button" data-cdg-pay>Pay ${ order.total_html }</button>
                    </div>
                    <p class="cdg-checkout-footnote">Card number and CVV are posted directly to PayTR and are not included in Codigle API requests.</p>
                </section>

                <aside class="cdg-checkout-summary">
                    <p class="cdg-checkout-kicker">ORDER #${ escapeHtml( order.number ) }</p>
                    <h3>${ escapeHtml( product.name ) }</h3>
                    <div class="cdg-summary-row"><span>${ escapeHtml( product.duration_label ) }</span><strong>${ order.subtotal_html }</strong></div>
                    <div class="cdg-summary-row"><span>Taxes and fees</span><strong>${ order.tax_html }</strong></div>
                    <div class="cdg-summary-total"><span>Total today</span><strong>${ order.total_html }</strong></div>
                    <div class="cdg-renewal-date"><span>Next renewal</span><strong>${ escapeHtml( order.renewal_date ) }</strong></div>
                    <p>The same billing period and amount apply at renewal unless your plan or applicable taxes change. Any change will be shown before it takes effect.</p>
                </aside>
            </div>
        `;

        const updateMethod = () => {
            const selected = content.querySelector( '[name="cdg_payment_method"]:checked' );
            const isNew = selected?.value === 'new';
            content.querySelector( '[data-cdg-new-card-fields]' ).hidden = ! isNew;
            const savedCvv = content.querySelector( '[data-cdg-saved-cvv]' );
            let needsCvv = false;
            if ( selected?.value?.startsWith( 'saved:' ) ) {
                const cardId = Number( selected.value.split( ':' )[ 1 ] );
                const card = cards.find( ( item ) => Number( item.id ) === cardId );
                needsCvv = Number( card?.require_cvv ) === 1;
            }
            savedCvv.hidden = ! needsCvv;
            content.querySelectorAll( '.cdg-payment-option' ).forEach( ( item ) => item.classList.remove( 'is-selected' ) );
            selected?.closest( '.cdg-payment-option' )?.classList.add( 'is-selected' );
        };

        content.querySelectorAll( '[name="cdg_payment_method"]' ).forEach( ( input ) => input.addEventListener( 'change', updateMethod ) );
        updateMethod();

        const cardNumber = content.querySelector( '[data-cdg-card-number]' );
        cardNumber?.addEventListener( 'input', () => {
            const digits = cardNumber.value.replace( /\D/g, '' ).slice( 0, 19 );
            cardNumber.value = digits.replace( /(.{4})/g, '$1 ' ).trim();
        } );
    };

    const modalError = ( message ) => {
        const box = content.querySelector( '[data-cdg-modal-error]' );
        if ( box ) {
            box.hidden = false;
            box.textContent = message;
            box.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
        } else {
            showError( message );
        }
    };

    const prepare = async ( productId ) => {
        state.productId = Number( productId );
        sessionStorage.setItem( 'codigleCheckoutProduct', String( state.productId ) );
        open();

        if ( ! config.loggedIn ) {
            loginScreen();
            return;
        }

        loading();
        const url = new URL( config.prepareUrl );
        url.searchParams.set( 'product_id', String( state.productId ) );

        try {
            renderProfile( await request( url.toString() ) );
        } catch ( error ) {
            if (
                error.status === 401
                || ( error.status === 403 && error.code === 'rest_not_logged_in' )
            ) {
                loginScreen();
                return;
            }
            showError( error.message );
        }
    };

    const reviewOrder = async ( button ) => {
        try {
            const payload = billingPayload();
            button.disabled = true;
            button.textContent = 'Calculating exact total…';
            const result = await request( config.quoteUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( payload ),
            } );
            renderPayment( result );
        } catch ( error ) {
            modalError( error.message );
            button.disabled = false;
            button.textContent = 'Review order';
        }
    };

    const luhnValid = ( number ) => {
        let sum = 0;
        let doubleDigit = false;
        for ( let index = number.length - 1; index >= 0; index -= 1 ) {
            let digit = Number( number.charAt( index ) );
            if ( doubleDigit ) {
                digit *= 2;
                if ( digit > 9 ) {
                    digit -= 9;
                }
            }
            sum += digit;
            doubleDigit = ! doubleDigit;
        }
        return number.length >= 12 && number.length <= 19 && sum % 10 === 0;
    };

    const expiryValid = ( month, year ) => {
        const numericMonth = Number( month );
        const numericYear = 2000 + Number( year );
        if ( numericMonth < 1 || numericMonth > 12 || numericYear < 2000 ) {
            return false;
        }
        const now = new Date();
        const currentYear = now.getUTCFullYear();
        const currentMonth = now.getUTCMonth() + 1;
        return numericYear > currentYear
            || ( numericYear === currentYear && numericMonth >= currentMonth );
    };

    const selectedPayment = () => {
        const selected = content.querySelector( '[name="cdg_payment_method"]:checked' );
        if ( ! selected ) {
            throw new Error( 'Choose a payment method.' );
        }
        if ( selected.value === 'new' ) {
            const owner = content.querySelector( '[name="cc_owner"]' )?.value.trim() || '';
            const number = content.querySelector( '[name="card_number"]' )?.value.replace( /\D/g, '' ) || '';
            const month = content.querySelector( '[name="expiry_month"]' )?.value || '';
            const year = content.querySelector( '[name="expiry_year"]' )?.value || '';
            const cvv = content.querySelector( '[name="cvv"]' )?.value.replace( /\D/g, '' ) || '';
            if ( ! owner || ! luhnValid( number ) ) {
                throw new Error( 'Enter a valid card number.' );
            }
            if ( ! expiryValid( month, year ) ) {
                throw new Error( 'Enter a valid card expiry date.' );
            }
            if ( cvv.length < 3 || cvv.length > 4 ) {
                throw new Error( 'Enter a valid card security code.' );
            }
            return { type: 'new', owner, number, month, year, cvv };
        }
        const cardId = Number( selected.value.split( ':' )[ 1 ] );
        const card = state.quote.cards.find( ( item ) => Number( item.id ) === cardId );
        const cvv = content.querySelector( '[name="saved_cvv"]' )?.value.replace( /\D/g, '' ) || '';
        if ( Number( card?.require_cvv ) === 1 && cvv.length < 3 ) {
            throw new Error( 'Enter the CVV for the selected saved card.' );
        }
        return { type: 'saved', cardId, cvv };
    };

    const submitPaytr = ( authorization, payment ) => {
        const form = document.createElement( 'form' );
        form.method = 'post';
        form.action = authorization.endpoint;
        form.autocomplete = 'off';
        form.acceptCharset = 'UTF-8';
        form.target = '_self';
        form.hidden = true;
        form.dataset.private = 'true';
        const append = ( name, value ) => {
            const input = document.createElement( 'input' );
            input.type = 'hidden';
            input.name = name;
            input.value = String( value ?? '' );
            form.appendChild( input );
        };
        Object.entries( authorization.fields || {} ).forEach( ( [ name, value ] ) => append( name, value ) );
        if ( authorization.method.type === 'saved' ) {
            append( 'utoken', authorization.method.utoken );
            append( 'ctoken', authorization.method.ctoken );
            append( 'require_cvv', authorization.method.require_cvv || 0 );
            if ( payment.cvv ) {
                append( 'cvv', payment.cvv );
            }
        } else {
            append( 'store_card', '1' );
            if ( authorization.method.utoken ) {
                append( 'utoken', authorization.method.utoken );
            }
            append( 'cc_owner', payment.owner );
            append( 'card_number', payment.number );
            append( 'expiry_month', payment.month );
            append( 'expiry_year', payment.year );
            append( 'cvv', payment.cvv );
        }
        document.body.appendChild( form );
        form.submit();
    };

    const pay = async ( button ) => {
        try {
            const payment = selectedPayment();
            const terms = content.querySelector( '[name="cdg_terms"]' )?.checked === true;
            const renewal = content.querySelector( '[name="cdg_renewal"]' )?.checked === true;
            const marketing = content.querySelector( '[name="cdg_marketing"]' )?.checked === true;
            if ( ! terms || ! renewal ) {
                throw new Error( 'Accept the purchase terms and automatic renewal authorization.' );
            }
            button.disabled = true;
            button.textContent = 'Opening PayTR securely…';
            const authorization = await request( config.authorizeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    order_id: state.orderId,
                    order_key: state.orderKey,
                    payment_method: payment.type,
                    card_id: payment.cardId || 0,
                    terms,
                    renewal,
                    marketing,
                    source_url: window.location.href,
                } ),
            } );
            submitPaytr( authorization, payment );
        } catch ( error ) {
            modalError( error.message );
            button.disabled = false;
            button.textContent = 'Pay securely';
        }
    };

    document.addEventListener( 'click', ( event ) => {
        const closeButton = event.target.closest( '[data-cdg-checkout-close]' );
        if ( closeButton ) {
            close();
            return;
        }
        const link = event.target.closest( 'a[href*="cpb_checkout_product="]' );
        if ( link && event.button === 0 && ! event.metaKey && ! event.ctrlKey && ! event.shiftKey && ! event.altKey ) {
            const url = new URL( link.href, window.location.href );
            const productId = Number( url.searchParams.get( 'cpb_checkout_product' ) );
            if ( productId > 0 ) {
                event.preventDefault();
                state.fallbackUrl = link.href;
                prepare( productId );
            }
            return;
        }
        const review = event.target.closest( '[data-cdg-review-order]' );
        if ( review ) {
            reviewOrder( review );
            return;
        }
        const back = event.target.closest( '[data-cdg-back-profile]' );
        if ( back && state.prepared ) {
            renderProfile( state.prepared );
            return;
        }
        const payButton = event.target.closest( '[data-cdg-pay]' );
        if ( payButton ) {
            pay( payButton );
            return;
        }
        const verify = event.target.closest( '[data-cdg-send-verification]' );
        if ( verify ) {
            const status = content.querySelector( '[data-cdg-email-status]' );
            verify.disabled = true;
            request( config.emailUrl, { method: 'POST' } )
                .then( ( result ) => { status.textContent = result.message || 'Verification email sent.'; } )
                .catch( ( error ) => { status.textContent = error.message; verify.disabled = false; } );
        }
    }, true );

    document.addEventListener( 'keydown', ( event ) => {
        if ( modal.hidden ) {
            return;
        }

        if ( event.key === 'Escape' ) {
            close();
            return;
        }

        if ( event.key !== 'Tab' ) {
            return;
        }

        const focusable = Array.from(
            dialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )
        ).filter( ( element ) => ! element.hidden && element.offsetParent !== null );

        if ( focusable.length === 0 ) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        const first = focusable[ 0 ];
        const last = focusable[ focusable.length - 1 ];

        if ( event.shiftKey && document.activeElement === first ) {
            event.preventDefault();
            last.focus();
        } else if ( ! event.shiftKey && document.activeElement === last ) {
            event.preventDefault();
            first.focus();
        }
    } );

    const params = new URLSearchParams( window.location.search );
    if ( params.get( 'codigle_email_verified' ) === '1' ) {
        const productId = Number( sessionStorage.getItem( 'codigleCheckoutProduct' ) || 0 );
        if ( productId > 0 ) {
            window.setTimeout( () => prepare( productId ), 250 );
        }
    }
} )();
