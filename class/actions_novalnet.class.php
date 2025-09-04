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
 * This file contains all methods to be executed as hooks.
 */

$langs->load("novalnet@novalnet");

/**
 * Class ActionsNovalnet
 */
class ActionsNovalnet
{
    /**
     * @var DoliDB database handler.
     */
    public $db;

    /**
     * @var string error code (or message).
     */
    public $error = '';

    /**
     * @var array errors.
     */
    public $errors = array();

    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse.
     */
    public $results = array();

    /**
     * @var string displayed by executeHook() immediately after return.
     */
    public $resprints;

    /**
     * Constructor.
     *
     *  @param    DoliDB        $db      Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Execute action.
     *
     * @param    array           $parameters     Array of parameters
     * @param    CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
     * @param    string          $action         'add', 'update', 'view'
     * @return   int                             <0 if KO,
     *                                           =0 if OK but we want to process standard actions too,
     *                                           >0 if OK and we want to replace standard actions
     */
    public function getNomUrl($parameters, &$object, &$action)
    {
        $this->resprints = '';
        return 0;
    }

    /**
     * Print Novalnet Payment Button on screen.
     *
     * @param   array            $parameters     Array of parameters
     * @param   Object            $object         Object output
     * @param   string            $action         'add', 'update', 'view'
     * @param   HookManager       $hookmanager    Hook manager propagated to allow calling another hook
     * @return  int                               < 0 on error, 0 on success, 1 to replace standard code
     */
    public function doAddButton($parameters, &$object, &$action, $hookmanager)
    { 
        global $conf, $langs;

        $source = GETPOST("s", 'alpha') ? GETPOST("s", 'alpha') : GETPOST("source", 'alpha');

        if ($source === 'invoice' ||  $source === 'order') {

         if (in_array($parameters['currentcontext'], array('newpayment')) && $conf->novalnet->enabled && !empty($conf->global->NOVALNET_PAYMENT_KEY) && !empty($conf->global->NOVALNET_PRODUCT_ACTIVATION)) {

                if (empty($conf->banque->enabled) || empty($conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS) || ($conf->global->NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS == -1)) {
                    dol_htmloutput_mesg($langs->trans('NOVALNET_WARNING_BANK'), '', 'warning');
                }

                
                    $result =  '<br>';
                    $result .= '<div class="button buttonpayment" id="div_dopayment_novalnet">';
                    $result .= '<span class="fa fa-credit-card"></span>';
                    $result .= '<input class="" type="submit" id="dopayment_novalnet" name="dopayment_novalnet" value="' . $langs->trans("NOVALNET_PAY_BUTTON") . '">';
                    $result .= '<br>';
                    $result .= '<span class="buttonpaymentsmall">' . $langs->trans("NOVALNET_PAY_BUTTON_MESSAGE") . '</span>';
                    $result .= '</div>';
                    $result .= '<script>
                                    $( document ).ready(function() {
                                        $("#div_dopayment_novalnet").click(function() {
                                            $("#dopayment_novalnet").click();
                                        });
                                        $("#dopayment_novalnet").click(function(e) {
                                            $("#div_dopayment_novalnet").css( \'cursor\', \'wait\' );
                                            e.stopPropagation();
                                            return true;
                                        });
                                    });
                                </script>
                                ';
                                                    
                    $this->resprints = $result;
            }
        }

            return 0; // Or return 1 to replace standard code.

    }

    /**
     * Check status of $object (invoice, order, donation...) to show a message in newpayment.php.
     *
     * @param   array             $parameters   Array of parameters
     * @param   Object            $object       Object output
     * @param   string            $action       'add', 'update', 'view'
     * @param   HookManager       $hookmanager  Hook manager propagated to allow calling another hook
     * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
     */
    public function doCheckStatus($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs;

        if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
            
            $paymentmethod = '';
            if(isset($parameters['paymentmethod']) && !empty($parameters['paymentmethod'])) {
                $paymentmethod = $parameters['paymentmethod'];
            }
            $source = $parameters['source'];
            $object = $parameters['object'];

            if ((empty($paymentmethod) || $paymentmethod == 'novalnet') && ! empty($conf->novalnet->enabled)) {
                
                if (in_array($source, ['invoice', 'order']) && $object->paye != 1 && strripos($object->note_private, 'ON_HOLD') || strripos($object->note_private, 'PENDING')) {
                    print '<br><br><span class="amountpaymentcomplete size12x">' . $langs->trans("InvoicePending") . '</span>';
                    exit;
                }
                if ($source == 'order' && $object->billed) {
                    print '<br><br><span class="amountpaymentcomplete size12x">' . $langs->trans("OrderBilled") . '</span>';
                    exit;
                }

                if ($source == 'invoice' &&  $object->paye == 1) {
                    print '<br><br><span class="amountpaymentcomplete size12x">' . $langs->trans("InvoicePaid") . '</span>';
                    exit;
                }
                if (in_array($source, ['invoice', 'order']) && $object->paye != 1 && strripos($object->note_private, 'PAYMENT_AWAIT')) { 
                    print '<br><br><span class="amountpaymentcomplete size12x">' . $langs->trans("PaymentPending") . '</span>';
                    exit;
                }
            }
           
        }

            return 1; // Or return 1 to replace standard code.


    }

    /**
     * Start payment process with Novalnet.
     *
     * @param   array        $parameters    Array of parameters
     * @param   Object       $object        Object output
     * @param   string       $action        'add', 'update', 'view'
     * @param   HookManager  $hookmanager   Hook manager propagated to allow calling another hook
     * @return  int                        < 0 on error, 0 on success, 1 to replace standard code
     */
    public function doPayment($parameters, &$object, &$action, $hookmanager)
    {  
        dol_include_once('/novalnet/lib/novalnet.lib.php');
        
        global $conf;
        if (in_array($parameters['currentcontext'], array('newpayment'))) {
            if ($parameters['paymentmethod'] == 'novalnet' && !empty($parameters['amount'])) {
                $tag = (empty($parameters['tag']) ? GETPOST("ref", 'alpha') : $parameters['tag']);
                novalnetForm($tag);
                return 0;
            }
        }

    }

    /**
     * Set Novalnet as a valid payment.
     *
     * @param   array        $parameters    Array of parameters
     * @param   Object       $object        Object output
     * @param   string       $action        'add', 'update', 'view'
     * @param   HookManager  $hookmanager   Hook manager propagated to allow calling another hook
     * @return  int                        < 0 on error, 0 on success, 1 to replace standard code
     */
    public function getValidPayment($parameters, &$object, &$action, $hookmanager)
    {
        $parameters['validpaymentmethod']['novalnet'] = 'valid';
        return 0;
    }
    
}
