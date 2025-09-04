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
require_once DOL_DOCUMENT_ROOT . '/core/lib/json.lib.php';

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
        if(!validateChecksum($requestData ,$response['transaction']['order_no'] )){
            novalnet_syslog('return data miss match for order => '.$response['transaction']['order_no'].'', '', LOG_ERR);
            printResultPayment('transaction', $response, $source, 'ERROR','Error : '.$langs->trans("NOVALNET_ERR_RETURNED"));
            exit;
        }
        $fulltag = $response['custom']['full_tag'];
        $source = getSourceType($fulltag);
        $alreadyPaid = verifyAlreadyPaid($source, $response['transaction']['order_no']);
        
        if ($alreadyPaid) {
            printResultPayment('transaction', $response, $source, 'ALREADY_PAID','');
            exit;
        }

        if (!verifyAmount($response, 'transaction')) {
            exit;
        }

        if ($source == 'order') {
            onSuccessOrderPayment($response, $fulltag, $user);
        } elseif ($source == 'invoice') {
            onSuccessInvoicePayment($response, $fulltag, $user);
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

    if ($source === 'invoice') {
        $object = new Facture($db);
    } elseif ($source === 'order') {
        $object = new Commande($db);
    }
    $object->fetch('', $response['transaction']['order_no']);
    setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
    updateNovalnetTransaction($response, '', '', $response['transaction']['order_no']);
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

    if ($source === 'invoice') {
        $object = new Facture($db);
    } elseif ($source === 'order') {
        $object = new Commande($db);
    }

    $object->fetch('', $order_id);

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
    $nNTxnSecret = getNovalnetTransactionData('tnx_secret', $order_id);
    if(!empty($nNTxnSecret)) {
        updateNovalnetTransaction($response, '','', $order_id);
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
            } elseif ($source == 'invoice') {
                onSuccessInvoicePayment($response, $fulltag, $user);
            }
        }
    } else {
        if ($source === 'invoice') {
            $object = new Facture($db);
        } elseif ($source === 'order') {
            $object = new Commande($db);
        }

        $object->fetch('', $order_id);
        setStatusNotePrivate($object, $response['transaction']['status'], $order_id);
        updateTransactionComments($object, $response);
        printResultPayment('payment', $novalnet_response, $source, 'FAILURE','');
    }
}

/**
 * Update transaction comments in the database.
 */
