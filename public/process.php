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

/**
 * Instant payment notification file. Wait for payment gateway confirmation, then validate order.
 */
define('NOLOGIN',1);        // This means this output page does not require to be logged.
define('NOCSRFCHECK',1);    // We accept to go on this page from external web site.

$res=0;

// Try master.inc.php using relative path.
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

dol_include_once('/novalnet/lib/novalnet.lib.php');


// Security check.
if (empty($conf->novalnet->enabled)) {
    accessforbidden('', 1, 1, 1);
}

$langs->load('main');
$langs->load('other');
$langs->load('dict');
$langs->load('novalnet@novalnet');
$langs->loadLangs(array('main', 'other', 'dict', 'bills', 'companies', 'errors' , 'NOVALNET')); // File with generic data.

require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/paymentdonation.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/json.lib.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/eventorganization/class/conferenceorboothattendee.class.php';
require_once DOL_DOCUMENT_ROOT.'/eventorganization/class/conferenceorbooth.class.php';



dol_include_once('/novalnet/core/modules/modNovalnet.class.php');
dol_include_once('/novalnet/class/NovalnetResponse.php');
dol_include_once('/novalnet/class/NovalnetRest.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';


global $conf, $db ;
$language = $conf->global->MAIN_LANG_DEFAULT ? strtoupper(substr($conf->global->MAIN_LANG_DEFAULT, 0, 2)) : 'EN';
print '<link rel="stylesheet" type="text/css" href="' . DOL_URL_ROOT . $conf->css . '?lang=' . $langs->defaultlang . '">' . "\n";

$source = '';
$novalnet_response = null;

// Get request data
$requestData = $_REQUEST;

// Initialize user rights if not set
if (empty($user->rights->societe)) {
    $user->rights->societe = new stdClass();
}
if (empty($user->rights->facture)) {
    $user->rights->facture = new stdClass();
    $user->rights->facture->invoice_advance = new stdClass();
}
if (empty($user->rights->adherent)) {
    $user->rights->adherent = new stdClass();
    $user->rights->adherent->cotisation = new stdClass();
}

$user->rights->societe->creer = 1;
$user->rights->facture->creer = 1;
$user->rights->facture->invoice_advance->validate = 1;
$user->rights->adherent->cotisation->creer = 1;

// Handle redirect payment
if (!empty($requestData['status_code']) && $requestData['status_code'] == 100 
    && !empty($requestData['checksum']) && !empty($requestData['tid'])) {

    $client = new NovalnetRest();
    $response = $client->post('transaction/details', json_encode([
        'transaction' => ['tid' => $requestData['tid']],
        'custom' => ['lang' => $language]
    ]), '');
    // Check the response status
    if ($response['result']['status_code'] == 100 && $response['result']['status'] == 'SUCCESS') {
        global $langs;
        $fulltag = $response['custom']['full_tag'];
        $source = getSourceType($fulltag);

        if(!validateChecksum($requestData ,$response['transaction']['order_no'], $source )){
            novalnet_syslog('return data miss match for order => '.$response['transaction']['order_no'].'', '', LOG_ERR);
            printResultPayment('transaction', $response, $source, 'ERROR','Error : '.$langs->trans("NOVALNET_ERR_RETURNED"));
            exit;
        }

        $alreadyPaid = verifyAlreadyPaid($source, $response['transaction']['order_no'],$response['transaction']['payment_type'],$response['transaction']['tid']);
        
        if ($alreadyPaid) {
            printResultPayment('transaction', $response, $source, 'ALREADY_PAID','');
            exit;
        }

        if (!verifyAmount($response, 'transaction')) {
            exit;
        }

        if ($source == 'order') {
            onSuccessOrderPayment($response, $fulltag, $user);
        } elseif ($source == 'invoice' || $source == 'organizedeventregistration') {
            onSuccessInvoicePayment($response, $fulltag, $user);
        } elseif ($source == 'donation') {
            onSuccessDonationPayment($response, $fulltag, $user);
        } elseif ($source == 'member') {
            onSuccessMemberPayment($response, $fulltag, $user);
        }
        exit;
    } elseif ($response['result']['status_code'] != 100) {
        printResultPayment('transaction', $response, $source, 'ERROR','');
        exit;
    }
} 
// Handle other status codes (non-100)
elseif (!empty($requestData['status_code']) && $requestData['status_code'] != 100 
    && !empty($requestData['checksum']) && !empty($requestData['tid'])) {

    $client = new NovalnetRest();
    $response = $client->post('transaction/details', json_encode([
        'transaction' => ['tid' => $requestData['tid']],
        'custom' => ['lang' => $language]
    ]), '');

    $fulltag = $response['custom']['full_tag'];
    $source = getSourceType($fulltag);
    $tmptag = dolExplodeIntoArray($fulltag, '.', '=');
    
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
        case 'member':
            $object = new Adherent($db);
            break;
        default:
            $object = new Facture($db);
            break;
    }

    $object->fetch('', $response['transaction']['order_no']);
    setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
    updateNovalnetTransaction($response, '', '', $response['transaction']['order_no'], $source);
    updateTransactionComments($object, $response);
    printResultPayment('transaction', $response, $source, 'FAILURE','Error : '.$response['result']['status_text'].'');
    exit;
}

// Handle payment details for redirect mode
$nnPaymentDetails = $_REQUEST['nn_payment_details'];
$paymentDetails = json_decode($nnPaymentDetails);

// Redirect payment processing
if ($paymentDetails->payment_details->process_mode == 'redirect') {
    $action = 'payment';
    if (!empty($paymentDetails->booking_details->payment_action) && $paymentDetails->booking_details->payment_action == 'authorized') {
        $action = 'authorize';
    }

    $novalnet_response = new NovalnetResponse($_REQUEST);
    $fulltag = !empty($novalnet_response->getExtInfoFullTag()) 
        ? $novalnet_response->getExtInfoFullTag() 
        : getNovalnetEscapeVar($novalnet_response, 'ext_info_full_tag');
    
    $amount = !empty($novalnet_response->getRawAmount()) 
        ? $novalnet_response->getRawAmount() 
        : getNovalnetEscapeVar($novalnet_response, 'raw_amount');
    
    $order_id = !empty($novalnet_response->getOrderId()) 
        ? $novalnet_response->getOrderId() 
        : getNovalnetEscapeVar($novalnet_response, 'order_id');
    
    $source = getSourceType($fulltag);

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
        case 'member':
            $object = new Adherent($db);
            break;
        default:
            $object = new Facture($db);
            break;
    }

    $object->fetch('', $order_id);
    if($source == 'organizedeventregistration'){
        $object->fetch($order_id);
    }
    if (!verifyAmount($novalnet_response, 'payment')) {
        exit;
    }
    
    $merchantParams = buildMerchantParams();
    $customerParams = buildCustomerParams($paymentDetails, $novalnet_response);
    $transactionParams = buildTransactionParams($paymentDetails, $novalnet_response);
    $customParams = buildCustomParams($novalnet_response);

    $data = array_merge($merchantParams, $customerParams, $transactionParams, $customParams);
    $client = new NovalnetRest();
    $response = $client->post($action, json_encode($data), '');
    $nNTxnSecret = getNovalnetTransactionData('tnx_secret', $order_id, $source);
    if(!empty($nNTxnSecret)) {
        updateNovalnetTransaction($response, '','', $order_id, $source);
    }
    else {
        novalnetPaymentTransaction($response, $novalnet_response, '', '');
    }

    if ($response['result']['status_code'] == 100 && $response['result']['redirect_url']) {
        setStatusNotePrivate($object, 'PAYMENT_AWAIT', $order_id);
        header("Location: " . $response['result']['redirect_url']);
    }
} 
// Handle direct payment
else {
    $novalnet_response = new NovalnetResponse($_REQUEST);
    $fulltag = !empty($novalnet_response->getExtInfoFullTag()) 
        ? $novalnet_response->getExtInfoFullTag() 
        : getNovalnetEscapeVar($novalnet_response, 'ext_info_full_tag');
    
    $amount = !empty($novalnet_response->getRawAmount()) 
        ? $novalnet_response->getRawAmount() 
        : getNovalnetEscapeVar($novalnet_response, 'raw_amount');
    
    $order_id = !empty($novalnet_response->getOrderId()) 
        ? $novalnet_response->getOrderId() 
        : getNovalnetEscapeVar($novalnet_response, 'order_id');
    
    $source = getSourceType($fulltag);

    if (!verifyAmount($novalnet_response, 'payment')) {
        exit;
    }
    
    $alreadyPaid = verifyAlreadyPaid($source, $order_id);

    if ($alreadyPaid) {
        printResultPayment('payment', $novalnet_response, $source, 'ALREADY_PAID','');
        exit;
    }

    $merchantParams = buildMerchantParams();
    $customerParams = buildCustomerParams($paymentDetails, $novalnet_response);
    $transactionParams = buildTransactionParams($paymentDetails, $novalnet_response);
    $customParams = buildCustomParams($novalnet_response);

    $data = array_merge($merchantParams, $customerParams, $transactionParams, $customParams);
    $action = 'payment';

    if (!empty($paymentDetails->booking_details->payment_action) && $paymentDetails->booking_details->payment_action == 'authorized') {
        $action = 'authorize';
    }

    $client = new NovalnetRest();
    $response = $client->post($action, json_encode($data), '');

    if ($response['result']['status_code'] == 100 && $response['result']['status'] == 'SUCCESS') {
        if (!empty($response['result']['redirect_url'])) {
            novalnetPaymentTransaction($response, $novalnet_response);
            setStatusNotePrivate($object, 'PAYMENT_AWAIT', $order_id);
            header("Location: " . $response['result']['redirect_url']);
        } else {
            if ($source == 'order') {
                onSuccessOrderPayment($response, $fulltag, $user);
            } elseif ($source == 'invoice' || $source == 'organizedeventregistration') {
                onSuccessInvoicePayment($response, $fulltag, $user);
            } elseif ($source == 'donation') {
                onSuccessDonationPayment($response, $fulltag, $user);
            } elseif ($source == 'member') {
                onSuccessMemberPayment($response, $fulltag, $user);
            }
        }
    } else {

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
            case 'member':
                $object = new Adherent($db);
                break;
            default:
                $object = new Facture($db);
                break;
        }

        $object->fetch('', $order_id);
        setStatusNotePrivate($object, $response['transaction']['status'], $order_id);
        updateTransactionComments($object, $response);
        printResultPayment('payment', $novalnet_response, $source, 'FAILURE','Error : '.$response['result']['status_text'].'');
    }
}

/**
 * Update transaction comments in the database.
 */
