<?php

namespace Omnipay\FirstAtlanticCommerce\Message;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * FACPG2 Authorize Request
 */
class AuthorizeRequest extends AbstractRequest
{
    /**
     * @var string;
     */
    protected $requestName = 'AuthorizeRequest';

    /**
     * Transaction code (flag as a authorization)
     *
     * @var int;
     */
    protected $transactionCode = 0;

    /**
     * Returns the signature for the request.
     *
     * @return string base64 encoded sha1 hash of the merchantPassword, merchantId,
     *    acquirerId, transactionId, amount and currency code.
     */
    protected function generateSignature()
    {
        $signature  = $this->getMerchantPassword();
        $signature .= $this->getMerchantId();
        $signature .= $this->getAcquirerId();
        $signature .= $this->getTransactionId();
        $signature .= $this->formatAmount();
        $signature .= $this->getCurrencyNumeric();

        return base64_encode( sha1($signature, true) );
    }

    /**
     * Validate and construct the data for the request
     *
     * @return array
     */
    public function getData()
    {
        $this->validate('merchantId', 'merchantPassword', 'acquirerId', 'transactionId', 'amount', 'currency', 'card');

        // Check for AVS and require billingAddress1 and billingPostcode
        if ( $this->getRequireAvsCheck() )
        {
            $this->getCard()->validate('billingAddress1', 'billingPostcode');
        }

        // Tokenized cards require the CVV and nothing else, token replaces the card number
        if ( $this->getCardReference() )
        {
            $this->validate('cardReference');
            $this->getCard()->validate('cvv', 'expiryMonth', 'expiryYear');

            $cardDetails = [
                'CardCVV2'       => $this->getCard()->getCvv(),
                'CardExpiryDate' => $this->getCard()->getExpiryDate('my'),
                'CardNumber'     => $this->getCardReference()
            ];
        }
        else
        {
            $this->getCard()->validate();

            $cardDetails = [
                'CardCVV2'       => $this->getCard()->getCvv(),
                'CardExpiryDate' => $this->getCard()->getExpiryDate('my'),
                'CardNumber'     => $this->getCard()->getNumber(),
                'IssueNumber'    => $this->getCard()->getIssueNumber()
            ];
        }

        // Only pass the StartDate if year/month are set otherwise it returns 1299
        if ( $this->getCard()->getStartYear() && $this->getCard()->getStartMonth() )
        {
            $cardDetails['StartDate'] = $this->getCard()->getStartDate('my');
        }

        $transactionDetails = [
            'AcquirerId'       => $this->getAcquirerId(),
            'Amount'           => $this->formatAmount(),
            'Currency'         => $this->getCurrencyNumeric(),
            'CurrencyExponent' => $this->getCurrencyDecimalPlaces(),
            'IPAddress'        => $this->getClientIp(),
            'MerchantId'       => $this->getMerchantId(),
            'OrderNumber'      => $this->getTransactionId(),
            'Signature'        => $this->generateSignature(),
            'SignatureMethod'  => 'SHA1',
            'TransactionCode'  => $this->getTransactionCode()
        ];

        $billingDetails = [
            'BillToAddress'     => $this->getCard()->getAddress1(),
            'BillToZipPostCode' => $this->formatPostcode(),
            'BillToFirstName'   => $this->getCard()->getFirstName(),
            'BillToLastName'    => $this->getCard()->getLastName(),
            'BillToCity'        => $this->getCard()->getCity(),
            'BillToCountry'     => $this->getCard()->getNumericCountry(),
            'BillToEmail'       => $this->getCard()->getEmail(),
            'BillToTelephone'   => $this->getCard()->getPhone(),
            'BillToFax'         => $this->getCard()->getFax()
        ];

        // FAC only accepts two digit state abbreviations from the USA
        if ( $billingDetails['BillToCountry'] == 840 )
        {
            $billingDetails['BillToState'] = $this->formatState();
        }

        $data = [
            'TransactionDetails' => $transactionDetails,
            'CardDetails'        => $cardDetails,
            'BillingDetails'     => $billingDetails
        ];

        return $data;
    }

    /**
     * Returns the billing state if its a US abbreviation or throws an exception
     *
     * @throws InvalidRequestException
     *
     * @return string State abbreviation
     */
    public function formatState()
    {
        $state = $this->getCard()->getState();

        if ( strlen($state) != 2 )
        {
            throw new InvalidRequestException("The state must be a two character abbreviation.");
        }

        return $state;
    }

    /**
     * Returns the postal code sanitizing dashes and spaces and throws exceptions with other
     * non-alphanumeric characters
     *
     * @throws InvalidRequestException
     *
     * @return string Postal code
     */
    public function formatPostcode()
    {
        $postal = preg_replace( '/[\s\-]/', '', $this->getCard()->getPostcode() );

        if ( preg_match('/[^a-z0-9]/i', $postal) )
        {
            throw new InvalidRequestException("The postal code must be alpha-numeric.");
        }

        return $postal;
    }

    /**
     * Returns endpoint for authorize requests
     *
     * @return string Endpoint URL
     */
    protected function getEndpoint()
    {
        return parent::getEndpoint() . 'Authorize';
    }

    /**
     * Returns the transaction code based on the AVS check requirement
     *
     * @return int Transaction Code
     */
    protected function getTransactionCode()
    {
        return $this->getRequireAvsCheck() ? $this->transactionCode + 1 : $this->transactionCode;
    }

    /**
     * Return the authorize response object
     *
     * @param \SimpleXMLElement $xml Response xml object
     *
     * @return AuthorizeResponse
     */
    protected function newResponse($xml)
    {
        return new AuthorizeResponse($this, $xml);
    }
}
