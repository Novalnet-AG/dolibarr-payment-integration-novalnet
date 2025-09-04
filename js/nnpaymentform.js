/**
 * Novalnet payment module
 * This script is used for loading payments from Novalnet
 *
 * @author     Novalnet AG
 * @copyright  Copyright (c) Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @link       https://www.novalnet.de
 *
 * Script : novalnet_payment_form.js
 *
 */

    const novalnetPaymentIframe = new NovalnetPaymentForm();
    const paymentFormRequestObj = {
        iframe: '#novalnetIframe',
        initForm : {
            orderInformation : {
                billing: {},
            },
            uncheckPayments: true,
            showButton : false
        }
    };


    /**
     * Initiate the payment form IFRAME
     */
    novalnetPaymentIframe.initiate(paymentFormRequestObj);

    /**
     * Wallet payments response callback
     */
    novalnetPaymentIframe.walletResponse({
        onProcessCompletion: (response) => { 
            if (response.result.status == 'SUCCESS') {
                $('#nn_payment_details').val(JSON.stringify(response));
                let submitEl = $("div #paymentSubmit :submit");
                $(submitEl).click();
                return {status: 'SUCCESS', statusText: 'successfull'};
            } else {
                return {status: 'FAILURE', statusText: 'failure'};
            }
        }
    });

    /**
     * Payment form validation result callback
     */
    novalnetPaymentIframe.validationResponse((data) => { });

    /**
     * Gives selected payment method
     */
    novalnetPaymentIframe.selectedPayment((data) => {
         if (data.payment_details.type == 'GOOGLEPAY' || data.payment_details.type == 'APPLEPAY') {
               $('.buttonpayment').hide();
         } else {
            $('.buttonpayment').show();
         }
        });
    
    document.querySelector('#paymentSubmit').addEventListener('click', function (event) {
        if ($('#nn_payment_details').val() == '') {
            event.preventDefault();
            event.stopImmediatePropagation();
            novalnetPaymentIframe.getPayment((data) => {
                if(novalnetPaymentIframe && data.result.status == 'SUCCESS') {
                    $('#nn_payment_details').val(JSON.stringify(data));
                    $('form[name="novalnetpaymentform"]').submit();
                    return true;
                }
            });
        }
    });

    $(document).ready(function() {
        $('.buttonpayment').hide(); // Hides the button with ID
    });
    
    

    

