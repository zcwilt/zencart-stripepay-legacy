<?php

if (!class_exists('Zencart\ModuleSupport\PaymentBase')) {
    require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/ModuleBase.php';
    require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/ModuleConcerns.php';
    require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/PaymentConcerns.php';
    require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/ModuleContract.php';
    require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/PaymentContract.php';
    require_once DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/ModuleSupport/PaymentBase.php';
}

use Carbon\Carbon;
use Zencart\ModuleSupport\PaymentBase;
use Zencart\ModuleSupport\PaymentContract;
use Zencart\ModuleSupport\PaymentConcerns;
use Stripe\SetupIntent;
use Stripe\PaymentIntent;
use Aura\Autoload\Loader;

class stripe_pay extends PaymentBase implements PaymentContract
{
    use PaymentConcerns;

    public string $code = 'stripe_pay';

    public string $version = '1.0.0';

    public string $defineName = 'STRIPE_PAY';

    public function selection(): array
    {
        global $order;
        $paymentCurrency = $order->info['currency'];
        $orderTotal = $this->convertCurrencyValue($order->info['total'], $paymentCurrency);
        $postcode = $order->billing['postcode'];
        $country = $order->billing['country']['iso_code_2'];
        $publishableKey = $this->getPublishableKey();
        $secretKey = $this->getSecretKey();
        Stripe\Stripe::setApiKey($secretKey);
        $stripeAlwaysShowForm = ($this->getDefine('ALWAYS_SHOW_FORM') === 'True')  ? true : false;

        $setupIntent = Stripe\SetupIntent::create([
            'payment_method_types' => ['card'],
        ]);
        $clientSecret = $setupIntent->client_secret;
        $selection = [];
        $selection['id'] = $this->code;
        $selection['module'] = $this->title;
        $selection['fields'] = [
            [
                'title' =>
                    '<script>const stripePublishableKey = "' . $publishableKey . '";</script>' .
                    '<script>const stripeSecretKey = "' . $clientSecret . '";</script>' .
                    '<script>const stripeAlwaysShowForm  = "' . $stripeAlwaysShowForm . '"</script>' .
                    '<script>const stripePaymentAmount  = "' . $orderTotal . '"</script>' .
                    '<script>const stripePaymentCurrency = "' . $paymentCurrency . '"</script>' .
                    '<script>const stripeBillingPostcode = "' . $postcode . '"</script>' .
                    '<script>const stripeBillingCountry = "' . $country . '"</script>' .
                    '',
                'field' =>
                    '<input type="hidden" name="stripepay-setup-intent-id" id="stripepay-setup-intent-id" value="' . $setupIntent->id . '">' .
                    '<script>' . file_get_contents(DIR_WS_MODULES . 'payment/stripe_pay/stripepay-paymentform.js') . '</script>' .
                    '<div id="stripepay-intent-payment-element" style="display: none">' .
                    '</div>' .
                    '<div id="stripepay-intent-error-message" class="alert"' .
                    '</div>' .
                    '',
            ],
        ];
        return $selection;
    }

    public function pre_confirmation_check()
    {
        if (!isset($_POST['stripepay-setup-intent-id'])) {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL'));
        }
    }

    public function process_button()
    {
        $process_button_string = zen_draw_hidden_field('stripepay-setup-intent-id', htmlspecialchars($_POST['stripepay-setup-intent-id'])) ;
        return $process_button_string;
    }

