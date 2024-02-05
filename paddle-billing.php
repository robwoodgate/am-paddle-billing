<?php
/**
 * Paddle Billing
 * Contributed by R Woodgate, Cogmentis Ltd.
 *
 * New account setup:
 * 1) Set Default Payment Link: Paddle > Checkout > Checkout Settings.
 *
 * ============================================================================
 * Revision History:
 * ----------------
 * 2024-01-31   v1.0    R Woodgate  Plugin Created
 * ============================================================================
 *
 * @am_payment_api 6.0
 */
class Am_Paysystem_PaddleBilling extends Am_Paysystem_Abstract
{
    public const PLUGIN_STATUS = self::STATUS_BETA;
    public const PLUGIN_REVISION = '@@VERSION@@';
    public const CUSTOM_DATA_INV = 'am_invoice';
    public const PRICE_ID = 'paddle-billing_pri_id';
    public const CUSTOMER_ID = 'paddle-billing_ctm_id';
    public const SUBSCRIPTION_ID = 'paddle-billing_sub_id';
    public const TXNITM = 'paddle-billing_txnitm';
    public const LIVE_URL = 'https://api.paddle.com/';
    public const SANDBOX_URL = 'https://sandbox-api.paddle.com/';
    public const PADDLEJS_URL = 'https://cdn.paddle.com/paddle/v2/paddle.js';
    public const API_VERSION = 1;

    protected $defaultTitle = 'Paddle Billing';
    protected $defaultDescription = 'Payment via Paddle Billing gateway';
    protected $_canAutoCreate = true;
    protected $_canResendPostback = true;

