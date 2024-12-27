<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This library is miniOrange Authentication Service.
 * Contains Request Calls to Customer service.
 * 
 * @package page-and-post-restriction
 */

require_once 'page-restriction-utility.php';

class Customer_page_restriction {

	public $email;
	public $phone;

	/*
	 * * Initial values are hardcoded to support the miniOrange framework to generate OTP for email.
	 * * We need the default value for creating the first time,
	 * * As we don't have the Default keys available before registering the user to our server.
	 * * This default values are only required for sending an One Time Passcode at the user provided email address.
	 */
	private $defaultCustomerKey = '16555';
	private $defaultApiKey      = 'fFd2XcvTGDemZvbw1bcUesNJWEqKbbUq';


	function papr_get_customer_key() {
		$url   = get_option( 'papr_host_name' ) . '/moas/rest/customer/key';
		$email = get_option( 'papr_admin_email' );

		$password = get_option( 'papr_admin_password' );

		$fields       = array(
			'email'    => $email,
			'password' => $password,
		);
		$field_string = json_encode( $fields );
		$headers      = array(
			'Content-Type'  => 'application/json',
			'charset'       => 'UTF-8',
			'Authorization' => 'Basic',
		);
		$args         = array(
			'method'      => 'POST',
			'body'        => $field_string,
			'timeout'     => '5',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);
		$response     = $this->papr_wp_remote_post( $url, $args );
		return $response;
	}

	function papr_create_customer() {
		$url = get_option( 'papr_host_name' ) . '/moas/rest/customer/add';

		$current_user = wp_get_current_user();
		$this->email  = get_option( 'papr_admin_email' );
		$password     = get_option( 'papr_admin_password' );

		$fields       = array(
			'areaOfInterest' => 'WP miniOrange Page Restriction Plugin',
			'email'          => $this->email,
			'password'       => $password,
		);
		$field_string = json_encode( $fields );

		$headers  = array(
			'Content-Type'  => 'application/json',
			'charset'       => 'UTF-8',
			'Authorization' => 'Basic',
		);
		$args     = array(
			'method'      => 'POST',
			'body'        => $field_string,
			'timeout'     => '5',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);
		$response = $this->papr_wp_remote_post( $url, $args );
		return $response;

	}

	function papr_check_customer() {
		$url   = get_option( 'papr_host_name' ) . '/moas/rest/customer/check-if-exists';
		$email = get_option( 'papr_admin_email' );

		$fields       = array(
			'email' => $email,
		);
		$field_string = json_encode( $fields );

		$headers = array(
			'Content-Type'  => 'application/json',
			'charset'       => 'UTF-8',
			'Authorization' => 'Basic',
		);
		$args    = array(
			'method'      => 'POST',
			'body'        => $field_string,
			'timeout'     => '5',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);

		$response = $this->papr_wp_remote_post( $url, $args );
		return $response;
	}

	function papr_submit_contact_us( $email, $phone, $query ) {
		$url          = get_option( 'papr_host_name' ) . '/moas/rest/customer/contact-us';
		$current_user = wp_get_current_user();
		$query        = '[WP Page Restriction Free Plugin] ' . $query;
		if ( isset( $_SERVER['SERVER_NAME'] ) ) {
			$company = sanitize_text_field( wp_unslash( $_SERVER ['SERVER_NAME'] ) );
		}
		$fields       = array(
			'firstName' => $current_user->user_firstname,
			'lastName'  => $current_user->user_lastname,
			'company'   => $company,
			'email'     => $email,
			'ccEmail'   => 'samlsupport@xecurify.com',
			'phone'     => $phone,
			'query'     => $query,
		);
		$field_string = json_encode( $fields );

		$headers  = array(
			'Content-Type'  => 'application/json',
			'charset'       => 'UTF-8',
			'Authorization' => 'Basic',
		);
		$args     = array(
			'method'      => 'POST',
			'body'        => $field_string,
			'timeout'     => '10',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);
		$response = $this->papr_wp_remote_post( $url, $args );
		return $response;
	}

