<?php
/*
 * Copyright (C) 2025 Novalnet AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
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
