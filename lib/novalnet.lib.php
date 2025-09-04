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

/**
 * \file    novalnet/lib/novalnet.lib.php
 * \ingroup novalnet
 * \brief   Library files with common functions for Novalnet
 */

/**
 * Prepare admin pages header.
 *
 * @return array
 */
function novalnetAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("novalnet@novalnet");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/novalnet/admin/novalnet.php", 1);
    $head[$h][1] = $langs->trans("Novalnet");
    $head[$h][2] = 'novalnetaccount';
    $h++;

    $object = new stdClass();

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'novalnet@novalnet');
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'novalnet@novalnet', 'remove');

    return $head;
}

/**
 * Prepare gateway request data.
 *
 * @param   string  $ref      Order id to find the object in database
 */
function novalnetPrepareRequest($ref)
{
    dol_include_once('/novalnet/class/NovalnetRequest.php');

    global $conf;

    $novalnet_request = new novalnetRequest();
    $language = $conf->global->MAIN_LANG_DEFAULT ? strtoupper(substr($conf->global->MAIN_LANG_DEFAULT, 0, 2)) : 'EN';
    $novalnet_request->set('language', $language);

    // Get the currency to use.
    $currency = novalnetApi::findCurrencyByAlphaCode($conf->currency);
    $novalnet_request->set('currency', $currency->getNum());

    // Set the amount to pay.
    $amount = price2num(GETPOST("newamount"), 'MT');
    $novalnet_request->set('amount', $currency->convertAmountToInteger($amount));

    // Check if order exists and get order_id, cust_id, and tax rate.
    $result = novalnetCheckOrder($ref);

    $novalnet_request->set('order_id', $result['order_id']);

    // Customer information.
    $fullname = novalnetSplitName(GETPOST('shipToName', 'alpha'));
    $novalnet_request->set('cust_email', GETPOST('email', 'alpha'));
    $novalnet_request->set('cust_id', $result['cust_id']);
    $novalnet_request->set('cust_first_name', $fullname['first_name']);
    $novalnet_request->set('cust_last_name', $fullname['last_name']);
    $novalnet_request->set('cust_address', GETPOST('shipToStreet', 'alpha'));
    $novalnet_request->set('cust_address2', GETPOST('shipToStreet2', 'alpha'));
    $novalnet_request->set('cust_zip', GETPOST('shipToZip', 'alpha'));
    $novalnet_request->set('cust_city', GETPOST('shipToCity', 'alpha'));
    $novalnet_request->set('cust_country', GETPOST('shipToCountryCode', 'alpha'));
    $novalnet_request->set('cust_phone', GETPOST('phoneNum', 'alpha'));
    $novalnet_request->set('cust_status', $result['cust_status']);
    $novalnet_request->set('raw_amount', GETPOST('amount', 'alpha'));
    $novalnet_request->set('ref', GETPOST('ref', 'alpha'));
    $novalnet_request->set('cust_state', $result['cust_state']);
    $novalnet_request->addExtInfo('full_tag', $result['full_tag']);

    return $novalnet_request;
}
/**
 * Check if object exists (order, invoice, donation, etc.)
 *
 * @return  array Customer info and tax rate
 */
function novalnetCheckOrder($ref)
{
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
    require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

    global $db;

    $source = GETPOST('s', 'alpha') ?: GETPOST('source', 'alpha');
    $object = null;
    $order_id = '';
    $tax_rate = '';
    $cust_id = '';
    $full_tag = '';

    switch ($source) {
        case 'invoice':
            $object = new Facture($db);
            break;
        case 'order':
            $object = new Commande($db);
            break;
        case 'donation':
            $object = new Don($db);
            break;
        case 'contractline':
            $object = new ContratLigne($db);
            break;
        case 'membersubscription':
            $object = null;
            break;
        default:
            $full_tag = GETPOST('fulltag', 'alpha');
            break;
    }

    if ($object) {
        $result = !empty($ref) ? $object->fetch('', $ref) : $object->fetch($ref);

        if ($result) {
            $full_tag = GETPOST('fulltag', 'alpha');
            $order_id = $ref;

            // Customer information is consulted.
            $societe = new Societe($db);
            $result = $societe->fetch('', GETPOST('shipToName', 'alpha'), null, null, null, null, null, null, null, null, GETPOST('email', 'alpha'));

            if ($societe->code_client) {
                $cust_status = 'PRIVATE';
                $cust_id = $societe->code_client;
            } elseif ($societe->code_fournisseur) {
                $cust_status = 'COMPANY';
                $cust_id = $societe->code_fournisseur;
            }
        } else {
            print "<h1>Error: Object with ID#$ref was not found in database.</h1>";
            exit;
        }
    }

    return array(
        'order_id'   => $order_id,
        'tax_rate'   => $tax_rate,
        'cust_id'    => $cust_id,
        'cust_status'=> $cust_status,
        'full_tag'   => $full_tag,
        'cust_state' => $societe->state
    );
}