    public function init(): void
    {
        $this->getDi()->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                static::PRICE_ID,
                'Paddle Billing: Price ID',
                'Optional. Lets you link and use Paddle Catalog items you have created. Only required if you accept direct payments.',
                null,
                ['placeholder' => 'pri_abc123']
            )
        );
        $this->getDi()->blocks->add(
            'thanks/success',
            new Am_Block_Base('Paddle Statement', 'paddle-statement', $this, [$this, 'renderStatement'])
        );
    }

    public function renderStatement(Am_View $v)
    {
        if (isset($v->invoice) && $v->invoice->paysys_id == $this->getId()) {
            $msg = ___('This order was processed by our online reseller & Merchant of Record, Paddle.com, who also handle order related inquiries and returns. The payment will appear on your bank/card statement as:');

            return <<<CUT
                <div class="am-block am-paddle-statement">
                    <p>{$msg} <strong>PADDLE.NET*{$this->getConfig('statement_desc')}</strong></p>
                </div>
                CUT;
        }
    }

    public function onBeforeRender(Am_Event $e): void
    {
        // Add PaddleJS to member home/signup forms
        static $init = 0;
        if (!$init++) {
            $e->getView()->placeholder('head-start')->prepend('<script id="paddle-billing-js" src="'.static::PADDLEJS_URL.'"></script>');
            $e->getView()->placeholder('body-start')->prepend($this->paddleJsSetupCode());
        }

        // Inject Paddle Payment Update URL into detailed subscriptions widget
        if (false !== strpos($e->getTemplateName(), 'blocks/member-history-detailedsubscriptions')) {
            $v = $e->getView();
            foreach ($v->activeInvoices as &$invoice) {
                if ($invoice->paysys_id == $this->getId()) {
                    if ($_ = $invoice->data()->get(static::SUBSCRIPTION_ID)) {
                        $invoice->_updateCcUrl = $this->getDi()->url(
                            'payment/'.$this->getId().'/update',
                            [
                                'id' => $invoice->getSecureId($this->getId()),
                                'sub' => $_,
                            ]
                        );
                    }
                }
            }
        }

        // Inject Paddle receipt download link into payment history widget
        if (false !== strpos($e->getTemplateName(), 'blocks/member-history-paymenttable')) {
            $v = $e->getView();
            foreach ($v->payments as &$p) {
                if ($p->paysys_id == $this->getId()) {
                    $invoice = $p->getInvoice();
                    $p->_invoice_url = $this->getDi()->url(
                        'payment/'.$this->getId().'/invoice',
                        [
                            'id' => $invoice->getSecureId($this->getId()),
                            'txn' => $p->receipt_id,
                        ]
                    );
                }
            }
        }
    }

    public function allowPartialRefunds()
    {
        // @see processRefund()
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        // @see https://developer.paddle.com/concepts/sell/supported-currencies
        return ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK', 'ARS', 'BRL', 'CNY', 'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'KRW', 'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'THB', 'TRY', 'TWD', 'UAH'];
    }

    public function _initSetupForm(Am_Form_Setup $form): void
    {
        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel("API Key\n".'Use your default API key or generate a new one from the Developer Tools > <a href="https://vendors.paddle.com/authentication-v2" target="_blank">Authentication menu</a> menu of your Paddle Dashboard.')
            ->addRule('required')
        ;

        $form->addSecretText('client_token', ['class' => 'am-el-wide'])
            ->setLabel("Client-Side Token\n".'From the Developer Tools > <a href="https://vendors.paddle.com/authentication-v2" target="_blank">Authentication menu</a> of your Paddle Dashboard.')
            ->addRule('required')
        ;

        $form->addSecretText('secret_key', ['class' => 'am-el-wide'])
            ->setLabel("Webhook Secret Key\n".'From the Developer Tools > <a href="https://vendors.paddle.com/notifications" target="_blank">Notifications menu</a> of your Paddle Dashboard. <a href="https://developer.paddle.com/webhooks/signature-verification#get-secret-key" target="_blank">Detailed Instructions</a>')
            ->addRule('required')
        ;

        $form->addSecretText('retain_key', ['class' => 'am-el-wide'])
            ->setLabel("Retain Public Token (Optional)\n".'From the Retain > Account Settings > <a href="https://www2.profitwell.com/app/account/integrations" target="_blank">Integrations</a> > API keys/Dev Kit of your Paddle Dashboard.')
        ;

        // Add Sandbox warning
        if ($this->isSandbox()) {
            $form->addProlog("<div class='warning_box'>You are currently using Sandbox credentials. All transactions are tests, meaning they're simulated and any money isn't real.</div>");
        }

        // @TODO
        $fs = $this->getExtraSettingsFieldSet($form);
        $fs->addAdvCheckbox('cbk_lock')->setLabel('Lock User Account on Chargeback
        If checked, will add a note and lock the user account if a chargeback is received.');
        $fs->addAdvCheckbox('hrt_lock')->setLabel('Lock User Account on Fraud Warning
        You need to configure Paddle to send "High Risk Transaction Created" and "High Risk Transaction Updated" webhook alerts. If checked, will add a note and lock the user account until the flagged transaction is approved.');
        $fs->addAdvCheckbox('show_grid')->setLabel(___("Show Plans in Product Grid\nIf checked, the plugin will add a column to the Manage Products grid"));
        $fs->addText('image_url', [
            'class' => 'el-wide',
            'placeholder' => 'https://www.example.com/path/to/my_logo.png',
        ])->setLabel("Default Image URL\nAn absolute URL to a square image of your brand or product. Recommended minimum size is 128x128px. Supported image types are: .gif, .jpeg, and .png. Will be used for single payments where the optional Paddle Product ID is not supplied.");
        $fs->addText('statement_desc')->setLabel("Statement Description\nThe Statement Description from your Paddle Dashboard > Checkout > Checkout Settings page. Shown on the thanks page to help alert customer as to what will appear on their card statement.");
    }

    public function isConfigured()
    {
        return $this->getConfig('client_token') && $this->getConfig('api_key');
    }

    public function isSandbox()
    {
        return 0 === strpos($this->getConfig('client_token'), 'test_');
    }

    public function _process($invoice, $request, $result): void
    {
        // Paddle lets us bill for non-catalog products and prices using the
        // Create Transaction API. This means we do not have to create/sync
        // products in Paddle. @see: https://developer.paddle.com/build/transactions/bill-create-custom-items-prices-products

        // * Prepare log
        $log = $this->getDi()->invoiceLogRecord;
        $log->title = 'DRAFT TRANSACTION';
        $log->user_id = $invoice->user_id;
        $log->invoice_id = $invoice->pk();

        // * Prepare transaction params
        $params = [
            'currency_code' => $invoice->currency,
            'custom_data' => [
                static::CUSTOM_DATA_INV => $invoice->public_id,
            ],
        ];

        // Filter to allow advanced customisation of the custom data
        // @see: https://docs.amember.com/API/HookManager/
        // Example code for site.php:
        // Am_Di::getInstance()->hook->add('PaddleBillingCustomData', function(Am_Event $e) {
        //     // Vars
        //     $invoice = $e->getInvoice();
        //     $user = $e->getUser();
        //
        //     // Add custom data kvps
        //     $e->addReturn('email', 'utm_medium');
        //     $e->addReturn('closed-deal', 'utm_content');
        //     $e->addReturn('AA-123', 'integration_id');
        // });
        $custom = Am_Di::getInstance()->hook->filter(
            [],
            $this->getId().'CustomData',
            ['invoice' => $invoice, 'user' => $invoice->getUser()]
        );
        if (!is_array($custom)) {
            throw new Am_Exception_InputError($this->getId().'CustomData Filter: Expected the custom filter to return an array, '.gettype($custom).' received');
        }
        foreach ($custom as $k => $v) {
            // Make sure we only add proper KVPs, and avoid overwriting invoice!
            if (!is_int($k) && static::CUSTOM_DATA_INV != $k) {
                $params['custom_data'][$k] = $v;
            }
        }

        // Get default image if available and correctly formatted
        // Choose the cart default (if set) over plugin default
        if ($default_img = $this->getDi()->config->get('cart.img_cart_default_path')) {
            $default_img = $this->url('data/public/'.$default_img);
        } else {
            $default_img = $this->getConfig('image_url');
        }
        if ('' == parse_url($default_img, PHP_URL_SCHEME)) {
            $default_img = null;
        }

        // Add invoice items to Paddle Transaction
        // @var $item InvoiceItem
        foreach ($invoice->getItems() as $item) {
            // Init vars
            $rebill = null;
            $fptext = ': '.$this->getText($item->first_period);
            $sptext = ': '.$this->getRebillText($item->rebill_times);

            // Try get product specific image, fall back to default if needed
            $image_url = $item->tryLoadProduct()->img_cart_path ?? $default_img;
            $terms = $item->tryLoadProduct()->getBillingPlan()->getTerms();

            // Build the core transaction item payload
            $txnitm = [
                'quantity' => $item->qty,
                'price' => [
                    'description' => $terms,
                    'name' => ___('Subscription').$sptext,
                    'billing_cycle' => [
                        'interval' => $this->getInterval($item->second_period),
                        'frequency' => $this->getFrequency($item->second_period),
                    ],
                    'trial_period' => [
                        'interval' => $this->getInterval($item->first_period),
                        'frequency' => $this->getFrequency($item->first_period),
                    ],
                    'tax_mode' => 'account_setting',
                    'unit_price' => [
                        'amount' => $this->getAmount(
                            $item->second_total,
                            $item->currency
                        ),
                        'currency_code' => $item->currency,
                    ],
                    'quantity' => [
                        'minimum' => $item->qty,
                        'maximum' => $item->qty,
                    ],
                    'custom_data' => [
                        'invoice_item' => $item->item_id,
                        'period' => 'first_period',
                    ],
                    'product' => [
                        'name' => $item->item_title,
                        'description' => $item->item_description,
                        'tax_category' => 'standard',
                        'image_url' => $image_url,
                    ],
                ],
            ];

            // Handle one-time payments (ie: no rebill/trial)
            if (!$item->second_period) {
                $txnitm['price']['name'] = (($item->first_total)
                    ? ___('Purchase') : ___('Free')).$fptext;
                unset($txnitm['price']['billing_cycle'], $txnitm['price']['trial_period']);

                $txnitm['price']['unit_price']['amount'] = $this->getAmount(
                    $item->first_total,
                    $item->currency
                );
                $params['items'][] = $txnitm;

                continue;
            }

            // Handle free first period products (free trials)
            // Use the core transaction item payload as is
            if (0 == $item->first_total) {
                $params['items'][] = $txnitm;

                continue;
            }

            // Handle simple rebills (same first and second price/period)
            if ($item->first_total == $item->second_total
                && $item->first_period == $item->second_period
            ) {
                unset($txnitm['price']['trial_period']);
                $params['items'][] = $txnitm;

                continue;
            }

            // Handle complex rebills (different first and second price/period)
            // Paddle doesn't support subscriptions with different prices/periods
            // so we have to treat the first period as a one-time payment
            // and add the second period as a seperate item starting
            // at the end of the first period using a Paddle Trial.
            $rebill = $txnitm; // Copy core transaction item
            $txnitm['price']['name'] = ___('First Payment').$fptext;
            $txnitm['price']['unit_price']['amount'] = $this->getAmount(
                $item->first_total,
                $item->currency
            );
            unset($txnitm['price']['billing_cycle'], $txnitm['price']['trial_period']);
            $rebill['price']['custom_data']['period'] = 'second_period';
            $params['items'][] = $txnitm;
            $params['items'][] = $rebill;
        }

        // * Generate the draft transaction
        $response = $this->_sendRequest('transactions', $params, $log);

        // * Decode and check transaction ID
        $body = @json_decode($response->getBody(), true);
        if (empty($body['data']['id'])) {
            throw new Am_Exception_InternalError('Bad response: '.$response->getBody());
        }

        // * Open pay page
        $a = new Am_Paysystem_Action_HtmlTemplate('pay.phtml');
        $a->invoice = $invoice;
        $environment = $this->isSandbox() ? 'Paddle.Environment.set("sandbox");' : '';
        $client_token = $this->getConfig('client_token');
        $retain_key = $this->getConfig('retain_key')
            ? '"'.$this->getConfig('retain_key').'"' : 'null';
        $txnid = $body['data']['id'];
        $thanks_url = $this->getReturnUrl();
        $name = $invoice->getName();
        $email = $invoice->getEmail();
        $country = $invoice->getCountry();
        $postcode = $invoice->getZip();
        $tax_id = $invoice->getUser()->tax_id ?? '';
        $a->form = <<<CUT
            <div class="checkout-container"></div>
            <script>
                {$environment}
                Paddle.Checkout.open({
                    transactionId: "{$txnid}",
                    successUrl: "{$thanks_url}",
                    customData: null,
                    settings: {
                        displayMode: "inline",
                        theme: "light",
                        locale: "en",
                        frameTarget: "checkout-container",
                        frameInitialHeight: "450",
                        frameStyle: "width: 100%; min-width: 312px; background-color: transparent; border: none;",
                        showAddTaxId: true,
                        allowLogout: false,
                        showAddDiscounts: false,
                        successUrl: "{$thanks_url}",
                    },
                    customer: {
                        email: "{$email}", // req
                        address: {
                          countryCode: "{$country}",   // req
                          postalCode: "{$postcode}", // req
                        },
                        business: {
                          taxIdentifier: "{$tax_id}"
                        }
                    }
                });
            </script>
            CUT;

        $result->setAction($a);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return Am_Paysystem_Transaction_PaddleBilling_Webhook::create($this, $request, $response, $invokeArgs);
    }

    public function directAction($request, $response, $invokeArgs)
    {
        // Default payment page
        if ('pay' == $request->getActionName()) {
            $view = $this->getDi()->view;
            $view->title = ___('Paddle Billing Checkout');
            $view->content = ___('If you have closed the checkout, please refresh the page or go back to the page you came from and try again.');
            $view->display('member/layout.phtml');

            return;
        }

        // Download Invoice
        if ('invoice' == $request->getActionName()) {
            // Get vars
            $inv_id = $request->getFiltered('id');
            $txn_id = $request->getFiltered('txn');
            $invoice = $this->getDi()->invoiceTable->findBySecureId($inv_id, $this->getId());
            if (!$invoice) {
                throw new Am_Exception_InputError('Invalid link');
            }

            // * Prepare log
            $log = $this->getDi()->invoiceLogRecord;
            $log->title = 'PDF INVOICE';
            $log->user_id = $invoice->user_id;
            $log->invoice_id = $invoice->pk();

            // * Make request
            $resp = $this->_sendRequest(
                "transactions/{$txn_id}/invoice",
                null,
                $log,
                Am_HttpRequest::METHOD_GET
            );

            // * Check response
            $body = @json_decode($resp->getBody(), true);
            if (200 !== $resp->getStatus() || empty($body['data']['url'])) {
                throw new Am_Exception_InputError('An error occurred while downloading: '.$body['error']['detail']);
            }

            // Download PDF
            set_time_limit(0);
            ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);
            header('Content-Type: application/pdf');
            $filename = strtok(basename($body['data']['url']), '?');
            header("Content-Disposition: attachment; filename={$filename}");
            readfile($body['data']['url']);

            exit;
        }

        // Update Payment Details
        if ('update' == $request->getActionName()) {
            // Get vars
            $inv_id = $request->getFiltered('id');
            $sub_id = $request->getFiltered('sub');
            $invoice = $this->getDi()->invoiceTable->findBySecureId($inv_id, $this->getId());
            if (!$invoice) {
                throw new Am_Exception_InputError('Invalid link');
            }

            // * Prepare log
            $log = $this->getDi()->invoiceLogRecord;
            $log->title = 'CARD UPDATE';
            $log->user_id = $invoice->user_id;
            $log->invoice_id = $invoice->pk();

            // * Make request
            $resp = $this->_sendRequest(
                "subscriptions/{$sub_id}/update-payment-method-transaction",
                null,
                $log,
                Am_HttpRequest::METHOD_GET
            );

            // * Check response
            $body = @json_decode($resp->getBody(), true);
            if (200 !== $resp->getStatus() || empty($body['data']['checkout']['url'])) {
                throw new Am_Exception_InputError('An error occurred: '.$body['error']['detail']);
            }

            // Redirect to checkout URL
            return $response->redirectLocation($body['data']['checkout']['url']);
        }

        // Let parent process it
        return parent::directAction($request, $response, $invokeArgs);
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        // Get subscription
        $subscription_id = $invoice->data()->get(static::SUBSCRIPTION_ID);
        if (!$subscription_id) {
            $result->setFailed('Can not find subscription id');

            return;
        }
        // * Prepare log
        $log = $this->getDi()->invoiceLogRecord;
        $log->title = 'CANCEL';
        $log->user_id = $invoice->user_id;
        $log->invoice_id = $invoice->pk();

        // * Make request
        $response = $this->_sendRequest(
            "subscriptions/{$subscription_id}/cancel",
            ['effective_from' => 'immediately'],
            $log
        );

        // * Check response
        if (200 !== $response->getStatus()) {
            $body = @json_decode($response->getBody(), true);
            $result->setFailed('An error occurred while processing your cancellation request: '.$body['error']['detail']);

            return;
        }

        $invoice->setCancelled(true);
        $result->setSuccess();
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        // Paddle refunds are not instantaneous - they get requested via API
        // and approved by Paddle staff, at which point a webhook alert is issued

        // * Prepare log
        $log = $this->getDi()->invoiceLogRecord;
        $invoice = $payment->getInvoice();
        $log->title = 'REFUND';
        $log->user_id = $invoice->user_id;
        $log->invoice_id = $invoice->pk();

        // * Get refund type
        $type = 'partial';
        if (doubleval($amount) == doubleval($payment->amount)) {
            $type = 'full';
        }

        // * Get the Paddle Transaction Item(s)
        // * and build the items payload
        $items = [];
        $txnitms = $invoice->data()->get(static::TXNITM);
        foreach ($txnitms as $txnitm) {
            $items[] = [
                'type' => $type,
                'item_id' => $txnitm,
            ];
        }

        // * Multi-item partial refunds must be handled via Paddle
        // * because we can't allocate the refund amount to items here
        // * Otherwise, add the amount for a partial refund
        if ('partial' == $type) {
            $items[0]['amount'] = $this->getAmount($amount, $invoice->currency);

            if (count($items) > 1) {
                $result->setFailed('Partial refunds for multi-product invoices must be requested from your Paddle account');

                return;
            }
        }

        // * Make request
        $response = $this->_sendRequest(
            'adjustments',
            [
                'action' => 'refund',
                'items' => $items,
                'transaction_id' => $payment->receipt_id,
                'reason' => 'Refund requested by user ('.$payment->getUser()->login.')',
            ],
            $log
        );

        // * Check response
        if (200 !== $response->getStatus()) {
            $body = @json_decode($response->getBody(), true);
            $result->setFailed('An error occurred while processing your refund request: '.$body['error']['detail']);

            return;
        }

        $result->setSuccess();
        // We will not add refund record here because it will be handled by
        // IPN script once the refund is approved and processed by Paddle.
    }

    /**
     * Show Paddle plans on the Manage Products grid.
     */
    public function onGridProductInitGrid(Am_Event_Grid $event): void
    {
        if (!$this->getConfig('show_grid')) {
            return;
        }
        $grid = $event->getGrid();
        $grid->addField(new Am_Grid_Field(static::PRICE_ID, ___('Paddle ID'), false, 'right'))
            ->setRenderFunction(function (Product $product) {
                $ret = [];
                foreach ($product->getBillingPlans() as $plan) {
                    $data = $plan->data()->get(static::PRICE_ID);
                    $ret[] = ($data) ? $data : '&ndash;';
                }
                $ret = implode('<br />', $ret);

                return '<td style="text-align:right">'.$ret.'</td>';
            })
        ;
    }

    /**
     * Convenience method to add a user note.
     *
     * @param string $email   User Email
     * @param string $content The note content
     *
     * @return null|Am_Record The aMember user or null
     */
    public function addUserNote($email, $content)
    {
        $user = $this->getDi()->userTable->findFirstByEmail($email);
        if ($user) {
            $note = $this->getDi()->userNoteRecord;
            $note->user_id = $user->user_id;
            $note->dattm = $this->getDi()->sqlDateTime;
            $note->content = $content;
            $note->insert();

            return $user;
        }

        return null;
    }

    protected function getAmount($amount, $currency = 'USD'): string
    {
        return (string) ($amount * pow(10, Am_Currency::$currencyList[$currency]['precision']));
    }

    protected function getText($period)
    {
        // Convert string if needed
        if (!$period instanceof Am_Period) {
            $period = new Am_Period($period);
        }

        return ucwords($period->getText());
    }

    protected function getRebillText($rebill_times)
    {
        switch ($rebill_times) {
            case '0':
                return ___('One Time Charge');

            case '1':
                return ___('Bills ONE Time');

            case IProduct::RECURRING_REBILLS:
                return ___('Rebills Until Cancelled');

            default:
                return ___('Bills %d Times', $rebill_times);
        }

        return ucwords($period->getText());
    }

    protected function getFrequency($period)
    {
        // Convert string if needed
        if (!$period instanceof Am_Period) {
            $period = new Am_Period($period);
        }

        // Fixed periods (eg Lifetime) - set 1 (year)
        if (Am_Period::FIXED == $period->getUnit()) {
            // return $this->getDays($period);
            return 1;
        }

        return $period->getCount();
    }

    protected function getInterval($period)
    {
        // Convert string if needed
        if (!$period instanceof Am_Period) {
            $period = new Am_Period($period);
        }
        $map = [
            Am_Period::DAY => 'day',
            Am_Period::MONTH => 'month',
            Am_Period::YEAR => 'year',
        ];

        // Fixed periods (eg Lifetime) - set year
        return $map[$period->getUnit()] ?? 'year';
    }

    protected function getDays($period)
    {
        // Convert string if needed
        if (!$period instanceof Am_Period) {
            $period = new Am_Period($period);
        }

        switch ($period->getUnit()) {
            case Am_Period::DAY:
                return $period->getCount();

            case Am_Period::MONTH:
                return $period->getCount() * 30;

            case Am_Period::YEAR:
                return $period->getCount() * 365;

            case Am_Period::FIXED:
            case Am_Period::MAX_SQL_DATE:
                $date = new DateTime($period->getCount());

                return $date->diff(new DateTime('now'))->days + 1;

            default:
                return 10; // actual value in this case does not matter
        }
    }

    protected function paddleJsSetupCode()
    {
        $environment = $this->isSandbox() ? 'Paddle.Environment.set("sandbox");' : '';
        $client_token = $this->getConfig('client_token');
        $retain_key = $this->getConfig('retain_key');
        $retain_key = $retain_key ? '"'.$retain_key.'"' : 'null';
        $user = $this->getDi()->auth->getUser();
        $email = ($user instanceof User) ? 'email: "'.$user->email.'"' : '';

        return <<<CUT
            <script>
                {$environment}
                Paddle.Setup({
                    token: "{$client_token}",
                    pwAuth: {$retain_key},
                    pwCustomer: {{$email}},
                    checkout: {
                        settings: {
                            displayMode: "overlay",
                            theme: "light",
                            locale: "en",
                            showAddTaxId: true,
                            allowLogout: false,
                            showAddDiscounts: false,
                        }
                    },
                    eventCallback: function(data) {
                        switch(data.name) {
                          case "checkout.loaded":
                            console.log("Checkout opened");
                            break;
                          case "checkout.customer.created":
                            console.log("Customer created");
                            break;
                          case "checkout.completed":
                            console.log("Checkout completed");
                            break;
                          default:
                            console.log(data);
                        }
                    }
                });
            </script>
            CUT;
    }

    /**
     * Private convenience method to send authenticated Paddle API requests.
     *
     * @param mixed $url
     * @param mixed $method
     */
    private function _sendRequest(
        $url,
        ?array $params = null,
        ?InvoiceLog $log = null,
        $method = Am_HttpRequest::METHOD_POST
    ): HTTP_Request2_Response {
        $request = $this->createHttpRequest();
        $request->setUrl(($this->isSandbox() ? static::SANDBOX_URL : static::LIVE_URL).$url);
        $request->setMethod($method);

        // Add headers
        $request->setHeader([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'paddlebilling-amember/v'.self::PLUGIN_REVISION,
            'Authorization' => 'Bearer '.$this->getConfig('api_key'),
            'Paddle-Version' => static::API_VERSION,
        ]);

        // Add params and send
        if (!is_null($params)) {
            $request->setBody(json_encode($params));
        }
        $response = $request->send();

        // Log it?
        if ($log) {
            $log->mask($this->getConfig('api_key'), '***api_key***');
            $log->paysys_id = $this->getId();
            $log->add($request);
            $log->add($response);
        }

        // Return response
        return $response;
    }
}