function updateTransactionComments($object, $response, $next_sub_date = '')
{ 
    global $db , $langs;

    $error = 0;
    $id = $object->id;
    $note_public = ''; // Initialize note_public variable.
    $paymentTitile = getPaymentTitile($response['transaction']['payment_type']);
    
    if ($response['transaction']['tid']) {
        // Add payment method and transaction details to the note.
        $note_public .= 'Payment Method: ' . $paymentTitile . "\n";
        $note_public .= 'Novalnet Transaction ID: ' . $response['transaction']['tid']."\n";

        if(in_array($response['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
           $note_public .= $langs->trans('NOVALNET_GUARANTEE')."\n";
        }

        if(!empty($response['transaction']['due_date']) && in_array($response['transaction']['payment_type'],['INSTALMENT_INVOICE','PREPAYMENT','GUARANTEED_INVOICE','INVOICE'])
        && $response['transaction']['status'] != 'ON_HOLD') {
            $note_public .=  $langs->trans('NOVALNET_DUEDATE_TEXT',getFormattedAmount($response['transaction']['amount']).' '.$response['transaction']['currency'],$response['transaction']['due_date'])."\n";
        }
        
        if (!empty($response['transaction']['bank_details']) && !in_array($response['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
            $note_public .= getInvoiceComments($response['transaction'])."\n";
        }

        if (!empty($response['transaction']['bank_details']) && in_array($response['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE']) && $response['transaction']['status_code'] == 100) {
            $note_public .= getInvoiceComments($response['transaction'])."\n";
        }

        if (!empty($response['transaction']['bank_details']) && in_array($response['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE']) && $response['transaction']['status_code'] != 100) {
            $note_public .= $langs->trans('NOVALNET_GUARANTEED_PENDING');
        }

        if(!empty($response['transaction']['partner_payment_reference'])) {
            $note_public .= $langs->trans('NOVALNET_PARTNER_REF', $response['transaction']['partner_payment_reference'])."\n";
            $note_public .= $langs->trans('NOVALNET_PARTNER_ENTITY', $response['transaction']['service_supplier_id'])."\n";
        }

        if ($response['transaction']['payment_type'] == 'CASHPAYMENT') {
            $note_public .= $langs->trans('NOVALNET_CASHPAYMENT_DUEDATE',$response['transaction']['due_date'])."\n";

            if(isset($response['transaction']['nearest_stores']) && !empty($response['transaction']['nearest_stores'])) {
                $note_public .= $langs->trans('NOVALNET_NEAR_STORE')."\n";

                $cashPaymentStores = [];
                foreach ($response['transaction']['nearest_stores'] as $key => $cashPaymentStore) {
                    $cashPaymentStores[] = [
                        'title' => $cashPaymentStore['store_name'],
                        'street' => $cashPaymentStore['street'],
                        'city' => $cashPaymentStore['city'],
                        'zipcode' => $cashPaymentStore['zip'],
                        'country' => $cashPaymentStore['country_code']
                    ];
                }
                $formattedStores = '';
                foreach ($cashPaymentStores as $store) {
                    $formattedStores .= "Store: " . $store['title'] . "\n";
                    $formattedStores .= "Street: " . $store['street'] . "\n";
                    $formattedStores .= "City: " . $store['city'] . "\n";
                    $formattedStores .= "Zipcode: " . $store['zipcode'] . "\n";
                    $formattedStores .= "Country: " . $store['country'] . "\n\n";
                }
    
                $note_public .= $formattedStores;

            }
        }

        // Start transaction for updating the database.
        $db->begin();
        
        $objElement = $object->element;
        if($object->element == 'member'){
            $objElement = 'adherent';
            if(!empty($next_sub_date)){
                $note_public .= $langs->trans('NOVALNET_NEXT_MEMBERSHIP_DATE', dol_print_date($next_sub_date)). "\n";
            }
        }

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $objElement . ' SET';
        $sql .= " note_public='" . $db->escape($note_public) . "'";
        $sql .= ' WHERE rowid = ' . (int)$id;

        $resql = $db->query($sql);
        if (!$resql) {
            // Handle error in updating the database.
            $error = 1;
        }

        // Commit or rollback based on the success of the query.
        if (!$error) {
            $db->commit();
        } else {
            $db->rollback();
        }
    }
}

/**
 * Get detailed bank information to include in the invoice comments.
 */
function getInvoiceComments($response)
{   global $langs;

    $invoicePaymentsNote = 'Account holder: ' . $response['bank_details']['account_holder'] . "\n";
    $invoicePaymentsNote .= 'IBAN: ' . $response['bank_details']['iban'] . "\n";
    $invoicePaymentsNote .= 'BIC: ' . $response['bank_details']['bic'] . "\n";
    $invoicePaymentsNote .= 'Bank: ' . $response['bank_details']['bank_name'] . "\n";
    $invoicePaymentsNote .= 'City: ' . $response['bank_details']['bank_place'] . "\n";
    $invoicePaymentsNote .= $langs->trans('PAYMENT_REF_DESC') . "\n";
    
    if (!empty($response['instalment']['cycle_amount'])) {
        $invoicePaymentsNote .= 'Payment Reference: ' . $response['tid'] . "\n";
    } else {
        $invoicePaymentsNote .= 'Payment reference 1: ' . $response['tid'] . "\n";
        $invoicePaymentsNote .= 'Payment reference 2: ' . $response['invoice_ref'] . "\n";
    }

    return $invoicePaymentsNote;
}

/**
 * Obtain source type (free, invoice, order, donation, contractline, membersubscription).
 */
function getSourceType($order_info)
{
    $source = '';
    
    if (preg_match('#^TAG(.*)$#', $order_info)) {
        $source = 'free'; // Free amount.
    } elseif (preg_match('#^INV(.*)$#', $order_info)) {
        $source = 'invoice'; // Invoice.
    } elseif (preg_match('#^ORD(.*)$#', $order_info)) {
        $source = 'order'; // Order.
    } elseif (preg_match('#^DON(.*)$#', $order_info)) {
        $source = 'donation'; // Donation.
    } elseif (preg_match('#^COL(.*)$#', $order_info)) {
        $source = 'contractline'; // Contract line.
    } elseif (preg_match('#^MEM(.*)$#', $order_info)) {
        $source = 'member'; // Member subscription.
    } elseif (preg_match('#^ATT(.*)$#', $order_info)) {
        $source = 'organizedeventregistration'; // organized event registration.
    }

    return $source;
}

/**
 * Print the result of the payment according to the status answer.
 */
function printResultPayment($dataType, $data, $source, $resultType , $additionalMsg)
{
    global $conf, $mysoc, $langs;

    $suffix = '';
    $title = '';
    $print_button = true;

    // Show logo.
    $logosmall = $mysoc->logo_small;
    $logo = $mysoc->logo;
    $paramlogo = 'ONLINE_PAYMENT_LOGO_' . $suffix;
    
    if (!empty($conf->global->$paramlogo)) {
        $logosmall = $conf->global->$paramlogo;
    } elseif (!empty($conf->global->ONLINE_PAYMENT_LOGO)) {
        $logosmall = $conf->global->ONLINE_PAYMENT_LOGO;
    }

    // Define logo URL.
    $urlLogo = '';
    if (!empty($logosmall) && is_readable($conf->mycompany->dir_output . '/logos/thumbs/' . $logosmall)) {
        $urlLogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;file=' . urlencode('logos/thumbs/' . $logosmall);
    } elseif (!empty($logo) && is_readable($conf->mycompany->dir_output . '/logos/' . $logo)) {
        $urlLogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;file=' . urlencode('logos/' . $logo);
    }

    // Output HTML code for logo.
    if ($urlLogo) {
        print '<div class="backgreypublicpayment">';
        print '<center><img id="dolpaymentlogo" title="' . $title . '" src="' . $urlLogo . '" width="150"></center>';
        print '</div><br>';
    }
    $favicon = DOL_URL_ROOT . '/theme/dolibarr_256x256_color.png';
    if (! empty($mysoc->logo_squarred_mini)) {
        $favicon = DOL_URL_ROOT . '/viewimage.php?cache=1&modulepart=mycompany&file=' . urlencode('logos/thumbs/' . $mysoc->logo_squarred_mini);
    }

    if (! empty($conf->global->MAIN_FAVICON_URL)) {
        $favicon = $conf->global->MAIN_FAVICON_URL;
    }

    if (empty($conf->dol_use_jmobile)) {
        print '<link rel="shortcut icon" type="image/x-icon" href="' . $favicon . '"/>' . "\n"; // Not required into an Android webview.
    }

    // Include necessary files.
    require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';

    // Determine payment details based on $dataType.
    if ($dataType == 'transaction') {
        $amount = getFormattedAmount($data['transaction']['amount']);
        $order_id = $data['transaction']['order_no'];
        $fulltag = $data['custom']['full_tag'];
    } else {
        $amount = !empty($data->getRawAmount()) ? $data->getRawAmount() : getNovalnetEscapeVar($data, 'raw_amount');
        $order_id = !empty($data->getOrderId()) ? $data->getOrderId() : getNovalnetEscapeVar($data, 'order_id');
        $fulltag = !empty($data->getExtInfoFullTag()) ? $data->getExtInfoFullTag() : getNovalnetEscapeVar($data, 'ext_info_full_tag');
    }

    // Get the source type.
    $source = getSourceType($fulltag);

    // Handle different payment result types.
    switch ($resultType) {
        case 'CANCELLED':
            $url = getOnlinePaymentUrl(0, $source, $order_id, $amount, $order_id);
            $message = $langs->trans("NOVALNET_CANCELLED_PAYMENT_TEXT");
            $messageDesc = $langs->trans("NOVALNET_CANCELLED_PAYMENT_MSG");
            header("Location: " . $url);
            break;
        
        case 'CONFIRMED':
            if ($source === 'free' && $resultType === 'CONFIRMED') {
                $print_button = false;
            }
            $url = getOnlinePaymentUrl(0, $source, $order_id);
            $message = $langs->trans("NOVALNET_THANK_FOR_PAYMENT_TEXT");
            $messageDesc = $langs->trans("NOVALNET_SUCCESS_PAYMENT_TEXT");
            $messageClic = $langs->trans("NOVALNET_DETAILS");
            break;

        case 'PENDING':
            $url = getOnlinePaymentUrl(0, $source, $order_id);
            $message = '';
            $messageDesc = '';
            $messageClic = $langs->trans("NOVALNET_DETAILS");
            $print_button = false;
            break;

        case 'INVALID_AMOUNT':
            $message = $langs->trans("NOVALNET_INVALID_AMOUNT");
            $messageDesc = $langs->trans("NOVALNET_INVALID_AMOUNT_DESC");
            $print_button = false;
            break;

        case 'ALREADY_PAID':
            $url = getOnlinePaymentUrl(0, $source, $order_id);
            $message = $langs->trans("NOVALNET_ALREADY_PAID_TXT");
            $messageDesc = $langs->trans("NOVALNET_SUCCESS_PAYMENT_TEXT");
            $messageClic = $langs->trans("NOVALNET_DETAILS");
            break;

        case 'ON_HOLD':
            $url = getOnlinePaymentUrl(0, $source, $order_id);
            $message = $langs->trans("NOVALNET_ONHOLD_STATUS");
            $messageDesc = $langs->trans("NOVALNET_SUCCESS_PAYMENT_TEXT");
            $messageClic = $langs->trans("NOVALNET_DETAILS");
            break;

        case 'ERROR':
            $url = getOnlinePaymentUrl(0, $source, $order_id);
            $message = $langs->trans("NOVALNET_ERROR_STATUS");
            $messageDesc = $langs->trans("NOVALNET_ERROR_PAYMENT_TEXT");
            $messageClic = $langs->trans("NOVALNET_DETAILS");
            break;
        
        case 'FAILURE':
            $url = getOnlinePaymentUrl(0, $source, $order_id);
            $messageDesc = $langs->trans("NOVALNET_FAILURE_PAYMENT_TEXT");
            $message = $langs->trans("NOVALNET_FAILURE_PAYMENT");
            $messageClic = $langs->trans('Re-try');
            break;
    }
    if($additionalMsg) {
        $messageDesc = $additionalMsg;
    }
    // Output the payment result summary form.
    print '<center>';
    print '<table id="dolpaymenttable" summary="Payment form">';
    print '<tr><td align="center">';
    print '<table width="100%" id="tablepublicpayment">';
    print '<br>';
    print '<tr class="liste_total"><td align="left" colspan="2">' . $message . '</td></tr>';

    // Display payment details.
    printSummaryPaymentDetails($dataType, $data, $source);
    print '<tr><td><br></td></tr>';
    print '<tr><td align="left" colspan="2">' . $messageDesc . '</td></tr>';

    // Display additional messages based on source type.
    if ($resultType === 'CONFIRMED') {
        if ($source == 'order') {
            print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("OrderBilled") . '</span>';
        } elseif ($source == 'invoice' || $source == 'organizedeventregistration') {
            print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("InvoicePaid") . '</span>';
        } elseif ($source == 'member') {
            print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("PaymentWillBeRecordedForNextPeriod") . '</span>';
        } elseif ($source == 'donation') {
            print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("DonationPaid") . '</span>';
        }
    }

    // Display the transaction ID for transaction type data.
    if ($dataType == 'transaction' && !empty($data['transaction']['tid'])) {
        print '<tr class="CTableRow2"><td class="CTableRow">Payment Method</td>';
        print '<td class="CTableRow2"><b>' . getPaymentTitile($data['transaction']['payment_type']) . '</b></td></tr>';
        print '<tr class="CTableRow2"><td class="CTableRow">Novalnet Transaction ID</td>';
        print '<td class="CTableRow2"><b>' . $data['transaction']['tid'] . '</b></td></tr>';
      
        if (!empty($data['transaction']['due_date']) && in_array($data['transaction']['payment_type'],['INSTALMENT_INVOICE','PREPAYMENT','GUARANTEED_INVOICE','INVOICE'])
        && $data['transaction']['status'] != 'ON_HOLD') {
        print '<br>';
        print '<tr><td align="left" colspan="2">' . $langs->trans('NOVALNET_DUEDATE_TEXT', getFormattedAmount($data['transaction']['amount']) .' '. $data['transaction']['currency'] , $data['transaction']['due_date']) . '<br></td></tr>';
    }
        
    if (!empty($data['transaction']['bank_details']) && !in_array($data['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
        print '<br>';
        $invoicePaymentsNote = 'Account holder: ' . $data['transaction']['bank_details']['account_holder'] . "<br>";
        $invoicePaymentsNote .= 'IBAN: ' . $data['transaction']['bank_details']['iban'] . "<br>";
        $invoicePaymentsNote .= 'BIC: ' . $data['transaction']['bank_details']['bic'] . "<br>";
        $invoicePaymentsNote .= 'Bank: ' . $data['transaction']['bank_details']['bank_name'] . "<br>";
        $invoicePaymentsNote .= 'City: ' . $data['transaction']['bank_details']['bank_place'] . "<br>";
        $invoicePaymentsNote .= $langs->trans('PAYMENT_REF_DESC') . "<br>";
    
        if (!empty($data['instalment']['cycle_amount'])) {
            $invoicePaymentsNote .= 'Payment Reference: ' . $data['transaction']['tid'] . "<br>";
        } else {
            $invoicePaymentsNote .= 'Payment reference 1: ' . $data['transaction']['tid'] . "<br>";
            $invoicePaymentsNote .= 'Payment reference 2: ' . $data['transaction']['invoice_ref'] . "<br>";
        }
        print '<tr><td align="left" colspan="2">'.'<b>' . $invoicePaymentsNote . '</b>'.'</td></tr>';
    }
    
    if (!empty($data['transaction']['bank_details']) && in_array($data['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE']) && $data['transaction']['status_code'] == 100) {
        print '<br>';
        $invoicePaymentsNote = 'Account holder: ' . $data['transaction']['bank_details']['account_holder'] . "<br>";
        $invoicePaymentsNote .= 'IBAN: ' . $data['transaction']['bank_details']['iban'] . "<br>";
        $invoicePaymentsNote .= 'BIC: ' . $data['transaction']['bank_details']['bic'] . "<br>";
        $invoicePaymentsNote .= 'Bank: ' . $data['transaction']['bank_details']['bank_name'] . "<br>";
        $invoicePaymentsNote .= 'City: ' . $data['transaction']['bank_details']['bank_place'] . "<br>";
        $invoicePaymentsNote .= $langs->trans('PAYMENT_REF_DESC') . "<br>";

        if (!empty($data['instalment']['cycle_amount'])) {
            $invoicePaymentsNote .= 'Payment Reference: ' . $data['transaction']['tid'] . "<br>";
        } else {
            $invoicePaymentsNote .= 'Payment reference 1: ' . $data['transaction']['tid'] . "<br>";
            $invoicePaymentsNote .= 'Payment reference 2: ' . $data['transaction']['invoice_ref'] . "<br>";
        }
        print '<tr><td align="left" colspan="2">'.'<b>' . $invoicePaymentsNote . '</b>'.'</td></tr>';
    }

    if (!empty($data['transaction']['bank_details']) && in_array($data['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE']) && $data['transaction']['status_code'] != 100) {
        $invoicePaymentsNote = $langs->trans('NOVALNET_GUARANTEED_PENDING');
        print '<tr><td align="left" colspan="2">'.'<b>' . $invoicePaymentsNote . '</b>'.'</td></tr>';
    }

    if(!empty($data['transaction']['partner_payment_reference'])) {
        print '<br>';
        print '<tr><td align="left" colspan="2">'.'<b>'. $langs->trans('NOVALNET_PARTNER_REF', $data['transaction']['partner_payment_reference']). '</b>'.'</td></tr>';
        print '<tr><td align="left" colspan="2">'.'<b>'. $langs->trans('NOVALNET_PARTNER_ENTITY', $data['transaction']['service_supplier_id']). '</b>'.'</td></tr>';
    }
    if ($data['transaction']['payment_type'] == 'CASHPAYMENT') {
        print '<br>';
        print '<tr><td align="left" colspan="2">'.$langs->trans('NOVALNET_CASHPAYMENT_DUEDATE',$data['transaction']['due_date']) .'</td></tr>';
        print '<br>';
        print '<tr><td align="left" colspan="2">'.$langs->trans('NOVALNET_NEAR_STORE').'</td></tr>';
        print '<br>';
        $cashPaymentStores = [];
        foreach ($data['transaction']['nearest_stores'] as $key => $cashPaymentStore) {
            $cashPaymentStores[] = [
                'title' => $cashPaymentStore['store_name'],
                'street' => $cashPaymentStore['street'],
                'city' => $cashPaymentStore['city'],
                'zipcode' => $cashPaymentStore['zip'],
                'country' => $cashPaymentStore['country_code']
            ];
        }
        $formattedStores = '';
        foreach ($cashPaymentStores as $store) {
            $formattedStores .= "Store: " . $store['title'] . '<br>';
            $formattedStores .= "Street: " . $store['street'] .'<br>';
            $formattedStores .= "City: " . $store['city'] . '<br>';
            $formattedStores .= "Zipcode: " . $store['zipcode'] . '<br>';
            $formattedStores .= "Country: " . $store['country'] . '<br>'.'<br>';
        }
        print '<br>';
        print '<tr><td align="left" colspan="2">'.'<b>' .$formattedStores.'<b>'.'</td></tr>';
    }
        
    }


    // Display redirect button.
    if ($print_button && $source != 'organizedeventregistration') {
        print '<tr>';
        print '<td align="center" colspan="2"><br><br><div class="button buttonpayment"><a style="color: white;" href="' . $url . '">' . $messageClic . '</a></div></td>';
        print '</tr>';
    }

    if ($print_button && $source == 'organizedeventregistration' && $resultType == 'FAILURE') {
        print '<tr>';
        $url = DOL_MAIN_URL_ROOT.'/public/payment/newpayment.php?source='.$source.'&ref='.$order_id.'';
        print '<td align="center" colspan="2"><br><br><div class="button buttonpayment"><a style="color: white;" href="' . $url . '">' . $messageClic . '</a></div></td>';
        print '</tr>';
    }

    print '</table>';
    print '</td></tr>';
    print '</table>';
    print '</center>';
}


/**
 * Print summary data of the payment object source (free, invoice...).
 */
function printSummaryPaymentDetails($dataType, $data, $source)
{
    global $langs, $mysoc, $conf, $db;

    $var = false;
    $creditor = $mysoc->name;
    $currency = NovalnetApi::findCurrencyByAlphaCode($conf->currency);

    if ($dataType == 'transaction') {
        $amount = getFormattedAmount($data['transaction']['amount']);
        $order_id = $data['transaction']['order_no'];
        $fulltag = $data['custom']['full_tag'];
        $cust_name = $data['customer']['first_name'];
    } else {
        $amount = !empty($data->getRawAmount()) ? $data->getRawAmount() : getNovalnetEscapeVar($data, 'raw_amount');
        $order_id = !empty($data->getOrderId()) ? $data->getOrderId() : getNovalnetEscapeVar($data, 'order_id');
        $fulltag = !empty($data->getExtInfoFullTag()) ? $data->getExtInfoFullTag() : getNovalnetEscapeVar($data, 'ext_info_full_tag');
        $cust_name = !empty($data->getCustFirstName()) ? $data->getCustFirstName() : getNovalnetEscapeVar($data, 'cust_first_name');
    }

    $source = getSourceType($fulltag);

    // Payment on customer order.
    if ($source == 'order') {
        $found = true;
        $langs->load("orders");

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';

        $order = new Commande($db);
        $result = $order->fetch('', $order_id);

        if ($result <= 0) {
            $mesg = $order->error;
            $error++;
        } else {
            $result = $order->fetch_thirdparty($order->socid);
        }

        $object = $order;
        $fulltag = dol_string_unaccent($fulltag);

        // Creditor.
        if(!empty($creditor)){
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                    <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_CREDITOR") . '</td>
                    <td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b></td>
                </tr>' . "\n";
        }
        // Debitor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_THIRD_PARTY") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $order->thirdparty->name . '</b></td>
              </tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("NOVALNET_PAYMENT_ORDER_REF", $order->ref) . '</b>';
        if (GETPOST('desc', 'alpha')) {
            $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_DESIGNATION") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $text . '</td>
              </tr>' . "\n";

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_AMOUNT") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '">';
        if (empty($amount) || !is_numeric($amount)) {
            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . price2num(GETPOST("newamount", "alpha"), 'MT') . '">';
        } else {
            print '<b class="amount">' . price($amount) . ' ' . $currency->getAlpha3() . '</b>';
        }
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_PAYMENT_CODE") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b></td>
              </tr>' . "\n";
    }

    // Payment of customer invoice.
    if ($source == 'invoice' || $source == 'organizedeventregistration') {
        $found = true;
        $langs->load("bills");

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $invoice = new Facture($db);
        $result = $invoice->fetch('', $order_id);
       if($source == 'organizedeventregistration') {
            $result = $invoice->fetch($order_id);
        }
        

        if ($result <= 0) {
            $mesg = $invoice->error;
            $error++;
        } else {
            $result = $invoice->fetch_thirdparty($invoice->socid);
        }

        $object = $invoice;
        $fulltag = dol_string_unaccent($fulltag);

        // Creditor.
        if(!empty($creditor)){
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                    <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_CREDITOR") . '</td>
                    <td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b></td>
                </tr>' . "\n";
        }
        // Debitor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_THIRD_PARTY") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $invoice->thirdparty->name . '</b></td>
              </tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("PaymentInvoiceRef", $invoice->ref) . '</b>';
        if (GETPOST('desc', 'alpha')) {
            $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_DESIGNATION") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $text . '</td>
              </tr>' . "\n";

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("PaymentAmount") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '">';
        if (empty($amount) && empty($object->paye)) {
            print ' (' . $langs->trans("NOVALNET_TO_COMPLETE") . ')';
        }
        if (empty($object->paye)) {
            if (empty($amount) || !is_numeric($amount)) {
                print '<input class="flat maxwidth75" type="text" name="newamount" value="' . price2num(GETPOST("newamount", "alpha"), 'MT') . '">';
            } else {
                print '<b>' . price($amount) . '</b>';
            }
        } else {
            print '<b>' . price($object->total_ttc, 1, $langs) . '</b>';
        }
        print ' <b>' . $langs->trans("Currency" . $currency->getAlpha3()) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_PAYMENT_CODE") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b></td>
              </tr>' . "\n";
    }

        // Payment on member subscription.
        if ($source == 'member') {
            $found = true;
            $langs->load("members");
    
            require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
            require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';
    
            $member = new Adherent($db);
            $result = $member->fetch('', $order_id);
            if ($result <= 0) {
                $mesg = $member->error;
                $error++;
            } else {
                $member->fetch_thirdparty();
                $subscription = new Subscription($db);
            }
    
            $object = $member;
            $fulltag = dol_string_unaccent($fulltag);
    
            // Creditor.
            if(!empty($creditor)){
                print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_CREDITOR");
                print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
                print '</td></tr>' . "\n";
            }
            // Debitor.
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("Member");
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>';
            if ($member->morphy == 'mor' && ! empty($member->societe)) {
                print $member->societe;
            } else {
                print $member->getFullName($langs);
            }
    
            print '</b>';
            print '</td></tr>' . "\n";
    
            // Object.
            $text = '<b>' . $langs->trans("PaymentSubscription") . '</b>';
            if (GETPOST('desc', 'alpha')) {
                $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
            }
    
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_DESIGNATION");
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;
            print '</td></tr>' . "\n";
    
            if ($object->datefin > 0) {
                print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("DateEndSubscription");
                print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . dol_print_date($member->datefin, 'day');
                print '</td></tr>' . "\n";
            }
    
            if ($member->last_subscription_date || $member->last_subscription_amount) {
                // Last subscription date.
                print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LastSubscriptionDate");
                print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . dol_print_date($member->last_subscription_date, 'day');
                print '</td></tr>' . "\n";
    
                // Last subscription amount.
                print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LastSubscriptionAmount");
                print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . price($member->last_subscription_amount);
                print '</td></tr>' . "\n";
    
                if (empty($amount) && ! GETPOST('newamount', 'alpha')) {
                    $_GET['newamount'] = $member->last_subscription_amount;
                }
            }
    
            // Amount.
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("PaymentAmount");
            if (empty($amount)) {
                if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                    print ' (' . $langs->trans("NOVALNET_TO_COMPLETE");
                }
    
                if (! empty($conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO)) {
                    print ' - <a href="' . $conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO . '" rel="external" target="_blank">' . $langs->trans("SeeHere") . '</a>';
                }
    
                if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                    print ')';
                }
            }
    
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
            $valtoshow = '';
            if (empty($amount) || ! is_numeric($amount)) {
                $valtoshow = price2num(GETPOST("newamount", 'alpha'), 'MT');
                // Force default subscription amount to value defined into constant...
                if (empty($valtoshow)) {
                    if (! empty($conf->global->MEMBER_NEWFORM_EDITAMOUNT)) {
                        if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                            $valtoshow = $conf->global->MEMBER_NEWFORM_AMOUNT;
                        }
                    } else {
                        if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                            $amount = $conf->global->MEMBER_NEWFORM_AMOUNT;
                        }
                    }
                }
            }
    
            if (empty($amount) || ! is_numeric($amount)) {
                if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                    $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
                }
    
                print '<input class="flat maxwidth75" type="text" name="newamount" value="' . $valtoshow . '">';
            } else {
                $valtoshow = $amount;
                if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                    $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
                }
    
                print '<b>' . price($valtoshow) . '</b>';
            }
    
            // Currency.
            print ' <b>' . $langs->trans("Currency" . $currency->getAlpha3()) . '</b>';
            print '</td></tr>' . "\n";
    
            // Tag.
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_PAYMENT_CODE");
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
            print '</td></tr>' . "\n";
        }
    
        // Payment on donation.
        if ($source == 'donation') {
            $found = true;
            $langs->load("don");
    
            require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
    
            $don = new Don($db);
            $result = $don->fetch($ref);
            if ($result <= 0) {
                $mesg = $don->error;
                $error++;
            } else {
                $don->fetch_thirdparty();
            }
    
            $object = $don;
            $fulltag = dol_string_unaccent($fulltag);
    
            // Creditor.
            if(!empty($creditor)){
                print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_CREDITOR");
                print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
                print '</td></tr>' . "\n";
            }
            // Debitor.
             print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_THIRD_PARTY");
             print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>';
             if ($don->morphy == 'mor' && ! empty($don->societe)) {
                 print $don->societe;
             } else {
                 print $don->getFullName($langs);
             }
    
             print '</b>';
             print '</td></tr>' . "\n";
    
             // Object.
             $text = '<b>' . $langs->trans("PaymentDonation") . '</b>';
             if (GETPOST('desc', 'alpha')) {
                 $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
             }
    
             print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_DESIGNATION");
             print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;
             print '</td></tr>' . "\n";
    
             // Amount.
             print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("PaymentAmount");
             if (empty($amount)) {
                 if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                     print ' (' . $langs->trans("NOVALNET_TO_COMPLETE");
                 }
    
                 if (! empty($conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO)) {
                     print ' - <a href="' . $conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO . '" rel="external" target="_blank">' . $langs->trans("SeeHere") . '</a>';
                 }
    
                 if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                     print ')';
                }
            }
    
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
            $valtoshow = '';
            if (empty($amount) || ! is_numeric($amount)) {
                $valtoshow = price2num(GETPOST("newamount", 'alpha'), 'MT');
                // Force default subscription amount to value defined into constant...
                if (empty($valtoshow)) {
                    if (! empty($conf->global->MEMBER_NEWFORM_EDITAMOUNT)) {
                        if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                            $valtoshow = $conf->global->MEMBER_NEWFORM_AMOUNT;
                        }
                    } else {
                        if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                            $amount = $conf->global->MEMBER_NEWFORM_AMOUNT;
                        }
                    }
                }
            }
    
            if (empty($amount) || ! is_numeric($amount)) {
                if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                    $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
                }
    
                print '<input class="flat maxwidth75" type="text" name="newamount" value="' . $valtoshow . '">';
            } else {
                $valtoshow = $amount;
                if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                    $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
                }
    
                print '<b>' . price($valtoshow) . '</b>';
             }

             // Currency.
             print ' <b>' . $langs->trans("Currency" . $currency->getAlpha3()) . '</b>';
             print '</td></tr>' . "\n";
    
             // Tag.
             print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_PAYMENT_CODE");
             print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
             print '</td></tr>' . "\n";
        }
}