/**
 * Escape variables in the request.
 *
 * @param   object  $request   Request object
 * @param   string  $var       Variable name to fetch
 * @param   bool    $isExtInfo Whether it's an extended info variable
 * @return  mixed              The value or null if not found
 */
function getNovalnetEscapeVar($request, $var, $isExtInfo = false)
{
    if ($isExtInfo) {
        $var = 'vads_ext_info_' . $var;
    }

    $value = $request->get($var);

    return empty($value) ? null : $value;
}

/**
 * Display Novalnet payment form.
 *
 * @param   string  $ref Order reference
 */
function novalnetForm($ref)
{
    global $conf, $langs; 
    $novalnet_request = novalnetPrepareRequest($ref);

	    $merchantParams = buildMerchantParams();
        $customerParams = buildCustomerParams('',$novalnet_request);
        $transactionParams = getV13Params($novalnet_request);
        $customParams = buildCustomParams('');
        $data = array_merge($merchantParams, $customerParams, $transactionParams, $customParams);
    dol_include_once('/novalnet/class/NovalnetRest.php');

    try {
        $client = new NovalnetRest();
        $response = $client->post('seamless/payment', json_encode($data),'');
            if (!empty($response['result']['redirect_url']) && $response['result']['status_code'] == 100) {
                    print '<table id="dolpaymenttable" summary="Payment form" class="center">
                            <tbody>';
                    print '     <tr>';
				    print '<link rel="stylesheet" type="text/css" href="' . DOL_MAIN_URL_ROOT . '/custom/novalnet/css/nnpaymentform.css">';
				    print '         <td>';
                    print '<form id="novalnetpaymentform" name="novalnetpaymentform" action="' . DOL_MAIN_URL_ROOT . '/custom/novalnet/public/process.php" method="POST" ">
                           <div style="display: flex; flex-direction: column; align-items: center;">
                        <iframe style="border: none; width: 400px" id="novalnetIframe" src="' . $response['result']['redirect_url'] . '" allow="payment"  loading="lazy" allow="payment" referrerPolicy="origin" ></iframe>
                        <input type="hidden" id="nn_payment_details" name="nn_payment_details" value="">
                        <input type="hidden" id="nn_wallet_total_label" name="nn_wallet_total_label" value="">
                        <button  class="button buttonpayment" style="margin-top: 20px;" id="paymentSubmit">Validate Payment</button>
                        <img id="hourglasstopay" class="hidden" src="'.DOL_URL_ROOT.'/theme/'.$conf->theme.'/img/working.gif">
                    ';
                    print $novalnet_request->getRequestHtmlFields();
                    print'</div>
                    </form>';       
				    print '<script type="text/javascript" src="https://cdn.novalnet.de/js/pv13/checkout.js"></script>';
				    print '<script src="' . DOL_MAIN_URL_ROOT . '/custom/novalnet/js/nnpaymentform.js" type="text/javascript"></script>';
                    print '</td>';
                    print '     </tr>';
                    print ' </tbody>';
                    print '</table>';
			 }else {
                dol_htmloutput_mesg('Due to some technical issue Payment form is not loading', '', 'error');
            }
    } catch (Exception $e) { 
        
        setEventMessages($langs->trans($e->getMessage()), null, 'errors');
    }
}
/**
 * Take the fullname from Dolibarr and return it as first name and last name.
 *
 * @param   string  $full_name  Full name to be split
 * @return  array               Array with first name and last name
 */