/**
 * Factory class for webhook handling.
 */
class Am_Paysystem_Transaction_PaddleBilling_Webhook extends Am_Paysystem_Transaction_Incoming
{
    protected $event;

    public function __construct(
        Am_Paysystem_Abstract $plugin,
        Am_Mvc_Request $request,
        Am_Mvc_Response $response,
        $invokeArgs
    ) {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->event = json_decode($request->getRawBody(), true);
    }

    public static function create(
        Am_Paysystem_Abstract $plugin,
        Am_Mvc_Request $request,
        Am_Mvc_Response $response,
        $invokeArgs
    ) {
        $event = json_decode($request->getRawBody(), true);

        switch ($event['type']) {
            case 'transaction.completed':
                return new Am_Paysystem_PaddleBilling_Webhook_Transaction($plugin, $request, $response, $invokeArgs);

                break;

            case 'subscription.created':
            case 'subscription.updated':
            case 'subscription.cancelled':
                return new Am_Paysystem_PaddleBilling_Webhook_Subscription($plugin, $request, $response, $invokeArgs);

                break;

            case 'adjustment.created':
            case 'adjustment.updated':
                return new Am_Paysystem_PaddleBilling_Webhook_Adjustment($plugin, $request, $response, $invokeArgs);
        }
    }

