<?php defined('BASEPATH') OR exit('No direct script access allowed');

use CI\Models\Transaction;
use CI\Models\PaymentLog;

class Paymaya_payment extends Public_Controller{


	function __construct(){
		parent::__construct();

		$this->load->library(['paymaya', 'paymaya_response']);
	}

	function init(){
		$order = $this->order;
		$items = [];

		if(!$this->customer_account->has_active_session() AND !$order->exists()) show_404();
		// dd($order->get());

		$customer_details = $order->get('customer_details');
		$shipping_details = $order->get('shipping_details');
		$order_details = $order->get('orders_details');

		$reference_number = $order->get('code');

		$total_amount = [
			'value' => $order->get('checkout_amount')
		];

		$buyer_details = [
			'firstName' => $customer_details->first_name,
			'lastName' => $customer_details->last_name,
			'contact' => [
				'email' => $order->get('email')
			],
			'ipAddress' => $order->get('ip')
		];

		foreach($order_details->items as $row){
			$row = (object)$row;
			$item = [];

			$item['name'] = $row->name;
			// $item['code'] = '';
			$item['quantity'] = $row->quantity;
			$item['amount'] = [
				'value' => $row->price
			];
			$item['totalAmount'] = [
				'value' => $row->amount
			];

			if($item) $items[] = $item;
		}

		$this->paymaya->set_checkout_fields($reference_number, $total_amount, [], $items);
		// print_array($this->paymaya->raw_checkout_fields(), TRUE);

		$response = $this->paymaya->checkout();
		// print_array($response, TRUE);

		if(!$this->paymaya->has_errors()) redirect($response->redirectUrl);
	}

	function webhook(){
		// capture PayMaya response
		$paymaya = file_get_contents('php://input');
		$paymaya = json_decode($paymaya);

		if(isset($paymaya->status) AND isset($paymaya->id)){
			$response = $this->paymaya->retrieve($paymaya->id); // retrieve paymaya checkout details for later validations

			if(isset($response->status)){
				$transaction = new Transaction;

				$code = trim($response->requestReferenceNumber);

				$transaction = $transaction
					->where('code', '=', $code)
					->first();

				if($transaction instanceOf Transaction AND $transaction->exists){
					if($transaction->isPaymayaPayment()){
						$this->paymaya_response->set($response);

						// Log Paymaya / API response
						$payment_log = new PaymentLog;
						$payment_log->transaction_id = $transaction->id;
						$payment_log->payment_response = $response;

						$payment_log->save();

						// Set Paymaya api response to be save to the transaction row
						$transaction->payment_details = $response;

						if($this->paymaya_response->is_transaction_completed()){

							if($this->paymaya_response->is_payment_success()) $transaction->markAsPassed();
							elseif($this->paymaya_response->is_payment_pending()) $transaction->markAsPending();
							else $transaction->markAsFailed();
						}
						else $transaction->markAsFailed();

						if($transaction->save()){
							// Send email notification to customer
							if($transaction->isPassed()) $this->notification->success_order($transaction);
							elseif($transaction->isFailed()) $this->notification->failed_order($transaction);
							
							$this->notification->send();
						}
					}
				}
			}
		}
	}
}