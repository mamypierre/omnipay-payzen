<?php

namespace Omnipay\PayZen\Message;
use DateTime;
use Exception;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\PayZen\Validation;

/**
 * PayZen Purchase Request
 */
class PurchaseRequest extends AbstractRequest
{
    const DEFAULT_CAPTURE_DELAY = 0;

    const PAYMENT_TYPE_SINGLE = 'SINGLE';
    const PAYMENT_TYPE_MULTI = 'MULTI';
    const PAYMENT_TYPE_MULTI_EXT = 'MULTI_EXT';

    const TYPE_MULTI_MIN_INSTALLMENTS = 1;
    const TYPE_MULTI_MIN_DAYS_BTW_PERIOD = 0;

    public function getData()
    {
        $this->validate('amount');

        $data = array();
        $data['vads_site_id'] = $this->getMerchantId();
        $data['vads_ctx_mode'] = $this->getTestMode() ? 'TEST' : 'PRODUCTION';
        $data['vads_trans_id'] = $this->getTransactionId();
        $data['vads_trans_date'] = $this->getTransactionDate();
        $data['vads_amount'] = $this->getAmount();
        $data['vads_currency'] = $this->getCurrencyNumeric();
        $data['vads_action_mode'] = 'INTERACTIVE';
        $data['vads_page_action'] = $this->resolvePageAction();
        $data['vads_version'] = 'V2';
        $data['vads_payment_config'] = $this->getPaymentConfig();
        $data['vads_capture_delay'] = $this->getCaptureDelay();
        $data['vads_validation_mode'] = 0;
        $data['vads_url_success'] = $this->getSuccessUrl();
        $data['vads_url_cancel'] = $this->getCancelUrl();
        $data['vads_url_error'] = $this->getErrorUrl();
        $data['vads_url_refused'] = $this->getRefusedUrl();
        $data['vads_order_id'] = $this->getOrderId();
        $data['vads_payment_cards'] = $this->getPaymentCards();
        $data['vads_ext_info_owner_reference'] = $this->getOwnerReference();

        if (null !== $this->getNotifyUrl()) {
            $data['vads_url_check'] = $this->getNotifyUrl();
        }

        if (null !== $this->getRedirectSuccessTimeout()) {
            $data['vads_redirect_success_timeout'] = $this->getRedirectSuccessTimeout();
        }

        if (null !== $this->getRedirectErrorTimeout()) {
            $data['vads_redirect_error_timeout'] = $this->getRedirectErrorTimeout();
        }

        // Customer infos
        if ($this->getCard()) {
            $data['vads_cust_first_name'] = $this->getCard()->getName();
            $data['vads_cust_address'] = $this->getCard()->getAddress1();
            $data['vads_cust_city'] = $this->getCard()->getCity();
            $data['vads_cust_state'] = $this->getCard()->getState();
            $data['vads_cust_zip'] = $this->getCard()->getPostcode();
            $data['vads_cust_country'] = $this->getCard()->getCountry();
            $data['vads_cust_phone'] = $this->getCard()->getPhone();
            $data['vads_cust_email'] = $this->getCard()->getEmail();
        }

        $metadata = $this->getMetadata();
        if (!empty($metadata)) {
            foreach ($metadata as $key => $value) {
                $data['vads_ext_info_' . $key] = $value;
            }
        }

        if ($this->hasCardReference()) {
            $data['vads_identifier'] = $this->getCardReference();
        }

        $data['signature'] = $this->generateSignature($data);

        return $data;
    }

    public function sendData($data)
    {
        return $this->response = new RedirectToGatewayResponse($this, $data);
    }

    public function setCreateCard($value)
    {
        return $this->setParameter('createCard', $value);
    }

    public function isCreatingCard()
    {
        return true === $this->getParameter('createCard', false);
    }

    public function setCardReference($value)
    {
        return $this->setParameter('cardReference', $value);
    }

    public function getCardReference()
    {
        return $this->getParameter('cardReference');
    }

    public function hasCardReference()
    {
        return null !== $this->getCardReference();
    }

    private function resolvePageAction()
    {
        if ($this->isCreatingCard()
            && false === $this->hasCardReference()
        ) {
            return 'REGISTER_PAY';
        }

        return 'PAYMENT';
    }

    public function getCaptureDelay()
    {
        $captureDelay = $this->getParameter('captureDelay');
        if (null !== $captureDelay) {
            return $captureDelay;
        }

        return self::DEFAULT_CAPTURE_DELAY;
    }

    /**
     * @param int $value
     * @return PurchaseRequest
     * @throws InvalidRequestException
     */
    public function setCaptureDelay($value)
    {
        if (!is_int($value) || $value < 0 || $value > 365) {
            throw new InvalidRequestException('Capture delay must be an integer between 0 and 365');
        }

        return $this->setParameter('captureDelay', $value);
    }