	function papr_forgot_password( $email ) {
		$url                 = get_option( 'papr_host_name' ) . '/moas/rest/customer/password-reset';
		$customerKey         = get_option( 'papr_admin_customer_key' );
		$apiKey              = get_option( 'papr_admin_api_key' );
		$currentTimeInMillis = round( microtime( true ) * 1000 );
		$stringToHash        = $customerKey . number_format( $currentTimeInMillis, 0, '', '' ) . $apiKey;
		$hashValue           = hash( 'sha512', $stringToHash );

		$fields = '';
		$fields = array(
			'email' => $email,
		);

		$field_string = json_encode( $fields );
		$headers      = array(
			'Content-Type'  => 'application/json',
			'Customer-Key'  => $customerKey,
			'Timestamp'     => $currentTimeInMillis,
			'Authorization' => $hashValue,
		);
		$args         = array(
			'method'      => 'POST',
			'body'        => $field_string,
			'timeout'     => '5',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);
		$response     = $this->papr_wp_remote_post( $url, $args );
		return $response;
	}

	function papr_send_email_alert( $email, $phone, $message ) {

		$url = get_option( 'papr_host_name' ) . '/moas/api/notify/send';

		$customerKey = $this->defaultCustomerKey;
		$apiKey      = $this->defaultApiKey;

		$currentTimeInMillis = round( microtime( true ) * 1000 );
		$currentTimeInMillis = number_format( $currentTimeInMillis, 0, '', '' );
		$stringToHash        = $customerKey . $currentTimeInMillis . $apiKey;
		$hashValue           = hash( 'sha512', $stringToHash );
		$fromEmail           = 'no-reply@xecurify.com';
		$subject             = 'Feedback: WP Page Restriction Free Plugin';
		$site_url            = site_url();
		$server_name         = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';

		global $user;
		$user = wp_get_current_user();

		$query = '[WP Page Restriction Free Plugin]: ' . esc_html( $message );

		$content = '<div >Hello, <br><br>First Name :' . esc_html( $user->user_firstname ) . '<br><br>Last  Name :' . esc_html( $user->user_lastname ) . '   <br><br>Company :<a href="' . esc_attr( $server_name ) . '" target="_blank" >' . esc_attr( $server_name ) . '</a><br><br>Phone Number :' . esc_html( $phone ) . '<br><br>Email :<a href="mailto:' . esc_html( $email ) . '" target="_blank">' . esc_html( $email ) . '</a><br><br>Query :' . esc_html( $query ) . '</div>';

		$fields       = array(
			'customerKey' => $customerKey,
			'sendEmail'   => true,
			'email'       => array(
				'customerKey' => $customerKey,
				'fromEmail'   => $fromEmail,
				'fromName'    => 'Xecurify',
				'toEmail'     => 'info@xecurify.com',
				'toName'      => 'samlsupport@xecurify.com',
				'bccEmail'    => 'samlsupport@xecurify.com',
				'subject'     => $subject,
				'content'     => $content,
			),
		);
		$field_string = json_encode( $fields );

		$headers  = array(
			'Content-Type'  => 'application/json',
			'Customer-Key'  => $customerKey,
			'Timestamp'     => $currentTimeInMillis,
			'Authorization' => $hashValue,
		);
		$args     = array(
			'method'      => 'POST',
			'body'        => $field_string,
			'timeout'     => '5',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);
		$response = $this->papr_wp_remote_post( $url, $args );
		return $response;
	}

	public static function papr_wp_remote_post( $url, $args = array() ) {
		$response = wp_remote_post( $url, $args );
		if ( ! is_wp_error( $response ) ) {
			return $response['body'];
		} else {
			update_option( 'papr_message', 'Unable to connect to the Internet. Please try again.' );
			update_option( 'papr_message_success_fail', 'error' );
			return null;
		}
	}

}