/**
 * Verify if source is already paid.
 */
function verifyAlreadyPaid($source, $ref, $paymentType = '' ,$ext_payment_id = '')
{
    global $conf, $db, $langs;

    if ($source == 'invoice' || $source == 'organizedeventregistration') {
        $object = new Facture($db);
    } elseif ($source == 'order') {
        $object = new Commande($db);
    } elseif ($source == 'donation') {
        $object = new Don($db);
    } elseif ($source == 'member') {
        $object = new Adherent($db);
    } elseif ($source == 'contractline') {
        $object = new ContratLigne($db);
    } elseif ($source == 'free') {
        $object = new Paiement($db);
        $sql = 'SELECT p.rowid, p.ref, p.ext_payment_id, p.ext_payment_site, p.fk_bank,';
        $sql .= ' p.num_paiement as num_payment, p.note';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'paiement as p LEFT JOIN ' . MAIN_DB_PREFIX . 'c_paiement as c ON p.fk_paiement = c.id';
        $sql .= ' WHERE p.entity IN (' . getEntity('invoice') . ')';
        if ($ref) {
            $sql .= " and p.num_paiement = '" . $ref . "'";
        }
        if ($ext_payment_id) {
            $sql .= " and p.ext_payment_id  = '" . $ext_payment_id . "'";
        }

        $num_rows = $db->query($sql)->num_rows;
        if ($num_rows > 0) {
            return true;
        }
    }

    if ($object) {
        $result = null;
        if (!empty($ref)) {
            $result = $object->fetch('', $ref); // Find by ref.
        }

        if ($source == 'donation' || $source == 'organizedeventregistration') {
            $result = $object->fetch($ref); // Find by rowid (Donation and organizedeventregistration).
        }
        
        if ($result) { // Order found.  
            if ($source == 'order' && $object->billed) {
                return true;
            } elseif ($source == 'invoice' && $object->paye == 1 || $source == 'organizedeventregistration' && $object->paye == 1) {
                return true;
            } elseif ($source == 'donation' && $object->paid || strripos($object->note_private, 'ON_HOLD') || strripos($object->note_private, 'PENDING')) {
                return true;
            } elseif ($source == 'member' && strripos($object->note_private, 'ON_HOLD') || strripos($object->note_private, 'PENDING')) {
                return true;
            }

            if ($source == 'member' && !empty($ext_payment_id)){
                $sql = 'SELECT subscription_id FROM ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction WHERE order_id = "' . $db->escape($ref) . '" AND source = "'.$source.'" AND payment_method = "'.$paymentType.'" AND tid = "'.$ext_payment_id.'"';
                $resql = $db->query($sql);
                $obj = $db->fetch_object($resql);
                if(!empty($obj->subscription_id)){
                    return true;
                }
            }

            return false;
        }
    }

    return false;
}

