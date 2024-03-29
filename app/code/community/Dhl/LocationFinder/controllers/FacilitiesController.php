<?php
/**
 * Dhl LocationFinder
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to
 * newer versions in the future.
 *
 * PHP version 5
 *
 * @category  Dhl
 * @package   Dhl_LocationFinder
 * @author    Christoph Aßmann <christoph.assmann@netresearch.de>
 * @author    Benjamin Heuer <benjamin.heuer@netresearch.de>
 * @copyright 2016 Netresearch GmbH & Co. KG
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      http://www.netresearch.de/
 */
use \Netresearch\Dhl\LocationFinder\ParcelLocation\Limiter;
use \Netresearch\Dhl\LocationFinder\ParcelLocation\Formatter\MarkerDetailsFormatter;
use \Netresearch\Dhl\LocationFinder\Webservice\Adapter\Soap as SoapAdapter;
use \Netresearch\Dhl\LocationFinder\Webservice\Parser\Location as LocationParser;
use \Netresearch\Dhl\LocationFinder\Webservice\RequestData;
use \Netresearch\Dhl\Psf\Api as LocationsApi;

/**
 * Dhl_LocationFinder_FacilitiesController
 *
 * @category  Dhl
 * @package   Dhl_LocationFinder
 * @author    Christoph Aßmann <christoph.assmann@netresearch.de>
 * @author    Benjamin Heuer <benjamin.heuer@netresearch.de>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      http://www.netresearch.de/
 */
class Dhl_LocationFinder_FacilitiesController extends Mage_Core_Controller_Front_Action
{
    const MSG_EMPTY_RESULT = 'We could not find any stores in your area.';

    /**
     * @var Dhl_LocationFinder_Model_Logger
     */
    protected $_logger;

    /**
     * Prepare logger. Using a wrapper seems sufficient for M1.
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_logger = Mage::getModel('dhl_locationfinder/logger');
    }

    /**
     * Only accept ajax requests to this controller
     *
     * @return $this
     */
    public function preDispatch()
    {
        parent::preDispatch();

        if (!$this->getRequest()->isXmlHttpRequest()) {
            $this->getResponse()
                ->setHeader('HTTP/1.1', '404 Not Found')
                ->setHeader('Status', '404 File not found');

            $this->_forward('defaultNoRoute');
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
        }

        return $this;
    }

    /**
     * Set status, messages and parcel locations for AJAX response.
     *
     * @param bool $success
     * @param string[] $messages
     * @param stdClass[] $locations
     */
    protected function setJsonResponse($success, $messages, $locations)
    {
        $jsonResponse = Mage::helper('core/data')->jsonEncode(
            array(
                'success'   => $success,
                'message'   => implode(' ', $messages),
                'locations' => $locations,
            )
        );
        $this->getResponse()->setHeader('Content-Type', 'application/json');
        $this->getResponse()->setBody($jsonResponse);
    }

    /**
     * Request facilities
     */
    public function indexAction()
    {
        $messages     = array();
        $mapLocations = array();

        /** @var Dhl_LocationFinder_Helper_Data $locationHelper */
        $locationHelper = Mage::helper('dhl_locationfinder/data');
        /** @var Dhl_LocationFinder_Model_Config $config */
        $config = Mage::getModel('dhl_locationfinder/config');

        /** @var SoapAdapter $adapter */
        $adapter = $locationHelper->getWebserviceAdapter($config);
        $parser  = new LocationParser();

        $requestAddress = $this->getRequest()->getParam('locationfinder', array());
        $address        = new RequestData\Address(
            $config->getWsValidCountries(),
            $requestAddress['country'],
            $requestAddress['zipcode'],
            $requestAddress['city'],
            '',
            $requestAddress['street']
        );

        try {

            $locations = $adapter->getParcelLocationByAddress($address, $parser);
            if (!count($locations)) {
                $messages[] = $this->__(self::MSG_EMPTY_RESULT);
            }

            $limiter   = new Limiter($config->getResultsLimit());
            $items     = $locations->getItems(null, $limiter);

            $translationsMap = $locationHelper->getTranslationsMap();
            $formatter = new MarkerDetailsFormatter(
                $translationsMap,
                array(LocationsApi\service::parking, LocationsApi\service::handicappedAccess)
            );
            $mapLocations = $formatter->format($items);

            $this->setJsonResponse((count($locations) > 0), $messages, $mapLocations);

        } catch (SoapFault $fault) {

            // unknown address error?
            // webservice not available?
            // Authorization Required / Security Violation?
            $messages[] = $this->__($fault->getMessage());

            $this->_logger->log($adapter->getLastRequest());
            $this->_logger->log($adapter->getLastResponse());

            $this->setJsonResponse(false, $messages, $mapLocations);

        } catch (RequestData\AddressException $e) {

            // given address too short?
            // no country given?
            $messages[] = $this->__($e->getMessage());

            $this->setJsonResponse(false, $messages, $mapLocations);

        } catch (\Exception $e) {

            // anything else
            $this->_logger->logException($e);
            $this->getResponse()->setHttpResponseCode(503);

        }
    }
}
