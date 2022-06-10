<?php  if(!defined('BASEPATH')) exit('No direct script access allowed');

class Paymaya {

	protected $ci;
	protected $api_key;
	protected $http_method;

	protected $key_type;
	protected $allowed_webhook = [
		'CHECKOUT_SUCCESS',
		'CHECKOUT_FAILURE',
		'CHECKOUT_DROPOUT',
		'PAYMENT_SUCCESS',
		'PAYMENT_FAILED',
		'PAYMENT_EXPIRED'
	];

	protected $curl_options = [
		CURLOPT_SSLVERSION => 6,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_USERAGENT => "PayMaya-PHP-SDK 0.0.1",
		CURLOPT_HTTPHEADER => [],
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_SSL_VERIFYPEER => 1,
	];

	protected $headers = [];
	protected $post_fields = [];
	protected $endpoint_urls;
	protected $url;
	protected $errors = [];

	function __construct() {
		$this->ci =& get_instance();
		$this->ci->load->config('paymaya');
		$this->ci->load->helper('paymaya');

		$this->api_key = $this->ci->config->item('api_key', 'paymaya'); // get both public and secret key
		$this->endpoint_urls = $this->ci->config->item('endpoint_urls', 'paymaya'); // All paymaya API URL
		$this->url = $this->endpoint_urls['checkout']; // default URL 
		$this->key_type = 'PUBLIC'; // Default key type
		$this->http_method = 'POST'; // Default http method
	}

	function set_checkout_fields($ref_number=NULL, $total_amount=[], $buyer_details=[], $checkout_items=[]){
		// To Do validate each parameter
		if(!$ref_number) $this->errors[] = 'Reference number: Please provide reference number.';

		$this->validate_total_amount($total_amount);
		// $this->validate_buyer_details($buyer_details);
		$this->validate_checkout_item($checkout_items);

		if(!$this->has_errors()){
			$total_amount['currency'] = 'PHP';

			$this->post_fields['totalAmount'] = $total_amount;
			$this->post_fields['requestReferenceNumber'] = $ref_number;
			// $this->post_fields['buyer'] = $buyer_details;
			$this->post_fields['items'] = $checkout_items;

			$redirect_url = (object)$this->ci->config->item('redirect_url', 'paymaya');

			$this->post_fields['redirectUrl'] = [
				'success' => base_url((is_mobile()) ? sprintf('mobile/%1$s', $redirect_url->success) : $redirect_url->success),
				'failure' => base_url((is_mobile()) ? sprintf('mobile/%1$s', $redirect_url->failed) : $redirect_url->failed),
				'cancel' => base_url((is_mobile()) ? sprintf('mobile/%1$s', $redirect_url->cancel) : $redirect_url->cancel),
			];
		}

		return $this;
	}

	/**
	 * Checkout method to execute checkout of items to paymaya. 
	 * @return Paymaya object contains the link of payment page of paymaya
	 */
	function checkout(){
		return $this->execute();
	}

	function retrieve($checkout_id=NULL) {
		if(!$checkout_id) $this->errors[] = 'Retrieve: Please provide checkout id.';

		$this->url = $this->endpoint_urls['checkout'].'/'.$checkout_id;
		$this->http_method = 'GET';
		$this->key_type = 'SECRET';

		return $this->execute();
	}
	