/**
 * Compare if source amount is equal to answer IPN amount.
 */
function verifyAmount($data, $dataType)
{
    global $db, $langs;

    if ($dataType == 'transaction') {
        $amount = getFormattedAmount($data['transaction']['amount']);
        $order_id = $data['transaction']['order_no'];
        $fulltag = $data['custom']['full_tag'];
    } else {
        $amount = !empty($data->getRawAmount()) ? $data->getRawAmount() : getNovalnetEscapeVar($data, 'raw_amount');
        $order_id = !empty($data->getOrderId()) ? $data->getOrderId() : getNovalnetEscapeVar($data, 'order_id');
        $fulltag = !empty($data->getExtInfoFullTag()) ? $data->getExtInfoFullTag() : getNovalnetEscapeVar($data, 'ext_info_full_tag');
    }

    $source = getSourceType($fulltag);

    switch ($source) {
        case 'invoice':
            $object = new Facture($db);
            break;
        case 'order':
            $object = new Commande($db);
            break;
        case 'donation':
            return true;
            break;
        case 'contractline':
            $object = new ContratLigne($db);
            break;
        case 'member':
            //$object = new Adherent($db);
            return true;
            break;  
        default:
            $object = new Facture($db);
            break;
    }

    $object->fetch('', $order_id);

    if($source == 'organizedeventregistration'){
        $object->fetch($order_id);
    }

    $objectAmount = (float)$object->total_ttc;

    if ($objectAmount !== (float)$amount) {
        $alreadyPaid = verifyAlreadyPaid($source, $order_id);
        if (!$alreadyPaid) {
            setStatusNotePrivate($object, 'INVALID_AMOUNT', $order_id);
        } else { 
            // If you want to notify already payment instead of invalid amount, activate this code.
            printResultPayment($dataType, $data, $source, 'ALREADY_PAID','');
        }
        printResultPayment($dataType, $data, $source, 'INVALID_AMOUNT','');
        return false;
    }

    return true;
}

/**
 * Set field private_note on database with the status.
 */
function setStatusNotePrivate($object, $trans_status, $order_id)
{
    global $db;

    $error = 0;
    $id = $object->id;

    $db->begin();

    $note_private = $object->note_private;
    $key = '##';

    // If exists another pending status, it is eliminated.
    $pos = strripos($note_private, $key); // The last appearance is found.
    if ($pos) {
        $note_private = substr($note_private, $pos + 2);
    }

    // Generate and replace new status.
    $note_private = $key . 'STATUS=' . $trans_status . $key . $note_private;
    
    $objElement = $object->element;

    if($object->element == 'member'){
        $objElement = 'adherent';
    }

    $sql = 'UPDATE ' . MAIN_DB_PREFIX . $objElement . ' SET note_private = "' . $note_private . '" WHERE rowid = ' . $id;

    $resql = $db->query($sql);
    if (!$resql) {
        $error++;
    }

    if (!$error) {
        $db->commit();
    } else {
        $db->rollback();
    }
}

/**
 * Handles successful order payment.
 *
 * @param array $response The payment response data.
 * @param string $fulltag The full tag associated with the order.
 * @param object $user The user object.
 * @return void
 */