    public function validateSource()
    {
        // Extract timestamp (ts) and hash (h1) from signature
        $raw_sig = $this->request->getHeader('Paddle-Signature');
        parse_str(str_replace(';', '&', $raw_sig), $sig);

        // Build payload
        $payload = $sig['ts'].':'.$this->request->getRawBody();

        return $sig['h1'] === hash_hmac(
            'sha256',
            $payload,
            $this->getPlugin()->getConfig('secret_key')
        );
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    /**
     * Function must return an unique identified of transaction, so the same
     * transaction will not be handled twice. It can be for example:
     * txn_id form paypal, invoice_id-payment_sequence_id from other paysystem
     * invoice_id and random is not accceptable here
     * timestamped date of transaction is acceptable.
     *
     * @return string (up to 32 chars)
     */
    public function getUniqId()
    {
        return $this->event['data']['id'];
    }

    /**
     * Function must return receipt id of the payment - it is the payment reference#
     * as returned from payment system. By default it just calls @see getUniqId,
     * but this can be overriden.
     *
     * @return string
     */
    public function getReceiptId()
    {
        return $this->getUniqId();
    }
}

class Am_Paysystem_PaddleBilling_Webhook_Transaction extends Am_Paysystem_Transaction_Incoming
{
    protected $_qty = [];

