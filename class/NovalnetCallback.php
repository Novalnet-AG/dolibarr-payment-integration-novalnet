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
        'PAYCONIQ' => 'Payconiq'
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
                //have to log
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
        $this->currency = $this->response['transaction']['currency']; // Get order currency
        $this->paymentType = $this->response['transaction']['payment_type'];
        $this->paymentMethod = $this->paymentTitles[$this->paymentType];
        $this->amount = number_format($this->response['transaction']['amount'] / 100, 2);
        
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
        global $langs;

        $this->displayMessage($langs->trans('NOVALNET_NOT_HANDLED',$this->eventType));
        return false;
    }
    
    /**
     * Handle payment refund/bookback process
     *
     * @return void
     */
    private function refundProcess()
    { 
         global $langs;

        $this->displayMessage($langs->trans('NOVALNET_NOT_HANDLED',$this->eventType));
        return false;
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
        $now = dol_now();
        $error = 0;
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
                $this->sendMailToEndCustomer($message);
                } else {
                    $this->novalnet_syslog('Invoice not found for the order no => '.$this->response['transaction']['order_no'].'', '', LOG_ERR);
                    $message = 'Invoice not found for the order no => '.$this->response['transaction']['order_no'].'';
                }
        }
    } elseif($transactionStatus == 'ON_HOLD') {
        if($this->response['transaction']['status'] == 'CONFIRMED'){
            if ($result) {
                $message = $this->createPayment($objectId);
                $this->sendMailToEndCustomer($message);
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
    private function updatePrivateNotes($object , $rowId) {
        global $db;
        $error = 0;  
        $key = '##';
        $note_private = $key . 'STATUS=' . $this->response['transaction']['status'] . $key;
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $object->element . ' SET';
        $sql .= " note_private='" . $note_private . "'";
        $sql .= ' WHERE rowid = ' . $rowId;
        $resql = $db->query($sql);
        if (! $resql) {
            $error++;
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
            $sql .= 'WHERE order_id = "'.$this->orderNo.'"'; 
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

        if ($this->response['transaction']['currency']) {
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
                $sql.= " and p.ext_payment_id  = '" . $ext_payment_id . "'";
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
        $error = 0;
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
                    $error++;
                } else {
                    $postactionmessages[] = 'Payment created';
                    $ispostactionok = 1;
                    $this->novalnet_syslog('Payment created for ID => '.$this->response['transaction']['order_no'].' with TID'.$this->response['transaction']['tid'].'', '', LOG_NOTICE);
                }

            if (!$error && isModEnabled("banque")) {
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
                        $error++;
                    } else {
                        $postactionmessages[] = 'Bank transaction of payment created';
                        $ispostactionok = 1;
                        $this->novalnet_syslog('Bank transaction of payment created for ID => '.$this->response['transaction']['order_no'].' with TID'.$this->response['transaction']['tid'].'', '', LOG_NOTICE);
                    }
                } else {
                    $postactionmessages[] = 'Setup of bank account to use in module ' . $paymentmethod . ' was not set. No way to record the payment.';
                    $ispostactionok = -1;
                    $this->novalnet_syslog('Setup of bank account for Novalnet payments was not set. No way to record the payment.', '',LOG_WARNING);
                }
            }

            $db->commit();
            if($this->eventType == 'TRANSACTION_CAPTURE') {
                $message = $langs->trans("NOVALNET_TRANSACTION_CONFIRM",$this->currentDate);
                $this->sendMailToEndCustomer($message);
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
        if ($this->eventType == 'CREDIT') {
            $note_public = $object->note_public. "\n"; // Initialize note_public variable.
        }
        if ($this->parentTid && $this->eventType != 'CREDIT') {
            //Add payment method and transaction details to the note.
            $note_public = 'Payment Method: ' . $this->paymentMethod . "\n";
            $note_public .= 'Novalnet Transaction ID: ' . $this->parentTid. "\n";
        }
           if(in_array($this->response['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
           $note_public .= $langs->trans('NOVALNET_GUARANTEE')."\n";
        }

        if(!empty($this->response['transaction']['due_date']) && in_array($this->response['transaction']['payment_type'],['INSTALMENT_INVOICE','PREPAYMENT','GUARANTEED_INVOICE','INVOICE']) 
        && $this->eventType != 'CREDIT' && $this->response['transaction']['status'] != 'ON_HOLD') {
            $note_public .=  $langs->trans('NOVALNET_DUEDATE_TEXT',$this->amount,$this->response['transaction']['due_date'])."\n";
        }
        
        if (!empty($this->response['transaction']['bank_details']) && $this->eventType != 'CREDIT') {
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
        $sql .= 'payment_status = "' . $db->escape($response['transaction']['status']) . '", ';
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
    
        $error = 0;

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
                $content .= $companylangs->trans("Link") . ': <a href="' . $url . '">' . $url . '</a>' . "<br>\n";
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
        $sql = 'SELECT * '; 
        $sql .= 'FROM '.MAIN_DB_PREFIX.'novalnet_callback '; 
        $sql .= 'WHERE order_id = "'.$this->orderNo.'"'; 
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
                $nextid = $this->getNextId();
                $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'novalnet_callback (rowid, order_id, callback_amount, reference_tid, callback_tid , callback_datetime)';
                $sql .= ' VALUES (' . $nextid . ", '" . $db->escape($this->orderNo) . "','" . $db->escape($this->response['transaction']['amount']) . "' ,'" . $db->escape($this->parentTid) . "','" . $db->escape($this->eventTid) . "','" . $db->escape($this->currentDate) . "')";
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
    public function getNextId() 
    {
        global $db;
        $newid = 0;

        // Get the maximum row ID and increment it
        $sql = "SELECT MAX(rowid) newid FROM " . MAIN_DB_PREFIX . "novalnet_callback";
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

  }

$data = file_get_contents('php://input');
new NovalnetCallback($data ,$user);
}

?>
