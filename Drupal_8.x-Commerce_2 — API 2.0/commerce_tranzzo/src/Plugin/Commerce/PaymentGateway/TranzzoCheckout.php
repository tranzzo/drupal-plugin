<?php
namespace Drupal\commerce_tranzzo\Plugin\Commerce\PaymentGateway;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_tranzzo",
 *   label = "Tranzzo",
 *   display_label = "Tranzzo",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_tranzzo\PluginForm\TranzzoCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 * )
 */
class TranzzoCheckout extends OffsitePaymentGatewayBase implements TranzzoCheckoutInterface
{
    /**
     * The logger.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface
     */
    protected $logger;
    /**
     * The HTTP client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;
    /**
     * The price rounder.
     *
     * @var \Drupal\commerce_price\RounderInterface
     */
    protected $rounder;
    /**
     * The time.
     *
     * @var \Drupal\Component\Datetime\TimeInterface
     */
    protected $time;
    /**
     * Module handler service.
     *
     * @var \Drupal\Core\Extension\ModuleHandlerInterface
     */
    protected $moduleHandler;
    /**
     * The event dispatcher.
     *
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    protected $eventDispatcher;
    /**
     * Constructs a new PaymentGatewayBase object.
     *
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
     *   The payment type manager.
     * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
     *   The payment method type manager.
     * @param \Drupal\Component\Datetime\TimeInterface $time
     *   The time.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
     *   The logger channel factory.
     * @param \GuzzleHttp\ClientInterface $client
     *   The client.
     * @param \Drupal\commerce_price\RounderInterface $rounder
     *   The price rounder.
     * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
     *   The module handler.
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
     *   The event dispatcher.
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, LoggerChannelFactoryInterface $logger_channel_factory, ClientInterface $client, RounderInterface $rounder, ModuleHandlerInterface $module_handler, EventDispatcherInterface $event_dispatcher)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
        $this->logger = $logger_channel_factory->get('commerce_paypal');
        $this->httpClient = $client;
        $this->rounder = $rounder;
        $this->moduleHandler = $module_handler;
        $this->eventDispatcher = $event_dispatcher;
    }
    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('entity_type.manager'),
            $container->get('plugin.manager.commerce_payment_type'),
            $container->get('plugin.manager.commerce_payment_method_type'),
            $container->get('datetime.time'),
            $container->get('logger.factory'),
            $container->get('http_client'),
            $container->get('commerce_price.rounder'),
            $container->get('module_handler'),
            $container->get('event_dispatcher')
        );
    }
    public function defaultConfiguration()
    {
        return [
                'POS_ID' => '',
                'API_KEY' => '',
                'API_SECRET' => '',
                'ENDPOINTS_KEY' => FALSE,
                //new
                'TYPE_PAYMENT' => 0,
                //new
                'server' => 'https://cpay.tranzzo.com/api/v1/',
            ] + parent::defaultConfiguration();
    }
    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
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
        //new
        $form['TYPE_PAYMENT'] = array(
            '#type' => 'checkbox',
            '#title' => $this->t('Использовать блокировку средств'),
            '#description' => $this->t('Средства блокируються на счете покупателя до их списания на счет продавца'),
            '#default_value' => $this->configuration['TYPE_PAYMENT'],
        );
        //new
        return $form;
    }
    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        $this->setConfiguration($this->configuration);
        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['POS_ID'] = $values['POS_ID'];
            $this->configuration['API_KEY'] = $values['API_KEY'];
            $this->configuration['API_SECRET'] = $values['API_SECRET'];
            $this->configuration['ENDPOINTS_KEY'] = $values['ENDPOINTS_KEY'];
            //new
            $this->configuration['TYPE_PAYMENT'] = $values['TYPE_PAYMENT'];
            //new
//            self::writeLog(array('$this' => (array)$this));
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
    public function onReturn(OrderInterface $order, Request $request)
    {
        self::writeLog('onReturn', '');
        self::writeLog(array('$order' => (array)$order));
//        if ($request->something_that_marks_a_failure) {
//            throw new PaymentGatewayException('Payment failed!');
//        }
        $order_state = $order->getState();
        self::writeLog('$order_state', $order_state->value);
        self::writeLog(array('$request' => (array)$request));
//        self::writeLog(array('$request ans' => (array)$request->something_that_marks_a_failure));
//        self::writeLog(array('$post' => (array)$_POST));
        if ($order_state->value != 'completed' && $order_state->value != "validation") {
//            throw new PaymentGatewayException("Payment isn't completed for this Tranzzo transaction.");
        }
    }
    /**
     * {@inheritdoc}
     */
    public function setTranzzoCheckout(PaymentInterface $payment, array $extra)
    {
        self::writeLog('setTranzzoCheckout', '');
        $order = $payment->getOrder();
        $amount = $this->rounder->round($payment->getAmount());
        //new
        $configuration = $this->getConfiguration();
        //if ($extra['capture']) {
        if ($configuration['TYPE_PAYMENT']) {
            //new
            $payment_method = 'auth';
        } else {
            $payment_method = 'purchase';
        }
        self::writeLog(array('$configuration' => (array)$configuration));
        self::writeLog('$payment_method', $payment_method);
        // Build a name-value pair array for this transaction.
        $nvp_data = [
            'mode' => 'hosted',
            'method' => $payment_method,
            'amount' => floatval($amount->getNumber()),
            'currency' => $amount->getCurrencyCode(),
            'description' => "Order #{$order->id()}",
            'order_id' => $order->id(),
            'order_3ds_bypass' => 'supported',
            'products' => array(),
            'server_url' => $extra['server_url'],
            'result_url' => $extra['return_url'],
        ];
        $items = $order->getItems();
        $products = array();
        if (!empty($items)) {
            foreach ($items as $item) {
                $item_amount = $this->rounder->round($item->getUnitPrice());
                $products[] = [
                    'id' => strval($item->getPurchasedEntityId()),
                    'name' => $item->getTitle(),
                    'currency' => $item_amount->getCurrencyCode(),
                    'amount' => floatval($item_amount->getNumber()),
                    'qty' => intval($item->getQuantity()),
                ];
            }
        }
        $nvp_data['products'] = $products;
//        return $this->doRequest($nvp_data, $order, 'payment');
        $rr = $this->doRequest($nvp_data, $order, 'payment');
//        self::writeLog(array('setTranzzoCheckout answer' => (array)$rr));
        return $rr;
    }
    /**
     * {@inheritdoc}
     */
    public function voidPayment(PaymentInterface $payment)
    {
        self::writeLog('voidPayment', '');
        $this->assertPaymentState($payment, ['authorization']);
        // GetCheckoutDetails API Operation (NVP).
        // Shows information about an Tranzzo Checkout transaction.
        $tranzzo_response = $this->doVoid($payment);
        self::writeLog(array('$tranzzo_response voidPayment!!!!!' => (array)$tranzzo_response));
        if ($tranzzo_response['status'] != 'success') {
            $message = $tranzzo_response['message'];
            throw new PaymentGatewayException($message, $tranzzo_response['code']);
        }
        $payment->setState('authorization_voided');
        $payment->save();
    }
    /**
     * {@inheritdoc}
     */
    public function refundPayment(PaymentInterface $payment, Price $amount = NULL)
    {
        self::writeLog('refundPayment', '');
        $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
        // If not specified, refund the entire amount.
        $amount = $amount ?: $payment->getAmount();
        $this->assertRefundAmount($payment, $amount);
        $amount = $this->rounder->round($amount);
        $extra['amount'] = $amount->getNumber();
        $extra['currency'] = $amount->getCurrencyCode();
        // Check if the Refund is partial or full.
        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
            $payment->setState('partially_refunded');
            $extra['refund_type'] = 'Partial';
        } else {
            $payment->setState('refunded');
            if ($amount->lessThan($payment->getAmount())) {
                $extra['refund_type'] = 'Partial';
            } else {
                $extra['refund_type'] = 'Full';
            }
        }
        $t_order_id = $payment->getRemoteId();
        $extra['order_id'] = strval($t_order_id);
        // RefundTransaction API Operation.
        // Refund (full or partial) an Tranzzo transaction.
        $tranzzo_response = $this->doRefundTransaction($payment, $extra);
        if ($tranzzo_response['status'] != 'success') {
            $message = $tranzzo_response['message'];
            throw new PaymentGatewayException($message, $tranzzo_response['code']);
        }
        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
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
    public function onNotify(Request $request)
    {
        self::writeLog('onNotify', '');
        //serialize_precision for json_encode
        if (version_compare(phpversion(), '7.1', '>=')) {
            ini_set('serialize_precision', -1);
        }
        try {
            $post = $request->request->all();
            if (empty($post['data']) && empty($post['signature'])) throw new \Exception('Warning! Bad Request!!!');
            $data = $post['data'];
            $signature = $post['signature'];
            $data_response = json_decode($this->base64url_decode($data), true);
            self::writeLog(array('$data_response answer!!!!!' => (array)$data_response));
            \Drupal::logger('tranzzo')
                ->notice('Получено оповещение с следующими данными: @data', ['@data' => print_r($data_response, TRUE)]);
            //new
            //$order_id = (int)$data_response['provider_order_id'];
            if ($data_response['method'] == 'purchase' || $data_response['method'] == 'auth') {
                $order_id = (int)$data_response['provider_order_id'];
            } else {
                $tranzo_id = (int)$data_response['order_id'];
                self::writeLog('$tranzo_id', $tranzo_id);
                $query = \Drupal::database()->query("SELECT order_id FROM commerce_payment WHERE remote_id = :id", [':id' => $tranzo_id,]);
                $order_id = $query->fetchColumn();
                self::writeLog('$order_id0id', $order_id);
            }
            self::writeLog('$order_id', $order_id);
            //new
            if ($this->validateSignature($data, $signature) && $order_id) {
                self::writeLog('if', '');
                $order_storage = $this->entityTypeManager->getStorage('commerce_order');
                $order = $order_storage->load($order_id);
                if (is_null($order)) {
                    throw new \Exception('Order not found');
                }
                $amount_payment = $data_response['amount'];
                $amount = $this->rounder->round($order->getTotalPrice());
                $amount_order = $amount->getNumber();
                $amount_paid = $order->getTotalPaid()->getNumber();
                if (!empty($data_response['response_code']) && $data_response['response_code'] == 1000 && ($amount_payment >= $amount_order)) {
                    self::writeLog('1000', '');
                    self::writeLog(array('locked' => (array)$order->isLocked()));
                    // Меняем статус ORDER'а и сохраняем его
//                    $order_state = $order->getState();
//                    $order_state_transitions = $order_state->getTransitions();
//                    self::writeLog(array('$order_state_transitions1' => (array)$order_state_transitions));
                    $order->getState()->applyTransitionById('place');
                    $order->save();
//                    $order_state = $order->getState();
//                    $order_state_transitions = $order_state->getTransitions();
//                    self::writeLog(array('$order_state_transitions2' => (array)$order_state_transitions));
                    $order->getState()->applyTransitionById('validate');
                    $order->save();
                    // Снимаем блокировку с заказа
                    $locked = $order->isLocked();
                    if (!empty($locked)) {
                        $order->unlock();
                        $order->save();
                    }
//                    $order->set('state', 'completed');
//                    $order->setOrderNumber($data_response['provider_order_id']);
//                    $order->setPlacedTime($this->time->getRequestTime());
//                    $order->save();
                    // Создаём платёж и сохраняем его
                    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                    $payment = $payment_storage->create([
                        'state' => 'completed',
                        'amount' => $order->getTotalPrice(), // сумма без комиссии
                        'payment_gateway' => $this->entityId,
                        'order_id' => $order->id(),
                        'remote_id' => $data_response['order_id'],
                        'transaction_id' => $data_response['transaction_id'],
//                        'remote_state' => 'PAYED',
                        'remote_state' => $data_response['status'],
                        'authorized' => \Drupal::time()->getRequestTime(),
                    ]);
                    //new сумма с комиссией
                    $payment->setAmount(new Price((string)$amount_payment, $data_response['currency']));
                    //new
                    $payment->save();
                }// auth
                elseif (!empty($data_response['response_code']) && $data_response['response_code'] == 1002 && ($amount_payment >= $amount_order)) {
                    self::writeLog('1002', '');
                    // Создаём платёж и сохраняем его
                    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                    $payment = $payment_storage->create([
                        'state' => 'authorization',
                        'amount' => $order->getTotalPrice(), // сумма без комиссии
                        'payment_gateway' => $this->entityId,
                        'order_id' => $order->id(),
                        'remote_id' => $data_response['order_id'],
                        'transaction_id' => $data_response['transaction_id'],
                        'remote_state' => $data_response['status'],
                        'authorized' => \Drupal::time()->getRequestTime(),
                    ]);
                    //new сумма с комиссией
                    $payment->setAmount(new Price((string)$amount_payment, $data_response['currency']));
                    //new
                    $payment->save();
                } // auth
                //  refund
                // elseif ($data_response['response_code'] == 1004) {
                elseif ($data_response['method'] == "refund" && $data_response['status'] == "success") {
                    self::writeLog('refund', '');
                    self::writeLog('empty', empty((float)$amount_paid));
                    self::writeLog('$amount_paid', $amount_paid);
                    self::writeLog('$amount_payment', $amount_payment);
                    self::writeLog('$amount_order', $amount_order);
                    $order_state = $order->getState();
                    $order_state_transitions = $order_state->getTransitions();
                    self::writeLog(array('$order_state_transitions_refund' => (array)$order_state_transitions));
                    if (empty((float)$amount_paid)) { // partial refund
                        // Меняем статус ORDER'а и сохраняем его
                        $order->getState()->applyTransitionById('cancel');
                        $order->save();
                        \Drupal::logger('tranzzo')->notice('Возврат Успешный');
                    } else {
                        \Drupal::logger('tranzzo')->notice('Частичный возврат Успешный');
                    }
                } // refund
                //  void
                elseif ($data_response['method'] == "void" && $data_response['status'] == "success") {
                    self::writeLog('void', '');
                    // Меняем статус ORDER'а и сохраняем его
                    $order->getState()->applyTransitionById('cancel');
                    $order->save();
                    \Drupal::logger('tranzzo')->notice('Возврат заблокированных средств успешный');
                } // void
                //  capture
                elseif ($data_response['method'] == "capture" && $data_response['status'] == "success") {
                    self::writeLog('capture', '');
                    // Меняем статус ORDER'а и сохраняем его
//                    $order->set('state', 'completed');
                    //!!!!!!!!!!!!!!!!
//                    $order->getState()->applyTransitionById('validate');
//                    $order->save();
                    \Drupal::logger('tranzzo')->notice('Заблокированные средства списаны успешно');
                } // capture
                elseif (!empty($data_response['response_code']) && $data_response['response_code'] == 2122) {
//                    \Drupal::logger('tranzzo')->notice('Ожидание оплаты');
                } //new
                else {
//                    $order->set('state', 'draft');
                    $order->getState()->applyTransitionById('draft');
                    $order->save();
                    \Drupal::logger('tranzzo')->notice('Платеж не прошел');
                    throw new PaymentGatewayException("Payment signature mismatch");
//                    throw new \Exception('Payment signature mismatch');
                }
            } else {
                throw new \Exception('Request parameters is not correct');
            }
        } catch (\Exception $e) {
            watchdog_exception('PayURL handler', $e);
        }
    }
    //new
    //for log
    static function writeLog($data, $flag = '', $filename = '', $append = true, $show = true)
    {
//        $show = false; // лог не пишется
        if ($show) {
            $filename = !empty($filename) ? strval($filename) : basename(__FILE__);
            file_put_contents(__DIR__ . "/{$filename}.log", "\n\n" . date('H:i:s') . " - $flag \n" .
                (is_array($data) ? json_encode($data, JSON_PRETTY_PRINT) : $data)
                , ($append ? FILE_APPEND : 0)
            );
        }
    }
    //new
    /**
     * {@inheritdoc}
     */
    public function capturePayment(PaymentInterface $payment, Price $amount = NULL)
    {
        self::writeLog('capturePayment', '');
        self::writeLog(array('$amount' => (array)$amount));
        $this->assertPaymentState($payment, ['authorization']);
        // If not specified, capture the entire amount.
        $amount = $amount ?: $payment->getAmount();
        $amount = $this->rounder->round($amount);
        self::writeLog(array('getAmount' => (array)$payment->getAmount()));
        self::writeLog(array('$amount' => (array)$amount));
        // GetTranzzoCheckoutDetails API Operation (NVP).
        // Shows information about an Tranzzo Checkout transaction.
        $tranzzo_response = $this->doCapture($payment, $amount->getNumber());
        self::writeLog(array('$tranzzo_response capturePayment!!!!!!' => (array)$tranzzo_response));
        if ($tranzzo_response['status'] != 'success') {
            $message = $tranzzo_response['message'];
            throw new PaymentGatewayException($message, $tranzzo_response['code']);
        }
//        $payment->setState('completed');
//        $payment->setAmount($amount);
        // Update the remote id for the captured transaction.
//        $payment->setRemoteId($tranzzo_response['payment_id']);
//        $payment->save();
    }
    /**
     * {@inheritdoc}
     */
    public function doCapture(PaymentInterface $payment, $amount)
    {
        self::writeLog('doCapture', '');
//        self::writeLog(array('getOrder' => (array)$payment->getOrder()));
//        $order = $payment->getOrder();
        global $base_url;
        // Build a name-value pair array for this transaction.
        $nvp_data = [
//            'order_id' => $order->getOrderNumber(),
            'order_id' => $payment->getRemoteId(),
//            'order_amount' => (float)$this->rounder->round($payment->getAmount()),
            'order_amount' => (float)$payment->getAmount()->getNumber(),
            'order_currency' => $payment->getAmount()->getCurrencyCode(),
            'charge_amount' => (float)$amount,
            'server_url' => $base_url . '/payment/notify/tranzzo',
        ];
        // Make the PayPal NVP API request.
        return $this->doRequest($nvp_data, $payment->getOrder(), 'capture');
    }
    /**
     * {@inheritdoc}
     */
    public function doRefundTransaction(PaymentInterface $payment, array $extra)
    {
        self::writeLog('doRefundTransaction', '');
//        $dd = (float)$payment->getAmount()->getNumber();
//        self::writeLog(array('$payment' => (array)$payment));
        // Build a name-value pair array for this transaction.
        global $base_url;
        $nvp_data = [
            'order_id' => $extra['order_id'],
//            'order_amount' => (float)$extra['amount'],
            'order_amount' => (float)$payment->getAmount()->getNumber(),
            'order_currency' => $extra['currency'],
            'refund_date' => date('Y-m-d H:i:s', time()),
//            'amount' => (float)$extra['amount'],
            'refund_amount' => (float)$extra['amount'], // сумма возврата
            'server_url' => $base_url . '/payment/notify/tranzzo',
        ];
//        self::writeLog(array('$nvp_data doRefundTransaction' => (array)$nvp_data));
        // Make the Tranzzo API request.
        return $this->doRequest($nvp_data, $payment->getOrder(), "refund");
    }
    /**
     * {@inheritdoc}
     */
    public function doVoid(PaymentInterface $payment)
    {
        self::writeLog('doVoid', '');
        self::writeLog(array('getAmount' => (array)$payment->getAmount()));
        self::writeLog('getAmount num', $payment->getAmount()->getNumber());
//        self::writeLog(array('$payment' => (array)$payment));
        self::writeLog('values', $payment->getRemoteId());
        // Build a name-value pair array for this transaction.
        global $base_url;
        $nvp_data = [
//            'order_id' => $payment->getOrder()->getOrderNumber(),
            'order_id' => $payment->getRemoteId(),
//            'order_amount' => (float)$this->rounder->round($payment->getAmount()),
            'order_amount' => (float)$payment->getAmount()->getNumber(),
            'order_currency' => $payment->getAmount()->getCurrencyCode(),
            'refund_date' => date('Y-m-d H:i:s', time()),
//            'amount' => (float)$this->rounder->round($payment->getAmount()),
//            'amount' => (float)$payment->getAmount()->getNumber(),
            'server_url' => $base_url . '/payment/notify/tranzzo',
        ];
        // Make the Tranzzo NVP API request.
        return $this->doRequest($nvp_data, $payment->getOrder(), 'void');
    }
    /**
     * {@inheritdoc}
     */
    public function doRequest(array $nvp_data, OrderInterface $order = NULL, $uri = NULL)
    {
        //serialize_precision for json_encode
        if (version_compare(phpversion(), '7.1', '>=')) {
            ini_set('serialize_precision', -1);
        }
        self::writeLog('doRequest', '');
        self::writeLog(array('$nvp_data' => (array)$nvp_data));
        // Add the default name-value pairs to the array.
        $configuration = $this->getConfiguration();
        $nvp_data['pos_id'] = $configuration['POS_ID'];
        $url = $configuration['server'] . $uri;
        $data = json_encode($nvp_data);
        // Make Tranzzo request.
        \Drupal::logger('tranzzo')
            ->notice('Отправлен запрос со следующими данными: @data', ['@data' => print_r($data, TRUE)]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/json', 'content-type: application/json',
            'X-API-Auth:CPAY ' . $configuration['API_KEY'] . ':' . $configuration['API_SECRET'], 'X-API-KEY:' . $configuration['ENDPOINTS_KEY']]);
        $server_output = curl_exec($ch);
        $http_code = curl_getinfo($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
//        self::writeLog(array('$server_output' => (array)$server_output));
//        self::writeLog(array('$http_code' => (array)$http_code));
//        self::writeLog(array('$errno' => (array)$errno));
        \Drupal::logger('tranzzo')
            ->notice('Получен ответ со следующими данными: @data', ['@data' => print_r($http_code, TRUE)]);
        if (!$errno && empty($server_output))
            return $http_code;
        else
            return (json_decode($server_output, true)) ? json_decode($server_output, true) : $server_output;
    }
    /**
     * @param $data
     * @param $requestSign
     * @return bool
     */
    public function validateSignature($data, $requestSign)
    {
        $configuration = $this->getConfiguration();
        $signStr = $configuration['API_SECRET'] . $data . $configuration['API_SECRET'];
        $sign = $this->base64url_encode(sha1($signStr, true));
        if ($requestSign !== $sign) {
            return false;
        }
        return true;
    }
    /**
     * @param $data
     * @return string
     */
    public function base64url_encode($data)
    {
        return strtr(base64_encode($data), '+/', '-_');
    }
    /**
     * @param $data
     * @return bool|string
     */
    public static function base64url_decode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}