function updateTransactionComments($object, $response)
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
            $note_public .=  $langs->trans('NOVALNET_DUEDATE_TEXT',getFormattedAmount($response['transaction']['amount']),$response['transaction']['due_date'])."\n";
        }
        
        if (!empty($response['transaction']['bank_details'])) {
            $note_public .= getInvoiceComments($response['transaction'])."\n";
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

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $object->element . ' SET';
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
{
    $invoicePaymentsNote = 'Account holder: ' . $response['bank_details']['account_holder'] . "\n";
    $invoicePaymentsNote .= 'IBAN: ' . $response['bank_details']['iban'] . "\n";
    $invoicePaymentsNote .= 'BIC: ' . $response['bank_details']['bic'] . "\n";
    $invoicePaymentsNote .= 'Bank: ' . $response['bank_details']['bank_name'] . ' ' . $response['bank_details']['bank_place'] . "\n";
    $invoicePaymentsNote .= 'Payment References description:' . "\n";

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
        $source = 'membersubscription'; // Member subscription.
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
        } elseif ($source == 'invoice') {
            print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("InvoicePaid") . '</span>';
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
        print '<tr><td align="left" colspan="2">' . $langs->trans('NOVALNET_DUEDATE_TEXT', getFormattedAmount($data['transaction']['amount']) . $data['transaction']['currency'] , $data['transaction']['due_date']) . '<br></td></tr>';
    }
        
    if (!empty($data['transaction']['bank_details'])) {
        print '<br>';
        $invoicePaymentsNote = 'Account holder: ' . $data['transaction']['bank_details']['account_holder'] . "<br>";
        $invoicePaymentsNote .= 'IBAN: ' . $data['transaction']['bank_details']['iban'] . "<br>";
        $invoicePaymentsNote .= 'BIC: ' . $data['transaction']['bank_details']['bic'] . "<br>";
        $invoicePaymentsNote .= 'Bank: ' . $data['transaction']['bank_details']['bank_name'] . ' ' . $data['transaction']['bank_details']['bank_place'] . "<br>";
        $invoicePaymentsNote .= 'Payment References description:' . "<br>";
    
        if (!empty($data['instalment']['cycle_amount'])) {
            $invoicePaymentsNote .= 'Payment Reference: ' . $data['transaction']['tid'] . "<br>";
        } else {
            $invoicePaymentsNote .= 'Payment reference 1: ' . $data['transaction']['tid'] . "<br>";
            $invoicePaymentsNote .= 'Payment reference 2: ' . $data['transaction']['invoice_ref'] . "<br>";
        }
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
    if ($print_button) {
        print '<tr>';
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
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_CREDITOR") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b></td>
              </tr>' . "\n";

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
    if ($source == 'invoice') {
        $found = true;
        $langs->load("bills");

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $invoice = new Facture($db);
        $result = $invoice->fetch('', $order_id);

        if ($result <= 0) {
            $mesg = $invoice->error;
            $error++;
        } else {
            $result = $invoice->fetch_thirdparty($invoice->socid);
        }

        $object = $invoice;
        $fulltag = dol_string_unaccent($fulltag);

        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '">
                  <td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("NOVALNET_CREDITOR") . '</td>
                  <td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b></td>
              </tr>' . "\n";

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
}

/**
 * Verify if source is already paid.
 */
function verifyAlreadyPaid($source, $ref, $ext_payment_id = '')
{
    global $conf, $db, $langs;

    if ($source == 'invoice') {
        $object = new Facture($db);
    } elseif ($source == 'order') {
        $object = new Commande($db);
    } elseif ($source == 'donation') {
        $object = new Don($db);
    } elseif ($source == 'membersubscription') {
        $object = null;
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

        if ($source == 'donation') {
            $result = $object->fetch($ref); // Find by rowid (Donation).
        }

        if ($result) { // Order found.  
            if ($source == 'order' && $object->billed) {
                return true;
            } elseif ($source == 'invoice' && $object->paye == 1) {
                return true;
            } elseif ($source == 'donation' && $object->paid) {
                return true;
            } elseif ($source == 'membersubscription' && $object->datefin > dol_now()) {
                return true;
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

    if ($source === 'invoice') {
        $object = new Facture($db);
    } elseif ($source === 'order') {
        $object = new Commande($db);
    }

    $object->fetch('', $order_id);

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

    $sql = 'UPDATE ' . MAIN_DB_PREFIX . $object->element . ' SET note_private = "' . $note_private . '" WHERE rowid = ' . $id;

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

                    $orderId = getNovalnetTransactionData('order_id', $response['transaction']['order_no']);
                    if (!empty($orderId)) {
                        updateNovalnetTransaction($response, $refNo, $refId, $orderId);
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
    $tmptag = dolExplodeIntoArray($fulltag, '.', '=');
    include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $object = new Facture($db);
    $result = $object->fetch((int) $tmptag['INV']);

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
            $orderId = getNovalnetTransactionData('order_id', $response['transaction']['order_no']);
            if (!empty($orderId)) {
                updateNovalnetTransaction($response, '', '', $orderId);
            } else {
                novalnetPaymentTransaction($response, '', '', '');
            }

            updateTransactionComments($object, $response);
            setStatusNotePrivate($object, $response['transaction']['status'], $response['transaction']['order_no']);
            printResultPayment('transaction', $response, 'invoice', $response['transaction']['status'],'');
    } else {
        $errorMessage = $langs->trans('NOVALNET_INVOICE_ORD_NOT_FOUND', $response['transaction']['order_no']);
        $postactionmessages[] = $errorMessage;
        $ispostactionok = -1;
        novalnet_syslog($errorMessage, '', LOG_ERR);
        printResultPayment('transaction', $response, 'order', 'ERROR',$errorMessage);
    }
    sendMail($response, $object ,$tmptag['INV']);
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

/**
 * Handles Novalnet transaction insertion.
 *
 * @param array $responseData The response data from the Novalnet payment gateway.
 * @param object $orderData The order object associated with the payment.
 * @param string $refNo The reference number for the transaction.
 * @param string $refId The reference ID for the transaction.
 * @return void
 */
function novalnetPaymentTransaction($responseData, $orderData, $refNo, $refId) 
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
    $nextid = getNextId();

    // SQL query to insert transaction data into the database
    $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction (rowid, order_id, ref_no, ref_id, source, tid, amount, payment_status, customer_id, payment_method, tnx_secret)';
    $sql .= ' VALUES (' . $nextid . ", '" . $db->escape(!empty($responseData['transaction']['order_no']) ? $responseData['transaction']['order_no'] : $order_id) . "',  '" . $db->escape(!empty($refNo) ? $refNo : '') . "', '" . $db->escape(!empty($refId) ? $refId : '') . "','" . $db->escape(!empty($source) ? $source : '') . "','" . $db->escape(!empty($responseData['transaction']['tid']) ? $responseData['transaction']['tid'] : '') . "', '" . $db->escape(!empty($responseData['transaction']['amount']) ? getFormattedAmount($responseData['transaction']['amount']) : $amount) . "','" . $db->escape(!empty($responseData['transaction']['status']) ? $responseData['transaction']['status'] : '') . "', '" . $db->escape(!empty($responseData['customer']['customer_no']) ? $responseData['customer']['customer_no'] : '') . "', '" . $db->escape(!empty($responseData['transaction']['payment_type']) ? $responseData['transaction']['payment_type'] : '') . "', '" . $db->escape(!empty($responseData['transaction']['txn_secret']) ? $responseData['transaction']['txn_secret'] : '') . "')";

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
function updateNovalnetTransaction($response, $ref_no, $ref_id, $order_id) 
{
    global $db;
    $error = 0;
    // Update transaction data in the database
    $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction SET ';
    if(isset($response['transaction']['txn_secret']) && !empty($response['transaction']['txn_secret'])) {
        $sql .= 'tnx_secret = "' . $db->escape($response['transaction']['txn_secret']) . '", ';
        $sql .= 'payment_method = "' . $db->escape($response['transaction']['payment_type']) . '" ';
        
        if(isset($ref_no) && !empty($ref_no)) {
            $sql .= ', ref_no = "' . $db->escape($ref_no) . '", ';
        }

        if(isset($ref_id) && !empty($ref_id)) {
            $sql .= 'ref_id = "' . $db->escape($ref_id) . '", ';
        }

        if(isset($response['transaction']['tid'])) {
            $sql .= 'tid = "' . $db->escape($response['transaction']['tid']) . '", ';
        }
        
        if(isset($response['transaction']['status'])) {
            $sql .= 'payment_status = "' . $db->escape($response['transaction']['status']) . '" ';
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
    $sql .= 'WHERE order_id = "' . $db->escape($order_id) . '"';
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
function getNextId() 
{
    global $db;
    $newid = 0;

    // Get the maximum row ID and increment it
    $sql = "SELECT MAX(rowid) newid FROM " . MAIN_DB_PREFIX . "novalnet_payment_transaction";
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
function getNovalnetTransactionData($field, $orderNo) 
{
    global $db;

    // SQL query to fetch data from Novalnet transaction table
    $sql = 'SELECT ' . $field . ' FROM ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction WHERE order_id = "' . $db->escape($orderNo) . '"';
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
function validateChecksum($responseData , $orderNo)
{   
    global $conf;
    
    $nNTxnSecret = getNovalnetTransactionData('tnx_secret', $orderNo);
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

        $paiement = new Paiement($db);
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
        
        
            $paiementId = $paiement->create($user, 1); // This includes closing invoices and regenerating documents
            if ($paiementId < 0) {
                $postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
                $ispostactionok = -1;
                novalnet_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors).' for order '.$response['transaction']['order_no'].'', '', LOG_ERR);
                $error++;
            } else {
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

    $bankAccountId = !empty($conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS) ? $conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS : 0;
    if ($bankAccountId > 0) {
        $label = '(CustomerInvoicePayment)';
        $result = $paiement->addPaymentToBank($user, 'payment', $label, $bankAccountId, '', '');
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
        'GUARANTEED_INVOICE' => 'Invoice with payment guarantee',
        'GUARANTEED_DIRECT_DEBIT_SEPA' => 'Direct Debit SEPA with payment guarantee',
        'INSTALMENT_INVOICE' => 'Instalment by Invoice',
        'INSTALMENT_DIRECT_DEBIT_SEPA' => 'Instalment by Direct Debit SEPA',
        'IDEAL' => 'iDEAL',
        'ONLINE_TRANSFER' => 'Sofort',
        'ONLINE_BANK_TRANSFER' => 'Online bank transfer',
        'GIROPAY' => 'giropay',
        'CASHPAYMENT' => 'Barzahlen/viacash',
        'PRZELEWY24' => 'Przelewy24',
        'EPS' => 'eps',
        'PAYPAL' => 'Paypal',
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
