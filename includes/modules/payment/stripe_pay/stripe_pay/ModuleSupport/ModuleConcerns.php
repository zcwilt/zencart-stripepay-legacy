<?php

namespace Zencart\ModuleSupport;

use App\Models\Configuration;

trait ModuleConcerns
{
    public function check(): bool
    {
        if (isset($this->_check)) {
            return $this->_check;
        }
        $_check = Configuration::where('configuration_key', 'MODULE_PAYMENT_' . strtoupper($this->code) . '_STATUS')->first();
        $this->_check = $_check ? 1 : 0;
        return $this->_check;
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
}