    public function generateInvoiceExternalId()
    {
        return $this->event['data']['invoice_number'];
    }

    public function generateUserExternalId(array $userInfo)
    {
        return $this->event['data']['customer_id'];
    }

    public function fetchUserInfo()
    {
        // * Prepare log
        $log = $this->getDi()->invoiceLogRecord;
        $log->title = 'GET CUSTOMER';
        $log->user_id = $this->invoice->user_id;
        $log->invoice_id = $this->invoice->pk();

        $response = $this->getPlugin()->_sendRequest('customers/'.$this->generateUserExternalId([]).'?include=address,business', null, $log, Am_HttpRequest::METHOD_GET);

        // * Decode and check transaction ID
        $body = @json_decode($response->getBody(), true);
        if (empty($body['data']['id'])) {
            throw new Am_Exception_InternalError('Bad response: '.$response->getBody());
        }

        [$name_f, $name_l] = explode(' ', $body['name']);

        return [
            'email' => $body['email'],
            'name_f' => $name_f,
            'name_l' => $name_l,
            'country' => $body['address']['country_code'] ?? '',
            'zip' => $body['address']['postal_code'] ?? '',
            'tax_id' => $body['business']['tax_identifier'] ?? '',
        ];
    }

    public function findInvoiceId()
    {
        // Try decoding to get CUSTOM_DATA_INV field
        $cdata = $this->event['data']['custom_data'];
        $public_id = $cdata[Am_Paysystem_PaddleBilling::CUSTOM_DATA_INV] ?? null;
        if ($public_id) {
            return $public_id;
        }

        // Try getting it by receipt id
        $invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin(
            $this->getReceiptId(),
            $this->getPlugin()->getId()
        );

        return $invoice ? $invoice->public_id : null;
    }