function novalnetSplitName($full_name)
{
    $tokens = explode(' ', trim($full_name));
    $names = array();

    // Words of compound lastnames.
    $special_tokens = array(
        'da', 'de', 'del', 'la', 'las', 'los', 'mac', 'mc', 'van', 'von', 'y', 'i', 'san', 'santa'
    );

    $prev = '';
    foreach ($tokens as $token) {
        $_token = strtolower($token);

        if (in_array($_token, $special_tokens)) {
            $prev .= "$token ";
        } else {
            $names[] = $prev . $token;
            $prev = '';
        }
    }

    $num_nombres = count($names);
    $nombres = '';
    $apellidos = '';

    switch ($num_nombres) {
        case 0:
            $nombres = '';
            break;
        case 1:
            $nombres = $names[0];
            break;
        case 2:
            $nombres = $names[0];
            $apellidos = $names[1];
            break;
        case 3:
            $nombres = $names[0];
            $apellidos = $names[1] . ' ' . $names[2];
            break;
        case 4:
            $nombres = $names[0] . ' ' . $names[1];
            $apellidos = $names[2] . ' ' . $names[3];
            break;
        default:
            $nombres = $names[0] . ' ' . $names[1];
            unset($names[0]);
            unset($names[1]);
            $apellidos = implode(' ', $names);
            break;
    }

    $nombres = mb_convert_case($nombres, MB_CASE_TITLE, 'UTF-8');
    $apellidos = mb_convert_case($apellidos, MB_CASE_TITLE, 'UTF-8');

    return array('first_name' => $nombres, 'last_name' => $apellidos);
}

/**
 * Build merchant parameters for the Novalnet API request.
 *
 * @return  array   Merchant parameters
 */
function buildMerchantParams()
{
    global $conf;
    $data['merchant'] = array(
        'signature' => $conf->global->NOVALNET_PRODUCT_ACTIVATION,
        'tariff'    => $conf->global->NOVALNET_TARRIFF,
    );
    return $data;
}

/**
 * Build customer parameters for the Novalnet API request.
 *
 * @param   object  $paymentDetails    Payment details object
 * @param   object  $novalnet_request Request object
 * @return  array   Customer parameters
 */
function buildCustomerParams($paymentDetails, $novalnet_request)
{
    $data['customer'] = array(
        'first_name'  => getNovalnetEscapeVar($novalnet_request, 'cust_first_name'),
        'last_name'   => !empty(getNovalnetEscapeVar($novalnet_request, 'cust_last_name')) ? getNovalnetEscapeVar($novalnet_request, 'cust_last_name') : getNovalnetEscapeVar($novalnet_request, 'cust_first_name'),
        'email'       => getNovalnetEscapeVar($novalnet_request, 'cust_email'),
        'tel'         => getNovalnetEscapeVar($novalnet_request, 'cust_phone'),
        'customer_ip' => getUserRemoteIP(),
        'customer_no' => getNovalnetEscapeVar($novalnet_request, 'cust_id'),
    );

    $data['customer']['billing'] = array(
        'street'       => getNovalnetEscapeVar($novalnet_request, 'cust_address') . getNovalnetEscapeVar($novalnet_request, 'cust_address2'),
        'city'         => getNovalnetEscapeVar($novalnet_request, 'cust_city'),
        'zip'          => getNovalnetEscapeVar($novalnet_request, 'cust_zip'),
        'country_code' => getNovalnetEscapeVar($novalnet_request, 'cust_country'),
        'state'        => getNovalnetEscapeVar($novalnet_request, 'cust_state')
    );
    
    $data['customer']['shipping']['same_as_billing'] = 1;

    if (!empty($paymentDetails->booking_details->birth_date)) {
        $data['customer']['birth_date'] = $paymentDetails->booking_details->birth_date;
    }

    return $data;
}

/**
 * Get V13 parameters for the Novalnet API request.
 *
 * @param   object  $novalnet_request Request object
 * @return  array   V13 API request parameters
 */
function getV13Params($novalnet_request)
{
    global $conf;
    $currency = NovalnetApi::findCurrencyByNumCode(getNovalnetEscapeVar($novalnet_request, 'currency'));

    $data['transaction'] = array(
        'amount'           => getNovalnetEscapeVar($novalnet_request, 'amount'),
        'currency'         => $currency->getAlpha3(),
        'system_ip'        => getUserRemoteIP(),
        'system_name'      => 'Dolibarr',
        'system_url'       => DOL_MAIN_URL_ROOT,
        'system_version'   => !empty($conf->global->MAIN_VERSION_FIRST_INSTALL) ? $conf->global->MAIN_VERSION_FIRST_INSTALL .'-NN1.0.0' : $conf->global->MAIN_VERSION_LAST_INSTALL .'-NN1.0.0'
    );

    $data['hosted_page'] = array(
        'hide_blocks'      => array('ADDRESS_FORM', 'SHOP_INFO', 'LANGUAGE_MENU', 'TARIFF', 'HEADER'),
        'skip_pages'       => array('CONFIRMATION_PAGE', 'SUCCESS_PAGE', 'PAYMENT_PAGE'),
        'form_version'     => 13,
        'type'             => 'PAYMENTFORM'
    );

    return $data;
}

