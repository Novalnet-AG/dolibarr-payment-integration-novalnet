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

require_once 'NovalnetCurrency.php';
require_once 'NovalnetField.php';

if (! class_exists('NovalnetApi', false)) {

    /**
     * Utility class for managing parameters checking, inetrnationalization, signature building and more.
     */
    class NovalnetApi
    {

        const ALGO_SHA1 = 'SHA-1';
        const ALGO_SHA256 = 'SHA-256';

        public static $SUPPORTED_ALGOS = array(self::ALGO_SHA1, self::ALGO_SHA256);

        /**
         * The list of encodings supported by the API.
         *
         * @var array[string]
         */
        public static $SUPPORTED_ENCODINGS = array(
            'UTF-8',
            'ASCII',
            'Windows-1252',
            'ISO-8859-15',
            'ISO-8859-1',
            'ISO-8859-6',
            'CP1256'
        );


        /**
         * Return the list of currencies recognized by the payment gateway.
         *
         * @return array[int][NovalnetCurrency]
         */
        public static function getSupportedCurrencies()
        {
            $currencies = array(
                array('EUR', '978', 2), array('GBP', '826', 2), array('CAD', '124', 2), array('JPY', '392', 0),
                array('DKK', '208', 2), array('PLN', '985', 2), array('USD', '840', 2), array('CHF', '756', 2),
                array('NOK', '578', 2), array('SEK', '752', 2)
            );

            $Novalnet_currencies = array();

            foreach ($currencies as $currency) {
                $Novalnet_currencies[] = new NovalnetCurrency($currency[0], $currency[1], $currency[2]);
            }

            return $Novalnet_currencies;
        }

        /**
         * Return a currency from its 3-letters ISO code.
         *
         * @param string $alpha3
         * @return NovalnetCurrency
         */
        public static function findCurrencyByAlphaCode($alpha3)
        {
            $list = self::getSupportedCurrencies();
            foreach ($list as $currency) {
                /**
                 * @var NovalnetCurrency $currency
                 */
                if ($currency->getAlpha3() == $alpha3) {
                    return $currency;
                }
            }

            return null;
        }

        /**
         * Returns a currency form its numeric ISO code.
         *
         * @param int $numeric
         * @return NovalnetCurrency
         */
        public static function findCurrencyByNumCode($numeric)
        {
            $list = self::getSupportedCurrencies();
            foreach ($list as $currency) {
                /**
                 * @var NovalnetCurrency $currency
                 */
                if ($currency->getNum() == $numeric) {
                    return $currency;
                }
            }

            return null;
        }

        /**
         * Return a currency from its 3-letters or numeric ISO code.
         *
         * @param string $code
         * @return NovalnetCurrency
         */
        public static function findCurrency($code)
        {
            $list = self::getSupportedCurrencies();
            foreach ($list as $currency) {
                /**
                 * @var NovalnetCurrency $currency
                 */
                if ($currency->getNum() == $code || $currency->getAlpha3() == $code) {
                    return $currency;
                }
            }

            return null;
        }

        /**
         * Returns currency numeric ISO code from its 3-letters code.
         *
         * @param string $alpha3
         * @return int
         */
        public static function getCurrencyNumCode($alpha3)
        {
            $currency = self::findCurrencyByAlphaCode($alpha3);
            return ($currency instanceof NovalnetCurrency) ? $currency->getNum() : null;
        }

        /**
         * Return the statuses list of finalized successful payments (authorized or captured).
         * @return array
         */
        public static function getSuccessStatuses()
        {
            return array(
                'CONFIRMED' ,
                'ON_HOLD'
            );
        }

        /**
         * Return the statuses list of payments that are waiting confirmation (successful but
         * the amount has not been transfered and is not yet guaranteed).
         * @return array
         */
        public static function getPendingStatuses()
        {
            return array(
                'PENDING',
                'ON_HOLD'
            );
        }



    }
}