    public function getPaymentConfig()
    {
        $paymentConfig = $this->getParameter('paymentConfig');
        if (null !== $paymentConfig) {
            return $paymentConfig;
        }

        return self::PAYMENT_TYPE_SINGLE;
    }

    /**
     * @param string $type
     * @param array $values
     * @return PurchaseRequest
     * @throws InvalidRequestException
     */
    public function setPaymentConfig($type, $values = [])
    {
        if ($type === self::PAYMENT_TYPE_SINGLE) {
            return $this->setParameter('paymentConfig', $type);
        }

        if (!in_array($type, [self::PAYMENT_TYPE_MULTI, self::PAYMENT_TYPE_MULTI_EXT])) {
            throw new InvalidRequestException('type is not allowed');
        }

        if ($type === self::PAYMENT_TYPE_MULTI) {
            $values = $this->checkAndFormatMultiPaymentConfig($values);
        } else {
            $values = $this->checkAndFormatMultiExtPaymentConfig($values);
        }

        $value = $type . ':' . http_build_query($values, '', ';');
        return $this->setParameter('paymentConfig', $value);
    }

    /**
     * @param array $settings
     * @return array
     * @throws InvalidRequestException
     */
    private function checkAndFormatMultiPaymentConfig(array $settings)
    {
        if (!isset($settings['first'], $settings['count'], $settings['period'])) {
            throw new InvalidRequestException('Invalid parameter for type MULTI');
        }

        if (!is_int($settings['count']) || $settings['count'] < self::TYPE_MULTI_MIN_INSTALLMENTS) {
            throw new InvalidRequestException(sprintf('Invalid count %s. Expecting an integer greater than %s', $settings['count'], self::TYPE_MULTI_MIN_INSTALLMENTS));
        }

        if (!is_int($settings['period']) || $settings['period'] < self::TYPE_MULTI_MIN_DAYS_BTW_PERIOD) {
            throw new InvalidRequestException(sprintf('Invalid period %s. Expecting an integer greater than %s', $settings['period'], self::TYPE_MULTI_MIN_DAYS_BTW_PERIOD));
        }

        $settings['first'] = $this->formatPaymentConfigAmount($settings['first']);

        return $settings;
    }

    /**
     * @param array $settings
     * @return array
     * @throws Exception
     * @throws InvalidRequestException
     */
    private function checkAndFormatMultiExtPaymentConfig(array $settings)
    {
        // First date must be greater or equal today
        $minPeriodDate = (new DateTime())->format('Ymd');
        // Last date must be less than in 1 year
        $maxPeriodDate = (new DateTime())->modify('+1 year')->format('Ymd');

        $totalAmount = 0;
        foreach ($settings as $date => $amount) {
            if (!Validation::isDate($date, 'Ymd')) {
                throw new InvalidRequestException(sprintf('Invalid date %s. Expecting format Ymd', $date));
            }

            if (!Validation::isDateInPeriod($date, $minPeriodDate, $maxPeriodDate)) {
                throw new InvalidRequestException(sprintf('Invalid date %s. The date must be between today and in one year', $date));
            }

            $amount = $this->formatPaymentConfigAmount($amount);
            $settings[$date] = $amount;
            $totalAmount += (int) $amount;
        }

        if ($totalAmount !== (int) $this->getAmount()) {
            throw new InvalidRequestException("Sum of amounts doesn't match with the total");
        }

        return $settings;
    }

    /**
     * Validates and returns the formatted given amount.
     *
     * @throws InvalidRequestException on any validation failure.
     * @return string The amount formatted to the correct number of decimal places for the selected currency.
     */
    public function formatPaymentConfigAmount($amount)
    {
        if ($amount !== null) {
            // Don't allow integers for currencies that support decimals.
            // This is for legacy reasons - upgrades from v0.9
            if ($this->getCurrencyDecimalPlaces() > 0) {
                if (is_int($amount) || (is_string($amount) && false === strpos((string) $amount, '.'))) {
                    throw new InvalidRequestException(
                        'Please specify amount as a string or float, '
                        . 'with decimal places (e.g. \'10.00\' to represent $10.00).'
                    );
                };
            }

            $amount = $this->toFloat($amount);

            // Check for a negative amount.
            if (!$this->negativeAmountAllowed && $amount < 0) {
                throw new InvalidRequestException('A negative amount is not allowed.');
            }

            // Check for a zero amount.
            if (!$this->zeroAmountAllowed && $amount === 0.0) {
                throw new InvalidRequestException('A zero amount is not allowed.');
            }

            // Check for rounding that may occur if too many significant decimal digits are supplied.
            $decimal_count = strlen(substr(strrchr(sprintf('%.8g', $amount), '.'), 1));
            if ($decimal_count > $this->getCurrencyDecimalPlaces()) {
                throw new InvalidRequestException('Amount precision is too high for currency.');
            }

            return $this->formatCurrency($amount);
        }
    }
}
