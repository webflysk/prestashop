<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require __DIR__ . '/vendor/autoload.php';

class LNCCofidis extends PaymentModule
{

    public function __construct()
    {
        $this->name = 'LNCCofidis';
        $this->version = '1.0.0';
        $this->author = 'Jan Cinert';
        $this->controllers = array(
            'payment',
            'validation'
        );
        $this->tab = 'payments_gateways';

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Cofidis');

        if (!count(Currency::checkPaymentCurrencies($this->id)))
            $this->warning = $this->l('No currency has been set for this module.');
    }

    public function runSync()
    {

        $this->log('--START--');

        $lock = new \NinjaMutex\Lock\DirectoryLock(__DIR__ . '/data');
        $mutex = new \NinjaMutex\Mutex(
            'worker',
            $lock
        );
        if (!$mutex->acquireLock(0)) {
            $this->log('Unable to gain lock!');

            return;
        }

        $processingLastPath = __DIR__ . '/data/processing_last.txt';
        $processingLast = json_decode(
            @file_get_contents($processingLastPath),
            true
        );
        $processingLast = array_merge(
            array(
                'type' => null,
                'id'   => null
            ),
            $processingLast
                ? $processingLast
                : array()
        );

        $startTime = microtime(true);

        $types = array(
            'contractUpdates'
        );
        $pos = array_search(
            $processingLast['type'],
            $types
        );
        if ($pos === false) {
            $pos = 0;
            $processingLast['id'] = null;
        }

        foreach (
            array_slice(
                $types,
                $pos
            ) as $type
        ) {
            $processingLast['type'] = $type;
            if ($type == 'contractUpdates') {

                $sql = 'SELECT cc.*
				FROM `' . _DB_PREFIX_ . 'cofidis_contract` cc
				WHERE (cc.contractStatus IS NULL OR cc.contractStatus IN (3, 7, 8) OR (cc.contractStatus IN (1) AND cc.despatch = 0))' . ($processingLast['id']
                        ? ' AND cc.id > ' . (int)$processingLast['id']
                        : '') . ' LIMIT 100';

                $rq = Db::getInstance(_PS_USE_SQL_SLAVE_)
                    ->executeS($sql);
                foreach ($rq as &$row) {

                    $limit = min(
                        60 * 60,
                        ini_get('max_execution_time')
                    );

                    $this->log('contract ' . $row['id']);

                    $options = array(
                        'partnerId' => $this->getConfigurationValue('ws_partnerId'),
                        'eshopId'   => $this->getConfigurationValue('ws_eshopId'),
                        'ssoId'     => $this->getConfigurationValue('ws_ssoId'),
                        'apiKey'    => $this->getConfigurationValue('ws_apiKey'),
                        'liveMode'  => $this->getConfigurationValue('liveMode')
                    );
                    $webService = new \LNC\Cofidis\CofidisWebService($options);

                    $result = $webService->getLoanDemandStatus($row['id']);

                    if ($result) {
                        Db::getInstance(_PS_USE_SQL_SLAVE_)->update('cofidis_contract', array(
                            'contractStatus' => pSQL($result['contractStatus']),
                            'despatch'       => pSQL($result['despatch']),
                            'updated_at'     => pSQL(date('Y-m-d H:i:s'))
                        ), 'id = ' . (int)$row['id']);

                        $order = new Order($row['id_order']);
                        if ($order->id) {

                            $newState = null;
                            if ($result['contractStatus'] == 1 && $result['despatch'] == 1) {
                                $newState = 'PS_OS_PAYMENT';
                            } elseif (in_array($result['contractStatus'], array(8)) && $result['despatch'] == 1) {
                                $newState = 'LNC_COFIDIS_OS_CONFIRMED';
                            } elseif (in_array($result['contractStatus'], array(
                                    1,
                                    8
                                )) && $result['despatch'] == 0
                            ) {
                                $newState = 'LNC_COFIDIS_OS_CONFIRMED';
                            }
                            if ($newState) {

                                $newState = Configuration::get($newState);

                                $order_state = new OrderState($newState);

                                $current_order_state = $order->getCurrentOrderState();

                                if ($current_order_state->id != $order_state->id) {

                                    $context = Context::getContext();

                                    $history = new OrderHistory();
                                    $history->id_order = $order->id;
                                    if ($context->employee) {
                                        $history->id_employee = (int)$context->employee->id;
                                    }

                                    $use_existings_payment = false;
                                    if (!$order->hasInvoice()) {
                                        $use_existings_payment = true;
                                    }
                                    $history->changeIdOrderState(
                                        (int)$order_state->id,
                                        $order,
                                        $use_existings_payment
                                    );

                                    $carrier = new Carrier(
                                        $order->id_carrier,
                                        $order->id_lang
                                    );
                                    $templateVars = array();
                                    if ($history->id_order_state == Configuration::get(
                                            'PS_OS_SHIPPING'
                                        )
                                        && $order->shipping_number
                                    ) {
                                        $templateVars = array(
                                            '{followup}' => str_replace(
                                                '@',
                                                $order->shipping_number,
                                                $carrier->url
                                            )
                                        );
                                    }

                                    // Save all changes
                                    if ($history->addWithemail(
                                        true,
                                        $templateVars
                                    )
                                    ) {
                                        // synchronizes quantities if needed..
                                        if (Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
                                            foreach ($order->getProducts() as $product) {
                                                if (StockAvailable::dependsOnStock($product['product_id'])) {
                                                    StockAvailable::synchronize(
                                                        $product['product_id'],
                                                        (int)$product['id_shop']
                                                    );
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $processingLast['id'] = $row['id'];
                    file_put_contents(
                        $processingLastPath,
                        json_encode(
                            $processingLast,
                            JSON_PRETTY_PRINT
                        )
                    );
                    $time_elapsed_secs = microtime(true) - $startTime;
                    if ($time_elapsed_secs >= $limit - 20) {

                        $this->log('--TIME LIMIT--');

                        return;
                    }
                }
            }
            $processingLast['id'] = null;
        }

        $processingLast['type'] = null;
        file_put_contents(
            $processingLastPath,
            json_encode(
                $processingLast,
                JSON_PRETTY_PRINT
            )
        );

        $this->log('--END--');
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {

        return parent::install()
        && $this->registerHook('displayBackOfficeHeader')
        && $this->registerHook('displayHeader')
        && $this->registerHook('payment')
        && $this->registerHook('paymentReturn')
        && $this->registerHook(
            'displayRightColumnProduct'
        )
        && $this->maybeUpdateDatabase()
        && $this->createOrderState()
        && $this->installModuleOverrides();

    }

    public function hookDisplayHeader()
    {

        $this->context->controller->addCSS(
            $this->_path . 'views/css/front.css?v=1',
            'all',
            null,
            false
        );

    }

    public function hookDisplayRightColumnProduct()
    {

        $product = new Product(Tools::getValue('id_product'));

        $options = array(
            'partnerId' => $this->getConfigurationValue('ws_partnerId'),
            'eshopId'   => $this->getConfigurationValue('ws_eshopId'),
            'ssoId'     => $this->getConfigurationValue('ws_ssoId'),
            'apiKey'    => $this->getConfigurationValue('ws_apiKey'),
            'liveMode'  => $this->getConfigurationValue('liveMode')
        );
        $webService = new \LNC\Cofidis\CofidisWebService($options);

        $price = Product::getPriceStatic($product->id, true, null, 2);
        if ($price < $this->getConfigurationValue('productMinPrice')) {
            return null;
        }

        $cacheKey = md5(
            implode(
                '+',
                array_merge(
                    $options,
                    array(
                        $price
                    )
                )
            )
        );
        $url = Cache::getInstance()->get($cacheKey);
        if (!$url) {
            $url = $webService->getLoanCalculatorUrl($price);
            Cache::getInstance()->set($cacheKey, $url, 60);
        }

        if ($url) {

            $this->context->smarty->assign(
                array(
                    'url' => $url
                )
            );

            return $this->display(__FILE__, 'HOOK_EXTRA_RIGHT.tpl');
        }

        return null;
    }

    public function hookPayment($params)
    {
        if (!$this->active)
            return;
        if (!$this->checkCurrency($params['cart']))
            return;

        $this->smarty->assign(array(
            'this_path'     => $this->_path,
            'this_path_bw'  => $this->_path,
            'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/'
        ));

        return $this->display(__FILE__, 'payment.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module))
            foreach ($currencies_module as $currency_module)
                if ($currency_order->id == $currency_module['id_currency'])
                    return true;

        return false;
    }

    public function getCronToken()
    {
        return substr(
            Tools::encrypt($this->name . '/cron'),
            0,
            10
        );
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {

        if (Tools::getValue('ajax')) {

            $result = array();

            $cmd = Tools::getValue('cmd');

            die(Tools::jsonEncode($result));
        }

        $output = '';
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit(
                $this->getSubmitActionName()
            )) == true
        ) {
            $this->postProcess();
        }

        $this->context->smarty->assign(
            'module_dir',
            $this->_path
        );

        $this->context->smarty->assign(
            'cron_url',
            _PS_BASE_URL_ . $this->_path . 'cron.php?token=' . rawurlencode(
                $this->getCronToken()
            )
        );

        $output .= $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        $output .= $this->renderForm();

        return $output;
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {

        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Nastavení'),
                    'icon'  => 'icon-cogs',
                ),
                'input'  => array(),
                'submit' => array(
                    'title' => $this->l('Uložit'),
                ),
            ),
        );
        $form['form']['input'] = array_merge(
            $form['form']['input'],
            array(
                array(
                    'type'     => 'text',
                    'label'    => $this->l('Web Service: partnerId'),
                    'name'     => $this->getConfigurationName('ws_partnerId'),
                    'rows'     => 20,
                    'required' => false
                ),
                array(
                    'type'     => 'text',
                    'label'    => $this->l('Web Service: eshopId'),
                    'name'     => $this->getConfigurationName('ws_eshopId'),
                    'rows'     => 20,
                    'required' => false
                ),
                array(
                    'type'     => 'text',
                    'label'    => $this->l('Web Service: ssoId'),
                    'name'     => $this->getConfigurationName('ws_ssoId'),
                    'rows'     => 20,
                    'required' => false
                ),
                array(
                    'type'     => 'text',
                    'label'    => $this->l('Web Service: apiKey'),
                    'name'     => $this->getConfigurationName('ws_apiKey'),
                    'rows'     => 20,
                    'required' => false
                ),
                array(
                    'type'     => 'text',
                    'label'    => $this->l('Product minimal price'),
                    'name'     => $this->getConfigurationName('productMinPrice'),
                    'rows'     => 20,
                    'required' => false
                ),
                array(
                    'type'    => 'switch',
                    'label'   => $this->l('Use iframe'),
                    'name'    => $this->getConfigurationName('useIframe'),
                    'is_bool' => true,
                    'values'  => array(
                        array(
                            'id'    => 'active_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id'    => 'active_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    ),
                ),
                array(
                    'type'    => 'switch',
                    'label'   => $this->l('Live mode'),
                    'name'    => $this->getConfigurationName('liveMode'),
                    'is_bool' => true,
                    'values'  => array(
                        array(
                            'id'    => 'active_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id'    => 'active_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    ),
                )
            )
        );

        foreach ($this->getArticleTypeCodebook() as $k => $v) {
            $form['form']['input'][] = array(
                'type'     => 'text',
                'label'    => $this->l($v),
                'name'     => $this->getConfigurationName('categoryMap[' . $k . ']'),
                'rows'     => 20,
                'desc'     => 'prestashop categories id eg. 1,2,3',
                'required' => false
            );
        }

        return $form;
    }

    public function getArticleTypeCodebook()
    {
        return array(
            1  => 'Notebooky a tablety',
            2  => 'Ostatná počítačová technika',
            3  => 'Mobilný telefón',
            4  => 'Biela technika',
            5  => 'Čierna technika',
            6  => 'Športové vybavenie a oblečenie',
            7  => 'Hobby a záhrada',
            8  => 'Dom a stavba',
            9  => 'Nábytok',
            10 => 'Služby',
            11 => 'Auto-moto',
            12 => 'Domáce potreby',
            16 => 'Kočiare a potreby pre deti',
            17 => 'Hodinky a šperky',
            13 => 'Kozmetika a drogéria',
            14 => 'Zdravotná technika a pomôcky',
            15 => 'Zájazdy a dovolenky'
        );
    }

    protected function getConfigDefaultValues()
    {
        return array_merge(
            array(),
            array(
                'ws_partnerId'    => '1',
                'ws_eshopId'      => '15',
                'ws_ssoId'        => '666',
                'ws_apiKey'       => 'Heslo123',
                'productMinPrice' => '300',
                'categoryMap'     => array(),
                'liveMode'        => 0,
            )
        );
    }

    protected function getConfigurationNames()
    {
        return array_merge(
            array(),
            array(
                'ws_partnerId',
                'ws_eshopId',
                'ws_ssoId',
                'ws_apiKey',
                'productMinPrice',
                'useIframe',
                'categoryMap',
                'liveMode'
            )
        );
    }

    protected $config_form      = false;
    protected $overridenModules = array();

    public function hookDisplayBackOfficeHeader()
    {

        if (Tools::getValue(
                'module_name',
                Tools::getValue('configure')
            ) == $this->name
        ) {
            $this->context->controller->addJquery();
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }

    }

    public function uninstall()
    {

        return parent::uninstall() && $this->uninstallModuleOverrides();
    }

    protected function createOrderState()
    {
        if (!Configuration::get('LNC_COFIDIS_OS_AWAITING')) {
            $order_state = new OrderState();
            $order_state->name = array();

            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Awaiting response from Cofidis';
            }

            $order_state->send_email = false;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;

            if ($order_state->add()) {

            }
            Configuration::updateValue('LNC_COFIDIS_OS_AWAITING', (int)$order_state->id);
        }

        if (!Configuration::get('LNC_COFIDIS_OS_CONFIRMED')) {
            $order_state = new OrderState();
            $order_state->name = array();

            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Confirmed from Cofidis';
            }

            $order_state->send_email = false;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;

            if ($order_state->add()) {

            }
            Configuration::updateValue('LNC_COFIDIS_OS_CONFIRMED', (int)$order_state->id);
        }

        return true;
    }

    protected function maybeUpdateDatabase()
    {

        $columnNames = array(
            'id'             => 'INT',
            'id_cart'        => 'INT NOT NULL',
            'id_order'       => 'INT DEFAULT NULL',
            'variableSymbol' => 'VARCHAR(20)',
            'price'          => 'decimal(20,6)',
            'article'        => 'text',
            'articleType'    => 'INT',
            'contractStatus' => 'INT DEFAULT NULL',
            'despatch'       => 'INT DEFAULT NULL',
            'created_at'     => 'DATETIME',
            'updated_at'     => 'DATETIME'
        );
        $indexsNames = array(
            'id_cart'  => 'INDEX(`id_cart`)',
            'id_order' => 'INDEX(`id_order`)'
        );
        foreach (
            array(
                'cofidis_contract'
            ) as $table
        ) {

            $columns = array();
            $sql = 'DESCRIBE ' . _DB_PREFIX_ . $table;
            try {
                $columns = Db::getInstance()
                    ->executeS($sql);
            }
            catch(Exception $e) {
                
            }

            if (!$columns) {
                if (!Db::getInstance()
                    ->execute(
                        'CREATE TABLE `' . _DB_PREFIX_ . $table . '` (`id` INT(10) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))'
                    )
                ) {
                    return false;
                }
                $columns = Db::getInstance()
                    ->executeS($sql);
            }

            $found = array();
            foreach ($columnNames as $k => $type) {
                $found[$k] = false;
            }

            foreach ($columns as $col) {
                if (isset($found[$col['Field']])) {
                    $found[$col['Field']] = true;
                }
            }

            foreach ($found as $k => $b) {
                if (!$found[$k]) {
                    if (!Db::getInstance()
                        ->execute(
                            'ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD `' . $k . '` ' . $columnNames[$k]
                        )
                    ) {
                        return false;
                    }
                }
            }

            $sql = 'SHOW INDEX FROM ' . _DB_PREFIX_ . $table;
            $indexes = Db::getInstance()
                ->executeS($sql);
            $found = array();
            foreach ($indexsNames as $k => $type) {
                $found[$k] = false;
            }

            foreach ($indexes as $col) {
                if (isset($found[$col['Key_name']])) {
                    $found[$col['Key_name']] = true;
                }
            }
            foreach ($found as $k => $b) {
                if (!$found[$k]) {
                    if (!Db::getInstance()
                        ->execute(
                            'ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD ' . $indexsNames[$k]
                        )
                    ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    protected function log($line)
    {
        file_put_contents(
            __DIR__ . '/data/log.txt',
            sprintf(
                '%s %s' . "\n",
                date('Y-m-d H:i:s'),
                $line
            ),
            FILE_APPEND
        );
    }

    protected function installModuleOverrides()
    {

        $result = true;
        foreach ($this->overridenModules as $overridenModule) {
            $result &= mkdir(
                    _PS_ROOT_DIR_ . '/override/modules/' . $overridenModule,
                    0777,
                    true
                )
                && copy(
                    __DIR__ . '/../install/override/modules/' . $overridenModule . '.php',
                    _PS_ROOT_DIR_ . '/override/modules/' . $overridenModule . '/' . $overridenModule . '.php'
                )
                && (Tools::generateIndex() || true);
        }

        return $result;

    }

    protected function uninstallModuleOverrides()
    {

        $result = true;
        foreach ($this->overridenModules as $overridenModule) {
            $result &= unlink(
                    _PS_ROOT_DIR_ . '/override/modules/' . $overridenModule . '/' . $overridenModule . '.php'
                )
                && rmdir(
                    _PS_ROOT_DIR_ . '/override/modules/' . $overridenModule
                )
                && (Tools::generateIndex() || true);
        }

        return $result;
    }

    public function getConfigurationValue($key)
    {
        $hasKey = Configuration::hasKey(
            $this->getConfigurationName($key)
        );
        if ($hasKey) {
            $v = Configuration::get(
                $this->getConfigurationName($key)
            );
            if (preg_match(
                '#^([adObis]:|N;)#',
                $v,
                $m
            )) {
                $v = unserialize($v);
            }

            return $v;
        }

        $default = $this->getConfigDefaultValues();
        if (isset($default[$key])) {
            return $default[$key];
        }

        return false;
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get(
            'PS_BO_ALLOW_EMPLOYEE_FORM_LANG',
            0
        );

        $helper->identifier = $this->identifier;
        $helper->submit_action = $this->getSubmitActionName();
        $helper->currentIndex = $this->context->link->getAdminLink(
                'AdminModules',
                false
            ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            /* Add values for your inputs */
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {

        $return = array();
        foreach ($this->getConfigurationNames() as $name) {
            $n = $this->getConfigurationName($name);
            $v = $this->getConfigurationValue($name);
            if (is_array($v)) {
                foreach ($v as $k => $v2) {
                    $return[$n . '[' . $k . ']'] = $v2;
                }
            } else {
                $return[$n] = $v;
            }
        }

        return $return;
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        foreach ($this->getConfigurationNames() as $key) {
            $n = $this->getConfigurationName($key);
            $v = $this->trim(Tools::getValue($n));
            if (is_array($v)) {
                $v = serialize($v);
            }
            Configuration::updateValue(
                $n,
                $v
            );
        }
    }

    protected function getSubmitActionName()
    {
        return sprintf(
            'submit%sModule',
            $this->name
        );
    }

    protected function getConfigurationName($key)
    {
        $key = sprintf(
            '%s_%s',
            $this->name,
            $key
        );

        return $key;
    }

    protected function trim($arr, $charlist = ' ')
    {
        if (is_string($arr)) {
            return trim(
                $arr,
                $charlist
            );
        } elseif (is_array($arr)) {
            $result = array();
            foreach ($arr as $key => $value) {
                if (is_array($value)) {
                    $result[$key] = $this->trim(
                        $value,
                        $charlist
                    );
                } else {
                    $result[$key] = trim(
                        $value,
                        $charlist
                    );
                }
            }

            return $result;
        } else {
            return $arr;
        }
    }

}
