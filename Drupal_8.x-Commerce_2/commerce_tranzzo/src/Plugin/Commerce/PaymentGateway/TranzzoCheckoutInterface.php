<?php

namespace Drupal\commerce_tranzzo\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface TranzzoCheckoutInterface extends SupportsAuthorizationsInterface, SupportsRefundsInterface
{

    /**
     * Performs a Tranzzo request.
     *
     * @param array $tranzzo_data
     *   The tranzzo data array as documented.
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *   The order entity, or null.
     * @param sting $uri
     *   Uri parameter for the tranzzo transaction.
     *
     * @return array
     *   Tranzzo response data.
     *
     * @see https://tranzzo.docs.apiary.io/
     */
    public function doRequest(array $tranzzo_data, OrderInterface $order = NULL, $uri = NULL);

    /**
     * DoCapture API Operation request.
     *
     * Builds the data for the request and make the request.
     *
     * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
     *   The payment.
     * @param int $amount_number
     *   The amount number to be captured.
     *
     * @return array
     *   Tranzzo response data.
     *
     */
    public function doRefundTransaction(PaymentInterface $payment, array $extra);

}