/**
 * Build custom parameters for the Novalnet API request.
 *
 * @param   object  $novalnet_request Request object
 * @return  array   Custom parameters
 */
function buildCustomParams($novalnet_request)
{ 
    global $conf;
    
    $language = $conf->global->MAIN_LANG_DEFAULT ? strtoupper(substr($conf->global->MAIN_LANG_DEFAULT, 0, 2)) : 'EN';
    
    $data['custom'] = array(
        'lang' => $language
    );

    if (!empty($novalnet_request)) {
        $data['custom']['input1'] = 'full_tag';
        $data['custom']['inputval1'] = getNovalnetEscapeVar($novalnet_request, 'ext_info_full_tag');
    }

    return $data;
}

/**
 * Build transaction parameters for the Novalnet API request.
 *
 * @param   object  $paymentDetails    Payment details object
 * @param   object  $novalnet_request Request object
 * @return  array   Transaction parameters
 */
function buildTransactionParams($paymentDetails, $novalnet_request)
{
    global $conf;
    $currency = NovalnetApi::findCurrencyByNumCode(getNovalnetEscapeVar($novalnet_request, 'currency'));

    $data['transaction'] = array(
        'payment_type'     => $paymentDetails->payment_details->type,
        'amount'           => getNovalnetEscapeVar($novalnet_request, 'amount'),
        'currency'         => $currency->getAlpha3(),
        'test_mode'        => $paymentDetails->booking_details->test_mode,
        'order_no'         => getNovalnetEscapeVar($novalnet_request, 'order_id'),
        'system_ip'        => getUserRemoteIP(),
        'system_name'      => 'Dolibarr',
        'system_version'   => !empty($conf->global->MAIN_VERSION_FIRST_INSTALL) ? $conf->global->MAIN_VERSION_FIRST_INSTALL .'-NN1.0.0' : $conf->global->MAIN_VERSION_LAST_INSTALL .'-NN1.0.0',
        'system_url'       => DOL_MAIN_URL_ROOT
    );
    
    if(!empty($paymentDetails->booking_details->payment_action)) {
       $data['paymentAction']    = $paymentDetails->booking_details->payment_action;
    }
    if ($paymentDetails->payment_details->process_mode == 'redirect') {
        $data['transaction']['return_url'] = DOL_MAIN_URL_ROOT . '/custom/novalnet/public/process.php';
        $data['transaction']['error_return_url'] = DOL_MAIN_URL_ROOT . '/custom/novalnet/public/process.php';
    }

    $paymentMapping = array(
        'token'          => 'payment_ref_token',
        'pan_hash'       => 'pan_hash',
        'unique_id'      => 'unique_id',
        'iban'           => 'iban',
        'wallet_token'   => 'wallet_token',
        'bic'            => 'bic'
    );

    foreach ($paymentMapping as $key => $property) {
        if (property_exists($paymentDetails->booking_details, $property)) {
            $data['transaction']['payment_data'][$key] = preg_replace('/\s+/', '', $paymentDetails->booking_details->$property);
        }
    }

    if(!empty($paymentDetails->booking_details->due_date)) {
        $paymentDueDate = $paymentDetails->booking_details->due_date;
        $paymentDueDate = (!empty($paymentDueDate)) ? ltrim($paymentDueDate, '0') : '';
        if ($paymentDueDate) {
            $data['transaction']['due_date'] = date('Y-m-d', strtotime('+' . $paymentDueDate . ' days'));
        }
    }
    if(!empty($paymentDetails->booking_details->cycle)) {
        $data['instalment']['cycles'] = $paymentDetails->booking_details->cycle;
        $data['instalment']['interval'] = '1m';
    }
    if(!empty($paymentDetails->booking_details->enforce_3d)) {
        $data['transaction']['enforce_3d'] = $paymentDetails->booking_details->enforce_3d;
    }

    return $data;
}
/**
 * Print the specified message in dolibarr_novalnet.log file.
 *
 * @param   string    $message    Message to be printed in log file
 * @param   string    $level      Level at which the message will be printed
 * @return  void
 */
function novalnet_syslog($message, $ref = '', $level = LOG_INFO)
{
    $file = basename($_SERVER['PHP_SELF']);
    $final_log =  '[' . $file . '][Ref=' . $ref . '] ' . $message;
    dol_syslog($message, $level, 0, '_novalnet');
}

