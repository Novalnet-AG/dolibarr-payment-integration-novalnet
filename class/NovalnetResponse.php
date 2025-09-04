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

require_once 'NovalnetApi.php';

if (! class_exists('NovalnetResponse', false)) {

    /**
     * Class representing the result of a transaction (sent by the IPN URL or by the client return).
     */
    class NovalnetResponse
    {

        /**
         * Raw response parameters array.
         *
         * @var array[string][string]
         */
        private $rawResponse = array();

        /**
         * value of custAddress
         *
         * @var string
         */
        private $custAddress;

        /**
         * custCity 
         *
         * @var string
         */
        private $custCity;

        /**
         * Value of vads_currency
         *
         * @var string
         */
        private $currency;

        /**
         * Value of vads_amount
         *
         * @var mixed
         */
        private $amount;
         
        /**
         * Value of vads_rawAmount
         *
         * @var mixed
         */
        private $rawAmount;
        
        /**
         * value of vads_extInfoFullTag
         *
         * @var string
         */
        private $extInfoFullTag;
        
        /**
         * value of vads_custCountry
         *
         * @var string
         */
        private $custCountry;
        
        /**
         * value of vads_custEmail
         *
         * @var string
         */
        private $custEmail;
        
        /**
         * value of vads_custFirstName
         *
         * @var string
         */
        private $custFirstName;
        
        /**
         * value of vads_custLastName
         *
         * @var string
         */
        private $custLastName;
        
        /**
         * value of vads_custPhone
         *
         * @var string
         */
        private $custPhone;
        
        /**
         * value of vads_custState
         *
         * @var string
         */
        private $custState;
        
        /**
         * value of vads_custZip
         *
         * @var string
         */
        private $custZip;
        
        /**
         * value of vads_language
         *
         * @var string
         */
        private $language;
        
        /**
         * value of vads_orderId
         *
         * @var string
         */
        private $orderId;
        
        /**
         * value of vads_ref
         *
         * @var string
         */
        private $ref;


        /**
         * Constructor for NovalnetResponse class.
         * Prepare to analyse check URL or return URL call.
         *
         * @param array[string][string] $params
         */
        public function __construct($params)
        {
            $this->rawResponse = $params;
           
            $this->nn_payment_details = self::findInArray('nn_payment_details', $this->rawResponse, null);
            $this->amount = self::findInArray('vads_amount', $this->rawResponse, null);
            $this->currency = self::findInArray('vads_currency', $this->rawResponse, null);
            $this->custAddress = self::findInArray('vads_cust_address', $this->rawResponse, null);
            $this->custCity = self::findInArray('vads_cust_city', $this->rawResponse, null);
            $this->custCountry = self::findInArray('vads_cust_country', $this->rawResponse, null);
            $this->custEmail = self::findInArray('vads_cust_email', $this->rawResponse, null);
            $this->custFirstName = self::findInArray('vads_cust_first_name', $this->rawResponse, null);
            $this->custLastName = self::findInArray('vads_cust_last_name', $this->rawResponse, null);
            $this->custPhone = self::findInArray('vads_cust_phone', $this->rawResponse, null);
            $this->custState = self::findInArray('vads_cust_state', $this->rawResponse, null);
            $this->custZip = self::findInArray('vads_cust_zip', $this->rawResponse, null);
            $this->language = self::findInArray('vads_language', $this->rawResponse, null);
            $this->orderId = self::findInArray('vads_order_id', $this->rawResponse, null);
            $this->ref = self::findInArray('vads_ref', $this->rawResponse, null);
            $this->rawAmount = self::findInArray('vads_raw_amount', $this->rawResponse, null);
            $this->extInfoFullTag = self::findInArray('vads_ext_info_full_tag', $this->rawResponse, null);
        }


        /**
         * Return the value of a response parameter.
         * @param string $name
         * @return string
         */
        public function get($name, $hasPrefix = true)
        {
            if ($hasPrefix) {
                // Manage shortcut notations by adding 'vads_' prefix.
                $name = (substr($name, 0, 5) != 'vads_') ? 'vads_' . $name : $name;
            }

            return array_key_exists($name, $this->rawResponse) ? $this->rawResponse[$name] : null;
        }

        /**
         * Shortcut for getting ext_info_* fields.
         * @param string $key
         * @return string
         */
        public function getExtInfo($key)
        {
            return $this->get("ext_info_$key");
        }

        /**
         * Return the expected signature received from gateway.
         * @return string
         */
        public function getSignature()
        {
            return $this->get('signature', false);
        }

        /**
         * Return the paid amount converted from cents (or currency equivalent) to a decimal value.
         * @return float
         */
        public function getFloatAmount()
        {
            $currency = NovalnetApi::findCurrencyByNumCode($this->get('currency'));
            return $currency->convertAmountToFloat($this->get('amount'));
        }


        /**
         * Return the NnPaymentDetails.
         * @return string
         */
        public function getNnPaymentDetails()
        {
            return $this->nn_payment_details;
        }

        /**
         * Return the amount.
         * @return string
         */
        public function getAmount()
        {
            return $this->amount;
        }
        
        /**
         * Return the currency.
         * @return string
         */
        public function getCurrency()
        {
            return $this->currency;
        }
        
        /**
         * Return the customer address.
         * @return string
         */
        public function getCustAddress()
        {
            return $this->custAddress;
        }
        
        /**
         * Return the customer city.
         * @return string
         */
        public function getCustCity()
        {
            return $this->custCity;
        }
        
        /**
         * Return the customer country.
         * @return string
         */
        public function getCustCountry()
        {
            return $this->custCountry;
        }
        
        /**
         * Return the customer email.
         * @return string
         */
        public function getCustEmail()
        {
            return $this->custEmail;
        }
        
        /**
         * Return the customer first name.
         * @return string
         */
        public function getCustFirstName()
        {
            return $this->custFirstName;
        }
        
        /**
         * Return the customer last name.
         * @return string
         */
        public function getCustLastName()
        {
            return $this->custLastName;
        }
        
        /**
         * Return the customer phone number.
         * @return string
         */
        public function getCustPhone()
        {
            return $this->custPhone;
        }
        
        /**
         * Return the customer state.
         * @return string
         */
        public function getCustState()
        {
            return $this->custState;
        }
        
        /**
         * Return the customer zip code.
         * @return string
         */
        public function getCustZip()
        {
            return $this->custZip;
        }
        
        /**
         * Return the language.
         * @return string
         */
        public function getLanguage()
        {
            return $this->language;
        }
        
        /**
         * Return the order id.
         * @return string
         */
        public function getOrderId()
        {
            return $this->orderId;
        }
        
        /**
         * Return the reference.
         * @return string
         */
        public function getRef()
        {
            return $this->ref;
        }
        
        /**
         * Return the raw amount.
         * @return string
         */
        public function getRawAmount()
        {
            return $this->rawAmount;
        }
        
        /**
         * Return the fulltag.
         * @return string
         */
        public function getExtInfoFullTag()
        {
            return $this->extInfoFullTag;
        }
        
        /**
         * Return the fulltag.
         * @param string $key name
         * @param array[string][string] $array response data
         * @param string $default default value.
         * @return string
         */
        public static function findInArray($key, $array, $default)
        {
            if (is_array($array) && key_exists($key, $array)) {
                return $array[$key];
            }

            return $default;
        }


    }
}