function onSuccessOrderPayment($response, $fulltag, $user) 
{
    global $conf, $db , $postactionmessages , $ispostactionok , $langs;

    if (empty($fulltag)) {
        $fulltag = $response['custom']['full_tag'];
    }

    $tmptag = dolExplodeIntoArray($fulltag, '.', '=');
    $object = new Commande($db);
    $result = $object->fetch((int) $tmptag['ORD']);

    if ($result) {
        $finalPaymentAmt = getFormattedAmount($response['transaction']['amount']);
        $paymentTypeId = 0;
        $paymentType = 'NOV';
        $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
        $refNo = '';
        $refId = '';

        if (isModEnabled('facture')) {
            if (!empty($finalPaymentAmt) && $paymentTypeId > 0) {
                include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
                $invoice = new Facture($db);
                $result = $invoice->createFromOrder($object, $user);

                if ($result > 0) {
                    $object->classifyBilled($user);
                    $invoice->validate($user);

                    $refNo = !empty($invoice->newref) ? $invoice->newref : $invoice->ref;
                    $refId = $invoice->id;

                    $key = '##';
                    $notePrivate = $key . 'STATUS=' . $response['transaction']['status'] . $key;
                    if(!empty($refId)) {
                        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $invoice->element . ' SET';
                        $sql .= " note_private='" . $db->escape($notePrivate) . "'";
                        $sql .= ' WHERE rowid = ' . $refId;
                        $resql = $db->query($sql);

                        if (!$resql) {
                            novalnet_syslog('private note not updated due to sql error '. $response['transaction']['order_no'] .'', '',LOG_NOTICE);
                        }
                    }
                    // Create payment line
                    if (!in_array($response['transaction']['status'], ['PENDING', 'ON_HOLD'])) {

                        createPayment($response , $user , $invoice , $finalPaymentAmt , $paymentTypeId);
                    
                    } else {
                        $postactionmessages[] = $langs->trans('NOVALNET_PAYMENT_SUCCESS_WITH_PENDING');
                        novalnet_syslog('success with Payment status pending', '',LOG_NOTICE);
                    }

                    $db->commit();

                    $orderId = getNovalnetTransactionData('order_id', $response['transaction']['order_no'],'order');
                    if (!empty($orderId)) {
                        updateNovalnetTransaction($response, $refNo, $refId, $orderId, 'order');
                    } else {
                        novalnetPaymentTransaction($response, '', $refNo, $refId);
                    }

                    updateTransactionComments($object, $response);
                    if($invoice) {
                        updateTransactionComments($invoice, $response);
                    }
                    setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
                    printResultPayment('transaction', $response, 'order', $response['transaction']['status'],'');
                } else {
                    $postactionmessages[] = $langs->trans('NOVALNET_FAILED_TO_CREATE_ORD', $response['transaction']['order_no']);
                    $ispostactionok = -1;
                    $errorMessage = 'Failed to create invoice from order ' . $response['transaction']['order_no'] . '.';
                    novalnet_syslog($errorMessage, '', LOG_ERR);
                    printResultPayment('transaction', $response, 'order', 'ERROR',$errorMessage);
                }
            } else {
                $errorMessage = 'Failed to get a valid value for "amount paid" (' . $finalPaymentAmt . ') or "payment type id" (' . $paymentTypeId . ') to record the payment of order ' . $response['transaction']['order_no'] . '. Payment may have already been recorded.';
                $ispostactionok = -1;
                novalnet_syslog($errorMessage, '', LOG_ERR);
                printResultPayment('transaction', $response, 'order', 'ERROR',$errorMessage);
            }
        } else {
            $errorMessage = 'Invoice module is not enabled so cant able to update payment for order => '.$response['transaction']['order_no'].'';
            $ispostactionok = -1;
            novalnet_syslog($errorMessage, '', LOG_ERR);
            printResultPayment('transaction', $response, 'order', 'ERROR',$errorMessage);
        }
    } else {
        $errorMessage = 'Order paid ' . $response['transaction']['order_no'] . ' was not found.';
        $ispostactionok = -1;
        novalnet_syslog($errorMessage, '', LOG_ERR);
        printResultPayment('transaction', $response, 'order', 'ERROR',$errorMessage);
    }
    sendMail($response, $object ,$tmptag['ORD']);
}

/**
 * Handles successful invoice payment.
 *
 * @param array $response The payment response data.
 * @param string $fulltag The full tag associated with the invoice.
 * @param object $user The user object.
 * @return void
 */
function onSuccessInvoicePayment($response, $fulltag, $user) 
{
    global $conf, $db, $langs , $postactionmessages , $ispostactionok;
    
    $source = getSourceType($fulltag);
    $tmptag = dolExplodeIntoArray($fulltag, '.', '='); 
    include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $object = new Facture($db);
    $ref = (int) $tmptag['INV'];
    
    if($source == 'organizedeventregistration'){
        $ref = (int) $response['transaction']['order_no'];
    }
    
    $result = $object->fetch($ref);

    if ($result) {
        $finalPaymentAmt = getFormattedAmount($response['transaction']['amount']);
        $paymentTypeId = 0;
        $paymentType = 'NOV';
        $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
     if (!in_array($response['transaction']['status'], ['PENDING', 'ON_HOLD'])) {
         
        if (!empty($finalPaymentAmt) && $paymentTypeId > 0 ) {
            $db->begin();
            if($source == 'organizedeventregistration'){
                $resultvalidate = $object->validate($user);
                    if ($resultvalidate < 0) {
                        $postactionmessages[] = 'Cannot validate invoice';
                        $ispostactionok = -1;
                        novalnet_syslog('error while validating the invoice for event registration order => "'.$response['transaction']['order_no'].'"', '',LOG_ERR);
                    }
                    $db->commit(); 
            }
            createPayment($response , $user , $object , $finalPaymentAmt , $paymentTypeId);
            if($source == 'organizedeventregistration'){
                validateAttendee($response, $tmptag, $user);
            }
         }

    } else {
        $postactionmessages[] = $langs->trans('NOVALNET_PAYMENT_SUCCESS_WITH_PENDING');
        novalnet_syslog('success with Payment status pending', '',LOG_NOTICE);
    }
        $db->commit();
            $orderId = getNovalnetTransactionData('order_id', $response['transaction']['order_no'],$source);
            if (!empty($orderId)) {
                updateNovalnetTransaction($response, '', '', $orderId, $source);
            } else {
                novalnetPaymentTransaction($response, '', '', '');
            }

            updateTransactionComments($object, $response);
            setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
            printResultPayment('transaction', $response, $source, $response['transaction']['status'],'');
    } else {
        $errorMessage = $langs->trans('NOVALNET_INVOICE_ORD_NOT_FOUND', $response['transaction']['order_no']);
        $postactionmessages[] = $errorMessage;
        $ispostactionok = -1;
        novalnet_syslog($errorMessage, '', LOG_ERR);
        printResultPayment('transaction', $response, 'order', 'ERROR',$errorMessage);
    }
    sendMail($response, $object ,$tmptag['INV']);
}