	function webhook($action=NULL, $webhook_id=NULL, $webhook_event=NULL, $callback_url=NULL) {

		if($action === 'register' AND (!$webhook_event OR !$callback_url)) $this->errors[] = 'Register webhook: Please provide the following webhook event and callback URL.';
		if($action === 'update' AND (!$webhook_event OR !$callback_url OR !$webhook_id)) $this->errors[] = 'Update webhook: Please provide the following webhook id, webhook event and callback url.';
		if($action === 'delete' AND !$webhook_id) $this->errors[] = 'Delete webhook: Please provide the webhook id.';
		if(!in_array($action, ['register', 'update', 'get', 'delete'])) $this->errors[] = 'Webhook: Unknown action.';
		if(!in_array($action, ['get', 'delete'] ) AND !in_array($webhook_event, $this->allowed_webhook)) $this->errors[] = 'Webhook: Unknown webhook events.';
		
		if(!$this->has_errors()){
			$this->key_type = 'SECRET'; // secret_key
			$this->url = $this->endpoint_urls['webhook'];

			if(in_array($action, ['register', 'update'])){
				$this->http_method = 'POST';

				$this->post_fields = [
					'name' => $webhook_event,
					'callbackUrl' => $callback_url
				];

				if($action === 'update'){
					$this->http_method = 'PUT';
					$this->url = $this->url.'/'.$webhook_id;
				}
			}
			elseif($action === 'get') $this->http_method = 'GET';
			elseif($action === 'delete'){
				$this->http_method = 'DELETE';
				$this->url = $this->url.'/'.$webhook_id;
			}

			return $this->execute();
		}

		return;
	}

	/**
	 * Customize user interface
	 */
	function customization($action=NULL){
		$this->url = $this->endpoint_urls['customization'];
		$configuration = $this->ci->config->item('customization', 'paymaya');

		if(!in_array($action, ['set', 'get', 'delete'])) $this->errors[] = 'Customization: Unknown customization action.';
		if(!$this->url) $this->errors[] = 'Customization: No customization url set in the config.';

		if(!$this->has_errors()){
			$this->key_type = 'SECRET';

			if($action === 'set'){
				$this->http_method = 'POST';

				$this->post_fields = [
					'logoUrl' => img_layout($configuration->logo),
					'iconUrl' => img_layout($configuration->icon['primary']),
					'appleTouchIconUrl' => img_layout($configuration->icon['secondary']),
					'customTitle' => $configuration->title,
					'colorScheme' => $configuration->color_scheme
				];
			}
			elseif($action === 'get') $this->hhtp_method = 'GET';
			elseif($action === 'delete') $this->http_method = 'DELETE';

			return $this->execute();
		}

		return;
	}

	function execute() {

		$key = NULL;

		if($this->key_type === 'PUBLIC') $key = base64_encode($this->api_key['public'].':');
		else $key = base64_encode($this->api_key['secret']);

		if(!$key) $this->errors[] = 'Execute: Please provide paymaya secret and public key.';
		if(!in_array($this->http_method, ['GET', 'POST', 'PUT', 'DELETE'])) $this->errors[] = 'Execute: Please set http method before excute the curl.';
		if(in_array($this->http_method, ['POST', 'PUT']) AND !count($this->post_fields)) $this->errors[] = 'Execute: http method is POST or PUT post fields are required.';

		if(!$this->has_errors()){

			if(in_array($this->http_method, ['POST', 'PUT'])) $this->headers[] = 'Content-Type: application/json';

			$this->headers[] = sprintf('Authorization: Basic %1$s', $key);
			$data = json_encode($this->post_fields);

			$curl = curl_init();

			curl_setopt_array($curl, $this->curl_options);
			curl_setopt_array($curl, array(
				CURLOPT_URL            => $this->url,
				CURLOPT_HTTPHEADER     => $this->headers,
				CURLOPT_RETURNTRANSFER => true
			));

			switch ($this->http_method) {
				case 'GET':
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->http_method);
					break;
				case 'POST':
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
					break;
				case 'PUT':
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
					curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
					break;
				case 'DELETE':
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
					curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
					break;
				default:
					show_error('Unknown http verb.');
					break;
			}

			$response = curl_exec($curl);
			$response = json_decode($response);
			curl_close($curl); // curl close

			return $response;
		}

