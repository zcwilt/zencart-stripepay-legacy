const StripeScript = document.createElement('script');
StripeScript.src = "https://js.stripe.com/v3/";
document.head.appendChild(StripeScript);
StripeScript.addEventListener("load", () => {
    if (stripeAlwaysShowForm) {
        $('#stripepay-intent-payment-element').show();
        $('#pmt-stripe_pay').prop('checked', true).trigger('change');
    }
    if ($('#pmt-stripe_pay').is(':checked')) {
        $('#stripepay-intent-payment-element').show();
    }
    if ($('#pmt-stripe_pay').is(':hidden')) {
        $('#stripepay-intent-payment-element').show();
    }
    $('input[name="payment"]').on('change', function () {
        if ($('#pmt-stripe_pay').is(':checked')) {
            $('#stripepay-intent-payment-element').show();
        } else {
            $('#stripepay-intent-payment-element').hide();
        }
    });
    const stripe = Stripe(stripePublishableKey);
    const elements = stripe.elements({clientSecret: stripeSecretKey});
    const paymentElement = elements.create('payment', {
        fields: {
            billingDetails: {
                address: {
                    'country': 'never',
                    'postalCode': 'never',
                }
            },
        },
        paymentMethod: {
            type: 'card',
            card: {
            }
        },
    });
    paymentElement.mount('#stripepay-intent-payment-element');
    const form = $('form[name="checkout_payment"]');
    const hiddenButton = document.createElement('button');
    hiddenButton.type = 'button';
    hiddenButton.id = 'stripe-submit-button';
    hiddenButton.style.display = 'none';
    form.append(hiddenButton);
    form.on('submit', function (event) {
        event.preventDefault();
        hiddenButton.click();
    });
    $('#stripe-submit-button').on('click', async function () {
        const { error } = await stripe.confirmSetup({
            elements,
            confirmParams: {
                'payment_method_data': {
                    billing_details: {
                        address: {
                            'country': stripeBillingCountry,
                            'postal_code': stripeBillingPostcode,
                        }
                    },
                },
            },
            redirect: 'if_required'
        });
        if (error) {
            console.error(error);
            $('#stripepay-intent-error-message').text(error.message);
        } else {
            form.off('submit'); // Remove the submit handler to avoid recursion
            form.submit(); // Submit the form normally
        }
    });
});

