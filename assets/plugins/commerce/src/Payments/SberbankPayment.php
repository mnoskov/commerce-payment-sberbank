<?php

namespace Commerce\Payments;

class SberbankPayment extends Payment implements \Commerce\Interfaces\Payment
{
    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('sberbank');
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('token')) && (empty($this->getSetting('login')) || empty($this->getSetting('password')))) {
            return '<span class="error" style="color: red;">' . $this->lang['sberbank.error_empty_token_and_login_password'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $order_id  = $order['id'];
        $currency  = ci()->currency->getCurrency($order['currency']);

        $amount = ci()->currency->convert($order['amount'], $currency['code'], 'RUB');

        try {
            $payment = $this->createPayment($order['id'], $amount);
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Failed to create payment: ' . $e->getMessage() . '<br>Data: <pre>' . htmlentities(print_r($order, true)) . '</pre>', 'Commerce Sberbank Payment');
            return false;
        }

        $customer = [];

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = $order['email'];
        }

        if (!empty($order['phone'])) {
            $phone = preg_replace('/[^0-9]+/', '', $order['phone']);
            $phone = preg_replace('/^8/', '7', $phone);

            if (preg_match('/^7\d{10}$/', $phone)) {
                $customer['phone'] = $phone;
            }
        }

        $params = [
            'CMS' => 'Evolution CMS ' . $this->modx->getConfig('settings_version'),
        ];

        foreach (['email', 'phone'] as $field) {
            if (isset($customer[$field])) {
                $params[$field] = $customer[$field];
            }
        }

        $data = [
            'orderNumber' => $order_id . '-' . time(),
            'amount'      => (int) round($payment['amount'] * 100),
            'currency'    => 643,
            'language'    => 'ru',
            'jsonParams'  => json_encode($params),
            'returnUrl'   => $this->modx->getConfig('site_url') . 'commerce/sberbank/payment-process/?' . http_build_query([
                'paymentId'   => $payment['id'],
                'paymentHash' => $payment['hash'],
            ]),
            'description' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                'order_id'  => $order_id,
                'site_name' => $this->modx->getConfig('site_name'),
            ]),
        ];

        if (!empty($customer)) {
            $cart = $processor->getCart();
            $items = $this->prepareItems($cart, $currency['code'], 'RUB');

            $isPartialPayment = abs($amount - $payment['amount']) > 0.01;

            if ($isPartialPayment) {
                $items = $this->decreaseItemsAmount($items, $amount, $payment['amount']);
            }

            $products = [];

            foreach ($items as $i => $item) {
                $products[] = [
                    'positionId'  => $i + 1,
                    'name'        => $item['name'],
                    'quantity'    => [
                        'value'   => $item['count'],
                        'measure' => $item['product'] ? isset($meta['measurements']) ? $meta['measurements'] : $this->lang['measures.units'] : '-',
                    ],
                    'itemAmount'  => (int) round($item['total'] * 100),
                    'itemPrice'   => (int) round($item['price'] * 100),
                    'itemCode'    => $item['id'],
                ];
            }

            $data['orderBundle'] = json_encode([
                'orderCreationDate' => date('c'),
                'customerDetails'   => $customer,
                'cartItems' => [
                    'items' => $products,
                ],
            ]);
        } else if (!empty($this->getSetting('debug'))) {
            $this->modx->logEvent(0, 2, 'User credentials not found in order: <pre>' . htmlentities(print_r($order, true)) . '</pre>', 'Commerce Sberbank Payment Debug');
        }

        try {
            $result = $this->request('payment/rest/register.do', $data);

            if (empty($result['formUrl'])) {
                throw new \Exception('Request failed!');
            }
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Link is not received: ' . $e->getMessage(), 'Commerce Sberbank Payment');
            return false;
        }

        return $result['formUrl'];
    }

    public function handleCallback()
    {
        if (isset($_REQUEST['orderId']) && is_string($_REQUEST['orderId']) && preg_match('/^[a-z0-9-]{36}$/', $_REQUEST['orderId'])) {
            $order_id = $_REQUEST['orderId'];

            try {
                $status = $this->request('payment/rest/getOrderStatusExtended.do', [
                    'orderId' => $order_id,
                ]);
            } catch (\Exception $e) {
                $this->modx->logEvent(0, 3, 'Order status request failed: ' . $e->getMessage(), 'Commerce Sberbank Payment');
                return false;
            }

            if (empty($status['errorCode']) && !empty($status['orderStatus']) && in_array($status['orderStatus'], [1, 2]) && !empty($_REQUEST['paymentId']) && !empty($_REQUEST['paymentHash'])) {
                try {
                    $processor = $this->modx->commerce->loadProcessor();
                    $payment   = $processor->loadPayment($_REQUEST['paymentId']);
                    $order     = $processor->loadOrder($payment['order_id']);

                    $processor->processPayment($payment, ci()->currency->convert(floatval($status['amount']) * 0.01, 'RUB', $order['currency']));
                } catch (\Exception $e) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Sberbank Payment');
                    return false;
                }

                $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/sberbank/payment-success?paymentHash=' . $_REQUEST['paymentHash']);
            }
        }

        return false;
    }

    protected function getUrl($method)
    {
        $url = $this->getSetting('test') == 1 ? 'https://3dsec.sberbank.ru/' : 'https://securepayments.sberbank.ru/';
        return $url . $method;
    }

    protected function request($method, $data)
    {
        $data['token'] = $this->getSetting('token');

        if (empty($data['token'])) {
            $data['userName'] = $this->getSetting('login');
            $data['password'] = $this->getSetting('password');
        }

        $url  = $this->getUrl($method);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE        => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-type: application/x-www-form-urlencoded',
                'Cache-Control: no-cache',
                'charset="utf-8"',
            ],
        ]);

        $result = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!empty($this->getSetting('debug'))) {
            $this->modx->logEvent(0, 1, 'URL: <pre>' . $url . '</pre><br>Data: <pre>' . htmlentities(print_r($data, true)) . '</pre><br>Response: <pre>' . $code . "\n" . htmlentities(print_r($result, true)) . '</pre><br>', 'Commerce Sberbank Payment Debug');
        }

        if ($code != 200) {
            $this->modx->logEvent(0, 3, 'Server is not responding', 'Commerce Sberbank Payment');
            return false;
        }

        $result = json_decode($result, true);

        if (!empty($result['errorCode']) && isset($result['errorMessage'])) {
            $this->modx->logEvent(0, 3, 'Server return error: ' . $result['errorMessage'], 'Commerce Sberbank Payment');
            return false;
        }

        return $result;
    }

    public function getRequestPaymentHash()
    {
        if (isset($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }
}
