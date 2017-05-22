<?php
/**
 * Display the Stripe Form in a Thickbox Pop-up
 *
 * @param $atts array Undefined, have not found any use yet
 * @return string Form Pop-up Link (wrapped in <a></a>)
 *
 * @since 1.3
 *
 */
function wp_stripe_shortcode( $atts ) {
	$options = get_option( 'wp_stripe_options' );
	$url     = add_query_arg( [ 'wp-stripe-iframe' => 'true', 'keepThis' => 'true', 'TB_iframe' => 'true', 'height' => 700, 'width' => 400 ], home_url() );
	$count   = 1;

	if ( isset( $options['stripe_modal_ssl'] ) && $options['stripe_modal_ssl'] === 'Yes' ) {
		$url = str_replace( 'http://', 'https://', $url, $count );
	}

	extract( shortcode_atts([
		'cards' => 'true'
	], $atts ) );

	if ( $cards === 'true' )  {
		$payments = '<div id="wp-stripe-types"></div>';
	}

	return '<a class="thickbox" id="wp-stripe-modal-button" title="' . esc_attr( $options['stripe_header'] ) . '" href="' . esc_url( $url ) . '"><span>' . esc_html( $options['stripe_header'] ) . '</span></a>' . $payments;
}
add_shortcode( 'wp-stripe', 'wp_stripe_shortcode' );

/**
 * Display Legacy Stripe form in-line
 *
 * @param $atts array Undefined, have not found any use yet
 * @return string Form / DOM Content
 *
 * @since 1.3
 *
 */
function wp_stripe_shortcode_legacy( $atts ){
	return wp_stripe_form();
}
add_shortcode( 'wp-legacy-stripe', 'wp_stripe_shortcode_legacy' );

/**
 * Create Charge using Stripe PHP Library
 *
 * @param $amount int transaction amount in cents (i.e. $1 = '100')
 * @param $card string
 * @param $description string
 * @return array
 *
 * @since 1.0
 *
 */
function wp_stripe_charge($amount, $card, $description) {
	$options = get_option( 'wp_stripe_options' );

	$currency = $options['stripe_currency'];

	$charge = [
		'card'     => $card,
		'amount'   => $amount,
		'currency' => $currency,
	];

	if ( $description ) {
		$charge['description'] = $description;
	}

	return \Stripe\Charge::create( $charge );
}

function wp_stripe_subscribe($amount, $customer, $plan, $card, $description) {
	$subscription = [
		'customer'   => $customer,
		'plan'       => $plan,
		'source'     => $card,
		'quantity'   => $amount,
	];

	if ( $description ) {
		$subscription['metadata'] = ['comment' => $description];
	}

	return \Stripe\Subscription::create( $subscription );
}

function wp_stripe_create_customer($email) {
	return \Stripe\Customer::create([
		'email' => $email,
	]);
}

/**
 * 3-step function to Process & Save Transaction
 *
 * 1) Capture POST
 * 2) Create Charge using wp_stripe_charge()
 * 3) Store Transaction in Custom Post Type
 *
 * @since 1.0
 *
 */
