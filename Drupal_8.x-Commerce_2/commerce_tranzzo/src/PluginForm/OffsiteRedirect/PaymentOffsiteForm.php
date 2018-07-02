<?php

namespace Drupal\commerce_tranzzo\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_tranzzo\TranzzoApi;


class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  private $pluginConfig;

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
    $this->pluginConfig = $payment_gateway_plugin->getConfiguration();
    $tranzzo = new TranzzoApi($this->pluginConfig['POS_ID'], $this->pluginConfig['API_KEY'],
      $this->pluginConfig['API_SECRET'], $this->pluginConfig['ENDPOINTS_KEY']);
    $params = array();
    $params[$tranzzo::P_REQ_SERVER_URL] = $base_url . '/payment/notify/tranzzo';
    $params[$tranzzo::P_REQ_RESULT_URL] = $form['#return_url'];
    $params[$tranzzo::P_REQ_ORDER] = $payment->getOrderId();
    $params[$tranzzo::P_REQ_AMOUNT] = $tranzzo::amountToDouble($payment->getAmount()->getNumber());
    $params[$tranzzo::P_REQ_CURRENCY] = $payment->getAmount()
      ->getCurrencyCode() == 'RUR' ? 'RUB' : $payment->getAmount()
      ->getCurrencyCode();
    $params[$tranzzo::P_REQ_DESCRIPTION] = "Order #{$payment->getOrderId()}";
    $params[TranzzoApi::P_REQ_PRODUCTS] = array();
    $items = $payment->getOrder()->getItems();
    $products = array();
    if (!empty($items)) {
      foreach ($items as $item) {
         $products[] = array(
          'id' => strval($item->getPurchasedEntityId()),
          'name' => $item->getTitle(),
          'currency' => $item->getUnitPrice()->getCurrencyCode(),
          'amount' => $tranzzo::amountToDouble($item->getUnitPrice()->getNumber()),
          'qty' => intval($item->getQuantity()),
        );
      }
    }
    $params[$tranzzo::P_REQ_PRODUCTS] = $products;
    $response = $tranzzo->createPaymentHosted($params);
    $order = $payment->getOrder();
    $order->setData('commerce_tranzzo', [
      'redirect_url' => $response['redirect_url'],
    ]);
    $order->save();
    $action = $response['redirect_url'];
    
    return $this->buildRedirectForm($form, $form_state, $action, array());
  }
}
