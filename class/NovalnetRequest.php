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

if (! class_exists('NovalnetRequest', false)) {

    /**
     * Class managing and preparing request parameters and HTML rendering of request.
     */
    class NovalnetRequest
    {

        /**
         * The fields to send to the payment gateway.
         *
         * @var array[string][NovalnetField]
         * @access private
         */
        private $requestParameters;

        /**
         * Certificate to use in TEST mode.
         *
         * @var string
         * @access private
         */
        private $keyTest;

        /**
         * Certificate to use in PRODUCTION mode.
         *
         * @var string
         * @access private
         */
        private $keyProd;

        /**
         * URL of the payment page.
         *
         * @var string
         * @access private
         */
        private $platformUrl;

        /**
         * Set to true to send the vads_redirect_* parameters.
         *
         * @var boolean
         * @access private
         */
        private $redirectEnabled;

        /**
         * Algo used to sign forms.
         *
         * @var string
         * @access private
         */
        private $algo = NovalnetApi::ALGO_SHA1;

        /**
         * The original data encoding.
         *
         * @var string
         * @access private
         */
        private $encoding;

        /**
         * The list of categories for payment with Accord bank.
         * To be sent with the products detail if you use this payment mean.
         */
        public static $ACCORD_CATEGORIES = array(
            'FOOD_AND_GROCERY',
            'AUTOMOTIVE',
            'ENTERTAINMENT',
            'HOME_AND_GARDEN',
            'HOME_APPLIANCE',
            'AUCTION_AND_GROUP_BUYING',
            'FLOWERS_AND_GIFTS',
            'COMPUTER_AND_SOFTWARE',
            'HEALTH_AND_BEAUTY',
            'SERVICE_FOR_INDIVIDUAL',
            'SERVICE_FOR_BUSINESS',
            'SPORTS',
            'CLOTHING_AND_ACCESSORIES',
            'TRAVEL',
            'HOME_AUDIO_PHOTO_VIDEO',
            'TELEPHONY'
        );

        public function __construct($encoding = 'UTF-8')
        {
            // Initialize encoding.
            $this->encoding = in_array(strtoupper($encoding), NovalnetApi::$SUPPORTED_ENCODINGS) ?
                strtoupper($encoding) : 'UTF-8';

            // Parameters' regular expressions.
            $ans = '[^<>]'; // Any character (except the dreadful "<" and ">").
            $an63 = '#^[A-Za-z0-9]{0,63}$#u';
            $ans255 = '#^' . $ans . '{0,255}$#u';
            $ans127 = '#^' . $ans . '{0,127}$#u';
            $supzero = '[1-9]\d*';
            $regex_payment_cfg = '#^(SINGLE|MULTI:first=\d+;count=' . $supzero . ';period=' . $supzero . ')$#u';
            // AAAAMMJJhhmmss
            $regex_trans_date = '#^\d{4}(1[0-2]|0[1-9])(3[01]|[1-2]\d|0[1-9])(2[0-3]|[0-1]\d)([0-5]\d){2}$#u';
            $regex_sub_effect_date = '#^\d{4}(1[0-2]|0[1-9])(3[01]|[1-2]\d|0[1-9])$#u';
            $regex_mail = '#^[^@]+@[^@]+\.\w{2,4}$#u';
            $regex_params = '#^([^&=]+=[^&=]*)?(&[^&=]+=[^&=]*)*$#u'; // name1=value1&name2=value2....
            $regex_ship_type = '#^RECLAIM_IN_SHOP|RELAY_POINT|RECLAIM_IN_STATION|PACKAGE_DELIVERY_COMPANY|ETICKET$#u';
            $regex_payment_option = '#^[a-zA-Z0-9]{0,32}$|^COUNT=([1-9][0-9]{0,2})?;RATE=[0-9]{0,4}(\\.[0-9]{1,4})?;DESC=.{0,64};?$#';

            // Defining all parameters and setting formats and default values.
            $this->addField('signature', 'Signature', '#^[0-9a-f]{40}$#u', true);

            $this->addField('vads_amount', 'Amount', '#^' . $supzero . '$#u', true);
            $this->addField('vads_available_languages', 'Available languages', '#^(|[A-Za-z]{2}(;[A-Za-z]{2})*)$#u', false, 2);
            $this->addField('vads_currency', 'Currency', '#^\d{3}$#u', true, 3);
            $this->addField('vads_cust_address', 'Customer address', $ans255);
            $this->addField('vads_cust_antecedents', 'Customer history', '#^NONE|NO_INCIDENT|INCIDENT$#u');
            $this->addField('vads_cust_cell_phone', 'Customer cell phone', $an63, false, 63);
            $this->addField('vads_cust_city', 'Customer city', '#^' . $ans . '{0,63}$#u', false, 63);
            $this->addField('vads_cust_country', 'Customer country', '#^[A-Za-z]{2}$#u', false, 2);
            $this->addField('vads_cust_email', 'Customer email', $regex_mail, false, 127);
            $this->addField('vads_cust_first_name', 'Customer first name', $an63, false, 63);
            $this->addField('vads_cust_id', 'Customer id', $an63, false, 63);
            $this->addField('vads_cust_last_name', 'Customer last name', $an63, false, 63);
            $this->addField('vads_cust_legal_name', 'Customer legal name', '#^' . $ans . '{0,100}$#u', false, 100);
            $this->addField('vads_cust_name', 'Customer name', $ans127, false, 127);
            $this->addField('vads_cust_phone', 'Customer phone', $an63, false, 63);
            $this->addField('vads_cust_state', 'Customer state/region', '#^' . $ans . '{0,63}$#u', false, 63);
            $this->addField('vads_cust_status', 'Customer status (private or company)', '#^PRIVATE|COMPANY$#u', false, 7);
            $this->addField('vads_cust_title', 'Customer title', '#^' . $ans . '{0,63}$#u', false, 63);
            $this->addField('vads_cust_zip', 'Customer zip code', $an63, false, 63);
            $this->addField('vads_language', 'Language', '#^[A-Za-z]{2}$#u', false, 2);
            $this->addField('vads_nb_products', 'Number of products', '#^' . $supzero . '$#u', false);
            $this->addField('vads_order_id', 'Order id', '#^[A-za-z0-9]{0,12}$#u', false, 12);
            $this->addField('vads_order_info', 'Order info', $ans255);
            $this->addField('vads_order_info2', 'Order info 2', $ans255);
            $this->addField('vads_order_info3', 'Order info 3', $ans255);
            $this->addField('vads_ship_to_city', 'Shipping city', '#^' . $ans . '{0,63}$#u', false, 63);
            $this->addField('vads_ship_to_country', 'Shipping country', '#^[A-Za-z]{2}$#u', false, 2);
            $this->addField('vads_ship_to_delay', 'Delay of shipping', '#^INFERIOR_EQUALS|SUPERIOR|IMMEDIATE|ALWAYS$#u', false, 15);
            $this->addField('vads_ship_to_delivery_company_name', 'Name of the delivery company', $ans127, false, 127);
            $this->addField('vads_ship_to_first_name', 'Shipping first name', $an63, false, 63);
            $this->addField('vads_ship_to_last_name', 'Shipping last name', $an63, false, 63);
            $this->addField('vads_ship_to_legal_name', 'Shipping legal name', '#^' . $ans . '{0,100}$#u', false, 100);
            $this->addField('vads_ship_to_name', 'Shipping name', '#^' . $ans . '{0,127}$#u', false, 127);
            $this->addField('vads_ship_to_phone_num', 'Shipping phone', $ans255, false, 63);
            $this->addField('vads_ship_to_speed', 'Speed of the shipping method', '#^STANDARD|EXPRESS|PRIORITY$#u', false, 8);
            $this->addField('vads_ship_to_state', 'Shipping state', $an63, false, 63);
            $this->addField('vads_ship_to_status', 'Shipping status (private or company)', '#^PRIVATE|COMPANY$#u', false, 7);
            $this->addField('vads_ship_to_street', 'Shipping street', $ans127, false, 127);
            $this->addField('vads_ship_to_street2', 'Shipping street (2)', $ans127, false, 127);
            $this->addField('vads_ship_to_type', 'Type of the shipping method', $regex_ship_type, false, 24);
            $this->addField('vads_ship_to_zip', 'Shipping zip code', $an63, false, 63);
            $this->addField('vads_shipping_amount', 'The amount of shipping', '#^' . $supzero . '$#u', false, 12);
            $this->addField('vads_shop_name', 'Shop name', $ans127);
            $this->addField('vads_shop_url', 'Shop URL', '#^https?://(\w+(:\w*)?@)?(\S+)(:[0-9]+)?[\w\#!:.?+=&%@`~;,|!\-/]*$#u');
            $this->addField('vads_tax_amount', 'The amount of tax', '#^' . $supzero . '$#u', false, 12);
            $this->addField('vads_tax_rate', 'The rate of tax', '#^\d{1,2}\.\d{1,4}$#u', false, 6);
            $this->addField('vads_totalamount_vat', 'The total amount of VAT', '#^' . $supzero . '$#u', false, 12);
            $this->addField('vads_trans_date', 'Transaction date', $regex_trans_date, true, 14);
            $this->addField('vads_trans_id', 'Transaction ID', '#^[0-8]\d{5}$#u', true, 6);
            $this->addField('vads_url_cancel', 'Cancel URL', $ans127, false, 127);
            $this->addField('vads_url_error', 'Error URL', $ans127, false, 127);
            $this->addField('vads_url_return', 'Return URL', $ans127, false, 127);
            $this->addField('vads_url_success', 'Success URL', $ans127, false, 127);
            $this->addField('vads_user_info', 'User info', $ans255);
            $this->addField('vads_ref', 'reference', $ans127, false, 127);
            $this->addField('vads_raw_amount', 'Amount Raw', '#^' . $supzero . '$#u', true);

        }

        /**
         * Shortcut function used in constructor to build requestParameters.
         *
         * @param string $name
         * @param string $label
         * @param string $regex
         * @param boolean $required
         * @param mixed $value
         * @return boolean
         */
        private function addField($name, $label, $regex, $required = false, $length = 255, $value = null)
        {
            $this->requestParameters[$name] = new NovalnetField($name, $label, $regex, $required, $length);

            if ($value !== null) {
                return $this->set($name, $value);
            }

            return true;
        }


        /**
         * General getter that retrieves a request parameter with its name.
         * Adds "vads_" to the name if necessary.
         * Example : <code>$site_id = $request->get('site_id');</code>
         *
         * @param string $name
         * @return mixed
         */
        public function get($name)
        {
            if (! $name || ! is_string($name)) {
                return null;
            }

            // Shortcut notation compatibility.
            $name = (substr($name, 0, 5) != 'vads_') ? 'vads_' . $name : $name;

            if (key_exists($name, $this->requestParameters)) {
                return $this->requestParameters[$name]->getValue();
            } else {
                return null;
            }
        }

        /**
         * Set a request parameter with its name and the provided value.
         * Adds "vads_" to the name if necessary.
         * Example : <code>$request->set('site_id', '12345678');</code>
         *
         * @param string $name
         * @param mixed $value
         * @return boolean
         */
        public function set($name, $value)
        {
            if (! $name || ! is_string($name)) {
                return false;
            }

            // Shortcut notation compatibility.
            $name = (substr($name, 0, 5) != 'vads_') ? 'vads_' . $name : $name;

            if (is_string($value)) {
                // Trim value before set.
                $value = trim($value);

                // Convert the parameters' values if they are not encoded in UTF-8.
                if ($this->encoding !== 'UTF-8') {
                    $value = iconv($this->encoding, 'UTF-8', $value);
                }

                // Delete < and > characters from $value and replace multiple spaces by one.
                $value = preg_replace(array('#[<>]+#u', '#\s+#u'), array('', ' '), $value);
            }

            // Search appropriate setter.
           
            if (key_exists($name, $this->requestParameters)) {
                return $this->requestParameters[$name]->setValue($value);
            } else {
                return false;
            }
        }

        /**
         * Set multi payment configuration.
         *
         * @param $total_in_cents total order amount in cents
         * @param $first_in_cents amount of the first payment in cents
         * @param $count total number of payments
         * @param $period number of days between 2 payments
         * @return boolean
         */
        public function setMultiPayment($total_in_cents = null, $first_in_cents = null, $count = 3, $period = 30)
        {
            $result = true;

            if (is_numeric($count) && $count > 1 && is_numeric($period) && $period > 0) {
                // Default values for first and total.
                $total_in_cents = ($total_in_cents === null) ? $this->get('amount') : $total_in_cents;
                $first_in_cents = ($first_in_cents === null) ? round($total_in_cents / $count) : $first_in_cents;

                // Check parameters.
                if (is_numeric($total_in_cents) && $total_in_cents > $first_in_cents
                    && $total_in_cents > 0 && is_numeric($first_in_cents) && $first_in_cents > 0) {
                    // Set value to payment_config.
                    $payment_config = 'MULTI:first=' . $first_in_cents . ';count=' . $count . ';period=' . $period;
                    $result &= $this->set('amount', $total_in_cents);
                    $result &= $this->set('payment_config', $payment_config);
                }
            }

            return $result;
        }



        /**
         * Add a product info as request parameters.
         *
         * @param string $label
         * @param int $amount
         * @param int $qty
         * @param string $ref
         * @param string $type
         * @param float vat
         * @return boolean
         */
        public function addProduct($label, $amount, $qty, $ref, $type = null, $vat = null)
        {
            $index = $this->get('nb_products') ? $this->get('nb_products') : 0;
            $ok = true;

            // Add product info as request parameters.
            $ok &= $this->addField('vads_product_label' . $index, 'Product label', '#^[^<>"+-]{0,255}$#u', false, 255, $label);
            $ok &= $this->addField('vads_product_amount' . $index, 'Product amount', '#^[1-9]\d*$#u', false, 12, $amount);
            $ok &= $this->addField('vads_product_qty' . $index, 'Product quantity', '#^[1-9]\d*$#u', false, 255, $qty);
            $ok &= $this->addField('vads_product_ref' . $index, 'Product reference', '#^[A-Za-z0-9]{0,64}$#u', false, 64, $ref);
            $ok &= $this->addField('vads_product_type' . $index, 'Product type', '#^' . implode('|', self::$ACCORD_CATEGORIES) . '$#u', false, 30, $type);
            $ok &= $this->addField('vads_product_vat' . $index, 'Product tax rate', '#^((\d{1,12})|(\d{1,2}\.\d{1,4}))$#u', false, 12, $vat);

            // Increment the number of products.
            $ok &= $this->set('nb_products', $index + 1);

            return $ok;
        }

        /**
         * Add extra info as a request parameter.
         *
         * @param string $key
         * @param string $value
         * @return boolean
         */
        public function addExtInfo($key, $value)
        {
            return $this->addField('vads_ext_info_' . $key, 'Extra info ' . $key, '#^.{0,255}$#u', false, 255, $value);
        }



        /**
         * Return the list of fields to send to the payment gateway.
         *
         * @return array[string][NovalnetField] a list of NovalnetField
         */
        public function getRequestFields()
        {
            $fields = $this->requestParameters;

            foreach ($fields as $field_name => $field) {
                if (! $field->isFilled() && ! $field->isRequired()) {
                    unset($fields[$field_name]);
                }
            }

            // Return the list of fields.
            return $fields;
        }


        /**
         * Return the HTML inputs of fields to send to the payment page.
         *
         * @param string $input_type
         * @param string $input_add
         * @return string
         */
        public function getRequestHtmlFields($input_type = 'hidden', $input_add = '', $escape = true)
        {
            $fields = $this->getRequestFields();

            $html = '';
            $format = '<input name="%s" value="%s" type="' . $input_type . '" ' . $input_add . "/>\n";
            foreach ($fields as $field) {
                if (! $field->isFilled()) {
                    continue;
                }

                // Convert special chars to HTML entities to avoid data truncation.
                if ($escape) {
                    $value = htmlspecialchars($field->getValue(), ENT_QUOTES, 'UTF-8');
                }

                $html .= sprintf($format, $field->getName(), $value);
            }
            return $html;
        }


    }
}