    public function autoCreateGetProducts()
    {
        // Check event has line items
        if (empty($this->event['data']['details']['line_items'])) {
            return [];
        }

        // Grab the price IDs from the transaction
        $products = [];
        foreach ($this->event['data']['details']['line_items'] as $txnitm) {
            $bp = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData(
                Am_Paysystem_Paddle::PRICE_ID,
                $txnitm['price_id']
            );
            if ($bp) {
                $product = $bp->getProduct();
                $products[] = $product->setBillingPlan($bp);
                $this->_qty[$product->pk()] = @$txnitm['quantity'] ?: 1;
            }
        }

        return $products;
    }

    public function autoCreateGetProductQuantity(Product $pr)
    {
        return $this->_qty[$pr->pk()];
    }

    /**
     * Provision access.
     *
     * @see https://developer.paddle.com/build/subscriptions/provision-access-webhooks
     */
    public function processValidated(): void
    {
        // Save the billed line items in case of refund
        // @see processRefund()
        $line_items = [];
        foreach ($this->event['data']['details']['line_items'] as $txnitm) {
            if ($txnitm['totals']['total'] > 0) {
                $line_items[] = $txnitm['id'];
            }
        }
        $this->invoice->data()->set(static::TXNITM, $line_items)->update();

        // Backfill user details, as customer may have added extra
        // info via the checkout form (like tax id, country etc)
        // NB: This doesn't CHANGE existing user data, just adds to it!
        $user = $this->invoice->getUser();
        if ($user) {
            try {
                $this->fillInUserFields($user);
            } catch (Exception $e) {
                // Don't sweat it
            }
        }

        // Add payment / access
        if (0 == (float) $this->invoice->first_total
            && Invoice::PENDING == $this->invoice->status
        ) {
            $this->invoice->addAccessPeriod($this);
        } else {
            $this->invoice->addPayment($this);
        }

        // Paddle subscriptions continue indefinitely, so we need to
        // cancel it once all expected payments have been made
        $subscription_id = $this->event['data']['subscription_id'];
        $expected = $this->invoice->getExpectedPaymentsCount();
        if ($subscription_id && $this->invoice->getPaymentsCount() >= $expected) {
            $this->log->add(
                "All {$expected} payments made for subscription_id: {$subscription_id}"
            );
            $result = new Am_Paysystem_Result();
            $result->setSuccess();
            $this->getPlugin()->cancelAction($this->invoice, 'cancel', $result);
            if ($result->isFailure()) {
                $this->log->add("Unable to cancel subscription_id: {$subscription_id} - ".$result->getLastError());
            }
        }
    }
}

class Am_Paysystem_PaddleBilling_Webhook_Subscription extends Am_Paysystem_Transaction_Incoming
{
    public function getReceiptId()
    {
        return $this->event['data']['transaction_id'];
    }

