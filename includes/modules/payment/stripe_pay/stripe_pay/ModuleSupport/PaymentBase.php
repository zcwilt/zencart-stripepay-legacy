<?php

namespace Zencart\ModuleSupport;

use Aura\Autoload\Loader;
use Carbon\Carbon;
use Zencart\Logger\Loggers\ModuleLogger;

abstract class PaymentBase extends ModuleBase
{
    use PaymentConcerns;

    /**
     * $order_status is the order status to set after processing the payment
     * @var int
     */
    public int $order_status;
    public string $email_footer = "";

    /**
     * @throws \Exception
     * @todo add a better exception
     */
    public function __construct()
    {
        /**
         * @var Loader $psr4Autoloader
         * @var \Order $order
         */
        global $order, $psr4Autoloader;

        if (empty($this->code)) {
            throw new \Exception('payment module parameter not set - code');
        }
        if (empty($this->version)) {
            throw new \Exception('payment module parameter not set - version');
        }

        $psr4Autoloader = $this->autoloadSupportClasses($psr4Autoloader);
        $loggerOptions = ['channel' => 'payment', 'prefix' => $this->code];
        $this->logger = new ModuleLogger($loggerOptions);
        $this->logger->pushHandlers(['handlers' => $this->getDefine('DEBUG_MODE')]);
        $this->configurationKeys = $this->setConfigurationKeys();
        $this->description = $this->getDescription();
        $this->sort_order = $this->getSortOrder();
        $this->zone = $this->getZone();
        $this->enabled = $this->isEnabled();
        $this->title = $this->getTitle();
        $this->logger->log('info', $this->messagePrefix('Called Constructor'));
        if ((int)$this->getDefine('ORDER_STATUS_ID', 0) > 0) {
            $this->order_status = (int)$this->getDefine('ORDER_STATUS_ID');
        }
        if (is_object($order)) $this->update_status();
        $this->email_footer = $this->getDefine('TEXT_EMAIL_FOOTER', '');
    }


    /**
     * @return array
     */
    protected function setCommonConfigurationKeys(): array
    {
        $configKeys = [];
        $key = $this->buildDefine('STATUS');
        $configKeys[$key] = [
            'configuration_value' => 'False',
            'configuration_title' => 'Enable this module',
            'configuration_description' => 'Do you want to accept payments using this module',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => "zen_cfg_select_option(array('True', 'False'), ",
        ];
        $key = $this->buildDefine('SORT_ORDER');
        $configKeys[$key] = [
            'configuration_value' => 0,
            'configuration_title' => 'Sort order of display.',
            'configuration_description' => 'Sort order of display. Lowest is displayed first.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('ZONE');
        $configKeys[$key] = [
            'configuration_value' => 0,
            'configuration_title' => 'Payment Zone',
            'configuration_description' => 'If a zone is selected, only enable this payment method for that zone.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => "zen_cfg_pull_down_zone_classes(",
        ];
        $key = $this->buildDefine('DEBUG_MODE');
        $configKeys[$key] = [
            'configuration_value' => '--none--',
            'configuration_title' => 'Use debug mode',
            'configuration_description' => 'Debug Mode adds extra logging to file, email and console output',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => "zen_cfg_select_multioption(array('File', 'Email', 'BrowserConsole'), ",
        ];
        return $configKeys;
    }

    protected function getModuleContext($toUpper = false): string
    {
        return 'PAYMENT';
    }

}
