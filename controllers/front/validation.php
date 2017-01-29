<?php

class LNCCofidisValidationModuleFrontController extends ModuleFrontController
{

    public function __construct()
    {

        $this->ssl = true;

        parent::__construct();

        $this->display_column_left = false;

    }

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        if (!$this->module->checkCurrency($cart))
            Tools::redirect('index.php?controller=order');

        $this->context->smarty->assign(array(
            'nbProducts'    => $cart->nbProducts(),
            'cust_currency' => $cart->id_currency,
            'currencies'    => $this->module->getCurrency((int)$cart->id_currency),
            'total'         => $cart->getOrderTotal(true, Cart::BOTH),
            'this_path'     => $this->module->getPathUri(),
            'this_path_bw'  => $this->module->getPathUri(),
            'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->module->name . '/'
        ));

        $this->setTemplate('payment_execution.tpl');
    }

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        /**
         * @var Cart $cart
         */
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
            Tools::redirect('index.php?controller=order&step=1');

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module)
            if ($module['name'] == 'LNCCofidis') {
                $authorized = true;
                break;
            }
        if (!$authorized)
            die($this->module->l('This payment method is not available.', 'validation'));

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer))
            Tools::redirect('index.php?controller=order&step=1');

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

            /**
             * @var LNCCofidis $module
             */
            $module = Module::getInstanceByName('LNCCofidis');

            $options = array(
                'partnerId' => $module->getConfigurationValue('ws_partnerId'),
                'eshopId'   => $module->getConfigurationValue('ws_eshopId'),
                'ssoId'     => $module->getConfigurationValue('ws_ssoId'),
                'apiKey'    => $module->getConfigurationValue('ws_apiKey'),
                'liveMode'  => $module->getConfigurationValue('liveMode')
            );
            $webService = new \LNC\Cofidis\CofidisWebService($options);

            $products = $cart->getProducts();
            $product = null;
            $arr = array();
            foreach ($products as $item) {
                $arr[$item['price']] = $item;
            }
            krsort($arr);
            $arr = array_values($arr);
            $product = new Product($arr[0]['id_product']);

            $price = $total;
            $article = mb_strcut(implode(' ', array(
                $product->reference,
                Tools::truncateString($product->name[$this->context->language->id], 60)
            )), 0, 50, 'utf-8');
            $articleType = 4;

            if (sizeof($products) == 1) {
                $arr = $module->getConfigurationValue('categoryMap');
                foreach ($arr as $k => $v) {
                    $v = explode(',', $v);
                    if (array_intersect(
                        $v,
                        $product->getCategories()
                    )) {
                        $articleType = $k;
                        break;
                    }
                }
            }

            $mailVars = array();

            $this->module->validateOrder($cart->id, Configuration::get('LNC_COFIDIS_OS_AWAITING'), $total, $this->module->displayName, NULL, $mailVars, (int)$currency->id, false, $customer->secure_key);

            $order = new Order($this->module->currentOrder);

            $t = date('Y-m-d H:i:s');
            Db::getInstance()->insert('cofidis_contract', array(
                'id_cart'        => $cart->id,
                'id_order'       => $this->module->currentOrder,
                'variableSymbol' => pSQL($order->reference),
                'price'          => pSQL($price),
                'article'        => pSQL($article),
                'articleType'    => pSQL($articleType),
                'created_at'     => pSQL($t),
                'updated_at'     => pSQL($t)
            ));

            $id = Db::getInstance()->Insert_ID();

            $url = $webService->getLoanDemandUrl($id, $price, $article, $articleType, $order->reference);
            if ($url) {
                if ($module->getConfigurationValue('useIframe')) {
                    $this->context->smarty->assign(
                        array(
                            'url' => $url
                        )
                    );
                } else {
                    Tools::redirect($url);

                    return;
                }
            }

        }

    }
}