    public function findInvoiceId()
    {
        // Try decoding to get CUSTOM_DATA_INV field
        $cdata = $this->event['data']['custom_data'];
        $public_id = $cdata[Am_Paysystem_PaddleBilling::CUSTOM_DATA_INV] ?? null;
        if ($public_id) {
            return $public_id;
        }

        // Try getting it by receipt id
        $invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin(
            $this->getReceiptId(),
            $this->getPlugin()->getId()
        );

        return $invoice ? $invoice->public_id : null;
    }

    /**
     * Provision access based on webhooks.
     *
     * @see https://developer.paddle.com/build/subscriptions/provision-access-webhooks
     */
    public function processValidated(): void
    {
        // * Handle webhook alerts
        switch ($this->event['event_type']) {
            case 'subscription.created':
                // Save subscription ID
                $this->invoice->data()->set(
                    Am_Paysystem_Paddle::SUBSCRIPTION_ID,
                    $this->event['data']['id']
                )->update();

                break;

            case 'subscription.updated':
                // Update recurring status
                if (in_array($this->event['data']['status'], ['active', 'trialing'])) {
                    $this->invoice->setStatus(Invoice::RECURRING_ACTIVE); // self-checks
                }
                if (in_array($this->event['data']['status'], ['paused', 'past_due'])) {
                    $this->invoice->setStatus(Invoice::RECURRING_FAILED); // self-checks
                }

                // Update rebill date if this was changed in Paddle subscription
                $rebill_date = $this->event['data']['next_billed_at'];
                if ($rebill_date && $this->invoice->rebill_date != sqlDate($rebill_date)) {
                    $this->invoice->updateQuick('rebill_date', $rebill_date);
                }

                // Extend access for past due invoices while they are in dunning
                // If dunning fails, status will change to paused or canceled
                if ('past_due' == $this->event['data']['status'] && $this->invoice->getAccessExpire() < $this->invoice->rebill_date) {
                    $this->invoice->extendAccessPeriod($this->invoice->rebill_date);
                }

                break;

            case 'subscription.cancelled':
                $this->invoice->setCancelled(true);
        }
    }
}

class Am_Paysystem_PaddleBilling_Webhook_Adjustment extends Am_Paysystem_Transaction_Incoming
{
    public function getReceiptId()
    {
        return $this->event['data']['transaction_id'];
    }

