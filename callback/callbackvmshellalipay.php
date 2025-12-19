<?php
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../stripe-php/init.php';

use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Refund;

$gatewayModule = 'vmshellstripealipay';
$gateway = getGatewayVariables($gatewayModule);

if (!$gateway['type']) {
    http_response_code(403);
    exit();
}

Stripe::setApiKey($gateway['stripeSecretKey']);
Stripe::setApiVersion('2024-06-20');

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent(
        $payload,
        $sigHeader,
        $gateway['webhookSecret']
    );
} catch (Exception $e) {
    logTransaction($gatewayModule, $e->getMessage(), 'Webhook Signature Error');
    http_response_code(400);
    exit();
}

if ($event->type === 'payment_intent.succeeded') {

    $intent = $event->data->object;
    $invoiceId = (int)$intent->metadata->invoice_id;
    $transactionId = $intent->id;
    $grossAmount = round($intent->amount / 100, 2);

    $feePercent = floatval($gateway['feePercentage']) / 100;
    $feeFixed = floatval($gateway['feeFixed']);
    $feeAmount = round(($grossAmount * $feePercent) + $feeFixed, 2);

    checkCbTransID($transactionId);

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $grossAmount,
        $feeAmount,
        $gatewayModule
    );

    logTransaction($gatewayModule, [
        'invoice_id' => $invoiceId,
        'amount' => $grossAmount,
        'fee' => $feeAmount,
    ], 'Payment Success');
}

/**
 * 🔁 手动退款逻辑说明（WHMCS 调用）
 * - Stripe 会全额退款
 * - 手续费 Stripe 不退 → 留在账户余额
 */
function vmshellstripealipay_refund($params)
{
    Stripe::setApiKey($params['stripeSecretKey']);

    $paymentIntentId = $params['transid'];
    $amount = round($params['amount'], 2);

    $feePercent = floatval($params['feePercentage']) / 100;
    $feeFixed = floatval($params['feeFixed']);
    $refundAmount = max(0, $amount - (($amount * $feePercent) + $feeFixed));
    $refundCents = (int)round($refundAmount * 100);

    try {
        $refund = Refund::create([
            'payment_intent' => $paymentIntentId,
            'amount' => $refundCents,
            'reason' => 'requested_by_customer',
        ]);

        return [
            'status' => 'success',
            'transid' => $refund->id,
            'amount' => $refundAmount,
            'message' => 'Refund processed, fee retained in Stripe balance',
        ];

    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

http_response_code(200);
