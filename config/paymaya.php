<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/*Development*/

$config['paymaya']['api_key'] = [
    'public' => '',
    'secret' => ''
];

$config['paymaya']['endpoint_urls'] = [
    'checkout' => 'https://pg-sandbox.paymaya.com/checkout/v1/checkouts',
    'webhook' => 'https://pg-sandbox.paymaya.com/checkout/v1/webhooks',
    'customization' => 'https://pg-sandbox.paymaya.com/checkout/v1/customizations'

];
}

/*General config*/
$config['paymaya']['redirect_url'] = [
    'success' => 'success',
    'failed' => 'failed',
    'cancel' => 'failed'
];

$config['paymaya']['webhooks'] = [
    'callback_url' => 'paymaya/webhook'
];

$config['paymaya']['customization'] = (object)[
    'logo' => 'logo.png',
    'icon' => [
        'primary' => 'favicon.ico',
        'secondary' => 'ios-favicon.png' // specific for ios
    ],
    'title' => 'Store name',
    'color_scheme' => '#00A6B6'
];
