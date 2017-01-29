<?php

namespace LNC\Cofidis;

class CofidisWebService
{

    protected $options;

    protected $urls = array(
        'live' => array(
            'calculatorrequest'   => 'https://ecommerce.cofidis.sk/api/calculatorapi/calculatorrequest',
            'startloandemand'     => 'https://ecommerce.cofidis.sk/api/contractapi/startloandemand',
            'getloandemandstatus' => 'https://ecommerce.cofidis.sk/api/contractapi/getloandemandstatus'
        ),
        'test' => array(
            'calculatorrequest'   => 'https://test.ecommerce.cofidis.sk/api/calculatorapi/calculatorrequest',
            'startloandemand'     => 'https://test.ecommerce.cofidis.sk/api/contractapi/startloandemand',
            'getloandemandstatus' => 'https://test.ecommerce.cofidis.sk/api/contractapi/getloandemandstatus'
        )
    );

    public function __construct($options = array())
    {

        $this->options = array_merge(
            array(
                'partnerId' => null,
                'eshopId'   => null,
                'ssoId'     => null,
                'apiKey'    => null,
                'liveMode'  => null
            ),
            $options
        );

    }

    public function getLoanCalculatorUrl($articlePrice, $numberOfMonthlyInstallments = null, $depositAmount = null)
    {

        $result = null;

        if (!($this->options['partnerId'] && $this->options['eshopId'] && $this->options['ssoId'] && $this->options['apiKey'])) {
            return $result;
        }

        $xmlWriter = new \XMLWriter();
        $xmlWriter->openMemory();
        $xmlWriter->startDocument();
        $xmlWriter->startElement('CalculationRequestData');
        $xmlWriter->writeElement('partnerId', $this->options['partnerId']);
        $xmlWriter->writeElement('eshopId', $this->options['eshopId']);
        $xmlWriter->writeElement('ssoId', $this->options['ssoId']);
        $timestamp = str_replace(' ', 'T', date('Y-m-d H:i:s'));
        $xmlWriter->writeElement('timestamp', $timestamp);

        $articlePrice = sprintf('%0.2f', $articlePrice);
        $xmlWriter->writeElement('hash', md5(
                implode(
                    '+',
                    array(
                        $this->options['partnerId'],
                        $this->options['eshopId'],
                        $this->options['ssoId'],
                        $articlePrice,
                        $this->options['apiKey'],
                        $timestamp
                    )
                )
            )
        );
        $xmlWriter->writeElement('articlePrice', $articlePrice);
        if (null === $numberOfMonthlyInstallments) {
            $this->addNullValue($xmlWriter, 'numberOfMonthlyInstallments');
        } else {
            $xmlWriter->writeElement('numberOfMonthlyInstallments', $numberOfMonthlyInstallments);
        }
        if (null === $depositAmount) {
            $this->addNullValue($xmlWriter, 'depositAmount');
        } else {
            $xmlWriter->writeElement('depositAmount', $depositAmount);
        }
        $this->addNullValue($xmlWriter, 'insuranceType');

        $xmlWriter->endElement();
        $xmlWriter->endDocument();
        $xml = $xmlWriter->flush(true);

        $opts = array(
            'http' =>
                array(
                    'ignore_errors'   => true,
                    'method'          => 'POST',
                    'header'          => 'Content-type: text/xml',
                    'follow_location' => false,
                    'content'         => $xml
                )
        );

        if ($this->options['liveMode']) {
            $opts['ssl'] = array(
                'cafile'           => __DIR__ . '/cacert.pem',
                'verify_peer'      => true,
                'verify_peer_name' => true
            );
        } else {
            $opts['ssl'] = array(
                'verify_peer'      => false,
                'verify_peer_name' => false
            );
        }

        $context = stream_context_create($opts);

        if ($this->options['liveMode']) {
            $url = $this->urls['live']['calculatorrequest'];
        } else {
            $url = $this->urls['test']['calculatorrequest'];
        }

        file_get_contents($url, false, $context);

        $matches = array();
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0], $matches);
        $httpStatusCode = $matches[1];

        if (($httpStatusCode == '302')) {

            $pattern = "/^Location:\s*(.*)$/i";
            $location_headers = preg_grep($pattern, $http_response_header);

            if (!empty($location_headers) &&
                preg_match($pattern, array_values($location_headers)[0], $matches)
            ) {
                $result = $matches[1];
            }

        }

        return $result;
    }

    public function getLoanDemandUrl($transactionId, $articlePrice, $article = null, $articleType = null, $variableSymbol = null, $deliveryChannel = 'OO')
    {

        $result = null;

        if (!($this->options['partnerId'] && $this->options['eshopId'] && $this->options['ssoId'] && $this->options['apiKey'])) {
            return $result;
        }

        $xmlWriter = new \XMLWriter();
        $xmlWriter->openMemory();
        $xmlWriter->startDocument();
        $xmlWriter->startElement('StartLoanDemandRequestData');
        $xmlWriter->writeElement('partnerId', $this->options['partnerId']);
        $xmlWriter->writeElement('eshopId', $this->options['eshopId']);
        $xmlWriter->writeElement('ssoId', $this->options['ssoId']);
        $timestamp = str_replace(' ', 'T', date('Y-m-d H:i:s'));
        $xmlWriter->writeElement('timestamp', $timestamp);

        $articlePrice = sprintf('%0.2f', $articlePrice);
        $xmlWriter->writeElement('hash', md5(
                implode(
                    '+',
                    array(
                        $this->options['partnerId'],
                        $this->options['eshopId'],
                        $this->options['ssoId'],
                        $articlePrice,
                        $this->options['apiKey'],
                        $timestamp
                    )
                )
            )
        );
        $xmlWriter->writeElement('articlePrice', $articlePrice);
        $xmlWriter->writeElement('deliveryChannel', $deliveryChannel);
        $xmlWriter->writeElement('transactionId', $transactionId);
        if (null === $article) {
            $this->addNullValue($xmlWriter, 'article');
        } else {
            $xmlWriter->writeElement('article', $article);
        }
        if (null === $articleType) {
            $this->addNullValue($xmlWriter, 'articleType');
        } else {
            $xmlWriter->writeElement('articleType', $articleType);
        }
        if (null === $variableSymbol) {
            $this->addNullValue($xmlWriter, 'variableSymbol');
        } else {
            $xmlWriter->writeElement('variableSymbol', $variableSymbol);
        }

        $xmlWriter->endElement();
        $xmlWriter->endDocument();
        $xml = $xmlWriter->flush(true);

        $opts = array(
            'http' =>
                array(
                    'ignore_errors'   => true,
                    'method'          => 'POST',
                    'header'          => 'Content-type: text/xml',
                    'follow_location' => false,
                    'content'         => $xml
                )
        );

        if ($this->options['liveMode']) {
            $opts['ssl'] = array(
                'cafile'           => __DIR__ . '/cacert.pem',
                'verify_peer'      => true,
                'verify_peer_name' => true
            );
        } else {
            $opts['ssl'] = array(
                'verify_peer'      => false,
                'verify_peer_name' => false
            );
        }

        $context = stream_context_create($opts);

        if ($this->options['liveMode']) {
            $url = $this->urls['live']['startloandemand'];
        } else {
            $url = $this->urls['test']['startloandemand'];
        }

        file_get_contents($url, false, $context);

        $matches = array();
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0], $matches);
        $httpStatusCode = $matches[1];

        if (($httpStatusCode == '302')) {

            $pattern = "/^Location:\s*(.*)$/i";
            $location_headers = preg_grep($pattern, $http_response_header);

            if (!empty($location_headers) &&
                preg_match($pattern, array_values($location_headers)[0], $matches)
            ) {
                $result = $matches[1];
            }

        }

        return $result;
    }

    public function getLoanDemandStatus($transactionId)
    {

        $result = null;

        if (!($this->options['partnerId'] && $this->options['eshopId'] && $this->options['ssoId'] && $this->options['apiKey'])) {
            return $result;
        }

        $xmlWriter = new \XMLWriter();
        $xmlWriter->openMemory();
        $xmlWriter->startDocument();
        $xmlWriter->startElement('GetLoanDemandStatusRequestData');
        $xmlWriter->writeElement('partnerId', $this->options['partnerId']);
        $xmlWriter->writeElement('eshopId', $this->options['eshopId']);
        $xmlWriter->writeElement('ssoId', $this->options['ssoId']);
        $timestamp = str_replace(' ', 'T', date('Y-m-d H:i:s'));
        $xmlWriter->writeElement('timestamp', $timestamp);

        $xmlWriter->writeElement('hash', md5(
                implode(
                    '+',
                    array(
                        $this->options['partnerId'],
                        $this->options['eshopId'],
                        $this->options['ssoId'],
                        $this->options['apiKey'],
                        $timestamp
                    )
                )
            )
        );

        $xmlWriter->writeElement('transactionId', $transactionId);

        $xmlWriter->endElement();
        $xmlWriter->endDocument();
        $xml = $xmlWriter->flush(true);

        $opts = array(
            'http' =>
                array(
                    'ignore_errors'   => true,
                    'method'          => 'POST',
                    'header'          => 'Content-type: text/xml',
                    'follow_location' => false,
                    'content'         => $xml
                )
        );

        if ($this->options['liveMode']) {
            $opts['ssl'] = array(
                'cafile'           => __DIR__ . '/cacert.pem',
                'verify_peer'      => true,
                'verify_peer_name' => true
            );
        } else {
            $opts['ssl'] = array(
                'verify_peer'      => false,
                'verify_peer_name' => false
            );
        }

        $context = stream_context_create($opts);

        if ($this->options['liveMode']) {
            $url = $this->urls['live']['getloandemandstatus'];
        } else {
            $url = $this->urls['test']['getloandemandstatus'];
        }

        $response = file_get_contents($url, false, $context);

        $matches = array();
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0], $matches);
        $httpStatusCode = $matches[1];

        if (($httpStatusCode == '200')) {

            $xml = simplexml_load_string($response);

            $result = array();
            $result['contractStatus'] = (string)$xml->contractStatus;
            $result['despatch'] = (string)$xml->despatch;

        }

        return $result;
    }

    protected function addNullValue(\XMLWriter $xmlWriter, $key)
    {
        $xmlWriter->startElement($key);
        $xmlWriter->writeAttributeNs('p2', 'nil', 'http://www.w3.org/2001/XMLSchema-instance', 'true');
        $xmlWriter->endElement();
    }

}