    public function findInvoiceId()
    {
        // Try getting it by receipt id
        $invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin(
            $this->getReceiptId(),
            $this->getPlugin()->getId()
        );

        return $invoice ? $invoice->public_id : null;
    }

    /**
     * Provision access based on webhooks.
     *
     * @see https://developer.paddle.com/build/subscriptions/provision-access-webhooks
     */
    public function processValidated(): void
    {
        // * Handle webhook alerts
        switch ($this->event['data']['action']) {
            case 'credit_reverse':
            case 'credit':
                $note = ___(
                    'Paddle %1$s a credit of %2$s for invoice #%3$s.',
                    'credit' == $this->event['data']['action'] ? 'issued' : 'reversed',
                    Am_Currency::render(
                        $this->event['data']['totals']['total'],
                        $this->event['data']['totals']['currency_code']
                    ),
                    $this->invoice->invoice_id.'/'.$this->invoice->public_id
                );
                $this->getPlugin()->addUserNote($this->invoice->getUser(), $note);

                break;

            case 'refund':
                // NB: Adjustments are updated when Paddle approves or rejects a refund
                if ('approved' != $this->event['data']['status']) {
                    return;
                }

                try {
                    $this->invoice->addRefund(
                        $this,
                        $this->getReceiptId(),
                        $this->event['data']['totals']['total'] / pow(10, Am_Currency::$currencyList[$this->invoice->currency]['precision'])
                    );
                } catch (Am_Exception_Db_NotUnique $e) {
                    // Refund already added
                }

                break;

            case 'chargeback':
                $this->invoice->addChargeback(
                    $this,
                    $this->getReceiptId()
                );
                if ($this->getPlugin()->getConfig('cbk_lock')) {
                    // Create a user note
                    $note = ___('Payment was disputed and a chargeback was received. User account disabled.');
                    $user = $this->getPlugin()->addUserNote(
                        $this->invoice->getUser(),
                        $note
                    );
                    if ($user) {
                        $user->lock(true);
                    }
                }

                break;
//@TODO FROM HERE
            case 'chargeback_reverse':
            case 'chargeback_warning':
                if ($this->getPlugin()->getConfig('hrt_lock')) {
                    // Lock account until case is resolved
                    $lock = true;
                    $note = ___(
                        'Paddle flagged a recent payment by this user as high risk (%s chance of fraud). User account disabled.',
                        (float) $this->request->getPost('risk_score').'%'
                    );
                    // Case rejected - lock account
                    if ('rejected' == $this->request->getPost('status')) {
                        $lock = true;
                        $note = ___('Paddle rejected the high risk payment. User account disabled.');
                    }
                    // Case resolved ok - unlock account
                    if ('accepted' == $this->request->getPost('status')) {
                        $lock = false;
                        $note = ___('Paddle approved the high risk payment. User account reenabled.');
                    }
                    $user = $this->getPlugin()->addUserNote(
                        $this->request->getPost('customer_email_address'),
                        $note
                    );
                    if ($user) {
                        $user->lock($lock);
                    }
                }
        }
    }
}