function onSuccessMemberPayment($response, $fulltag, $user) {
    global $conf, $db, $langs , $postactionmessages , $ispostactionok;
    	// Record subscription
		include_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		include_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
		include_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';
		$adht = new AdherentType($db);
		$object = new Adherent($db);

        $tmptag = dolExplodeIntoArray($fulltag, '.', '=');
        $result1 = $object->fetch((int) $tmptag['MEM']);
		$result2 = $adht->fetch($object->typeid);
        
		$defaultdelay = !empty($adht->duration_value) ? $adht->duration_value : 1;
		$defaultdelayunit = !empty($adht->duration_unit) ? $adht->duration_unit : 'y';
        if ($result1 > 0 && $result2 > 0) {
            $FinalPaymentAmt = getFormattedAmount($response['transaction']['amount']);
            $paymentTypeId = 0;
            $paymentType = 'NOV';
            $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
            if (!empty($FinalPaymentAmt) && $paymentTypeId > 0) {
                
                if (empty($adht->caneditamount)) {// If we didn't allow members to choose their membership amount (if the amount is allowed in edit mode, no need to check)
					if ($object->status == $object::STATUS_DRAFT) {		// If the member is not yet validated, we check that the amount is the same as expected.
						$typeid = $object->typeid;
						$amountbytype = $adht->amountByType(1);		// Load the array of amount per type

						// Set amount for the subscription:
						// - First check the amount of the member type.
						$amountexpected = empty($amountbytype[$typeid]) ? 0 : $amountbytype[$typeid];
						// - If not found, take the default amount
						if (empty($amountexpected) && getDolGlobalString('MEMBER_NEWFORM_AMOUNT')) {
							$amountexpected = getDolGlobalString('MEMBER_NEWFORM_AMOUNT');
						}

						$amountexpected = max(0, (float) $amountexpected, (float) getDolGlobalInt("MEMBER_MIN_AMOUNT"));

						if ($amountexpected && $amountexpected != $FinalPaymentAmt) {
							$errmsg = 'Value of FinalPayment ('.$FinalPaymentAmt.') propagated by payment page differs from the expected value for membership ('.$amountexpected.'). May be a hack to try to pay a different amount ?';
							$postactionmessages[] = $errmsg;
							$ispostactionok = -1;
                            novalnet_syslog("Failed to validate member (bad amount check): ".$errmsg, '', LOG_ERR);
						}
					}
				}
            
                // Security protection:
				if (getDolGlobalInt('MEMBER_MIN_AMOUNT')) {
					if ($FinalPaymentAmt < getDolGlobalInt('MEMBER_MIN_AMOUNT')) {
						$errmsg = 'Value of FinalPayment ('.$FinalPaymentAmt.') is lower than the minimum allowed (' . getDolGlobalString('MEMBER_MIN_AMOUNT').'). May be a hack to try to pay a different amount ?';
						$postactionmessages[] = $errmsg;
						$ispostactionok = -1;
                        novalnet_syslog("Failed to validate member (amount propagated from payment page is lower than allowed minimum): ".$errmsg, '', LOG_ERR);
					}
				}

				// Security protection:
				if ($currencyCodeType && $currencyCodeType != $conf->currency) {	// Check that currency is the good one
					$errmsg = 'Value of currencyCodeType ('.$currencyCodeType.') differs from value expected for membership ('.$conf->currency.'). May be a hack to try to pay a different amount ?';
					$postactionmessages[] = $errmsg;
					$ispostactionok = -1;
					novalnet_syslog("Failed to validate member (bad currency check): ".$errmsg,'',LOG_ERR);
				}

                // We validate the member (no effect if it is already validated)
                $result = ($object->status == $object::STATUS_EXCLUDED) ? -1 : $object->validate($user); // if membre is excluded (status == -2) the new validation is not possible
                if ($result < 0 || empty($object->datevalid)) {
                    $errmsg = $object->error;
                    $postactionmessages[] = $errmsg;
                    $postactionmessages = array_merge($postactionmessages, $object->errors);
                    $ispostactionok = -1;
                    novalnet_syslog("Failed to validate member: ".$errmsg,'',LOG_ERR);
                }

                // Guess the subscription start date
				$datesubscription = $object->datevalid;// By default, the subscription start date is the payment date
				if ($object->datefin > 0) {
					$datesubscription = dol_time_plus_duree($object->datefin, 1, 'd');
				} elseif (getDolGlobalString('MEMBER_SUBSCRIPTION_START_AFTER')) {
					$datesubscription = dol_time_plus_duree($now, (int) substr(getDolGlobalString('MEMBER_SUBSCRIPTION_START_AFTER'), 0, -1), substr(getDolGlobalString('MEMBER_SUBSCRIPTION_START_AFTER'), -1));
				}
				// Now do a correction of the suggested date
				if (getDolGlobalString('MEMBER_SUBSCRIPTION_START_FIRST_DAY_OF') === "m") {
					$datesubscription = dol_get_first_day(dol_print_date($datesubscription, "%Y"), dol_print_date($datesubscription, "%m"));
				} elseif (getDolGlobalString('MEMBER_SUBSCRIPTION_START_FIRST_DAY_OF') === "Y") {
					$datesubscription = dol_get_first_day(dol_print_date($datesubscription, "%Y"));
				}
                

                $datesubend = null;
				if ($datesubscription && $defaultdelay && $defaultdelayunit) { 
					$datesubend = dol_time_plus_duree($datesubscription, $defaultdelay, $defaultdelayunit);
					// the new end date of subscription must be in futur
					while ($datesubend < $now) {
						$datesubend = dol_time_plus_duree($datesubend, $defaultdelay, $defaultdelayunit);
						$datesubscription = dol_time_plus_duree($datesubscription, $defaultdelay, $defaultdelayunit);
					}
					$datesubend = dol_time_plus_duree($datesubend, -1, 'd');
				}
               
				// Set output language
				$outputlangs = new Translate('', $conf);
				$outputlangs->setDefaultLang(empty($object->thirdparty->default_lang) ? $mysoc->default_lang : $object->thirdparty->default_lang);
				$paymentdate = dol_now();
				$amount = $FinalPaymentAmt;
				$formatteddate = dol_print_date($paymentdate, 'dayhour', 'auto', $outputlangs);
				$label = $langs->trans("OnlineSubscriptionPaymentLine", $formatteddate, 'novalnet', '', $response['transaction']['tid']);

                $accountid = !empty($conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS) ? $conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS : 0;
                
                if ($accountid < 0) {
					$errmsg = 'Setup of bank account to use for payment is not correctly done for payment method novalnet';
					$postactionmessages[] = $errmsg;
					$ispostactionok = -1;
					novalnet_syslog("Failed to get the bank account to record payment: ".$errmsg,'',LOG_ERR);
				}

                $operation = dol_getIdFromCode($db, $paymentTypeId, 'c_paiement', 'id', 'code', 1);// Payment mode code returned from payment mode id
				// Define default choice for complementary actions
				$option = '';
				if (getDolGlobalString('ADHERENT_BANK_USE') == 'bankviainvoice' && isModEnabled("bank") && isModEnabled("societe") && isModEnabled('invoice')) {
					$option = 'bankviainvoice';
				} elseif (getDolGlobalString('ADHERENT_BANK_USE') == 'bankdirect' && isModEnabled("bank")) {
					$option = 'bankdirect';
				} elseif (getDolGlobalString('ADHERENT_BANK_USE') == 'invoiceonly' && isModEnabled("bank") && isModEnabled("societe") && isModEnabled('invoice')) {
					$option = 'invoiceonly';
				}
				if (empty($option) && isModEnabled("bank") && isModEnabled("societe") && isModEnabled('invoice')) {
					$option = 'bankviainvoice';
				}
            
				$sendalsoemail = 1;

                $service = 'Test mode';
                if($response['transaction']['test_mode'] != 1) {
                    $service = 'Live';
                }

                $orderId = getNovalnetTransactionData('order_id', $response['transaction']['order_no'],'member');
                $nnTid = getNovalnetTransactionData('tid', $response['transaction']['order_no'],'member');

                if(in_array($response['transaction']['status'], ['PENDING', 'ON_HOLD'])) {
                   novalnetSubscription($response['transaction']['order_no'], $datesubscription, $amount, $datesubend, $object->typeid, $response['transaction']['status']);
                   
                   if (!empty($orderId) && empty($nnTid)) {
                       updateNovalnetTransaction($response, '', '', $orderId, 'member');
                   } else {
                       novalnetPaymentTransaction($response, '', '', '');
                   }
       
                   updateTransactionComments($object, $response);
                   setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
                   printResultPayment('transaction', $response, 'invoice', $response['transaction']['status'],'');
                   exit;
                } 
                // Record the subscription then complementary actions
				$db->begin();

				// Create subscription
					novalnet_syslog("Call ->subscription to create subscription",'',LOG_DEBUG);
                    $error = 0;
					$crowid = $object->subscription($datesubscription, $amount, $accountid, $operation, $label, '', '', '', $datesubend, $object->typeid);
                    if ($crowid <= 0) {
						$error++;
						$errmsg = $object->error;
						$postactionmessages[] = $errmsg;
						$ispostactionok = -1;
					} else {
                        $db->commit();
						$postactionmessages[] = 'Subscription created (id='.$crowid.')';
						$ispostactionok = 1;
					}
                
                if (!$error) {
					novalnet_syslog("Call ->subscriptionComplementaryActions option=".$option,'',LOG_DEBUG);

					$autocreatethirdparty = 1; // will create thirdparty if member not yet linked to a thirdparty

					$result = $object->subscriptionComplementaryActions($crowid, $option, $accountid, $datesubscription, $paymentdate, $operation, $label, $amount, '', '', '', $autocreatethirdparty, $response['transaction']['tid'], $service);
                    if ($result < 0) {
						novalnet_syslog("Error ".$object->error." ".implode(',', $object->errors),'',LOG_DEBUG);

						$error++;
						$postactionmessages[] = $object->error;
						$postactionmessages = array_merge($postactionmessages, $object->errors);
						$ispostactionok = -1;
					} else {
						if ($option == 'bankviainvoice') {
							$postactionmessages[] = 'Invoice, payment and bank record created';
							novalnet_syslog("Invoice, payment and bank record created",'',LOG_DEBUG);
						}
						if ($option == 'bankdirect') {
							$postactionmessages[] = 'Bank record created';
							novalnet_syslog("Bank record created",'',LOG_DEBUG);
						}
						if ($option == 'invoiceonly') {
							$postactionmessages[] = 'Invoice recorded';
							novalnet_syslog("Invoice recorded",'',LOG_DEBUG);
						}
						$ispostactionok = 1;
						// If an invoice was created, it is into $object->invoice
					}
				}

                if (!$error) {
					$db->commit();
				} else {
					$db->rollback();
				}

				// Set string to use to send email info
				$infouserlogin = '';

				// Create external user
				if (getDolGlobalString('ADHERENT_CREATE_EXTERNAL_USER_LOGIN')) {
					$nuser = new User($db);
					$tmpuser = dol_clone($object, 0);		// $object is type Adherent
					// Check if a user login already exists for this member or not
					$found = 0;
					$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."user WHERE fk_member = ".((int) $object->id);
					$resqlcount = $db->query($sql);
					if ($resqlcount) {
						$objcount = $db->fetch_object($resqlcount);
						if ($objcount) {
							$found = $objcount->nb;
						}
					}

					if (!$found) {
						$result = $nuser->create_from_member($tmpuser, $object->login);
						$newpassword = $nuser->setPassword($user, '');

						if ($result < 0) {
							$outputlangs->load("errors");
							$postactionmessages[] = 'Error in create external user : '.$nuser->error;
						} else {
							$infouserlogin = $outputlangs->trans("Login").': '.$nuser->login.' '."\n".$outputlangs->trans("Password").': '.$newpassword;
							$postactionmessages[] = $langs->trans("NewUserCreated", $nuser->login);
						}
					} else {
						$outputlangs->load("errors");
						$postactionmessages[] = 'No user created because a user linked to member already exists';
					}
				}

                if (!$error) {
					novalnet_syslog("Send email to customer to ".$object->email." if we have to (sendalsoemail = ".$sendalsoemail.")",'',LOG_DEBUG);

					// Send confirmation Email
					if ($object->email && $sendalsoemail) {
						$subject = '';
						$msg = '';

						// Send subscription email
						include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
						$formmail = new FormMail($db);
						// Load traductions files required by page
						$outputlangs->loadLangs(array("main", "members"));
						// Get email content from template
						$arraydefaultmessage = null;
						$labeltouse = getDolGlobalString('ADHERENT_EMAIL_TEMPLATE_SUBSCRIPTION');

						if (!empty($labeltouse)) {
							$arraydefaultmessage = $formmail->getEMailTemplate($db, 'member', $user, $outputlangs, 0, 1, $labeltouse);
						}

						if (!empty($labeltouse) && is_object($arraydefaultmessage) && $arraydefaultmessage->id > 0) {
							$subject = $arraydefaultmessage->topic;
							$msg     = $arraydefaultmessage->content;
						}

						$substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $object);

						if ($infouserlogin) {
							$substitutionarray['__MEMBER_USER_LOGIN_INFORMATION__'] = $infouserlogin;
						}

						complete_substitutions_array($substitutionarray, $outputlangs, $object);
						$subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
						$texttosend = make_substitutions(dol_concatdesc($msg, $adht->getMailOnSubscription()), $substitutionarray, $outputlangs);

						// Attach a file ?
						$file = '';
						$listofpaths = array();
						$listofnames = array();
						$listofmimes = array();
						if (is_object($object->invoice)) {
							$invoicediroutput = $conf->facture->dir_output;
							$fileparams = dol_most_recent_file($invoicediroutput.'/'.$object->invoice->ref, preg_quote($object->invoice->ref, '/').'[^\-]+');
							$file = $fileparams['fullname'];

							$listofpaths = array($file);
							$listofnames = array(basename($file));
							$listofmimes = array(dol_mimetype($file));
						}

						$moreinheader = 'X-Dolibarr-Info: send_an_email by public/payment/paymentok.php'."\r\n";

						$result = $object->sendEmail($texttosend, $subjecttosend, $listofpaths, $listofmimes, $listofnames, "", "", 0, -1, "", $moreinheader);

						if ($result < 0) {
							$errmsg = $object->error;
							$postactionmessages[] = $errmsg;
							$ispostactionok = -1;
						} else {
							if ($file) {
								$postactionmessages[] = 'Email sent to member (with invoice document attached)';
                                novalnet_syslog("Email sent to member (with invoice document attached)",'',LOG_NOTICE);
							} else {
								$postactionmessages[] = 'Email sent to member (without any attached document)';
                                novalnet_syslog("Email sent to member (without any attached document)",'',LOG_NOTICE);
							}
							// TODO Add actioncomm event
						}
					}

                    $db->commit();
                    // $sub_count = (int) count($object->subscriptions) -1;
                    // $next_sub_id = $object->subscriptions[$sub_count]->id +1;
                    $sub_id = $object->invoice->linked_objects['subscription'];
                    if (!empty($orderId) && empty($nnTid)) {
                        updateNovalnetTransaction($response, '', '', $orderId, 'member', $sub_id);
                    } else {
                        novalnetPaymentTransaction($response, '', '', '', $sub_id);
                    }
                    novalnetSubscription($response['transaction']['order_no'], $datesubscription, $amount, $datesubend, $object->typeid, $response['transaction']['status'], $sub_id, $object->invoice->ref, $object->invoice->id);
                    updateTransactionComments($object, $response, $datesubend);
                    setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
                    printResultPayment('transaction', $response, 'invoice', $response['transaction']['status'],'');
                    sendMail($response, $object ,$tmptag['MEM']);
				}

            } else {
                novalnet_syslog('Failed to get a valid value for "amount paid" or "payment type" to record the payment of subscription for member '.$tmptag['MEM'].'. May be payment was already recorded.','',LOG_ERR);
				$postactionmessages[] = 'Failed to get a valid value for "amount paid" or "payment type" to record the payment of subscription for member '.$tmptag['MEM'].'. May be payment was already recorded.';
				$ispostactionok = -1;
			}
        } else {
            novalnet_syslog('Member '.$tmptag['MEM'].' for subscription paid was not found','',LOG_ERR);
			$postactionmessages[] = 'Member '.$tmptag['MEM'].' for subscription paid was not found';
			$ispostactionok = -1;
		}
}

function onSuccessDonationPayment($response, $fulltag, $user) {
    global $conf, $db, $langs , $postactionmessages , $ispostactionok;
    include_once DOL_DOCUMENT_ROOT.'/don/class/don.class.php';
        $tmptag = dolExplodeIntoArray($fulltag, '.', '=');
		$object = new Don($db);
		$result = $object->fetch((int) $tmptag['DON']);

		if ($result) {
            $finalPaymentAmt = getFormattedAmount($response['transaction']['amount']);
            $paymentTypeId = 0;
            $paymentType = 'NOV';
            $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
            if (!in_array($response['transaction']['status'], ['PENDING', 'ON_HOLD'])) {
                if (!empty($finalPaymentAmt) && $paymentTypeId > 0 ) {
                    $db->begin();
                    createPayment($response , $user , $object , $finalPaymentAmt , $paymentTypeId);
                }
            } else {
                $postactionmessages[] = $langs->trans('NOVALNET_PAYMENT_SUCCESS_WITH_PENDING');
                novalnet_syslog('success with Payment status pending', '',LOG_NOTICE);
            }
            $db->commit();
            $orderId = getNovalnetTransactionData('order_id', $response['transaction']['order_no'],'donation');
            if (!empty($orderId)) {
                updateNovalnetTransaction($response, '', '', $orderId, 'donation');
            } else {
                novalnetPaymentTransaction($response, '', '', '');
            }

            updateTransactionComments($object, $response);
            setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
            printResultPayment('transaction', $response, 'donation', $response['transaction']['status'],'');
            sendMail($response, $object ,$tmptag['MEM']);
        } else {
			$postactionmessages[] = 'Donation paid '.$tmptag['DON'].' was not found';
			$ispostactionok = -1;
		}

}

/**
 * Sends an email regarding the payment status.
 *
 * @param array $response The payment response data.
 * @param object $object The object associated with the payment.
 * @return void
 */