function wp_stripe_charge_initiate() {
	// Security Check
	if ( ! wp_verify_nonce( $_POST['nonce'], 'wp-stripe-nonce' ) ) {
		wp_die( __( 'Nonce verification failed!', 'wp-stripe' ) );
	}

	// Define/Extract Variables
	$frequency = sanitize_text_field( $_POST['wp_stripe_month_frequency'] );
	$name   = sanitize_text_field( $_POST['wp_stripe_name'] );
	$email  = sanitize_email( $_POST['wp_stripe_email'] );
	$address  = sanitize_text_field( $_POST['address'] );
	$city  = sanitize_text_field( $_POST['address-city'] );
	$state  = sanitize_text_field( $_POST['address-state'] );
	$zip  = sanitize_text_field( $_POST['address-zip'] );

	// Strip any comments from the amount
	$amount = str_replace( ',', '', sanitize_text_field( $_POST['wp_stripe_amount'] ) );
	$amount = str_replace( '$', '', $amount ) * 100;

	$card = sanitize_text_field( $_POST['stripeToken'] );

	$widget_comment = '';

	if ( empty( $_POST['wp_stripe_comment'] ) ) {
		$stripe_comment = __( 'E-mail: ', 'wp-stipe') . sanitize_text_field( $_POST['wp_stripe_email'] ) . ' - ' . __( 'This transaction has no additional details', 'wp-stripe' );
	} else {
		$stripe_comment = __( 'E-mail: ', 'wp-stipe' ) . sanitize_text_field( $_POST['wp_stripe_email'] ) . ' - ' . sanitize_text_field( $_POST['wp_stripe_comment'] );
		$widget_comment = sanitize_text_field( $_POST['wp_stripe_comment'] );
	}

	$success = true;
	try {
		if ( $frequency == 0 ) {
			$response = wp_stripe_charge( $amount, $card, $stripe_comment );

			$id       = $response->id;
			$amount   = $response->amount;
			$currency = strtoupper($response->currency);
			$created  = $response->created;
			$live     = $response->livemode;
			$paid     = $response->paid;
			$fee      = 0;
			if ( isset( $response->fee ) ) {
				$fee  = $response->fee;
			}

			$result = wp_stripe_charge_complete($id, $name, $email, $amount, $currency, $created, $live, $paid, $widget_comment, $fee);
		} else {
			$customerResponse = wp_stripe_create_customer($email);
			// plans: 1m, 3m, 6m, 12m
			$response = wp_stripe_subscribe($amount, $customerResponse->id, ($frequency . 'm'), $card, $stripe_comment);

			$id       = $response->id;
			$amount   = $response->plan->amount * $response->quantity;
			$currency = strtoupper($response->plan->currency);
			$created  = $response->created;
			$live     = $response->livemode;
			$paid     = ($response->status === 'active');
			$fee      = 0;
			if ( isset( $response->fee ) ) {
				$fee  = $response->fee;
			}

			$result = wp_stripe_charge_complete($id, $name, $email, $amount, $currency, $created, $live, $paid, $widget_comment, $fee);
		}
	} catch ( Exception $e ) {
		$result = sprintf( __( 'Oops, something went wrong (%s)', 'wp-stripe' ), $e->getMessage() );
		$success = false;
		do_action( 'wp_stripe_post_fail_charge', $email, $e->getMessage() );
	}

	// Return Results to JS
	if ( $success ) {
		$emailMessage = 'name: ' . $name . "\r\n" .
		'email: ' . $email . "\r\n" .
		'amount: $' . ($amount/100) . "\r\n" .
		'frequency: ' . $frequency . "\r\n" .
		'address: ' . $address . "\r\n" .
		'city: ' . $city . "\r\n" .
		'state: ' . $state . "\r\n" .
		'zip: ' . $zip . "\r\n" .
		'comment: ' . $widget_comment;
	} else {
		http_response_code(400);
	}

	header( 'Content-Type: application/json' );
	echo json_encode( $result );
	exit;
}
add_action('wp_ajax_wp_stripe_charge_initiate', 'wp_stripe_charge_initiate');
add_action('wp_ajax_nopriv_wp_stripe_charge_initiate', 'wp_stripe_charge_initiate');

function wp_stripe_charge_complete($id, $name, $email, $amount, $currency, $created, $live, $paid, $widget_comment, $fee) {
	if ( $paid === true ) {
		if ( $live ) {
			$live = 'LIVE';
		} else {
			$live = 'TEST';
		}

		$meta = [
			'wp-stripe-name' => $name,
			'wp-stripe-email' => $email,
			'wp-stripe-address' => $address,
			'wp-stripe-city' => $city,
			'wp-stripe-state' => $state,
			'wp-stripe-zip' => $zip,
			'wp-stripe-live' => $live,
			'wp-stripe-date' => $created,
			'wp-stripe-amount' => $amount/100,
			'wp-stripe-currency' => $currency,
			'wp-stripe-fee' => $fee,
		];

		$post_id = wp_insert_post( [
            'post_type'	   => 'wp-stripe-trx',
            'post_author'  => 1,
            'post_content' => $widget_comment,
            'post_title'   => $id,
            'post_status'  => 'publish',
            'meta_input'   => $meta,
        ] );

		do_action( 'wp_stripe_post_successful_charge', $response, $email, $stripe_comment );

		// Update Project
		// wp_stripe_update_project_transactions( 'add', $project_id , $post_id );
	}

	return 'success';
}
