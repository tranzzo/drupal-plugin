<?php

namespace Drupal\commerce_tranzzo\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_tranzzo\TranzzoApi;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_tranzzo",
 *   label = "Tranzzo",
 *   display_label = "Tranzzo",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_tranzzo\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase {

  public function defaultConfiguration() {
    return [
      'POS_ID' => '',
      'API_KEY' => '',
      'API_SECRET' => '',
      'ENDPOINTS_KEY' => FALSE,
      'server' => 'https://cpay.tranzzo.com/api/v1',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['POS_ID'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('POS_ID'),
      '#description' => $this->t('POS_ID TRANZZO'),
      '#default_value' => $this->configuration['POS_ID'],
      '#size' => 40,
    );
    $form['API_KEY'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('API_KEY'),
      '#description' => $this->t('API_KEY TRANZZO'),
      '#default_value' => $this->configuration['API_KEY'],
      '#size' => 40,
    );
    $form['API_SECRET'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('API_SECRET'),
      '#description' => $this->t('API_SECRET TRANZZO'),
      '#default_value' => $this->configuration['API_SECRET'],
      '#size' => 40,
    );
    $form['ENDPOINTS_KEY'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('ENDPOINTS_KEY'),
      '#description' => $this->t('ENDPOINTS_KEY TRANZZO'),
      '#default_value' => $this->configuration['ENDPOINTS_KEY'],
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->setConfiguration($this->configuration);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['POS_ID'] = $values['POS_ID'];
      $this->configuration['API_KEY'] = $values['API_KEY'];
      $this->configuration['API_SECRET'] = $values['API_SECRET'];
      $this->configuration['ENDPOINTS_KEY'] = $values['ENDPOINTS_KEY'];
    }
  }

  /**
   * PayURL handler
   * URL: http://site.domain/payment/notify/tranzzo
   *
   * {@inheritdoc}
   *
   * @param Request $request
   * @return null|void
   */
  public function onNotify(Request $request) {
    try {
      $post = $request->request->all();

      if(empty($post['data']) && empty($post['signature'])) die('Warning! Bad Request!!!');
      $data = $post['data'];
      $signature = $post['signature'];
      $tranzzo = new TranzzoApi($this->configuration['POS_ID'],$this->configuration['API_KEY'], $this->configuration['API_SECRET'], $this->configuration['ENDPOINTS_KEY']);
      $data_response = json_decode($tranzzo::base64url_decode($data), true);
      \Drupal::logger('tranzzo')
        ->notice('Получено оповещение о заказе с следующими данными: @data', ['@data' => print_r($data_response, TRUE)]);
      $order_id = (int)$data_response[$tranzzo::P_REQ_ORDER_ANSW];
      if($tranzzo->validateSignature($data, $signature) && $order_id) {
        $order_storage = $this->entityTypeManager->getStorage('commerce_order');
        $order = $order_storage->load($order_id);
        if (is_null($order)) {
          throw new \Exception('Order not found');
        }
        $amount_payment = $tranzzo::amountToDouble($data_response[$tranzzo::P_REQ_AMOUNT]);
        $amount_order = $tranzzo::amountToDouble($order->getTotalPrice()->getNumber());
        if ($data_response[$tranzzo::P_RES_RESP_CODE] == 1000 && ($amount_payment == $amount_order)) {
          $order->set('state', 'completed');
          $order->save();
          // Создаём платёж и сохраняем его
          $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
          $payment = $payment_storage->create([
            'state' => 'completed',
            'amount' => $order->getTotalPrice(),
            'payment_gateway' => $this->entityId,
            'order_id' => $order->id(),
            'remote_id' => $data_response[TranzzoApi::P_RES_PAYMENT_ID],
            'transaction_id' => $data_response[TranzzoApi::P_RES_TRSACT_ID],
            'remote_state' => 'PAYED',
            'authorized' => \Drupal::time()->getRequestTime(),
          ]);
          $payment->save();
        }
        else {
          throw new \Exception('Payment signature mismatch');
        }
      }
      else {
        throw new \Exception('Request parameters is not correct');
      }
    } catch (\Exception $e) {
      watchdog_exception('PayURL handler', $e);
    }
  }

  /**
   * Page for success
   *
   * {@inheritdoc}
   *
   * @param OrderInterface $order
   * @param Request $request
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $order_state = $order->getState();
    if ($order_state->value != 'completed') {
      throw new PaymentGatewayException("Payment isn't completed for this Tranzzo transaction.");
    }
  }
}