    public function before_process()
    {
        global $messageStack, $order;
        $secretKey = $this->getSecretKey();
        Stripe\Stripe::setApiKey($secretKey);
        $setupIntentId = htmlspecialchars($_POST['stripepay-setup-intent-id']) ?? null;
        if (!$setupIntentId) {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT));
        }
        try {
            // Retrieve the SetupIntent from Stripe
            $setupIntent = Stripe\SetupIntent::retrieve($setupIntentId);
            // Check the status of the PaymentIntent
            if ($setupIntent->status === 'succeeded') {
                $this->handleSetupSuccess($setupIntent);
            } else {
                $this->handleSetupFailure($setupIntent, $order);
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $context = $this->buildErrorContextFromException($e, $order);
            $this->logger->log('critical', $e->getMessage(), $context);
            $messageStack->add_session('checkout_payment', $e->getMessage(), 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL'));
        }
    }

    protected function handleSetupSuccess(SetupIntent $setupIntent): void
    {
        global $order;

        $customer = Stripe\Customer::create([
            'email' => $order->customer['email_address'],
        ]);

        $paymentMethod = Stripe\PaymentMethod::retrieve($setupIntent->payment_method);

        if ($paymentMethod->customer) {
            // If the payment method is already attached to a customer, use that customer
            $customer_id = $paymentMethod->customer;
        } else {
            // Attach the payment method to the newly created customer
            $paymentMethod->attach([
                'customer' => $customer->id,
            ]);
            $customer_id = $customer->id;
        }
        $paymentIntent = Stripe\PaymentIntent::create([
            'amount' => $this->convertCurrencyValue($order->info['total'], $order->info['currency']),
            'currency' => $order->info['currency'],
            'payment_method' => $setupIntent->payment_method,
            'customer' => $customer_id,
            'confirm' => true,
            'return_url' => zen_href_link(FILENAME_CHECKOUT_CONFIRMATION, '', 'SSL', true),
        ]);

        if ($paymentIntent->status === 'succeeded') {
            $this->handlePaymentSuccess($paymentIntent, $order);
        } else {
            $this->handlePaymentFailure($paymentIntent, $order);
        }
    }

    protected function handleSetupFailure(SetupIntent $setupIntent, $order): void
    {
        global $messageStack;
        $context = [];
        $context['setupIntent'] = $setupIntent;
        $context['customer'] = ['email' => $order->customer['email_address'], 'first_name' => $order->customer['firstname'], 'last_name' => $order->customer['lastname']];
        $this->logger->log('critical', 'SetupIntent failed', $context);
        $messageStack->add_session('checkout_payment', $this->getDefine('ERROR_TEXT_PROCESSING'), 'error');
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL'));

    }

    protected function handlePaymentSuccess(PaymentIntent $paymentIntent, Order $order): void
    {
        $context = [];
        $context['paymentIntent'] = $paymentIntent;
        $context['customer'] = ['email' => $order->customer['email_address'], 'first_name' => $order->customer['firstname'], 'last_name' => $order->customer['lastname']];
        $this->logger->log('critical', 'PaymentIntent succeeded', $context);

    }

    protected function handlePaymentFailure(PaymentIntent $paymentIntent, Order $order): void
    {
        global $messageStack;
        $context = [];
        $context['paymentIntent'] = $paymentIntent;
        $context['customer'] = ['email' => $order->customer['email_address'], 'first_name' => $order->customer['firstname'], 'last_name' => $order->customer['lastname']];
        $this->logger->log('critical', 'PaymentIntent failed', $context);
        $messageStack->add_session('checkout_payment', $this->getDefine('ERROR_TEXT_PROCESSING'), 'error');
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL'));
    }



    protected function checkFatalConfigureStatus(): bool
    {
        $configureStatus = true;
        $toCheck = 'LIVE';
        if ($this->getDefine('MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        if ($this->getDefine($toCheck . '_PUB_KEY') == '' || $this->getDefine($toCheck . '_SECRET_KEY') == '') {
            $this->configureErrors[] = sprintf('(not configured - needs %s publishable and secret key)', $toCheck);
            $configureStatus = false;
        }
        return $configureStatus;
    }

    protected function addCustomConfigurationKeys(): array
    {
        $configKeys = [];
        $key = $this->buildDefine('ORDER_STATUS_ID');
        $configKeys[$key] = [
            'configuration_value' => '2',
            'configuration_title' => 'Completed Order Status',
            'configuration_description' => 'Set the status of orders whose payment has been successfully <em>captured</em> to this status.<br>Recommended: <b>Processing[2]</b><br>',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => 'zen_cfg_pull_down_order_statuses(',
            'use_function' => 'zen_get_order_status_name',
        ];
        $key = $this->buildDefine('ALWAYS_SHOW_FORM');
        $configKeys[$key] = [
            'configuration_value' => 'False',
            'configuration_title' => 'Always show the Stripe payment form',
            'configuration_description' => 'Normally the form for collecting Credit card information will only be shown if the user selects the payment method. This option will force the form to always be displayed.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => "zen_cfg_select_option(array('True', 'False'), ",
        ];
        $key = $this->buildDefine('LIVE_PUB_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe live publishable key',
            'configuration_description' => 'Your live publishable key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('LIVE_SECRET_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe live secret key',
            'configuration_description' => 'Your live secret key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('TEST_PUB_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe test publishable key',
            'configuration_description' => 'Your test publishable key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('TEST_SECRET_KEY');
        $configKeys[$key] = [
            'configuration_value' => '',
            'configuration_title' => 'Stripe test key',
            'configuration_description' => 'Your test secret key.',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
        ];
        $key = $this->buildDefine('MODE');
        $configKeys[$key] = [
            'configuration_value' => 'Test',
            'configuration_title' => 'Test or Live mode',
            'configuration_description' => 'Whether to process transactions in test or live mode',
            'configuration_group_id' => 6,
            'sort_order' => 1,
            'date_added' => Carbon::now(),
            'set_function' => "zen_cfg_select_option(array('Test', 'Live'), ",
        ];
        return $configKeys;
    }

    protected function getPublishableKey(): string
    {
        $toCheck = 'LIVE';
        if ($this->getDefine('MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        return $this->getDefine($toCheck . '_PUB_KEY');
    }

    protected function getSecretKey(): string
    {
        $toCheck = 'LIVE';
        if ($this->getDefine('MODE') == 'Test') {
            $toCheck = 'TEST';
        }
        return $this->getDefine($toCheck . '_SECRET_KEY');
    }

    protected function buildErrorContextFromException(Exception $exception, Order $order): array
    {
        $errorContext = [];
        $errorContext['error'] = $exception->getMessage();
        $errorContext['error_code'] = $exception->getCode();
        $errorBody = $exception->getJsonBody();
        unset($errorBody['payment_intent']);
        $errorContext['customer'] = ['email' => $order->customer['email_address'], 'first_name' => $order->customer['firstname'], 'last_name' => $order->customer['lastname']];
        $errorContext['body'] = $errorBody;
        return $errorContext;
    }

    protected function convertCurrencyValue($value, $currency): int
    {
        global $order;

        $asIs = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
        $by1000 = ['BHD','JOD','KWD','OMR','TND'];

        switch (true) {
            case in_array($currency, $asIs):
                $value = $value;
                break;
            case in_array($currency, $by1000):
                $value = $value * 1000;
                break;
            default:
                $value = $value * 100;
        }
        return (int)$value;
    }
    protected function moduleAutoloadSupportClasses(Loader $psr4Autoloader): Loader
    {
        $psr4Autoloader->addPrefix('Stripe', DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/stripe-php-13.15.0/lib/');
        $psr4Autoloader->addPrefix('Monolog', DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/monolog/src/Monolog/');
        $psr4Autoloader->addPrefix('Zencart\Logger', DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/stripe_pay/Logger/');
        return $psr4Autoloader;
    }
}
