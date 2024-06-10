<?php

namespace Zencart\ModuleSupport;

use App\Models\Configuration;
use Aura\Autoload\Loader;
use Zencart\Logger\Logger;

abstract class ModuleBase
{
    /**
     * $_check is used to check the configuration key set up
     * @var int
     */
    protected int $_check;
    /**
     * @var string
     */
    public string $description;
    /**
     * $enabled determines whether this module shows or not... in catalog.
     *
     * @var boolean
     */
    public bool $enabled;
    /**
     * $sort_order is the order priority of this payment module when displayed
     * @var int
     */
    public ?int $sort_order;
    /**
     * $code determines the internal 'code' name used to designate "this" payment module
     * @var string
     */
    public string $code = '';
    /**
     * @var string
     */
    public string $title = '';
    /**
     * @var array
     */
    protected array $configurationKeys;
    /**
     * @var int
     */
    protected int $zone;
    /**
     * @var array
     */
    protected array $configureErrors = [];

    protected Logger $logger;



    abstract protected function getModuleContext(): string;
    /**
     * @param string $defineTemplate
     * @param $default
     * @return mixed
     */
    protected function getDefine(string $defineSuffix, $default = null): mixed
    {
        $define = $this->buildDefine($defineSuffix);
        if (!defined($define)) {
            return $default;
        }
        return constant($define);
    }

    /**
     * @param $defineTemplate
     * @return string
     */
    protected function buildDefine(string $defineSuffix): string
    {
        $define = 'MODULE_' . strtoupper($this->getModuleContext()) . '_' . strtoupper($this->code) . '_' . strtoupper($defineSuffix);
        return $define;
    }


    protected function messagePrefix(string $message): string
    {
        return $this->title . ': ' . $message;
    }

    /**
     * @return void
     */
    protected function getTitle(): string
    {
        $title = $this->getDefine('TEXT_TITLE');
        if (IS_ADMIN_FLAG === true) {
            $title = $this->getAdminTitle();
        }
        return $title ?? '';
    }
    public function install()
    {
        global $messageStack;
        if ($this->getDefine('STATUS', null)) {
            $messageStack->add_session($this->getDefine('ERROR_TEXT_ALREADY_INSTALLED'), 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=' . $this->code, 'NONSSL'));
            return;
        }
        foreach ($this->configurationKeys as $configurationKey => $configurationValues) {
            $configurationValues['configuration_key'] = $configurationKey;
            $config = new Configuration($configurationValues);
            $config->save();
        }
    }

    public function keys(): array
    {
        return array_keys($this->configurationKeys);
    }

    public function remove()
    {
        $define = 'MODULE_PAYMENT_' . strtoupper($this->code) . '_%';
        Configuration::where('configuration_key', 'LIKE', $define)->delete();
    }

    protected function setConfigurationKeys(): array
    {
        $local = [];
        $common = $this->setCommonConfigurationKeys();
        if (method_exists($this, 'addCustomConfigurationKeys')) {
            $local = $this->addCustomConfigurationKeys();
        }
        return array_merge($common, $local);
    }

    protected function getAdminTitle(): string
    {
        $title = $this->getDefine('TEXT_TITLE_ADMIN');
        $title = $title ?? $this->getDefine('TEXT_TITLE');
        $title = $title . '['. $this->version . ']';
        if (method_exists($this, 'checkNonFatalConfigureStatus')) {
            $this->checkNonFatalConfigureStatus();
        }
        if (empty($this->configureErrors)) {
            return $title;
        }
        foreach ($this->configureErrors as $configureError) {
            $title .= '<span class="alert">' . $configureError . '</span>';
        }
        return $title;
    }

    /**
     * @return string
     */
    protected function getDescription(): string
    {
        return $this->getDefine('TEXT_DESCRIPTION') ?? '';
    }

    /**
     * @return int|null
     */
    protected function getSortOrder(): ?int
    {
        $defineValue = $this->getDefine('SORT_ORDER');
        return $defineValue ?? null;
    }

    /**
     * @return int
     */
    protected function getZone(): int
    {
        $defineValue = $this->getDefine('ZONE');
        return $defineValue ?? 0;
    }

    /**
     * @return bool
     */
    protected function getDebugMode(): bool
    {
        $defineValue = $this->getDefine('DEBUG_MODE');
        return ($defineValue === 'Yes') ?? false;
    }

    /**
     * is the payment module enabled.
     * This allows a check for configuration values through a method
     * @return bool
     */
    protected function isEnabled(): bool
    {
        $enabled = true;
        if (method_exists($this, 'checkFatalConfigureStatus')) {
            $enabled = $this->checkFatalConfigureStatus();
        }
        if (!$enabled) {
            return false;
        }
        $defineValue = $this->getDefine('STATUS');
        return isset($defineValue) && $defineValue === 'True';
    }

    /**
     * Note: This is a stub method as it might not be used in all payment modules
     *
     * @return void
     */
    protected function autoloadSupportClasses(Loader $psr4Autoloader): Loader
    {
        if (method_exists($this, 'moduleAutoloadSupportClasses')) {
            $this->moduleAutoloadSupportClasses($psr4Autoloader);
        }
        return $psr4Autoloader;
    }

}
