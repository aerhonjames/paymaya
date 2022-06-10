<?php  if(!defined('BASEPATH')) exit('No direct script access allowed');

class Paymaya_response {

	protected $response;
	protected $ci;

	function __construct() {
		$this->ci =& get_instance();
	}

	function set($response=NULL) {
		if(isset($response->status)) {
			$this->response = $response;
			return $this;
		}else show_error('Invalid response.');
	}

	function is_transaction_completed(){
		if($this->response->status === 'COMPLETED') return TRUE;
		return FALSE;
	}

	function is_transaction_expired(){
		if($this->response->status === 'EXPIRED') return TRUE;
		return FALSE;
	}

	function is_payment_success(){
		if($this->response->paymentStatus === 'PAYMENT_SUCCESS') return TRUE;
		return FALSE;
	}

	function is_payment_pending(){
		if($this->response->paymentStatus === 'PENDING_PAYMENT') return TRUE;
		return FALSE;
	}

	function is_payment_failed(){
		if($this->response->paymentStatus === 'PAYMENT_FAILED') return TRUE;
		return FALSE;
	}

	function is_payment_expired(){
		if($this->response->paymentStatus === 'PAYMENT_EXPIRED') return TRUE;
		return FALSE;
	}

	function is_payment_dropout(){
		if($this->response->paymentStatus === 'PAYMENT_DROPOUT') return TRUE;
		return FALSE;
	}


	function extract_payment_details(){
		if(isset($this->response->paymentDetails)) return $this->response->paymentDetails;
		return NULL;
	}

	function get_error_messages(){
		$errors = NULL;
		if($this->is_payment_failed() AND $this->extract_payment_details()){
			$payment_details = $this->extract_payment_details();
			$payment_details = $payment_details->responses->efs;
			if(isset($payment_details->unhandledError)){
				$unhandled_errors = $payment_details->unhandledError;

				$errors = array_pluck($unhandled_errors, 'message', 'code');

				$errors = join(', ', $errors);
			}
		}elseif($this->is_transaction_expired()){
			$errors = 'Payment expiration time has been reached.';
		}

		return $errors;
	}
}