		return;
	}

	function errors(){
		return $this->errors;
	}

	function raw_checkout_fields(){
		return $this->post_fields;
	}

	/*Helpers*/
	function allowed_webhooks()	{
		return $this->allowed_webhook;
	}

	function has_errors(){
		if(count($this->errors)) return TRUE;

		return FALSE;
	}

	/*Checkout Post fields Validation*/

	protected function validate_buyer_details($buyer_details=[]){
		$allowed_keys = [
			'main' => [
				'firstName',
				'middleName',
				'lastName',
				'contact',
				'billingAddress',
				'shippingAddress',
				'ipAddress'
			],
			'contact' => [
				'phone',
				'email'
			],
			'shipping/billing' => [
				'line1',
				'line2',
				'city',
				'state',
				'zipCode',
				'countryCode'
			]
		];

		if(!is_valid_array_keys($buyer_details, $allowed_keys['main'])) $this->errors[] = 'Buyer details: Invalid value or contains array keys that are not valid.';
		if(isset($buyer_details['ipAddress']) AND !$this->ci->form_validation->valid_ip($buyer_details['ipAddress'])) $this->errors[] = 'Buyer details: Ip address is invalid.';

		if(isset($buyer_details['contact'])){
			$contact_details = $buyer_details['contact'];

			if(!is_valid_array_keys($contact_details, $allowed_keys['contact'])) $this->errors[] = 'Contact details: Invalid value or contains array keys that are not valid.';
		}

		if(isset($buyer_details['billingAddress'])){
			$billing_address = $buyer_details['billingAddress'];

			if(!is_valid_array_keys($billing_address, $allowed_keys['shipping/billing'])) $this->errors[] = 'Billing address: Invalid value or contains array keys that are not valid.';
		}

		if(isset($buyer_details['shippingAddress'])){
			$shipping_address = $buyer_details['billingAddress'];

			if(!is_valid_array_keys($billing_address, $allowed_keys['shipping/billing'])) $this->errors[] = 'Shipping address: Invalid value or contains array keys that are not valid.';
		}

		return $this->has_errors();
	}


	protected function validate_total_amount($total_amount=[], $error_label='Total amount'){
		$allowed_keys = [
			'main' => [
				'value',
				'currency',
				'details'
			],
			'details' => [
				'discount',
				'serviceCharge',
				'shippingFee',
				'tax',
				'subtotal'
			]
		];

		if(!is_valid_array_keys($total_amount, $allowed_keys['main'])) $this->errors[] = sprintf('%1$s: Invalid value or contains array keys that are not valid.', $error_label);
		if(!isset($total_amount['value'])) $this->errors[] = sprintf('%1$s: value key is required.', $error_label);

		if(isset($total_amount['details'])){
			if(!is_valid_array_keys($total_amount['details'], $allowed_keys['details'])) $this->errors[] = sprintf('%1$s details: Invalid value or contains array keys that are not valid.', $error_label);
		}

		return $this->has_errors();
	}

	/**
	 * Validation of individual checkout item
	 * @param  array  $items array of checkout items
	 * @return bool        return true if checkout items is valid
	 */
	protected function validate_checkout_item($items=[]){
		$allowed_keys = [
			'name',
			'quantity',
			'code',
			'description',
			'amount',
			'totalAmount'
		];

		if(is_array($items)){
			foreach($items as $index=>$item){
				$keys_diff = array_diff(array_keys($item), $allowed_keys);

				if(count($keys_diff)) $this->errors[] = sprintf('Checkout item %1$s: Item details contains key that are not allowed.', $index);
				if(!isset($item['name'])) $this->errors[] = sprintf('Checkout item %1$s: Item name is required.', $index);

				if(isset($item['totalAmount'])){
					$this->validate_total_amount($item['totalAmount'], sprintf('Checkout item %1$s', $index));
				}
				else $this->errors[] = sprintf('Checkout item %1$s: Item total amount is required.', $index);
			}
		}
		else $this->errors[] = 'Checkout item: Invalid items provided.';


		return $this->has_errors();
	}
}	