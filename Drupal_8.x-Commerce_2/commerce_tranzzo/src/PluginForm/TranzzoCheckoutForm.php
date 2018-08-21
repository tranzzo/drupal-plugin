<?php

namespace Drupal\commerce_tranzzo\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;


class TranzzoCheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    global $base_url;
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $extra = [
      'return_url' => $form['#return_url'],
      'server_url' => $base_url . '/payment/notify/tranzzo',
      'capture' => $form['#capture'],
    ];
    $tranzzo_response = $payment_gateway_plugin->setTranzzoCheckout($payment, $extra);
    $order = $payment->getOrder();
    $order->setData('commerce_tranzzo', [
      'redirect_url' => $tranzzo_response['redirect_url'],
    ]);
    $order->save();
    $action = $tranzzo_response['redirect_url'];

    return $this->buildRedirectForm($form, $form_state, $action, array());
  }
}
