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

if (! class_exists('NovalnetRest', false)) {

    /**
     * Cient class for REST web services API.
     */
    class NovalnetRest
    {
        
        private $privateKey = null;
        
        /**
         * To send the request data to the payment server
         * @param string $target endpoint.
         * @param array $data request data .
         * @param string $paymentAccessKey key for auth.
         * @return  array $response response.
         */  
        public function post($target, $data ,$paymentAccessKey)
        {  
            if (extension_loaded('curl')) {
                return $this->curlPost($target, $data ,$paymentAccessKey);
            } else {
                novalnet_syslog('curl extention not loaded', '', LOG_ERR);
                return null;
            }
        }
        
        /**
         * To send the request data to the payment server
         * @param string $target endpoint.
         * @param array $data request data .
         * @param string $paymentAccessKey key for auth.
         * @return  array $response response.
         */  
        public function curlPost($target, $data ,$paymentAccessKey)
        {  
            global $conf;
            $url = 'https://payport.novalnet.de/v2/'.$target;
            $payment_access_key = !empty($conf->global->NOVALNET_PAYMENT_KEY) ? $conf->global->NOVALNET_PAYMENT_KEY : $paymentAccessKey;
            $encoded_data = base64_encode($payment_access_key);
            $headers = [
                'Content-Type: application/json',
                'Charset: utf-8',
                'Accept: application/json',
                'X-NN-Access-Key: ' . $encoded_data,
            ];
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_USERPWD, $this->privateKey);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            $raw_response = curl_exec($curl);

            $info = curl_getinfo($curl);
            if (!in_array($info['http_code'], array(200, 401))) {
                $error = curl_error($curl);
                $errno = curl_errno($curl);
                curl_close($curl);

                $msg = "Call to URL $url failed with unexpected status: {$info['http_code']}";

                if ($raw_response) {
                    $msg .= ", raw response: $raw_response";
                }

                if ($errno) {
                    $msg .= ", cURL error: $error ($errno)";
                }

                $msg .= ", cURL info: " . print_r($info, true);
            
                novalnet_syslog($msg, '', LOG_ERR);
            }

            $response = json_decode($raw_response, true);
            if (!is_array($response)) {
                $error = curl_error($curl);
                $errno = curl_errno($curl);
                curl_close($curl);

                $msg = "Call to URL $url returned an unexpected response, raw response: $raw_response";

                if ($errno) {
                    $msg .= ", cURL error: $error ($errno)";
                }

                $msg .= ", cURL info: " . print_r($info, true);

                novalnet_syslog($msg, '', LOG_ERR);
            }

            curl_close($curl);

            return $response;
        }

    }
}