function sendMail($response, $object ,$objectId) 
{
    global $conf, $langs, $db, $mysoc, $appli, $postactionmessages, $ispostactionok;
    $error = 0;

    $fulltag = $response['custom']['full_tag'];
    $paymentTitile = getPaymentTitile($response['transaction']['payment_type']);
    // Determine whether to send email to admins
    $sendemail = getDolGlobalString('NOVALNET_ONLINE_PAYMENT_SENDEMAIL');
    $tmptag = dolExplodeIntoArray($fulltag, '.', '=');
    //dol_syslog("Send email to admins if we have to (sendemail = " . $sendemail . ")", LOG_DEBUG, 0, '_payment');

    if ($sendemail) {
        // Prepare language and email content
        $companylangs = new Translate('', $conf);
        $companylangs->setDefaultLang($mysoc->default_lang);
        $companylangs->loadLangs(array('main', 'members', 'bills', 'novalnet' , 'NOVALNET'));

        $sendto = $sendemail;
        $from = getDolGlobalString('MAILING_EMAIL_FROM') ? $conf->global->MAILING_EMAIL_FROM : getDolGlobalString("MAIN_MAIL_EMAIL_FROM");

        // Define URL for the application
        $urlwithroot = DOL_MAIN_URL_ROOT;

        // Set URL to the current page
        $urlback = $_SERVER["REQUEST_URI"];
        $topic = $companylangs->transnoentitiesnoconv("NewOnlinePaymentReceived");
        $content = "";

        // Prepare content based on object type
        if ($objectId) {
            //$url = $urlwithroot . "/compta/facture/card.php?id=" . ((int) $object->id);
            $content .= '<strong>' . $companylangs->trans("Payment") . "</strong><br><br>\n";
            $content .= $companylangs->trans("InvoiceId") . ': <strong>' . $objectId . "</strong><br>\n";
            //$content .= $companylangs->trans("Link") . ': <a href="' . $url . '">' . $url . '</a>' . "<br>\n";
        } else {
            $content .= $companylangs->transnoentitiesnoconv("NewOnlinePaymentReceived") . "<br>\n";
        }

        $content .= '<br>' . "\n";

        $content .= $companylangs->transnoentities("PostActionAfterPayment").' : ';
		if ($ispostactionok > 0) {
			//$topic.=' ('.$companylangs->transnoentitiesnoconv("Status").' '.$companylangs->transnoentitiesnoconv("OK").')';
			$content .= '<span style="color: green">'.$companylangs->transnoentitiesnoconv("OK").'</span>';
		} elseif ($ispostactionok == 0) {
			$content .= $companylangs->transnoentitiesnoconv("None");
		} else {
			$topic .= ($ispostactionok ? '' : ' ('.$companylangs->trans("WarningPostActionErrorAfterPayment").')');
			$content .= '<span class="star">'.$companylangs->transnoentitiesnoconv("Error").'</span>';
		}
		$content .= '<br>'."\n";

        // Add technical information to email content
        $content .= "<br>\n";
        $content .= '<u>' . $companylangs->transnoentitiesnoconv("TechnicalInformation") . ":</u><br>\n";
        $content .= $companylangs->transnoentitiesnoconv("PaymentMethod") . ': <strong>' . 'NOVALNET' . "</strong><br>\n";
        $content .= $companylangs->transnoentitiesnoconv("TransactionId") . ': <strong>' . $response['transaction']['tid'] . "</strong><br>\n";
        $content .= $companylangs->transnoentitiesnoconv("ReturnURLAfterPayment") . ': ' . $urlback . "<br>\n";
        $content .= $companylangs->transnoentitiesnoconv("TransactionStatus") . ': ' .$response['transaction']['status']. "<br>\n";
        $content .= "<br>\n";
        $content .= "tag=" . $fulltag . "<br>\npaymentType=" . $paymentTitile . "<br>\ncurrencycodeType=" . $response['transaction']['currency'] . "<br>\nipaddress=" . getUserRemoteIP() . "<br>\nFinalPaymentAmt=" . getFormattedAmount($response['transaction']['amount']) . "<br>\n";
        
        if(!empty($postactionmessages)) {
            foreach ($postactionmessages as $postactionmessage) {
                $content .= '<br>'."\n";
                $content .= ' * '.$postactionmessage.'<br>'."\n";
            }
        }
        
        // Send the email
        $ishtml = dol_textishtml($content);
        $trackid = '';

        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        try {
            $mailfile = new CMailFile($topic, $sendto, $from, $content, array(), array(), array(), '', '', 0, $ishtml, '', '', $trackid, '', 'standard');

            $result = $mailfile->sendfile();
            if ($result) {
                novalnet_syslog("EMail sent to " . $sendto ." for order no => ".$response['transaction']['order_no']."", '', LOG_NOTICE);
            } else {
                novalnet_syslog("Failed to send EMail to " . $sendto." for order no => ".$response['transaction']['order_no']."", '', LOG_ERR);
            }
        }catch(\Exception $e) {
            $this->novalnet_syslog("Error sending mail for the order => ".$response['transaction']['order_no']." error due to " . $e, '', LOG_ERR);
        }
    }
}
function validateAttendee($response, $tmptag, $user) {
    global $conf, $db, $langs , $postactionmessages , $ispostactionok;

    require_once DOL_DOCUMENT_ROOT.'/eventorganization/class/conferenceorboothattendee.class.php';
    require_once DOL_DOCUMENT_ROOT.'/eventorganization/class/conferenceorbooth.class.php';
    $attendeetovalidate = new ConferenceOrBoothAttendee($db);
    // Validating the attendee
    $resultattendee = $attendeetovalidate->fetch((int) $tmptag['ATT']);
    if ($resultattendee < 0) {
        novalnet_syslog('Error while validate the attendee : "'.$attendeetovalidate->errors.'" for the order no "'.$response['transaction']['order_no'].'"', '', LOG_ERR);
        setEventMessages(null, $attendeetovalidate->errors, "errors");
    } else {
        $attendeetovalidate->validate($user);

        $attendeetovalidate->amount = getFormattedAmount($response['transaction']['amount']);
        $attendeetovalidate->date_subscription = dol_now();
        $paymentTitile = getPaymentTitile($response['transaction']['payment_type']);
        $note_public  = 'Payment Method: ' . $paymentTitile . "\n";
        $note_public .= 'Novalnet Transaction ID: ' . $response['transaction']['tid']."\n";
        $attendeetovalidate->note_public = $note_public;
        $attendeetovalidate->note_private = '##PAYMENT_STATUS="'.$response['transaction']['status'].'"##';
        $attendeetovalidate->update($user);
    }

    $db->commit();
    // Sending mail
    $thirdparty = new Societe($db);
    $resultthirdparty = $thirdparty->fetch($attendeetovalidate->fk_soc);
    if ($resultthirdparty < 0) {
        novalnet_syslog('Error while fetch the thirdparty : "'.$resultthirdparty->error.'" for the order no "'.$response['transaction']['order_no'].'"', '', LOG_ERR);
        setEventMessages($resultthirdparty->error, $resultthirdparty->errors, "errors");
    } else {
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
        $formmail = new FormMail($db);
        // Set output language
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang(empty($thirdparty->default_lang) ? $mysoc->default_lang : $thirdparty->default_lang);
        // Load traductions files required by page
        $outputlangs->loadLangs(array("main", "members", "eventorganization"));
        // Get email content from template
        $arraydefaultmessage = null;

        $idoftemplatetouse = getDolGlobalString('EVENTORGANIZATION_TEMPLATE_EMAIL_AFT_SUBS_EVENT');	// Email to send for Event organization registration

        if (!empty($idoftemplatetouse)) {
            $arraydefaultmessage = $formmail->getEMailTemplate($db, 'conferenceorbooth', $user, $outputlangs, $idoftemplatetouse, 1, '');
        }

        if (!empty($idoftemplatetouse) && is_object($arraydefaultmessage) && $arraydefaultmessage->id > 0) {
            $subject = $arraydefaultmessage->topic;
            $msg     = $arraydefaultmessage->content;
        } else {
            $subject = '['.$appli.'] '.$object->ref.' - '.$outputlangs->trans("NewRegistration");
            $msg = $outputlangs->trans("OrganizationEventPaymentOfRegistrationWasReceived");
        }

        $substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $thirdparty);
        complete_substitutions_array($substitutionarray, $outputlangs, $object);

        $subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
        $texttosend = make_substitutions($msg, $substitutionarray, $outputlangs);

        $sendto = $attendeetovalidate->email;
        $cc = '';
        if ($thirdparty->email) {
            $cc = $thirdparty->email;
        }
        if ($attendeetovalidate->email_company && $attendeetovalidate->email_company != $thirdparty->email) {
            $cc = ($cc ? ', ' : '').$attendeetovalidate->email_company;
        }

        $from = getDolGlobalString('MAILING_EMAIL_FROM') ? $conf->global->MAILING_EMAIL_FROM : getDolGlobalString("MAIN_MAIL_EMAIL_FROM");

        $urlback = $_SERVER["REQUEST_URI"];

        $ishtml = dol_textishtml($texttosend); // May contain urls

        // Attach a file ?
        $file = '';
        $listofpaths = array();
        $listofnames = array();
        $listofmimes = array();
        if (is_object($object)) {
            $invoicediroutput = $conf->facture->dir_output;
            $fileparams = dol_most_recent_file($invoicediroutput.'/'.$object->ref, preg_quote($object->ref, '/').'[^\-]+');
            $file = $fileparams['fullname'];

            $listofpaths = array($file);
            $listofnames = array(basename($file));
            $listofmimes = array(dol_mimetype($file));
        }

        $trackid = 'inv'.$object->id;

        $mailfile = new CMailFile($subjecttosend, $sendto, $from, $texttosend, $listofpaths, $listofmimes, $listofnames, $cc, '', 0, $ishtml, '', '', $trackid, '', 'standard');

        $result = $mailfile->sendfile();
        if ($result) {
            dol_syslog("EMail sent to ".$sendto, LOG_DEBUG, 0, '_payment');
        } else {
            dol_syslog("Failed to send EMail to ".$sendto.' - '.$mailfile->error, LOG_ERR, 0, '_payment');
        }
    }
}

/**
 * Handles Novalnet transaction insertion.
 *
 * @param array $responseData The response data from the Novalnet payment gateway.
 * @param object $orderData The order object associated with the payment.
 * @param string $refNo The reference number for the transaction.
 * @param string $refId The reference ID for the transaction.
 * @return void
 */
function novalnetPaymentTransaction($responseData, $orderData, $refNo, $refId,$subscription_id = '') 
{
    global $db;
    $error = 0;
    // Retrieve order information
    if (!empty($orderData)) {
        $fulltag = !empty($orderData->getExtInfoFullTag()) ? $orderData->getExtInfoFullTag() : getNovalnetEscapeVar($orderData, 'ext_info_full_tag');
        $amount = !empty($orderData->getRawAmount()) ? $orderData->getRawAmount() : getNovalnetEscapeVar($orderData, 'raw_amount');
        $order_id = !empty($orderData->getOrderId()) ? $orderData->getOrderId() : getNovalnetEscapeVar($orderData, 'order_id');
    } else {
        $fulltag = $responseData['custom']['full_tag'];
        $order_id = !empty($responseData['transaction']['order_no']) ? $responseData['transaction']['order_no'] : '';
    }

    $source = getSourceType($fulltag);
    $nextid = getNextId('novalnet_payment_transaction');

    // SQL query to insert transaction data into the database
    $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction (rowid, order_id, ref_no, ref_id, source, tid, amount, payment_status, customer_id, payment_method, tnx_secret, subscription_id)';
    $sql .= ' VALUES (' . $nextid . ", '" . $db->escape(!empty($responseData['transaction']['order_no']) ? $responseData['transaction']['order_no'] : $order_id) . "',  '" . $db->escape(!empty($refNo) ? $refNo : '') . "', '" . $db->escape(!empty($refId) ? $refId : '') . "','" . $db->escape(!empty($source) ? $source : '') . "','" . $db->escape(!empty($responseData['transaction']['tid']) ? $responseData['transaction']['tid'] : '') . "', '" . $db->escape(!empty($responseData['transaction']['amount']) ? getFormattedAmount($responseData['transaction']['amount']) : $amount) . "','" . $db->escape(!empty($responseData['transaction']['status']) ? $responseData['transaction']['status'] : '') . "', '" . $db->escape(!empty($responseData['customer']['customer_no']) ? $responseData['customer']['customer_no'] : '') . "', '" . $db->escape(!empty($responseData['transaction']['payment_type']) ? $responseData['transaction']['payment_type'] : '') . "', '" . $db->escape(!empty($responseData['transaction']['txn_secret']) ? $responseData['transaction']['txn_secret'] : '') . "', '" . $db->escape($subscription_id) . "')";

    $resql = $db->query($sql);
    if (!$resql) {
        $error++;
    }

    if (!$error) {
        $db->commit();
    } else {
        $db->rollback();
    }
}

