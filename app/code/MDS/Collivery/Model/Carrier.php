<?php
/**
 * Created by Lilian Codreanu @Evozon.
 * User: Lilian.Codreanu@evozon.com
 * Date: 25.09.2014
 * Time: 16:15
 */

namespace MDS\Collivery\Model;


use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Catalog\Model\Product\Type;
use Magento\Framework\Module\Dir;
use Magento\Sales\Model\Order\Shipment;
use Magento\Shipping\Model\Rate\Result;
use Magento\Framework\Logger;
use MDS\Collivery\Model\Collivery;
use SoapClient;
use SoapFault;



class Carrier extends AbstractCarrier implements CarrierInterface {

    /**
     * Code of the carrier
     * @var string
     */
    protected $_code='mds_collivery';

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var \Magento\Sales\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $_rateMethodFactory;

    /**
     * Rate result data
     *
     * @var Result
     */
    protected $_result;

    public $collivery;

    public $soap;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Sales\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Magento\Framework\Logger\AdapterFactory $logAdapterFactory
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Sales\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,

//        \Magento\Framework\Logger\AdapterFactory $logAdapterFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Psr\Log\LoggerInterface $logger,
//        \MDS\Collivery\Model\Collivery $collivery,




        array $data = array()
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;

//        $config = array(
//            'app_name'      => 'Default App Name', // Application Name
//            'app_version'   => '0.0.1',            // Application Version
//            'app_host'      => '', // Framework/CMS name and version, eg 'Wordpress 3.8.1 WooCommerce 2.0.20' / 'Joomla! 2.5.17 VirtueMart 2.0.26d'
//            'app_url'       => '', // URL your site is hosted on
//            'user_email'    => 'api@collivery.co.za',
//            'user_password' => 'api123',
//            'demo'          => false,
//        );


//        parent::__construct($scopeConfig, $rateErrorFactory, $logAdapterFactory, $data);
        parent::__construct($scopeConfig, $rateErrorFactory,$logger, $data);

//        $collivery = new \MDS\Collivery\Model\Collivery($config);
//
//        $this->collivery = $collivery;
    }

    /**
     * Returns array of key-value pairs of all available methods
     * @return array
     */
    public function getAllowedMethods()
    {
        return array(
            'SDX'  =>  'Overnight by 10:00',
            'NDX'   =>  'Overnight by 16:00',
            'ECO'   =>  'Road Freight Express',
            'FRT'   =>  'Road Freight'
        );
    }


    /**
     * @param RateRequest $request
     * @return bool|Result|null
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $expressAvailable = true;
//        $expressMaxWeight = $this->getConfigData('express_max_weight');
        $shippingTotalWeight = 0;

        $this->_result = $this->_rateResultFactory->create();

        if ($request->getAllItems()) {
            foreach ($request->getAllItems() as $item) {
                if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                    continue;
                }

                if ($item->getHasChildren() && $item->isShipSeparately()) {
                    foreach ($item->getChildren() as $child) {
                        if ($child->getFreeShipping() && !$child->getProduct()->isVirtual()) {
                            $shippingTotalWeight += $child->getWeight();
                        }
                    }
                } elseif ($item->getFreeShipping()) {
                    $shippingTotalWeight += $item->getWeight();
                }
            }
        }
//        if ($shippingTotalWeight > $expressMaxWeight) {
//            $expressAvailable = false;
//        }

//        if ($expressAvailable) {
//            $this->_getExpressRate();
//        }

      $this->soap_init();
        $rates = $this->get_services();
        print_r($rates);
        die();




//        $rates = $this->getAllowedMethods();

        foreach ($rates as $key => $rated) {

            $rate = $this->_rateMethodFactory->create();
            $rate->setCarrier($this->_code);
            $rate->setCarrierTitle($this->getConfigData('title'));
            $rate->setMethodTitle($rated);
            $rate->setPrice(1.23);
            $rate->setCost(0);
            $this->_result->append($rate);
//            return $this;

        }

        return $this->getResult();
    }

    /**
     * Get result of request
     * @return Result
     */
    public function getResult()
    {
        return $this->_result;
    }

    /**
     * @return $this
     */
    protected function _getStandardRate()
    {
        $rate = $this->_rateMethodFactory->create();
        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title'));
        $rate->setMethodTitle('Standard delivery');
        $rate->setPrice(1.23);
        $rate->setCost(0);
        $this->_result->append($rate);
        return $this;
    }

    /**
     * @return $this
     */
    protected function _getExpressRate()
    {
        $rate = $this->_rateMethodFactory->create();
        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title'));
        $rate->setMethodTitle('Express delivery');
        $rate->setPrice(2.50);
        $rate->setCost(0);
        $this->_result->append($rate);
        return $this;
    }

    private function soap_init()
    {
        // Check if soap session exists
        if (!$this->soap){
            // Start Soap Client
            $this->soap = new SoapClient("http://www.collivery.co.za/wsdl/v2");
            // Plugin and Host information
            $info = array('name' => 'Magento Shipping Module by MDS Collivery', 'version'=> '1', 'host'=> 'Magento 1');
            // Authenticate
            $authenticate = $this->soap->authenticate('api@collivery.co.za','api123');
            // Save Authentication token in session to identify the user again later
//            $_SESSION['token'] = $authenticate['token'];
//            if(!$authenticate['token']) {
//                exit("Authentication Error : ".$authenticate['access']);
//            }
            // Make authentication publically accessible
            $this->authenticate=$authenticate;
        }
        return $this->soap;
    }

    function get_services()
    {
        /* Uncomment the following lines of code if you'd like to edit/remove any services.
         *
         * 1: Overnight Before 10:00
         * 2: Overnight Before 16:00
         * 5: Road Freight Express
         * 3: Road Freight
         */
        /*return array(
                1 => "Overnight Before 10:00", // 1: Overnight Before 10:00
                2 => "Overnight before 16:00", // 2: Overnight Before 16:00
                5 => "Road Freight Express", //   5: Road Freight Express
                3 => "Road Freight" //            3: Road Freight
            );
        */
        if (!isset($this->services))
        {
            try{
                $this->soap_init();
                $services = $this->soap->get_services($this->authenticate['token']);
                if (is_array($services['services'])&&!isset($services['error'])){
                    $this->services = $services['services'];
                } else {
                    $this->log("Error returning services! Recieved: ". $services, 3);
                    return false;
                }
            } catch (SoapFault $e){
                $this->log("Error returning services! SoapFault: ". $e->faultcode ." - ". $e->getMessage(), 2);
                return false;
            }
        }
        return $this->services;
    }


} 