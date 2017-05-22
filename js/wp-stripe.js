/**
 * WP-Stripe
 *
 * @since 1.4
 *
 */

Stripe.setPublishableKey( wpstripekey );

jQuery(document).ready(function($) {
    function resetStripeForm() {
        $('#wp-stripe-payment-form').get(0).reset();
        $('input').removeClass('stripe-valid stripe-invalid');
    }

    function stripeResponseHandler(status, response) {
        if (response.error) {
            $('.stripe-submit-button').prop('disabled', false).css('opacity','1.0');
            $('.wp-stripe-notification').show().removeClass('failure').addClass('failure').html(response.error.message);
            $('.stripe-submit-button .spinner').fadeOut('slow');
            $('.stripe-submit-button span').removeClass('spinner-gap');
        } else {
            var form$ = $('#wp-stripe-payment-form');
            var token = response['id'];
            form$.remove('.stripe-token').append('<input type="hidden" name="stripeToken" class="stripe-token" value="' + token + '" />');

            var serializedForm = form$.serialize();

            $.ajax({
                type : 'POST',
                dataType : 'json',
                url : ajaxurl,
                data : serializedForm,
            })
                .done(function ( data ) {
                    // $('.wp-stripe-notification').show().removeClass('failure').text(data);
                    if ( data.indexOf('success') >= 0 ) {
                        $('#wp-stripe-wrap').hide();
                        $('#final-success').show();
                    }
                })
                .fail(function ( data ) {
                    $('.wp-stripe-notification').show().removeClass('failure').addClass('failure').text(data.responseText);
                })
                .always(function () {
                    $('.wp-stripe-details').prepend(response);
                    $('.stripe-submit-button').prop('disabled', false).css('opacity','1.0');
                    $('.stripe-submit-button .spinner').fadeOut('slow');
                    $('.stripe-submit-button span').removeClass('spinner-gap');
                    resetStripeForm();
                });
        }
    }

    function updateSummary() {
        var t = '';
        var amount = parseFloat($('.wp-stripe-card-amount').val());
        var frequency = parseInt($('#wp_stripe_month_frequency').val());
        if ( amount ) {
            if ( frequency ) {
                var now = new Date();
                var nextMonth = now.getMonth() + frequency;
                var nextDate = new Date(now.getFullYear(), nextMonth, now.getDate());
                while( nextDate.getMonth() != (new Date(now.getFullYear(), nextMonth, 1)).getMonth() ) {
                    nextDate = new Date(nextDate.getFullYear(), nextDate.getMonth(), nextDate.getDate() - 1);
                }
                t = 'You will be charged $' + amount + ' now and this donation will not renew every ' + frequency + ' months. Your next payment will occur on ' + nextDate.toLocaleDateString() + '.';
            } else {
                t = 'You will be charged $' + amount + ' now and this donation will not renew.';
            }
        }
        $('#payment-summary').text(t);
    }

    $('.wp-stripe-card-amount').on('input', updateSummary);
    $('#wp_stripe_month_frequency').change(updateSummary);

    $('#wp-stripe-payment-form').submit(function(event) {
        event.preventDefault();
        $('.wp-stripe-notification').text('').hide();

        $('.stripe-submit-button').prop('disabled', true).css('opacity','0.4');
        $('.stripe-submit-button .spinner').fadeIn('slow');
        $('.stripe-submit-button span').addClass('spinner-gap');

        Stripe.createToken({
            name: $('.wp-stripe-name').val(),
            number: $('.card-number').val(),
            cvc: $('.card-cvc').val(),
            exp_month: $('.card-expiry-month').val(),
            exp_year: $('.card-expiry-year').val(),
            address_zip: $('.address-zip').val(),
        }, stripeResponseHandler);

        return false;
    });

    $('.card-number').focusout( function () {
        var cardValid = Stripe.validateCardNumber( $(this).val() );
        var cardType = Stripe.cardType( $(this).val() );

        if ( cardValid ) {
            $(this).removeClass('stripe-invalid').addClass('stripe-valid');
        } else {
            $(this).removeClass('stripe-valid').addClass('stripe-invalid');
        }
    });

    $('.card-cvc').focusout( function () {
        if ( Stripe.validateCVC( $(this).val() ) ) {
            $(this).removeClass('stripe-invalid').addClass('stripe-valid');
        } else {
            $(this).removeClass('stripe-valid').addClass('stripe-invalid');
        }
    });
});
