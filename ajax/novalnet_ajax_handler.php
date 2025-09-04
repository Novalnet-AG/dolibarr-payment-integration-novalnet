<?php
/**
 * Novalnet payment extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Novalnet End User License Agreement
 * that is bundled with this package in the file LICENSE.txt
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @category   Novalnet
 * @package    Novalnet_Payment
 * @copyright  Copyright (c) Novalnet AG
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

$res=0;

// Try master.inc.php using relative path.
if (! $res && file_exists('../../../main.inc.php')) {
    require '../../../main.inc.php';
}
if (! $res && file_exists('../master.inc.php')) {
    $res = @include '../master.inc.php';
}

if (! $res && file_exists('../../master.inc.php')) {
    $res = @include '../../master.inc.php';
}

if (! $res && file_exists('../../../master.inc.php')) {
    $res = @include '../../../master.inc.php';
}

if (! $res && file_exists('../../../../main.inc.php')) {
    $res = @include('../../../../main.inc.php');
}

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // Disables token renewal
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}


top_httphead();
if(!GETPOST('request_type')) {
	echo json_encode(['error_msg' => 'invalid request']);
	die;
}

$request_type =  GETPOST('request_type',  'alpha');
$request_type();

function get_merchant_details() {
	global $conf;
	$product_activation_key = GETPOST('product_activation_key');
	$payment_access_key = GETPOST('payment_access_key');
	$language = $conf->global->MAIN_LANG_DEFAULT ? strtoupper(substr($conf->global->MAIN_LANG_DEFAULT, 0, 2)) : 'EN';
	if(!empty($product_activation_key && $payment_access_key)) {
		dol_include_once('/novalnet/class/NovalnetRest.php');
			 
		$client = new NovalnetRest();
		$data = [
			'merchant' => [
				'signature' => $product_activation_key
			],
			'custom' => [
				'lang' => $language
			]
		];
		
		$response = $client->post('merchant/details', json_encode($data), $payment_access_key);
		if ($response['result']['status'] === 'SUCCESS') {
			echo json_encode($response);
			die;
		} else {
			echo json_encode(['error_msg' => $response['result']['status_text']]);
			die;
		}
		
	}
	echo json_encode(['error_msg' => 'invalid request']);
	die;
}

function configure_webhook_url() {
	global $conf;
    $webhook_url = GETPOST('webhook_url', 'alpha');
    $product_activation_key = GETPOST('product_activation_key', 'alpha'); // You can add logic to verify the signature
    $language = $conf->global->MAIN_LANG_DEFAULT ? strtoupper(substr($conf->global->MAIN_LANG_DEFAULT, 0, 2)) : 'EN';
	if(!empty($product_activation_key && $webhook_url)) {
		dol_include_once('/novalnet/class/NovalnetRest.php');
			 
		$client = new NovalnetRest();
		$data = [
			'merchant' => [
				'signature' => $product_activation_key
			],
			'custom' => [
				'lang' => $language
			],
			'webhook' => [
				'url' => $webhook_url
			]
		];
		
		$response = $client->post('webhook/configure', json_encode($data), $payment_access_key);
		if ($response['result']['status'] === 'SUCCESS') {
			echo json_encode($response);
			die;
		} else {
			echo json_encode(['error_msg' => $response['result']['status_text']]);
			die;
		}
		
	}
	echo json_encode(['error_msg' => 'invalid request']);
	die;
}
