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

// dol_include_once('/novalnet/public/process.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';


if (! class_exists('NovalnetCallback', false)) {
class NovalnetCallback
    {
     /**
     * @var array
     */
    private $mandatoryParams = [
        'event' => [
            'type',
            'checksum',
            'tid'
        ],
        'merchant' => [
            'vendor',
            'project'
        ],
        'transaction' => [
            'tid',
            'payment_type',
            'status',
        ],
        'result' => [
            'status'
        ],
    ];

    /**
     * @var mixed
     */
    private $response;

    /**
     * @var array
     */
    private $eventData;

    /**
     * @var string
     */
    private $eventType;

    /**
     * @var int
     */
    private $eventTid;

    /**
     * @var int
     */
    private $parentTid;
    
    /**
     * @var mixed
     */
    private $orderNo;

    /**
     * @var mixed
     */
    private $testMode;

    /**
     * @var mixed
     */
    private $emailBody;
    
    /**
     * @var string
     */
    private $paymentAccessKey;
    
    /**
     * @var mixed
     */
    private $paymentTxnId;
    
    /**
     * @var string
     */
    private $paymentType;
    
    /**
     * @var string
     */
    private $fullTag;
     
    /**
     * @var string
     */
    private $source;
    
    /**
     * @var string
     */
    private $user;
    
    /**
     * @var string
     */
    private $currency;
     
    /**
      * @var mixed
      */
     private $object;

    /**
     * @var mixed
     */
    private $additionalMessage;
    
    /**
     * @var mixed
     */
    private $currentDate;
    
    /**
     * @var mixed
     */
    private $amount;
   
    /**
     * @var string
     */
    private $paymentMethod;
     
    /**
     * @var array
     */
    protected $paymentTitles = [
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

        /**
         * Constructor.
         *
         *  @param   array   $data callback response data
         *  @param   object  $user core object used for the specific privilege
         */
        public function __construct($data , $user)
        {
         $this->user = $user;   
         $this->callback($data);
        }
        
        /**
         * Novalnet payment callback
         *
         *  @param   array   $data callback response data
         * 
         */
        public function callback($data)
        { 
            if ($this->assignGlobalParams($data)) {
                if ($this->eventType == 'PAYMENT') {
                    if (empty($this->paymentTxnId)) {
                        $this->handleCommunicationFailure();
                    } else {
                        $this->displayMessage('Novalnet Callback executed. The Transaction ID already existed');
                    }
                } elseif ($this->eventType == 'TRANSACTION_CAPTURE') {
                    $this->transactionCapture();
                 } elseif ($this->eventType == 'TRANSACTION_CANCEL') {
                    $this->transactionCancellation();
                } elseif ($this->eventType == 'TRANSACTION_REFUND') {
                    $this->refundProcess();
                } elseif ($this->eventType == 'TRANSACTION_UPDATE') {
                    $this->transactionUpdate();
                } elseif ($this->eventType == 'CREDIT') {
                    $this->creditProcess();
                } elseif ($this->eventType == 'INSTALMENT') {
                    $this->instalmentProcess();
                } elseif ($this->eventType == 'INSTALMENT_CANCEL') {
                    $this->instalmentCancelProcess();
                } elseif (in_array($this->eventType, ['CHARGEBACK', 'RETURN_DEBIT', 'REVERSAL'])) {
                    $this->chargebackProcess();
                } elseif (in_array($this->eventType, ['PAYMENT_REMINDER_1', 'PAYMENT_REMINDER_2'])) {
                    $this->paymentReminderProcess();
                } elseif ($this->eventType == 'SUBMISSION_TO_COLLECTION_AGENCY') {
                    $this->collectionProcess();
                } else {
                    $this->displayMessage("The webhook notification has been received for the unhandled EVENT type($this->eventType)");
                }
            }
             echo json_encode( $this->additionalMessage);
        }
	
    /**
     * Assign Global params for callback process
     *
     * @return boolean
     */
    public function assignGlobalParams($data) {
        global $conf , $db ;

        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            try{
                 $this->eventData = (!empty($data)) ? json_decode($data, true) : [] ;
            } catch(\Exception $e) {
                $this->displayMessage("Received data is not in the JSON format $e");
            }

            $this->testMode = $conf->global->NOVALNET_TEST_WEBHOOK_VALUE;
            $this->emailBody = '';

            if (!$this->checkIP()) {
                return false;
            }
            if (empty($this->eventData)) {
                $this->displayMessage('No params passed over!');
                return false;
            }
         $this->response = $this->eventData;
            $this->eventType = $this->response['event']['type'];
            $this->parentTid = !empty($this->response['event']['parent_tid'])
            ? $this->response['event']['parent_tid'] : $this->response['event']['tid'];

        $this->eventTid  = $this->response['event']['tid'];
        $this->orderNo = $this->response['transaction']['order_no'];
        $this->currentDate = date("Y-m-d");
        $this->paymentAccessKey = $conf->global->NOVALNET_PAYMENT_KEY;
        $this->paymentTxnId = $this->getNovalnetTransationData('tid'); // Get payment last transaction id
        if(isset($this->response['transaction']['currency'])){
            $this->currency = $this->response['transaction']['currency']; // Get order currency
        }
        $this->paymentType = $this->response['transaction']['payment_type'];
        $this->paymentMethod = $this->paymentTitles[$this->paymentType];
        if(isset($this->response['transaction']['amount'])){
            $this->amount = number_format($this->response['transaction']['amount'] / 100, 2);
        }
        
        if (empty($this->user->rights->societe)) {
            $this->user->rights->societe = new stdClass();
        }
        if (empty($this->user->rights->facture)) {
            $this->user->rights->facture = new stdClass();
            $this->user->rights->facture->invoice_advance = new stdClass();
        }
        if (empty($this->user->rights->adherent)) {
            $this->user->rights->adherent = new stdClass();
            $this->user->rights->adherent->cotisation = new stdClass();
        }
        $this->user->rights->societe->creer = 1;
        $this->user->rights->facture->creer = 1;
        $this->user->rights->facture->invoice_advance->validate = 1;
        $this->user->rights->adherent->cotisation->creer = 1;

        $this->fullTag =  $this->response['custom']['full_tag'];
        $this->source = $this->getSourceType($this->fullTag);
        if ($this->source === 'invoice') {
            $this->object = new Facture($db);
        } elseif($this->source === 'order') {
            $this->object = new Commande($db);
        }
        if (!$this->validateEventData()) {
            return false;
        }

        return true;
    }

    /**
     * Validate required parameter from the server request
     *
     * @return bool
     */
    private function validateEventData()
    {
        foreach ($this->mandatoryParams as $category => $parameters) {
             if (empty($this->response[$category])) {
            //     // Could be a possible manipulation in the notification data
                 $this->displayMessage('Required parameter category(' . $category . ') not received');

                 return false;
             } else { 
                foreach ($parameters as $parameter) {
                    if (empty($this->response[$category][$parameter])) {
                        // Could be a possible manipulation in the notification data
                        $this->displayMessage(
                            'Required parameter(' . $parameter . ') in the category(' . $category . ') not received'
                        );

                        return false;
                    }
                }
             }
        }

       // Validate the received checksum.
        if (!$this->validateChecksum()) {
            return false;
        }

        // Validate TID's from the event data
        if (!empty($this->parentTid) && !preg_match('/^\d{17}$/', $this->parentTid)
        ) {
            $this->displayMessage(
                'Invalid TID[' . $this->parentTid
                . '] for Order :' . $this->response['transaction']['order_no']
            );

            return false;
        } elseif (!empty($this->eventTid) && !preg_match('/^\d{17}$/', $this->eventTid)) {
            $this->displayMessage(
                'Invalid TID[' . $this->eventTid
                . '] for Order :' . $this->response['transaction']['order_no']
            );

            return false;
        }

        return true;
    }

    /**
     * Complete the order in-case response failure from Novalnet server
     *
     * @return mixed
     */
    private function handleCommunicationFailure() 
    {
        global $langs;

        $this->displayMessage($langs->trans('NOVALNET_NOT_HANDLED',$this->eventType));
        return false;
    }

    /**
     * Check transaction cancellation
     *
     * @return void
     */
    private function transactionCancellation()
    { 
        global $langs , $db , $postactionmessages , $ispostactionok;
        
        $tmptag = dolExplodeIntoArray($this->fullTag, '.', '=');
        
        if ($this->source === 'invoice') {
            
            $this->object->fetch((int) $tmptag['INV']);
        
        } else {
            
            $this->object->fetch((int) $tmptag['ORD']);

            if ($this->object->statut == Commande::STATUS_CANCELED || $this->object->statut == Commande::STATUS_CLOSED) {
                $this->displayMessage($langs->trans('NOVALNET_ORDER_ALREADY_CANCELLED'));
                return false;
            }
    
            $idwarehouse = $this->object->fk_warehouse;
    
            $qualified_for_stock_change = 0;
            if (!getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
                $qualified_for_stock_change = $this->object->hasProductsOrServices(2);
            } else {
                $qualified_for_stock_change = $this->object->hasProductsOrServices(1);
            }
    
            // Check parameters
            if (isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_VALIDATE_ORDER') && $qualified_for_stock_change) {
                if (!$idwarehouse || $idwarehouse == -1) {
                    $this->displayMessage('Invalid warehouse id');
                    return false;
                }
            }
    
            if($this->object->billed != 1) {
                $this->displayMessage($langs->trans('NOVALNET_ORDER_NOT_BILLED'));
                return false;
            }
    
            $result = $this->object->cancel($idwarehouse);
    
            if ($result < 0) {
                $this->displayMessage($langs->trans('NOVALNET_ORDER_CANCELLATION_FAILED'));
                return false;
            }
        }

        $postactionmessages[] = $langs->trans('NOVALNET_TRANSACTION_CANCEL_POSTMSG');
        $ispostactionok = 1;
        $this->emailBody = $message = $langs->trans('NOVALNET_TRANSACTION_CANCEL',$this->currentDate);
        $this->updatePrivateNotes($this->object ,$this->object->id ,'DEACTIVATED');                            
        $this->updateTransactionComments($this->object, $message);
        $this->updateNovalnetTransactionStatus($this->response, $this->response['transaction']['order_no']);
        // $this->sendMailToEndCustomer($message);
        $this->sendMail();
        $this->displayMessage($message);
    }
    
    /**
     * Handle payment refund/bookback process
     *
     * @return void
     */
    private function refundProcess()
    { 
         global $langs , $db , $conf ,$postactionmessages , $ispostactionok;

         $refunded_amount = $this->getNovalnetCreditNoteData('refunded_amount') + $this->response['transaction']['refund']['amount'];
         $total_amount_paid = $this->response['transaction']['amount'];
         
         if($refunded_amount > $total_amount_paid) {
             $this->displayMessage($langs->trans('NOVALNET_INVALID_REFUND_AMOUNT'));
             $this->novalnet_syslog('Invalid refund amount for the order number  => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
            return false;
         }

        $refund_amount = number_format($this->response['transaction']['refund']['amount'] / 100, 2);
        $refundedRefId = $this->getNovalnetCreditNoteData('ref_id');
        if(!empty($refundedRefId)){
            $object = new Facture($db);
            $result = $object->fetch((int) $refundedRefId);
            $this->addRefundPayment($object ,$refund_amount);
            $this->updatePrivateNotes($object ,$object->id , 'REFUNDED');
            $this->updateCreditNote($object);
        } else {

            $invoice_id = $this->getObjectId();
            $result = $this->object->fetch((int) $invoice_id);
            $invoiceRef = '';
            if($this->object->ref) {
            $invoiceRef = $this->object->ref;
            } else {
            $invoiceRef = $this->object->newref;
            }

            $alreadyPaid = $this->verifyAlreadyPaid('invoice', $invoiceRef);

            if(!$alreadyPaid) {
                $this->displayMessage($langs->trans('NOVALNET_ORDER_AMOUNT_NOT_PAID'));
                $this->novalnet_syslog('Order amount not paid refund not possible for the order number  => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
               return false;
            }
            
            if ($this->object <= 0) {
                $this->displayMessage('Invoice not found for the order '.$this->response['transaction']['order_no'].'');
                return false;
            }
            // Check if the refund amount is valid
            if ($refund_amount <= 0 || $refund_amount > $this->object->total_ttc) {
                $this->displayMessage($langs->trans('NOVALNET_INVALID_REFUND_AMOUNT'));
                $this->novalnet_syslog('Invalid refund amount for the order number  => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                return false;
            }

            $sourceinvoice = $this->getNovalnetTransationData('ref_id');

            $sourceinvoice = $sourceinvoice ? $sourceinvoice : $this->object->id;
            
            if (!($sourceinvoice > 0) && !getDolGlobalString('INVOICE_CREDIT_NOTE_STANDALONE')) {
                $this->displayMessage($langs->trans('Invoice reference id not found'));
                $this->novalnet_syslog('Invoice reference id not found for the order number  => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                return false;
            }

                $object = new Facture($db);
                $object->entity = 1;
                $object->socid              = $this->object->socid;
                $object->subtype            = 0;
                $object->ref                = 'provisoire';
                $object->date               = dol_now();

                $note_public = 'Payment Method: ' . $this->paymentMethod . "\n";
                $note_public .= 'Novalnet Transaction ID: ' . $this->parentTid. "\n";
                
                $object->note_public		= $note_public;
                $object->note_private		= '##STATUS=STARTED##';
                $object->model_pdf          = 'sponge';
                $object->fk_account         = $this->object->fk_account;
                $object->fk_incoterms       = $this->object->fk_incoterms;
                $object->multicurrency_code = $this->object->multicurrency_code;
                $object->multicurrency_tx   = $this->object->multicurrency_tx;

                // Special properties of replacement invoice
                $object->fk_facture_source = $sourceinvoice > 0 ? $sourceinvoice : '';
                $object->type = Facture::TYPE_CREDIT_NOTE;

                $facture_source = new Facture($db); // fetch origin object
                if ($facture_source->fetch($object->fk_facture_source) > 0) {
                    if ($facture_source->isSituationInvoice()) {
                        $object->situation_counter = $facture_source->situation_counter;
                        $object->situation_cycle_ref = $facture_source->situation_cycle_ref;
                        $facture_source->fetchPreviousNextSituationInvoice();
                    }
                }

                $id = $object->create($this->user);

                if ($id <= 0) {
                    $this->displayMessage($langs->trans('While creating credit note something went worng'));
                    $this->novalnet_syslog('While creating credit note something went worng id less than or equal to 0 for the order number  => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                    return false; 
                } else {
                    // copy internal contacts
                    if ($object->copy_linked_contact($facture_source, 'internal') < 0) {
                        $this->novalnet_syslog('No internal contacts to copy', '', LOG_NOTICE);
                    } elseif ($facture_source->socid == $object->socid) {
                        // copy external contacts if same company
                        if ($object->copy_linked_contact($facture_source, 'external') < 0) {
                            $this->novalnet_syslog('No external contact to copy', '', LOG_NOTICE);
                        }
                    }
                }

                $postactionmessages[] = $langs->trans('NOVALNET_CREDIT_NOTE_CREATED');

                    if (!empty($facture_source->lines)) {
                        $fk_parent_line = 0;

                        foreach ($facture_source->lines as $line) {
                            // Extrafields
                            if (method_exists($line, 'fetch_optionals')) {
                                // load extrafields
                                $line->fetch_optionals();
                            }

                            // Reset fk_parent_line for no child products and special product
                            if (($line->product_type != 9 && empty($line->fk_parent_line)) || $line->product_type == 9) {
                                $fk_parent_line = 0;
                            }


                            $line->fk_facture = $object->id;
                            $line->fk_parent_line = $fk_parent_line;

                            $line->subprice = -$line->subprice; // invert price for object
                            // $line->pa_ht = $line->pa_ht; // we chose to have buy/cost price always positive, so no revert of sign here
                            $line->total_ht = -$line->total_ht;
                            $line->total_tva = -$line->total_tva;
                            $line->total_ttc = -$line->total_ttc;
                            $line->total_localtax1 = -$line->total_localtax1;
                            $line->total_localtax2 = -$line->total_localtax2;

                            $line->multicurrency_subprice = -$line->multicurrency_subprice;
                            $line->multicurrency_total_ht = -$line->multicurrency_total_ht;
                            $line->multicurrency_total_tva = -$line->multicurrency_total_tva;
                            $line->multicurrency_total_ttc = -$line->multicurrency_total_ttc;

                            $line->context['createcreditnotefrominvoice'] = 1;
                            $result = $line->insert(0, 1); // When creating credit note with same lines than source, we must ignore error if discount already linked

                            $object->lines[] = $line; // insert new line in current object

                            // Defined the new fk_parent_line
                            if ($result > 0 && $line->product_type == 9) {
                                $fk_parent_line = $result;
                            }
                        }

                            $object->update_price(1);
                    }
                // Add link between credit note and origin
                if (!empty($object->fk_facture_source) && $id > 0) {
                    $facture_source->fetch($object->fk_facture_source);
                    $facture_source->fetchObjectLinked();
                    
                    if (!empty($facture_source->linkedObjectsIds)) {
                        foreach ($facture_source->linkedObjectsIds as $sourcetype => $TIds) {
                            $object->add_object_linked($sourcetype, current($TIds));
                        }
                    }
                }

                $idwarehouse = $this->object->fk_warehouse;
                $object->fetch($object->id);
                $object->fetch_thirdparty();
                $result = $object->validate($this->user, '', $idwarehouse); 
                $this->updateCreditNote($object);
            if ($result >= 0) {
                // Define output language
                if (!getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
                    $outputlangs = $langs;
                    $newlang = '';
                    if (getDolGlobalInt('MAIN_MULTILANGS') /* && empty($newlang) */ && GETPOST('lang_id', 'aZ09')) {
                        $newlang = GETPOST('lang_id', 'aZ09');
                    }
                    if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang)) {
                        $newlang = $object->thirdparty->default_lang;
                    }
                    if (!empty($newlang)) {
                        $outputlangs = new Translate("", $conf);
                        $outputlangs->setDefaultLang($newlang);
                        $outputlangs->load('products');
                    }
                    $model = $object->model_pdf;

                    $ret = $object->fetch($object->id); // Reload to get new records

                    $result = $object->generateDocument($model, $outputlangs, 0, 0, 0);
                }
            }else {
                $this->novalnet_syslog('Credit note PDF related error for the order number  => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
            }

            $this->addRefundPayment($object , $refund_amount);
            $this->updatePrivateNotes($object ,$object->id , 'REFUNDED');
        }

        $message = $langs->trans("NOVALNET_TRANSACTION_REFUND", $this->parentTid , $refund_amount .' '. $this->currency  , $this->eventTid);
        $this->updateTransactionComments($object , $message);
        $this->emailBody = $message;
        // $this->sendMailToEndCustomer($message);
        $this->sendMail();
        $this->displayMessage($message);
    }

    /**
     * Handle payment INSTALMENT process
     *
     * @return void
     */
    private function instalmentProcess()
    {
        global $langs;

        $this->displayMessage($langs->trans('NOVALNET_NOT_HANDLED',$this->eventType));
        return false;
    }

    /**
     * Handle payment CHARGEBACK/RETURN_DEBIT/REVERSAL process
     *
     * @return void
     */
    private function chargebackProcess()
    {
        global $langs;

        $this->displayMessage($langs->trans('NOVALNET_NOT_HANDLED',$this->eventType));
        return false;
    }

    /**
     * Handle payment INSTALMENT cancel process
     *
     * @return void
     */
    private function instalmentCancelProcess()
    {
        global $langs;

        $this->displayMessage($langs->trans('NOVALNET_NOT_HANDLED',$this->eventType));
        return false;
    }

    /**
     * Handle payment reminder process
     *
     * @return void
     */
    private function paymentReminderProcess() 
    { 
        global $langs;

        $reminderCount = explode('_', $this->response['event']['type']);
        $reminderCount = end($reminderCount);
        $this->emailBody = $message =
            $langs->trans('NOVALNET_PAYMENT_REMINDER',$reminderCount);
        $objectId = $this->getObjectId();
        if(empty($objectId)) {
           $this->displayMessage('Invalid order');
            return false;
        }
        
        $result = $this->object->fetch((int) $objectId);
            $invoiceRef = '';
            if($this->object->ref) {
              $invoiceRef = $this->object->ref;
            } else {
              $invoiceRef = $this->object->newref;
            }
            $alreadyPaid = $this->verifyAlreadyPaid('invoice', $invoiceRef);

        if($alreadyPaid) {
        $this->displayMessage('Order already captured.');
        return false;
        }
        if ($result) {
            $this->updatePrivateNotes($this->object ,$objectId);
            $this->updateTransactionComments($this->object , $message);
            $this->sendMail(); 
        }
        $this->displayMessage($message);

    }

    /**
     * Handle payment collection process
     *
     * @return void
     */
    private function collectionProcess() 
    {   
        global $langs;

        $this->emailBody = $message = 
        $langs->trans('NOVALNET_COLLECTION_PROCESS', $this->response['collection']['reference']);
        $objectId = $this->getObjectId();
        if(empty($objectId)) {
            $this->displayMessage('Invalid order');
           return false;
        }
        $result = $this->object->fetch((int) $objectId);
        $invoiceRef = '';
        if($this->object->ref) {
          $invoiceRef = $this->object->ref;
        } else {
          $invoiceRef = $this->object->newref;
        }
        $alreadyPaid = $this->verifyAlreadyPaid('invoice', $invoiceRef);

        if($alreadyPaid) {
        $this->displayMessage('Order already captured.');
        return false;
        }
        if ($result) {
            $this->updatePrivateNotes($this->object ,$objectId);
            $this->updateTransactionComments($this->object , $message);
            $this->sendMail(); 
        }
        $this->displayMessage($message);
    }
    
     /**
     * Capture transaction
     *
     * @return void
     */
    private function transactionCapture()
    { 
       global $conf , $db , $langs;
        $objectId = $this->getObjectId();

        if(empty($objectId)) {
            $this->displayMessage('Invalid order');
           return false;
        }
             
            $result = $this->object->fetch((int) $objectId);
            $invoiceRef = '';
            if($this->object->ref) {
              $invoiceRef = $this->object->ref;
            } else {
              $invoiceRef = $this->object->newref;
            }
            $alreadyPaid = $this->verifyAlreadyPaid('invoice', $invoiceRef);

        if($alreadyPaid) {
        $this->displayMessage('Order already captured.');
        return false;
        }

    $this->updatePrivateNotes($this->object ,$objectId);
    if ($result) {
        $message = $this->createPayment($objectId);
        } else {
            $this->novalnet_syslog('Invoice not found for the order no => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
            $message = 'Invoice not found for the order no => '.$this->response['transaction']['order_no'].'';
        }
        $this->displayMessage($message);
           
    }

   /**
     * Handle transaction update
     *
     * @return void
     */
  private function transactionUpdate() {
   global $db , $langs;
   $message = "Novalnet callback received for the unhandled transaction type(".$this->response['transaction']['payment_type'].") for ".$this->eventType." EVENT";
    $transactionStatus = $this->getNovalnetTransationData('payment_status');
    $objectId = $this->getObjectId();
    if(empty($objectId)) {
            $this->displayMessage('Invalid order');
           return false;
        }
    $result = $this->object->fetch((int) $objectId);
    $invoiceRef = '';
    if($this->object->ref) {
       $invoiceRef = $this->object->ref;
     } else {
      $invoiceRef = $this->object->newref;
     }
      $alreadyPaid = $this->verifyAlreadyPaid('invoice', $invoiceRef);

      if($alreadyPaid) {
        $this->displayMessage('Amount already paid for these transaction');
        return false;
      }
    if($transactionStatus == 'PENDING') {
        if($this->response['transaction']['status'] == 'ON_HOLD') {
            $this->emailBody = $message = $langs->trans('NOVALNET_PENDING_TO_ONHOLD',$this->parentTid , $this->currentTime);
            $this->updatePrivateNotes($this->object ,$this->object->id);                            
            $this->updateTransactionComments($this->object, $message);
            $this->updateNovalnetTransactionStatus($this->response, $this->response['transaction']['order_no']);
        }
        elseif($this->response['transaction']['status'] == 'CONFIRMED'){
            if ($result) {
                $message = $this->createPayment($objectId);
                $this->updatePrivateNotes($this->object ,$this->object->id);
                // $this->sendMailToEndCustomer($message);
                } else {
                    $this->novalnet_syslog('Invoice not found for the order no => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                    $message = 'Invoice not found for the order no => '.$this->response['transaction']['order_no'].'';
                }
        }
    } elseif($transactionStatus == 'ON_HOLD') {
        if($this->response['transaction']['status'] == 'CONFIRMED'){
            if ($result) {
                $message = $this->createPayment($objectId);
                // $this->sendMailToEndCustomer($message);
                $this->updatePrivateNotes($this->object ,$this->object->id);

                } else {
                    $this->novalnet_syslog('Invoice not found for the order no => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                    $message = 'Invoice not found for the order no => '.$this->response['transaction']['order_no'].'';
                }
        }
    }
    $this->emailBody = $message;
    $this->sendMail();
    $this->displayMessage($message);
  }

    /**
     * Handle payment credit process
     *
     * @return void
     */
  private function creditProcess() {
    global $db;
    $transactionPaymentType = $this->response['transaction']['payment_type'];
    $message = "Novalnet callback received for the unhandled transaction type(".$transactionPaymentType.") for ".$this->eventType." EVENT";
    $objectId = $this->getObjectId();
    if(empty($objectId)) {
        $this->displayMessage('Invalid order');
       return false;
    }
    $result = $this->object->fetch((int) $objectId);
    $invoiceRef = '';
            if($this->object->ref) {
              $invoiceRef = $this->object->ref;
            } else {
              $invoiceRef = $this->object->newref;
            }
            $alreadyPaid = $this->verifyAlreadyPaid('invoice', $invoiceRef);
        if($alreadyPaid) {
        $this->displayMessage('Amount already paid.');
        return false;
        }
        if ($result) {
            $message = $this->createPayment($objectId);
        }
    $this->emailBody = $message;
    $this->sendMail();
    $this->updateNovalnetCallback();
    $this->updatePrivateNotes($this->object ,$objectId);
    $this->displayMessage($message);

  }
    /**
     * Check whether the ip address is authorised
     *
     * @return boolean
     */
    private function checkIP()
    { 
        $requestReceivedIp = getUserRemoteIP(); 
        $novalnetHostIp = gethostbyname('pay-nn.de');

        if (!empty($novalnetHostIp)) {
            if (!$this->validateRequestIp($novalnetHostIp) && $this->testMode != 1) {
                $this->displayMessage(
                    'Unauthorised access from the IP '.$requestReceivedIp.''
                );

                return false;
            }
        } else {
            $this->displayMessage('Unauthorised access from the IP');
            return false;
        }

        return true;
    }

    /**
     * Validate the request ip with Novalnet host Ip
     *
     * @param string $novalnetHostIp
     * @return boolean
     */
    private function validateRequestIp($novalnetHostIp)
    {
        $serverVariables = $_SERVER;
        $remoteAddrHeaders = [
            'HTTP_X_FORWARDED_HOST',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
    
        foreach ($remoteAddrHeaders as $header) {
            if (isset($serverVariables[$header])) {
                if (in_array($header, ['HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR'])) {
                    $forwardedIps = (!empty($serverVariables[$header])) ? explode(",", $serverVariables[$header]) : [];
                    if (in_array($novalnetHostIp, $forwardedIps)) {
                        return true;
                    }
                }
    
                if ($serverVariables[$header] == $novalnetHostIp) {
                    return true;
                }
            }
        }
    
        return false;
    }

    /**
     * Set field private_note on database with the status .
     * @param object $object object of (invoive , order etc...).
     * @param int $rowId row id 
     */
    private function updatePrivateNotes($object , $rowId , $status = '') {
        global $db;
        $key = '##';
        $status = $status ? $status : $this->response['transaction']['status'];
        $note_private = $key . 'STATUS=' . $status . $key;
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $db->escape($object->element) . ' SET';
        $sql .= " note_private='" . $db->escape($note_private) . "'";
        $sql .= ' WHERE rowid = ' . $db->escape($rowId);
        $resql = $db->query($sql);
        if (! $resql) {
            $this->novalnet_syslog('sql related error for the order number  '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
        } 
    }                        
    /**
     * To get the data from novalnet transaction table.
     * @param string $field name of the field.
     */
    private function getNovalnetTransationData($field) {
            global $db;
            $sql = 'SELECT '.$field.' '; 
            $sql .= 'FROM '.MAIN_DB_PREFIX.'novalnet_payment_transaction '; 
            $sql .= 'WHERE order_id = "'. $db->escape($this->orderNo) .'"'; 
            $result = $db->query($sql); 
            if ($result) {
                $nbrows = $db->num_rows($result); 
                if ($nbrows == 1) {
                    $obj = $db->fetch_object($result);
                    return $obj->$field;
                }
                elseif ($nbrows > 1) {
                    $this->novalnet_syslog('More than one row is getting for order '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                    return null;
                }
            }

        }

private function getNovalnetCreditNoteData($field) {
            global $db;
            $sql = 'SELECT '.$field.' '; 
            $sql .= 'FROM '.MAIN_DB_PREFIX.'novalnet_credit_note '; 
            $sql .= 'WHERE order_id = "'. $db->escape($this->orderNo) .'"'; 
            $result = $db->query($sql); 
            if ($result) {
                $nbrows = $db->num_rows($result); 
                if ($nbrows == 1) {
                    $obj = $db->fetch_object($result);
                    return $obj->$field;
                }
                elseif ($nbrows > 1) {
                    $this->novalnet_syslog('More than one row is getting for order '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                    return null;
                }
            }

        }        

    /**
     * Show callback process transaction comments
     *
     * @param  string  $text
     * @return void
     */
    private function displayMessage($text)
    {   
        global $langs;
        $this->additionalMessage = $text;

    }

    /**
     * Validate checksum in response
     *
     * @return bool
     */
    private function validateChecksum()
    {
        $checksumString  = $this->response['event']['tid'] . $this->response['event']['type']
            . $this->response['result']['status'];

        if (isset($this->response['transaction']['amount'])) {
            $checksumString .= $this->response['transaction']['amount'];
        }

        if (isset($this->response['transaction']['currency'])) {
            $checksumString .= $this->response['transaction']['currency'];
        }

        $accessKey = trim($this->paymentAccessKey);
        if (!empty($accessKey)) {
            $checksumString .= strrev($accessKey);
        }

        $generatedChecksum = hash('sha256', $checksumString);
        if ($generatedChecksum !== $this->response['event']['checksum']) {
            $this->displayMessage('While notifying some data has been changed. The hash check failed');

            return false;
        }

        return true;
    }
    
    /**
     * Obtain source type (free, invoice, order, donation, contractline, membersubscription).
     *
     * @param  string   $order_info  Information from answer
     * @return string   $source       (free, invoice, order, donation, contractline, membersubscription)
     */
    public function getSourceType ($order_info)
    { 
        $source = '';
        if (preg_match('#^TAG(.*)$#', $order_info)) {
            $source = 'free'; // Free amount.
        } elseif (preg_match('#^INV(.*)$#', $order_info)) {
            $source = 'invoice'; // Invoice.
        } elseif (preg_match('#^ORD(.*)$#', $order_info)) {
            $source = 'order';
        } elseif (preg_match('#^ORD(.*)$#', $order_info)) {
            $source = 'order'; // Order.
        } elseif (preg_match('#^DON(.*)$#', $order_info)) {
            $source = 'donation'; // Donation.
        } elseif (preg_match('#^COL(.*)$#', $order_info)) {
            $source = 'contractline'; // Contract ligne.
        } elseif (preg_match('#^MEM(.*)$#', $order_info)) {
            $source = 'membersubscription'; // Member subscription.
        } else {
            $source = '';
        }

        return $source;
    }

    /**
     * Verify if source is already paid.
     * @param string $source source(i.e invoice , order) of the transaction
     * @param string $ref reference of the transaction.
     * @param string  $ext_payment_id extend payment id .
     * @return boolean
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
            $sql.= ' p.num_paiement as num_payment, p.note';
            $sql.= ' FROM ' . MAIN_DB_PREFIX . 'paiement as p LEFT JOIN ' . MAIN_DB_PREFIX . 'c_paiement as c ON p.fk_paiement = c.id';
            $sql.= ' WHERE p.entity IN (' . getEntity('invoice') . ')';
            if ($ref) {
                $sql.= " and p.num_paiement = '" . $ref . "'";
            }

            if ($ext_payment_id) {
                $sql.= " and p.ext_payment_id  = '" . $db->escape($ext_payment_id) . "'";
            }

            $num_rows = $db->query($sql)->num_rows;
            if ($num_rows > 0) {
                return true;
            }
        }

        if ($object) {
            $result = null;
            if (! empty($ref)) {
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
     * To create a payment
     *
     * @param  string   $objectId object id of source.
     * @return string   transaction message.
     */
    public function createPayment($objectId) {

    global $conf , $db , $langs,$postactionmessages , $ispostactionok;
        $now = dol_now();
        $FinalPaymentAmt = $this->amount;
        $paymentType = 'NOV';
        $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
    if (!empty($FinalPaymentAmt) && $paymentTypeId > 0 && $this->response['transaction']['status'] == 'CONFIRMED') {
            $db->begin();
            // Creation of payment line
            include_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
            $paiement = new Paiement($db); 
            $paiement->datepaye = $now;
            if ($this->currency == $conf->currency) {
                $paiement->amounts = array($objectId => $FinalPaymentAmt); // Array with all payments dispatching with invoice id
            } else {
                $paiement->multicurrency_amounts = array($objectId => $FinalPaymentAmt); // Array with all payments dispatching
                $postactionmessages[] = 'Payment was done in a currency ('.$this->response['transaction']['currency'].') other than the expected currency of company ('.$conf->currency.')';
                $ispostactionok = -1;
                $this->novalnet_syslog('Payment was done in a different currency than the currency expected by the company for order => '.$this->response['transaction']['order_no'].'', '', LOG_NOTICE);
            }
            $paiement->paiementid   = $paymentTypeId;
            $paiement->ext_payment_id = $this->parentTid;

                $paiement_id = $paiement->create($this->user, 1); // This include closing invoices and regenerating documents
                if ($paiement_id < 0) {
                    $message = 'While updating payment . payment_id is missing';
                    $postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
                    $ispostactionok = -1;
                    $this->novalnet_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors).' for order '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                } else {
                    $postactionmessages[] = 'Payment created';
                    $ispostactionok = 1;
                    $this->novalnet_syslog('Payment created for ID => '.$this->response['transaction']['order_no'].' with TID'.$this->response['transaction']['tid'].'', '', LOG_NOTICE);
                }

                $this->addPaymentToBank($paiement);

            $db->commit();
            if($this->eventType == 'TRANSACTION_CAPTURE') {
                $message = $langs->trans("NOVALNET_TRANSACTION_CONFIRM",$this->currentDate);
                // $this->sendMailToEndCustomer($message);
                $this->emailBody = $message;
                $this->sendMail();
            } elseif ($this->eventType == 'CREDIT') {
                $message = $langs->trans("NOVALNET_TRANSACTION_CREDIT",$this->parentTid , $this->amount , $this->currentDate ,$this->eventTid);
            } elseif ($this->eventType == 'TRANSACTION_UPDATE') {
                $message = $langs->trans("NOVALNET_TRANSACTION_UPDATE",$this->parentTid ,$this->amount ,$this->currentDate );
            }
            $this->updateTransactionComments($this->object, $message);
            $this->updateNovalnetTransactionStatus($this->response, $this->response['transaction']['order_no']);
        } else {
            $message = '';
            if($this->eventType == 'TRANSACTION_CAPTURE') {
                $message = $langs->trans("NOVALNET_TRANSACTION_CONFIRM",$this->currentDate);
                $this->emailBody = $message;
                $this->sendMail();
            } elseif ($this->eventType == 'TRANSACTION_UPDATE') {
                $message = $langs->trans("NOVALNET_TRANSACTION_UPDATE",$this->parentTid ,$this->amount ,$this->currentDate );
            }
            $postactionmessages[] = $message;
        }
            return $message;
    }

    /**
     * To get object id from the source.
     */
    public function getObjectId() {
        global $db;
        if($this->source == 'order') {
            $objectId = $this->getNovalnetTransationData('ref_id');
            if (!empty($objectId)) {
                $tmptag = dolExplodeIntoArray($this->fullTag, '.', '=');
                $this->object->fetch((int) $tmptag['ORD']);
                $this->updatePrivateNotes($this->object ,$this->object->id);
                $this->object = new Facture($db);
            }
        } elseif ($this->source == 'invoice') {
                $tmptag = dolExplodeIntoArray($this->fullTag, '.', '=');
                $objectId = $tmptag['INV'];
        }      
        if(empty($objectId)) {
                $objectId = $this->object->id;
        }
        return $objectId;
    }

    /**
     * Update transaction comments in the database.
     * @param object $object object of the source.
     * @param string $message message to set in the public note.
     */
    public function updateTransactionComments($object, $message)
    {
        global $db , $langs;

        $error = 0;
        $id = $object->id;
        if(empty($id)) {
            $id = $this->getObjectId();
        }
        if ($this->eventType == 'CREDIT' || $this->eventType == 'TRANSACTION_REFUND') {
            $note_public = $object->note_public. "\n"; // Initialize note_public variable.
        }
        if ($this->parentTid && $this->eventType != 'CREDIT' && $this->eventType != 'TRANSACTION_REFUND') {
            //Add payment method and transaction details to the note.
            $note_public = 'Payment Method: ' . $this->paymentMethod . "\n";
            $note_public .= 'Novalnet Transaction ID: ' . $this->parentTid. "\n";
        }
        if(in_array($this->response['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA']) && $this->eventType != 'TRANSACTION_REFUND') {
           $note_public .= $langs->trans('NOVALNET_GUARANTEE')."\n";
        }

        if(!empty($this->response['transaction']['due_date']) && in_array($this->response['transaction']['payment_type'],['INSTALMENT_INVOICE','PREPAYMENT','GUARANTEED_INVOICE','INVOICE']) 
        && $this->eventType != 'CREDIT' && $this->eventType != 'TRANSACTION_REFUND' && $this->response['transaction']['status'] != 'ON_HOLD') {
            $note_public .=  $langs->trans('NOVALNET_DUEDATE_TEXT',$this->amount,$this->response['transaction']['due_date'])."\n";
        }
        
        if (!empty($this->response['transaction']['bank_details']) && $this->eventType != 'CREDIT' && $this->eventType != 'TRANSACTION_REFUND') {
            $note_public .= $this->getInvoiceComments($this->response['transaction'])."\n";
        }

        if ($this->response['transaction']['payment_type'] == 'CASHPAYMENT') {
            $note_public .= $langs->trans('NOVALNET_CASHPAYMENT_DUEDATE',$this->response['transaction']['due_date'])."\n";
            $note_public .= $langs->trans('NOVALNET_NEAR_STORE')."\n";

            $cashPaymentStores = [];
            foreach ($this->response['transaction']['nearest_stores'] as $key => $cashPaymentStore) {
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
            if(!empty($message)) {
            $note_public .= $message. "\n";
            }
            // Start transaction for updating the database.
            $db->begin();

            $sql = 'UPDATE ' . MAIN_DB_PREFIX . $db->escape($object->element) . ' SET';
            $sql .= " note_public='" . $db->escape($note_public) . "'";
            $sql .= ' WHERE rowid = ' . $db->escape((int)$id);
            $resql = $db->query($sql);
            if (!$resql) {
                // Handle error in updating the database.
                $error = 1;
            }

            // Commit or rollback based on the success of the query.
            if (!$error) {
                $db->commit();
            } else {
                $this->novalnet_syslog('Due to sql releated error data will be roll back','', LOG_ERR);
                $db->rollback();
            }
    }

    /**
     * Print the specified message in dolibarr_novalnet.log file.
     *
     * @param   string    $message    Message to be printed in log file
     * @param   string    $level      Level at which the message will be printed
     * @return  void
     */
    public function novalnet_syslog($message, $ref = '', $level = LOG_INFO)
    {
        $file = basename($_SERVER['PHP_SELF']);
        $final_log =  '[' . $file . '][Ref=' . $ref . '] ' . $message;
        dol_syslog($message, $level, 0, '_novalnet');
    }

    /**
     * Get detailed bank information to include in the invoice comments.
     * @param array $response transaction data.
     */
    public function getInvoiceComments($response)
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
     * Updates Novalnet transaction information.
     *
     * @param array $response The response data from the Novalnet payment gateway.
     * @param string $order_id The order ID associated with the payment.
     * @return void
     */
    public function updateNovalnetTransactionStatus($response, $order_id) {
        global $db;
        $error = 0;

        // Update transaction data in the database
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'novalnet_payment_transaction SET ';
        $sql .= 'payment_status = "' . $db->escape($response['transaction']['status']) . '" ';
        $sql .= 'WHERE order_id = "' . $db->escape($order_id) . '"';

        $resql = $db->query($sql);
        if (!$resql) {
            $error++;
        }

        if (!$error) {
            $db->commit();
        } else {
            $this->novalnet_syslog('Due to sql releated error data will be roll back','', LOG_ERR);
            $db->rollback();
        }
    }

    /**
     * Sends an email regarding the payment status to the end customer.
     *
     * @param string $message transaction message.
     * @return void
     */
    public function sendMailToEndCustomer($message) {
        global $conf, $langs, $db ;
        $from = getDolGlobalString('MAILING_EMAIL_FROM') ? $conf->global->MAILING_EMAIL_FROM : getDolGlobalString("MAIN_MAIL_EMAIL_FROM");
        $sendto = $this->response['customer']['email'];
        $content = "";
        if ($this->response['transaction']['tid']) {
            //Add payment method and transaction details to the note.
            $content .= 'Payment Method: ' . $this->paymentMethod . "\n";
            $content .= 'Novalnet Transaction ID: ' . $this->response['transaction']['tid']. "\n";

            if (!empty($this->response['transaction']['bank_details'])) {
                $content .= "\n" . $this->getInvoiceComments($this->response['transaction']);
            }
        }
            if(!empty($message)) {
            $content .= "\n".$message;
            }
        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        $ishtml = dol_textishtml($content);
        $trackid = '';
        try {
            $mailfile = new CMailFile('Transaction status update', $sendto, $from, $content, array(), array(), array(), '', '', 0, $ishtml, '', '', $trackid, '', 'standard');
            $result = $mailfile->sendfile();
            if ($result) {
                $this->novalnet_syslog("End customer E-Mail sent to " . $sendto, '',LOG_NOTICE );
            } else {
                $this->novalnet_syslog("End customer Failed to send E-Mail to " . $sendto, '', LOG_ERR);
            }
        } catch(\Exception $e) {
            $this->novalnet_syslog("Error sending end customer mail " . $e, '', LOG_ERR);
            $this->displayMessage("Error sending end customer mail $e");
        }
    }

    /**
     * Sends an email regarding the payment status to the merchant.
     * @return void
     */
    public function sendMail() {
        global $conf, $langs, $db, $mysoc, $appli ,$postactionmessages ,$ispostactionok;

        // Determine whether to send email to admins
        $sendemail = getDolGlobalString('NOVALNET_WEBHOOK_EMAIL');
        $ObjectId = $this->getObjectId();
        if ($sendemail) {
            // Prepare language and email content
            $companylangs = new Translate('', $conf);
            $companylangs->setDefaultLang($mysoc->default_lang);
            $companylangs->loadLangs(array('main', 'members', 'bills', 'novalnet' ,'NOVALNET' ));

            $sendto = $sendemail;
            $from = getDolGlobalString('MAILING_EMAIL_FROM') ? $conf->global->MAILING_EMAIL_FROM : getDolGlobalString("MAIN_MAIL_EMAIL_FROM");


            $urlwithroot = DOL_MAIN_URL_ROOT;

            // Set URL to the current page
            $urlback = $_SERVER["REQUEST_URI"];
            $topic = $companylangs->transnoentitiesnoconv("NovalnetCallback");
            $content = "";

            if ($ObjectId) {
                $url = $urlwithroot . "/compta/facture/card.php?id=" . ((int) $ObjectId);
                $content .= '<strong>' . $companylangs->trans("Payment") . "</strong><br><br>\n";
                $content .= $companylangs->trans("InvoiceId") . ': <strong>' . $ObjectId . "</strong><br>\n";
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
            $content .= $companylangs->transnoentitiesnoconv("PaymentMethod") . ': <strong>' . 'Novalnet' . "</strong><br>\n";
            $content .= $companylangs->transnoentitiesnoconv("TransactionId") . ': <strong>' . $this->parentTid . "</strong><br>\n";
            $content .= $companylangs->transnoentitiesnoconv("ReturnURLAfterPayment") . ': ' . $urlback . "<br>\n";
            $content .= $companylangs->transnoentitiesnoconv("TransactionComment") . ': ' .$companylangs->trans($this->emailBody). "<br>\n";
            $content .= $companylangs->transnoentitiesnoconv("TransactionStatus") . ': ' .$this->response['transaction']['status']. "<br>\n";
            $content .= "<br>\n";
            $content .= "tag=" . $this->fullTag . "<br>\npaymentType=" . $this->paymentMethod . "<br>\ncurrencycode=" . $this->currency . "<br>\nipaddress=" . getUserRemoteIP() . "<br>\nFinalPaymentAmt=" . $this->amount . "<br>\n";

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
                    $this->novalnet_syslog("Callback E-Mail sent to " . $sendto, '',LOG_NOTICE );
                } else {
                    $this->novalnet_syslog("Callback Failed to send E-Mail to " . $sendto, '', LOG_ERR);
                }
            } catch(\Exception $e) {
                $this->novalnet_syslog("Error sending end customer mail " . $e, '', LOG_ERR);
                $this->displayMessage("Error sending end customer mail $e");
            }
        }
    }

    /**
     * To update novalnet callback table.
     *
     * @return void.
     */
    public function updateNovalnetCallback() {
        global $db;
        $error = 0;
        $sql = 'SELECT * '; 
        $sql .= 'FROM '.MAIN_DB_PREFIX.'novalnet_callback '; 
        $sql .= 'WHERE order_id = "'. $db->escape($this->orderNo) .'"'; 
        $result = $db->query($sql); 
        if ($result) {
            $nbrows = $db->num_rows($result);
            if ($nbrows == 1) {
                $obj = $db->fetch_object($result);
                $callbackAmount = $obj->callback_amount;
            }
            elseif ($nbrows > 1) {
                $this->novalnet_syslog('More than one row is getting for order '.$this->response['transaction']['order_no'].' in the call back table', '', LOG_ERR);
                print 'more than one row';
            }
        }
        if(!empty($callbackAmount) && $nbrows != 0) {
                $callbackAmount = $callbackAmount + $this->response['transaction']['amount'];
                $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'novalnet_callback SET ';
                $sql .= 'reference_tid = "' . $db->escape($this->parentTid) . '", ';
                $sql .= 'callback_tid = "' . $db->escape($this->eventTid) . '", ';
                $sql .= 'callback_datetime = "' . $db->escape($this->currentDate) . '", ';
                $sql .= 'callback_amount = "' . $db->escape($callbackAmount) . '" ';
                $sql .= 'WHERE order_id = "' . $db->escape($this->orderNo) . '"';
            } else {
                $nextid = $this->getNextId('novalnet_callback');
                $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'novalnet_callback (rowid, order_id, callback_amount, reference_tid, callback_tid , callback_datetime)';
                $sql .= ' VALUES (' . $db->escape($nextid) . ", '" . $db->escape($this->orderNo) . "','" . $db->escape($this->response['transaction']['amount']) . "' ,'" . $db->escape($this->parentTid) . "','" . $db->escape($this->eventTid) . "','" . $db->escape($this->currentDate) . "')";
            }
            
            $resql = $db->query($sql);
            if (!$resql) {
                $error++;
            }
        
            if (!$error) {
                $db->commit();
            } else {
                $this->novalnet_syslog('Due to sql releated error data will be roll back','', LOG_ERR);
                $db->rollback();
            }
    
    }

    /**
     * Returns the next available ID for inserting a new transaction into the database.
     *
     * @return int The next available transaction ID.
     */
    public function getNextId($table) 
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

    public function updateCreditNote($object) 
    {
      global $db;
      $error = 0;
      $refund_amount = $this->response['transaction']['refund']['amount'];
      $sql = 'SELECT * '; 
      $sql .= 'FROM '.MAIN_DB_PREFIX.'novalnet_credit_note '; 
      $sql .= 'WHERE order_id = "'. $db->escape($this->orderNo) .'"'; 
      $result = $db->query($sql); 
      if ($result) {
          $nbrows = $db->num_rows($result);
          if ($nbrows == 1) {
              $obj = $db->fetch_object($result);
              $alreadyRefunded = $obj->refunded_amount;
          }
          elseif ($nbrows > 1) {
              $this->novalnet_syslog('More than one row is getting for order '.$this->response['transaction']['order_no'].' in the call back table', '', LOG_ERR);
              print 'more than one row';
          }
      }
      if(!empty($alreadyRefunded) && $nbrows != 0) {
              $refundedAmount = $alreadyRefunded + $refund_amount;
              $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'novalnet_credit_note SET ';
              $sql .= 'reference_tid = "' . $db->escape($this->parentTid) . '", ';
              $sql .= 'callback_tid = "' . $db->escape($this->eventTid) . '", ';
              $sql .= 'callback_datetime = "' . $db->escape($this->currentDate) . '", ';
              $sql .= 'refunded_amount = "' . $db->escape($refundedAmount) . '" ';
              $sql .= 'WHERE order_id = "' . $db->escape($this->orderNo) . '"';
          } else {
              $nextid = $this->getNextId('novalnet_credit_note');
              $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'novalnet_credit_note(rowid, order_id, ref_id, credit_note_id, refunded_amount, reference_tid, callback_tid , callback_datetime)';
              $sql .= ' VALUES (' . $db->escape($nextid) . ", '" . $db->escape($this->orderNo) . "','" . $db->escape($object->id) . "' ,'" . $db->escape($object->ref) . "','" . $db->escape($refund_amount) . "','" . $db->escape($this->parentTid) . "','" . $db->escape($this->eventTid) . "','" . $db->escape($this->currentDate) . "')";
          }
          
          $resql = $db->query($sql);
          if (!$resql) {
              $error++;
          }
      
          if (!$error) {
              $db->commit();
          } else {
              $this->novalnet_syslog('Due to sql releated error data will be roll back','', LOG_ERR);
              $db->rollback();
          }

    }

    /**
     * To add refund payment data and bank details
     *
     * @return void
     */
    public function addRefundPayment($object ,$refund_amount) {
        global $db , $postactionmessages , $ispostactionok , $langs;

        $thirdparty = new Societe($db);
        if ($object->socid > 0) {
            $thirdparty->fetch($object->socid);
        }
        
        $multicurrency_code = array();
        $multicurrency_tx = array();
        
        // Clean parameters amount if payment is for a credit note
            $tmpinvoice = new Facture($db);
            $tmpinvoice->fetch($object->id);
            if ($tmpinvoice->type == Facture::TYPE_CREDIT_NOTE) {
                $newvalue = price2num($refund_amount, 'MT');
                $amounts[$object->id] = - abs((float) $newvalue);
            }
            $multicurrency_code[$object->id] = $tmpinvoice->multicurrency_code;
            $multicurrency_tx[$object->id] = $tmpinvoice->multicurrency_tx;

            $tmpinvoice = new Facture($db);
            $tmpinvoice->fetch($object->id);
            if ($tmpinvoice->type == Facture::TYPE_CREDIT_NOTE) {
                $newvalue = price2num($refund_amount, 'MT');
                $multicurrency_amounts[$object->id] = - abs((float) $newvalue);
            }
            $multicurrency_code[$object->id] = $tmpinvoice->multicurrency_code;
            $multicurrency_tx[$object->id] = $tmpinvoice->multicurrency_tx;

        // Creation of payment line
        $paiement = new Paiement($db);
        $paiement->datepaye = dol_now();
        $paiement->amounts = $amounts; // Array with all payments dispatching with invoice id
        $paiement->multicurrency_amounts = $multicurrency_amounts; // Array with all payments dispatching
        $paiement->multicurrency_code = $multicurrency_code; // Array with all currency of payments dispatching
        $paiement->multicurrency_tx = $multicurrency_tx; // Array with all currency tx of payments dispatching
        $paiement->paiementid   = 'NOV';
        $paiement->fk_account   = $object->fk_account;
        
        $paymentType = 'NOV';
        $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
        $paiement->paiementid   = $paymentTypeId;
        $paiement->ext_payment_id = $this->eventTid;

        $paiement_id =0;

        $paiement_id = $paiement->create($this->user, 1 , $thirdparty); 

        if ($paiement_id < 0) {
            $postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
            $ispostactionok = -1;
            $this->novalnet_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors).' for order '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
        } else {
            $postactionmessages[] = $langs->trans('NOVALNET_PAYMENT_CREATED');
            $ispostactionok = 1;
            $this->novalnet_syslog('Payment created for ID => '.$this->response['transaction']['order_no'].' with TID'.$this->response['transaction']['tid'].'', '', LOG_NOTICE);
        } 

        $this->addPaymentToBank($paiement);
        $db->commit();
    }

    /**
     * To add  bank details 
     *
     * @return void
     */
    public function addPaymentToBank($paiement) {
        global $postactionmessages , $ispostactionok , $conf , $langs;
        if (isModEnabled("banque")) {
            $bankaccountid = 0;
            $bankaccountid = !empty($conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS) ? $conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS : 0;
            if ($bankaccountid > 0) {
                $label = '(CustomerInvoicePayment)';
                if ($this->object->type == Facture::TYPE_CREDIT_NOTE) {
                    $label = '(CustomerInvoicePaymentBack)'; // Refund of a credit note
                }
                $result = $paiement->addPaymentToBank($this->user, 'payment', $label, $bankaccountid, '', '');
                if ($result < 0) {
                    $message = 'Error While trying to add payments to the bank details';
                    $postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
                    $ispostactionok = -1;
                    $this->novalnet_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors).'for order '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                } else {
                    $postactionmessages[] = $langs->trans('NOVALNET_BANK_TRNS_CREATED');
                    $ispostactionok = 1;
                    $this->novalnet_syslog('Bank transaction of payment created for ID => '.$this->response['transaction']['order_no'].' with TID'.$this->response['transaction']['tid'].'', '', LOG_NOTICE);
                }
            } else {
                $postactionmessages[] = $langs->trans('NOVALNET_SETUP_BANK_ACC');
                $ispostactionok = -1;
                $this->novalnet_syslog('Setup of bank account for Novalnet payments was not set. No way to record the payment.', '',LOG_WARNING);
            }
        }
    }

  }
$data = file_get_contents('php://input');
new NovalnetCallback($data ,$user);
}

?>
