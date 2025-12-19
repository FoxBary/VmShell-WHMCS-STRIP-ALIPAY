<?php
header('Content-Type: text/html; charset=UTF-8');

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/stripe-php/init.php';

use Stripe\Stripe;
use Stripe\PaymentIntent;

function vmshellstripealipay_MetaData()
{
    return [
        'DisplayName' => 'VmShell-Stripe-Alipay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
    ];
}

function vmshellstripealipay_config()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'VmShell-Stripe-Alipay'],
        'stripeSecretKey' => ['FriendlyName' => 'Stripe Secret Key', 'Type' => 'text', 'Size' => '50'],
        'stripePublishableKey' => ['FriendlyName' => 'Stripe Publishable Key', 'Type' => 'text', 'Size' => '50'],
        'webhookSecret' => ['FriendlyName' => 'Webhook Secret', 'Type' => 'text', 'Size' => '50'],
        'currency' => [
            'FriendlyName' => 'Currency',
            'Type' => 'dropdown',
            'Options' => 'USD,HKD,EUR,SGD',
            'Default' => 'USD',
        ],
        'feePercentage' => ['FriendlyName' => 'Fee %', 'Type' => 'text', 'Default' => '2.9'],
        'feeFixed' => ['FriendlyName' => 'Fixed Fee', 'Type' => 'text', 'Default' => '0.3'],
    ];
}

function vmshellstripealipay_link($params)
{
    Stripe::setApiKey($params['stripeSecretKey']);
    Stripe::setApiVersion('2024-06-20');

    $invoiceId = (int)$params['invoiceid'];
    $amount = round($params['amount'], 2);
    $currency = strtolower($params['currency']);
    $amountCents = (int)round($amount * 100);
    $returnUrl = $params['systemurl'] . '/viewinvoice.php?id=' . $invoiceId;

    try {
        // 创建 + 确认 PaymentIntent
        $intent = PaymentIntent::create([
            'amount' => $amountCents,
            'currency' => $currency,
            'payment_method_types' => ['alipay'],
            'confirm' => true,
            'return_url' => $returnUrl,
            'description' => 'Invoice #' . $invoiceId,
            'metadata' => [
                'invoice_id' => $invoiceId,
                'gateway' => 'vmshellstripealipay',
            ],
        ]);

        if (
            $intent->status !== 'requires_action' ||
            empty($intent->next_action->redirect_to_url->url)
        ) {
            throw new Exception('Alipay QR code not available');
        }

        $qrUrl = $intent->next_action->redirect_to_url->url;

        return <<<HTML
<div style="text-align:center">
    <h3>请使用支付宝扫码支付 {$amount} {$currency}</h3>
    <iframe src="{$qrUrl}" style="width:360px;height:480px;border:none;"></iframe>
    <p style="color:#888;font-size:13px">支付完成后将自动返回账单页面</p>
</div>
HTML;

    } catch (Exception $e) {
        logTransaction('vmshellstripealipay', $e->getMessage(), 'Stripe Error');
        return '<div style="color:red">支付初始化失败：'.$e->getMessage().'</div>';
    }
}