function novalnetSubscription($order_id, $datesubscription, $amount, $datesubend, $member_typeid, $payment_status, $sub_id = '', $invoice_no = '', $invoice_ref = '') 
{
    global $db;
    $error = 0;

    $nextid = getNextId('novalnet_subscription');

    // SQL query to insert transaction data into the database
    $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'novalnet_subscription (rowid, order_id, datesubscription, amount, datesubend, member_typeid, payment_status, subscription_id, invoice, invoice_ref)';
    $sql .= ' VALUES (' . $nextid . ", '" . $db->escape($order_id) . "',  '" . $db->escape($datesubscription) . "', '" . $db->escape($amount) . "','" . $db->escape($datesubend) . "','" . $db->escape($member_typeid) . "', '" . $db->escape($payment_status) . "','" . $db->escape($sub_id) . "','" . $db->escape($invoice_no) . "','" . $db->escape($invoice_ref) . "')";

    $resql = $db->query($sql);
    if (!$resql) {
        $error++;
    }

    if (!$error) {
        $db->commit();
    } else {
        $db->rollback();
    }
}

/**
 * Updates Novalnet transaction information.
 *
 * @param array $response The response data from the Novalnet payment gateway.
 * @param string $ref_no The reference number for the transaction.
 * @param string $ref_id The reference ID for the transaction.
 * @param string $order_id The order ID associated with the payment.
 * @return void
 */
function updateNovalnetTransaction($response, $ref_no, $ref_id, $order_id, $source, $subscription_id = '') 
{
    global $db;
    $error = 0;
    // Update transaction data in the database
    $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction SET ';
    if(isset($response['transaction']['txn_secret']) && !empty($response['transaction']['txn_secret'])) {
        $sql .= 'tnx_secret = "' . $db->escape($response['transaction']['txn_secret']) . '"';
        $sql .= ', payment_method = "' . $db->escape($response['transaction']['payment_type']) . '"';
        
        if(isset($ref_no) && !empty($ref_no)) {
            $sql .= ', ref_no = "' . $db->escape($ref_no) . '"';
        }

        if(isset($ref_id) && !empty($ref_id)) {
            $sql .= ', ref_id = "' . $db->escape($ref_id) . '"';
        }

        if(isset($subscription_id) && !empty($subscription_id)) {
            $sql .= ', subscription_id = "' . $db->escape($subscription_id) . '"';
        }

        if(isset($response['transaction']['tid'])) {
            $sql .= ', tid = "' . $db->escape($response['transaction']['tid']) . '"';
        }
        
        if(isset($response['transaction']['status'])) {
            $sql .= ', payment_status = "' . $db->escape($response['transaction']['status']) . '"';
        }
        
        if(isset($response['customer']['customer_no']) && !empty($response['customer']['customer_no'])) {
             $sql .= ', customer_id = "' . $db->escape($response['customer']['customer_no']) . '" ';
        }

    } else {
        $sql .= 'ref_no = "' . $db->escape($ref_no) . '", ';
        $sql .= 'ref_id = "' . $db->escape($ref_id) . '", ';
        $sql .= 'tid = "' . $db->escape($response['transaction']['tid']) . '", ';
        $sql .= 'payment_status = "' . $db->escape($response['transaction']['status']) . '", ';
        $sql .= 'customer_id = "' . $db->escape($response['customer']['customer_no']) . '" ';
    } 
    $sql .= ' WHERE order_id = "' . $db->escape($order_id) . '" AND source = "'.$source.'"';

    if($source == 'member'){
        $sql .= ' AND subscription_id = ""';
    }
    
    $resql = $db->query($sql);
    if (!$resql) {
        $error++;
    }

    if (!$error) {
        $db->commit();
    } else {
        $db->rollback();
    }
}

/**
 * Returns the next available ID for inserting a new transaction into the database.
 *
 * @return int The next available transaction ID.
 */
function getNextId($table) 
{
    global $db;
    $newid = 0;

    // Get the maximum row ID and increment it
    $sql = "SELECT MAX(rowid) newid FROM " . MAIN_DB_PREFIX . $table;
    $result = $db->query($sql);
    if ($result) {
        $obj = $db->fetch_object($result);
        $newid = ($obj->newid + 1);
    } else {
        dol_print_error($db);
        return -1;
    }
    return $newid;
}

/**
 * Fetches specific data from a Novalnet transaction based on the order ID.
 *
 * @param string $field The field to fetch.
 * @param string $order_id The order ID associated with the transaction.
 * @return mixed The value of the specified field or null if not found.
 */
function getNovalnetTransactionData($field, $orderNo, $source) 
{
    global $db;

    // SQL query to fetch data from Novalnet transaction table
    $sql = 'SELECT ' . $field . ' FROM ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction WHERE order_id = "' . $db->escape($orderNo) . '" AND source = "'.$source.'"';
    
    if($source == 'member'){
        $sql .= 'AND subscription_id = ""';
    }

    $result = $db->query($sql);
    
    if ($result) {
        $nbrows = $db->num_rows($result);
        if ($nbrows == 1) {
            $obj = $db->fetch_object($result);
            return $obj->$field;
        } elseif ($nbrows > 1) {
            novalnet_syslog('More than one row is getting', '', LOG_ERR);
            return null;
        }
    }

    return null;
}
    /**
     * Validate checksum in response
     * @param $responseData redirect response data.
     * @return bool
     */
function validateChecksum($responseData , $orderNo, $source)
{
    global $conf;
    
    $nNTxnSecret = $responseData['txn_secret'];
   
    if($source != 'organizedeventregistration' && $source != 'member' && $source != 'donation'){
       $nNTxnSecret = getNovalnetTransactionData('tnx_secret', $orderNo, $source);
    }

    $accessKey = (!empty($conf->global->NOVALNET_PAYMENT_KEY)) ? trim($conf->global->NOVALNET_PAYMENT_KEY) : '';
    $checksumString = $responseData['tid'] . $nNTxnSecret . $responseData['status']
        . strrev($accessKey);
    $generatedChecksum = (!empty($checksumString)) ? hash('sha256', $checksumString) : '';
    if ($generatedChecksum !== $responseData['checksum']) {
        return false;
    }

    return true;
}

/**
 * To convert amounts in cents 
 * @param int $amount contain amounts in cents
 */
function getFormattedAmount($amount) 
{
    $convertedAmount = $amount / 100;
    $formattedAmount = number_format($convertedAmount, 2);
    return $formattedAmount;
}

/**
 * To create payment for the invoice
 * @param array $response payment transaction response 
 * @param string $user user permission to perfrom action
 * @param object $object object either inoive or order object presents 
 * @param int $finalPaymentAmt amoun to create payment
 * @param string $paymentTypeId payment id
 */
function createPayment($response , $user ,$object ,$finalPaymentAmt , $paymentTypeId) {

    global $db , $postactionmessages , $ispostactionok , $conf , $langs;
        
    $error = 0;
    $fulltag = $response['custom']['full_tag'];
    $source = getSourceType($fulltag);
    require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
    $paiement = new Paiement($db);
    if($source == 'donation'){
        $paiement = new PaymentDonation($db);
        $paiement->fk_donation = $object->id;
        $paiement->datep = dol_now();
        $paiement->paymenttype = $paymentTypeId;
    }
    $paiement->datepaye = dol_now();
    
    if ($response['transaction']['currency'] == $conf->currency) {
        $paiement->amounts = array($object->id => $finalPaymentAmt); // Array with all payments dispatching with invoice id
    } else {
        $paiement->multicurrency_amounts = array($object->id => $finalPaymentAmt); // Array with all payments dispatching
        $postactionmessages[] = $langs->trans('NOVALNET_PAYMENT_DONE_WITH_DIFF_CURRENCY', $response['transaction']['currency'] , $conf->currency );
        $ispostactionok = -1;
        novalnet_syslog('Payment was done in a different currency than the currency expected by the company for order => '.$response['transaction']['order_no'].'', '', LOG_NOTICE);
    }
    
    $paiement->paiementid = $paymentTypeId;
    $paiement->num_payment = '';
    $paiement->note_public = '';
    $paiement->ext_payment_id = $response['transaction']['tid']; // Transaction ID from the payment gateway
    $paiement->ext_payment_site = ''; // Payment site, e.g. 'novalnet'

    $paiementId = $paiement->create($user, 1);
    if ($paiementId < 0) {
        $postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
        $ispostactionok = -1;
        novalnet_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors).' for order '.$response['transaction']['order_no'].'', '', LOG_ERR);
        $error++;
    } else {
        if ($source == 'donation' && $finalPaymentAmt >= $object->getRemainToPay()) {
                            $object->setPaid($object->id);
        }
        $postactionmessages[] = $langs->trans('NOVALNET_PAYMENT_INIT_CREATED');
        $ispostactionok = 1;
        novalnet_syslog('Payment created for ID => '.$response['transaction']['order_no'].' with TID'.$response['transaction']['tid'].'', '', LOG_NOTICE);
    }
    
    if (!$error && isModEnabled("banque")) {
        addPaymentToBank($paiement , $response , $user);
    }
}

/**
 * To add payment in the respective bank account
 * @param object $paiement payment object
 * @param array $response payment transaction response 
 */
function addPaymentToBank($paiement , $response , $user) {

    global $conf , $postactionmessages , $ispostactionok , $langs;
    $fulltag = $response['custom']['full_tag'];
    $source = getSourceType($fulltag);

    $bankAccountId = !empty($conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS) ? $conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS : 0;
    if ($bankAccountId > 0) {
        $label = '(CustomerInvoicePayment)';
        if($source == 'donation') {
            $label = '(DonationPayment)';
            $result = $paiement->addPaymentToBank($user, 'payment_donation', $label, $bankAccountId, '', '');
        }else {
            $result = $paiement->addPaymentToBank($user, 'payment', $label, $bankAccountId, '', '');
        }
        if ($result < 0) {
            $postactionmessages[] = $paiement->error.' '.implode("<br>\n", $paiement->errors);
            $ispostactionok = -1;
            novalnet_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors).'for order '.$response['transaction']['order_no'].'', '', LOG_ERR);
        } else {
            $postactionmessages[] = $langs->trans('NOVALNET_BANK_TRANS_ADD_WITH_TID', $response['transaction']['order_no'] , $response['transaction']['tid']);
            $ispostactionok = 1;
            novalnet_syslog('Bank transaction of payment created for ID => '.$response['transaction']['order_no'].' with TID'.$response['transaction']['tid'].'', '', LOG_NOTICE);
        }
    } else {
        $postactionmessages[] = $langs->trans('NOVALNET_SETUP_BANK_ACC');
        $ispostactionok = -1;
        novalnet_syslog('Setup of bank account for Novalnet payments was not set. No way to record the payment.', '',LOG_WARNING);
    }
}

/**
 * Fetches specific payment titile.
 *
 * @param string $paymentType contain payment type.
 */
function getPaymentTitile($paymentType) 
{

    $paymentTitles = [
        'DIRECT_DEBIT_SEPA' => 'Direct Debit SEPA',
        'DIRECT_DEBIT_ACH' => 'Direct Debit ACH',
        'CREDITCARD' => 'Credit/Debit Cards',
        'APPLEPAY' => 'Apple Pay',
        'GOOGLEPAY' => 'Google Pay',
        'INVOICE' => 'Invoice',
        'PREPAYMENT' => 'Prepayment',
        'GUARANTEED_INVOICE' => 'Invoice',
        'GUARANTEED_DIRECT_DEBIT_SEPA' => 'Direct Debit SEPA',
        'INSTALMENT_INVOICE' => 'Instalment by Invoice',
        'INSTALMENT_DIRECT_DEBIT_SEPA' => 'Instalment by Direct Debit SEPA',
        'IDEAL' => 'iDEAL',
        'ONLINE_TRANSFER' => 'Sofort',
        'ONLINE_BANK_TRANSFER' => 'Online bank transfer',
        'GIROPAY' => 'giropay',
        'CASHPAYMENT' => 'Barzahlen/viacash',
        'PRZELEWY24' => 'Przelewy24',
        'EPS' => 'eps',
        'PAYPAL' => 'PayPal',
        'POSTFINANCE_CARD' => 'PostFinance Card',
        'POSTFINANCE' => 'PostFinance E-Finance',
        'BANCONTACT' => 'Bancontact',
        'MULTIBANCO' => 'Multibanco',
        'ALIPAY' => 'Alipay',
        'WECHATPAY' => 'WeChat Pay',
        'TRUSTLY' => 'Trustly',
        'PAYCONIQ' => 'Payconiq',
        'TWINT' => 'TWINT',
        'BLIK' => 'Blik'

    ];

    return $paymentTitles[$paymentType];
}
