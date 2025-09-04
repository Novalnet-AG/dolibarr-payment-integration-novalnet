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
                } else {
                    document.querySelector('#error_msg').innerHTML = '';

                    // Create a new error message element
                    var errorMessage = data.result.message || 'An unknown error occurred.';
                    var errorDiv = document.createElement('div');
                    errorDiv.classList.add('error-message');
                    errorDiv.style.color = 'red';
                    errorDiv.style.fontSize = '16px';
                    errorDiv.style.padding = '10px';
                    errorDiv.style.backgroundColor = '#ffe6e6'; // Optional: light red background for emphasis
                    
                    errorDiv.innerHTML = errorMessage;
                    // Append the new error message to the #error_msg container
                    document.querySelector('#error_msg').appendChild(errorDiv);
                    // Scroll to the error message
                    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
            });
        }
    });

    $(document).ready(function() {
        $('.buttonpayment').hide(); // Hides the button with ID
    });
    
    

    

