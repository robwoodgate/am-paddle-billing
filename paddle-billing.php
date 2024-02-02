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

    public const SUBSCRIPTION_ID = 'paddlebilling_subscription_id';
    public const PMT_RECEIPT_URL = 'paddlebilling_receipt_url';
    public const CARD_UPDATE_URL = 'paddlebilling_update_url';
    public const CATALOG_ID = 'paddlebilling_prod_id';
    public const LIVE_URL = 'https://api.paddle.com/';
    public const SANDBOX_URL = 'https://sandbox-api.paddle.com/';
    public const CUST_DATA_INV = 'am_invoice';
    public const API_VERSION = 1;

    protected $defaultTitle = 'Paddle Billing';
    protected $defaultDescription = 'Payment via Paddle Billing gateway';
    protected $_canAutoCreate = true;
    protected $_canResendPostback = true;

    public function init(): void
    {
        $this->getDi()->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                static::CATALOG_ID,
                'Paddle Subscription Plan ID',
                "Recurring billing plans *MUST* have a Paddle Subscription Plan with the SAME 'Second Period' and same default currency. Product IDs are optional for single payment billing plans, but if provided must have same default currency."
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
        // Inject Paddle Payment Update URL into detailed subscriptions widget
        if (false !== strpos($e->getTemplateName(), 'blocks/member-history-detailedsubscriptions')) {
            $v = $e->getView();
            foreach ($v->activeInvoices as &$invoice) {
                if ($invoice->paysys_id == $this->getId()) {
                    if ($_ = $invoice->data()->get(static::CARD_UPDATE_URL)) {
                        $invoice->_updateCcUrl = $_;
                    }
                }
            }
        }

        // Inject Paddle receipt link into payment history widget
        if (false !== strpos($e->getTemplateName(), 'blocks/member-history-paymenttable')) {
            $v = $e->getView();
            foreach ($v->payments as &$p) {
                if ($p->paysys_id == $this->getId()) {
                    if ($_ = $p->data()->get(static::PMT_RECEIPT_URL)) {
                        $p->_invoice_url = $_;
                    }
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
        return ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK', 'ARS', 'BRL', 'CNY', 'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'KRW', 'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'THB', 'TRY', 'TWD', 'UAH'];
    }

    public function _initSetupForm(Am_Form_Setup $form): void
    {
        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel("API Key\n".'Use your default API key or generate a new one from the Developer Tools > <a href="https://vendors.paddle.com/authentication-v2">Authentication menu</a> menu of your Paddle Dashboard.')
            ->addRule('required')
        ;

        $form->addSecretText('client_token', ['class' => 'am-el-wide'])
            ->setLabel("Client-Side Token\n".'From the Developer Tools > <a href="https://vendors.paddle.com/authentication-v2">Authentication menu</a> of your Paddle Dashboard.')
            ->addRule('required')
        ;

        $form->addSecretText('secret_key', ['class' => 'am-el-wide'])
            ->setLabel("Webhook Secret Key\n".'From the Developer Tools > <a href="https://vendors.paddle.com/notifications">Notifications menu</a> of your Paddle Dashboard. <a href="https://developer.paddle.com/webhooks/signature-verification#get-secret-key">Detailed Instructions</a>')
            ->addRule('required')
        ;

        // Add Sandbox warning
        if ($this->isSandbox()) {
            $form->addProlog("<div class='warning_box'>You are currently using Sandbox credentials. All transactions are tests, meaning they're simulated and any money isn't real.</div>");
        }

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
                static::CUST_DATA_INV => $invoice->public_id,
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
            if (!is_int($k) && static::CUST_DATA_INV != $k) {
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
            // Try get product specific image, fall back to default if needed
            $image_url = $item->tryLoadProduct()->img_cart_path ?? $default_img;
            $terms = $item->tryLoadProduct()->getBillingPlan()->getTerms();

            // Add first payment info
            $params['items'][] = $rebill = [
                'quantity' => $item->qty,
                'price' => [
                    'description' => $terms,
                    'name' => ($item->first_total) ? ___('First Payment') : ___('Free'),
                    'tax_mode' => 'account_setting',
                    'unit_price' => [
                        'amount' => (string) $this->getAmount(
                            $item->first_total,
                            $invoice->currency
                        ),
                        'currency_code' => $item->currency,
                    ],
                    'product' => [
                        'name' => $item->item_title,
                        'description' => $item->item_description,
                        'tax_category' => 'standard',
                        'image_url' => $image_url,
                    ],
                ],
            ];

            // Add rebill info
            if ($item->second_period) {
                $rebill['price']['name'] = ___('Second and Subsequent Payments');
                $rebill['price']['billing_cycle'] = [
                    'interval' => $this->getInterval($item->second_period),
                    'frequency' => $this->getFrequency($item->second_period),
                ];
                $rebill['price']['trial_period'] = [
                    'interval' => 'day',
                    'frequency' => $this->getDays($item->first_period),
                ];
                $rebill['price']['unit_price']['amount'] = (string) $this->getAmount(
                    $item->second_total,
                    $invoice->currency
                );
                $params['items'][] = $rebill;
            }
        }

        // * Generate the draft transaction
        $response = $this->_sendRequest('transactions', $params, $log);

        // * Decode and check transaction ID
        $resp_data = @json_decode($response->getBody(), true);
        if (empty($resp_data['data']['id'])) {
            throw new Am_Exception_InternalError('Bad response: '.$resp_data);
        }

        // * Open pay page
        $a = new Am_Paysystem_Action_HtmlTemplate('pay.phtml');
        $a->invoice = $invoice;
        $environment = $this->isSandbox() ? 'Paddle.Environment.set("sandbox");' : '';
        $client_token = $this->getConfig('client_token');
        $retain_key = $this->getConfig('retain_key')
            ? '"'.$this->getConfig('retain_key').'"' : 'null';
        $txnid = $resp_data['data']['id'];
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
                Paddle.Setup({
                    token: "{$client_token}", // replace with a client-side token
                    pwAuth: {$retain_key}, // replace with your Retain API key
                    pwCustomer: {email: "{$email}"}, // can pass the id or email of your logged-in customer
                    checkout: {
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
                Paddle.Checkout.open({
                    transactionId: "{$txnid}",
                    successUrl: "{$thanks_url}",
                    customData: null,
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

        $v = new Am_View();
        $v->headScript()->appendFile('https://cdn.paddle.com/paddle/v2/paddle.js');
        $result->setAction($a);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_PaddleBilling_Transaction($this, $request, $response, $invokeArgs);
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        // Get subscription
        $subscription_id = $invoice->data()->get(static::SUBSCRIPTION_ID);
        if ($subscription_id) {
            // * Prepare log
            $log = $this->getDi()->invoiceLogRecord;
            $log->title = 'CANCEL';
            $log->user_id = $invoice->user_id;
            $log->invoice_id = $invoice->pk();

            // * Make request
            $response = $this->_sendRequest(
                '/subscription/users_cancel',
                ['subscription_id' => $subscription_id],
                $log
            );
            $resp_data = @json_decode($response->getBody(), true);
            if (!$resp_data['success']) {
                $result->setFailed('Cancellation request failed: '.$resp_data['error']['message']);

                return $result;
            }
            $result->setSuccess();
        } else {
            $result->setFailed('Can not find subscription id');
        }
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
        if(doubleval($amount) == doubleval($payment->amount)){
            $type = 'full';
        }

        // * Make request
        $response = $this->_sendRequest(
            '/adjustments',
            [
                'action' => 'refund',
                'items' => [
                    'type' => $type,
                    'amount' => $this->getAmount($amount, $invoice->currency),
                    //'item_id' => @TODO: txnitm_abc123
                ],
                'transaction_id' => $payment->receipt_id,
                'reason' => 'Refund requested by user ('.$payment->getUser()->login.')',
            ],
            $log
        );
        $resp_data = @json_decode($response->getBody(), true);
        if (!$resp_data['success']) {
            $result->setFailed('Refund request failed: '.$resp_data['error']['message']);

            return $result;
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
        $grid->addField(new Am_Grid_Field(static::CATALOG_ID, ___('Paddle ID'), false, 'right'))
            ->setRenderFunction(function (Product $product) {
                $ret = [];
                foreach ($product->getBillingPlans() as $plan) {
                    $data = $plan->data()->get(static::CATALOG_ID);
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

    protected function getAmount($amount, $currency = 'USD')
    {
        return $amount * pow(10, Am_Currency::$currencyList[$currency]['precision']);
    }

    protected function getFrequency($period)
    {
        $period = new Am_Period($period);

        return $period->getCount();
    }

    protected function getInterval($period)
    {
        $period = new Am_Period($period);
        $map = [
            Am_Period::DAY => 'day',
            Am_Period::MONTH => 'month',
            Am_Period::YEAR => 'year',
        ];

        return $map[$period->getUnit()]
            ?? throw new Am_Exception_InternalError("Unsupported period: {$period}");
    }

    protected function getDays($period)
    {
        $period = new Am_Period($period);

        switch ($period->getUnit()) {
            case Am_Period::DAY:
                return $period->getCount();

            case Am_Period::MONTH:
                return $period->getCount() * 30;

            case Am_Period::YEAR:
                return $period->getCount() * 365;

            default:
                return 0; // actual value in this case does not matter
        }
    }

    protected function paddleJsSetupCode($email = '')
    {
        $environment = $this->isSandbox() ? 'Paddle.Environment.set("sandbox");' : '';
        $client_token = $this->getConfig('client_token');
        $retain_key = $this->getConfig('retain_key');
        $retain_key = $retain_key ? 'pwAuth: "'.$retain_key.'",' : '';
        $txnid = $resp_data['data']['id'];
        $thanks_url = $this->getReturnUrl();
        $code = <<<CUT
            <div class="checkout-container"></div>
            <script>
                {$environment}
                Paddle.Setup({
                    token: "{$client_token}",
                    {$retain_key},
                    pwCustomer: {email: "{$email}"}, // can pass the id or email of your logged-in customer
                    checkout: {
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

        $v = new Am_View();
        $v->headScript()->appendFile('https://cdn.paddle.com/paddle/v2/paddle.js');

        return $code;
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
            'Paddle-Version' => self::API_VERSION,
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

class Am_Paysystem_PaddleBilling_Transaction extends Am_Paysystem_Transaction_Incoming
{
    protected $_autoCreateMap = [
        'name' => 'customer_name',
        'email' => 'email',
        'country' => 'country',
        'user_external_id' => 'email',
        'invoice_external_id' => 'order_id',
    ];

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

    public function findInvoiceId()
    {
        // Try decoding to get CUST_DATA_INV field
        $cdata = $this->event['data']['custom_data'];

        return $cdata[Am_Paysystem_PaddleBilling::CUST_DATA_INV] ?? null;
    }

    public function autoCreateGetProducts()
    {
        // Could be a subscription plan or product ID
        $item_name = get_first(
            $this->event['data']['subscription_id'],
            $this->event['data']['product_id']
        );
        if (empty($item_name)) {
            return;
        }
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData(
            Am_Paysystem_Paddle::CATALOG_ID,
            $item_name
        );
        if ($billing_plan) {
            return [$billing_plan->getProduct()];
        }
    }

    public function getUniqId()
    {
        return $this->event['notification_id'];
    }

    public function getReceiptId()
    {
        return $this->event['transaction_id'];
    }

    /**
     * @see https://developer.paddle.com/webhooks/signature-verification
     */
    public function validateSource()
    {
        // Extract timestamp (ts) and hash (h1) from signature
        $raw_sig = $this->request->getHeader('Paddle-Signature');
        parse_str(str_replace(';', '&', $raw_sig), $sig);

        // Build payload
        $payload = $sig['ts'].':'.$request->getRawBody();

        return $sig['h1'] === hash_hmac(
            'sha256',
            $payload,
            $this->getPlugin()->getConfig('secret_key')
        );
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated(): void
    {
        // * Save subscription ID if set
        $subscription_id = $this->request->getPost('subscription_id');
        if (!empty($subscription_id)) {
            $this->invoice->data()->set(
                Am_Paysystem_Paddle::SUBSCRIPTION_ID,
                $subscription_id
            )->update();
        }

        // * Save payment details update URL if set
        // * NB: Set globally as it has changed any time update_url is set ;-)
        $update_url = $this->request->getPost('update_url');
        if (!empty($update_url)) {
            $this->invoice->data()->set(
                Am_Paysystem_Paddle::CARD_UPDATE_URL,
                $update_url
            )->update();
        }

        // * Handle webhook alerts
        switch ($this->request->getPost('alert_name')) {
            case 'subscription_created':
                // nothing to do here as subscription_payment_succeeded
                // fires for free subscriptions too
                break;

            case 'subscription_updated':
                if ('active' == $this->request->getPost('status')) {
                    $this->invoice->setStatus(Invoice::RECURRING_ACTIVE); // self-checks
                }
                if ('paused' == $this->request->getPost('status')) {
                    $this->invoice->setStatus(Invoice::RECURRING_FAILED); // self-checks
                }

                // Update rebill date if this was changed in Paddle subscription
                // NB: Does not change access periods here, because we don't know
                // why the date was changed - and all payment/cancellation cases
                // are handled elsewhere, so leave it to be done manually
                $paddle_rebill_date = $this->request->getPost('next_bill_date');
                if ($this->invoice->rebill_date != $paddle_rebill_date) {
                    $this->invoice->updateQuick('rebill_date', $paddle_rebill_date);
                }

                break;

            case 'subscription_cancelled':
                $this->invoice->setCancelled(true);

                break;

            case 'subscription_payment_succeeded':
            case 'transaction.paid':
                // Update user country if needed
                $user = $this->invoice->getUser();
                $country = $this->request->getPost('country');
                if ($user && $country && $user->country != $country) {
                    $user->updateQuick('country', $country);
                }

                // Add payment / access
                if (0 == (float) $this->invoice->first_total
                    && Invoice::PENDING == $this->invoice->status
                ) {
                    $this->invoice->addAccessPeriod($this);
                } else {
                    $p = $this->invoice->addPayment($this);
                    $receipt_url = $this->request->getPost('receipt_url');
                    if (!empty($receipt_url)) {
                        $p->data()->set( // Save the receipt url
                            Am_Paysystem_Paddle::PMT_RECEIPT_URL,
                            $receipt_url
                        )->update();
                    }
                }

                // We are all done for one-off payments...
                if ('payment_succeeded' == $this->request->getPost('alert_name')) {
                    break;
                }

                // Paddle subscriptions continue indefinitely, so we need to
                // cancel it once all expected payments have been made
                $payment_count = $this->request->getPost('instalments');
                $expected = $this->invoice->getExpectedPaymentsCount();
                if ($subscription_id && $payment_count >= $expected) {
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

                break;

            case 'subscription_payment_failed':
                // Handle Paddle Dunning process - next_retry_date will be set
                // if there are more retries to make. If set, sync rebill date
                // then extend the access if required to allow for this grace period
                $next_retry_date = $this->request->getPost('next_retry_date');
                if ($next_retry_date && $this->invoice->rebill_date != $next_retry_date) {
                    $this->invoice->updateQuick('rebill_date', $next_retry_date);
                }
                if ($this->invoice->getAccessExpire() < $this->invoice->rebill_date) {
                    $this->invoice->extendAccessPeriod($this->invoice->rebill_date);
                }

                break;

            case 'subscription_payment_refunded':
            case 'payment_refunded':
                // VAT refunds do not affect member access, and aMember invoices
                // are blind to Paddle's VAT accounting, so just add a user note.
                if ('vat' == $this->request->getParam('refund_type')) {
                    $note = ___(
                        'Paddle issued a VAT refund of %1$s for invoice #%2$s.',
                        Am_Currency::render(
                            $this->request->getParam('balance_gross_refund'),
                            $this->request->getParam('balance_currency')
                        ),
                        $this->invoice->invoice_id.'/'.$this->invoice->public_id
                    );
                    $this->getPlugin()->addUserNote($this->request->getPost('email'), $note);

                    return; // nothing more to do
                }

                // NB: Paddle currency localisation means refunds may be given
                // in a different currency to the aMember invoice. Paddle reports
                // refund amount in both local currency and balance currency
                // We do this to record partial refunds made on Paddle's side
                $local_currency = $this->request->getParam('currency');
                $balance_currency = $this->request->getParam('balance_currency');
                $amount = null;
                if ($this->invoice->currency == $local_currency) {
                    // Invoice currency matches Local currency
                    $amount = $this->request->getParam('gross_refund');
                } elseif ($this->invoice->currency == $balance_currency) {
                    // Invoice currency matches Balance currency
                    $amount = $this->request->getParam('balance_gross_refund');
                } elseif (Am_Currency::getDefault() == $local_currency) {
                    // aMember currency matches Local currency
                    // NB: We multiply to convert FROM base TO invoice currency
                    $amount = $this->request->getParam('gross_refund');
                    $amount = $amount * $this->invoice->base_currency_multi;
                } elseif (Am_Currency::getDefault() == $balance_currency) {
                    // aMember currency matches Balance currency
                    // NB: We multiply to convert FROM base TO invoice currency
                    $amount = $this->request->getParam('balance_gross_refund');
                    $amount = $amount * $this->invoice->base_currency_multi;
                }

                // Calculate the remaining balance for a full refund
                // This will ensure access is revoked and balance is zero
                if ('full' == $this->request->getParam('refund_type')) {
                    $amount = 0;
                    foreach ($this->getPlugin()->getDi()->invoicePaymentTable->findBy(
                        ['receipt_id' => $this->getReceiptId(),
                            'invoice_id' => $this->invoice->invoice_id]
                    ) as $p) {
                        $amount += $p->amount;
                    }
                    foreach ($this->getPlugin()->getDi()->invoiceRefundTable->findBy(
                        ['receipt_id' => $this->getReceiptId(),
                            'invoice_id' => $this->invoice->invoice_id]
                    ) as $p) {
                        $amount -= $p->amount;
                    }
                }

                // Could not get the refund amount in the invoice currency or
                // aMember currency, so just add refund as a user note.
                if (!$amount) {
                    $note = ___(
                        '%1$s refund of %2$s issued via Paddle for invoice #%3$s.',
                        ucfirst($this->request->getParam('refund_type')),
                        Am_Currency::render(
                            $this->request->getParam('balance_gross_refund'),
                            $this->request->getParam('balance_currency')
                        ),
                        $this->invoice->invoice_id.'/'.$this->invoice->public_id
                    );
                    $this->getPlugin()->addUserNote($this->request->getPost('email'), $note);

                    return; // nothing more to do
                }

                // Process the refund
                $this->invoice->addRefund(
                    $this,
                    $this->getReceiptId(),
                    $amount
                );

                break;

            case 'payment_dispute_created':
                $this->invoice->addChargeback(
                    $this,
                    $this->getReceiptId()
                );
                if ($this->getPlugin()->getConfig('cbk_lock')) {
                    // Create a user note
                    $note = ___('Payment was disputed and a chargeback was received. User account disabled.');
                    $user = $this->getPlugin()->addUserNote(
                        $this->request->getPost('email'),
                        $note
                    );
                    if ($user) {
                        $user->lock(true);
                    }
                }

                break;

            case 'high_risk_transaction_created':
            case 'high_risk_transaction_updated':
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

                break;
        }
    }
}
