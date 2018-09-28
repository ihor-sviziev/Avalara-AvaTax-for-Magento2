<?php
/**
 * ClassyLlama_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright  Copyright (c) 2018 Avalara, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace ClassyLlama\AvaTax\Plugin\Model\Customer;

use ClassyLlama\AvaTax\Exception\AvataxConnectionException;
use ClassyLlama\AvaTax\Helper\UrlSigner;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;

class DataProviderPlugin
{
    // 24 hours in seconds
    const CERTIFICATE_URL_EXPIRATION = (60 * 60 * 24);

    /**
     * @var \ClassyLlama\AvaTax\Api\RestCustomerInterface
     */
    protected $customerRest;

    /**
     * @var DataObjectFactory
     */
    protected $dataObjectFactory;

    /**
     * @var UrlSigner
     */
    protected $urlSigner;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param \ClassyLlama\AvaTax\Api\RestCustomerInterface $customerRest
     * @param DataObjectFactory                             $dataObjectFactory
     * @param UrlSigner                                     $urlSigner
     * @param \Magento\Framework\UrlInterface               $urlBuilder
     */
    public function __construct(
        \ClassyLlama\AvaTax\Api\RestCustomerInterface $customerRest,
        DataObjectFactory $dataObjectFactory,
        UrlSigner $urlSigner,
        \Magento\Framework\UrlInterface $urlBuilder
    )
    {
        $this->customerRest = $customerRest;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->urlSigner = $urlSigner;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @param $certificateId
     * @param $customerId
     *
     * @return string
     */
    public function getCertificateUrl($certificateId, $customerId)
    {
        $parameters = [
            'certificate_id' => $certificateId,
            'customer_id' => $customerId,
            // For security, expire the url after a period of time
            'expires' => time() + self::CERTIFICATE_URL_EXPIRATION
        ];

        $parameters['signature'] = $this->urlSigner->signParameters($parameters);
        // This messes with URL signing as the parameter is added after the fact. Don't use url keys for certificate downloads
        $parameters['_nosecret'] = true;

        return $this->urlBuilder->getUrl('avatax/certificates/download', $parameters);
    }

    /**
     * @param $dataObject
     *
     * @return array|mixed
     */
    public function flattenDataObject($dataObject)
    {
        $data = $dataObject;

        if ($dataObject instanceof DataObject) {
            $data = $dataObject->getData();
        }

        if (!\is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $property) {
            $data[$key] = $this->flattenDataObject($property);
        }

        return $data;
    }

    /**
     * @param $customerId
     *
     * @return DataObject[]
     * @throws AvataxConnectionException
     */
    public function getCertificates($customerId)
    {
        $certificates = [];

        if ($customerId === null) {
            return $certificates;
        }

        $certificates = $this->customerRest->getCertificatesList(
            $this->dataObjectFactory->create(['data' => ['customer_id' => $customerId]])
        );

        foreach ($certificates as $certificate) {
            $certificate->setData(
                'certificate_url',
                $this->getCertificateUrl($certificate->getData('id'), $customerId)
            );
        }

        return $certificates;
    }

    /**
     * @param \Magento\Customer\Model\Customer\DataProvider $subject
     * @param array                                         $data
     *
     * @return mixed
     * @throws AvataxConnectionException
     */
    public function afterGetData(\Magento\Customer\Model\Customer\DataProvider $subject, $data)
    {
        if (empty($data)) {
            return $data;
        }

        foreach ($data as $index => $fieldData) {
            if (!isset($fieldData['customer']['entity_id'])) {
                continue;
            }

            $data[$index]['certificates'] = $this->flattenDataObject(
                $this->getCertificates($fieldData['customer']['entity_id'])
            );
        }

        return $data;
    }
}