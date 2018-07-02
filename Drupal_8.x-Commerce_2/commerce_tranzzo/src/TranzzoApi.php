<?php
namespace Drupal\commerce_tranzzo;

class TranzzoApi
{
  /*
   * https://tranzzo.docs.apiary.io/ The Tranzzo API is an HTTP API served by Tranzzo payment core
   */
  //Common params
  const P_MODE_HOSTED     = 'hosted';
  const P_MODE_DIRECT     = 'direct';
  const P_REQ_CPAY_ID     = 'uuid';
  const P_REQ_POS_ID      = 'pos_id';
  const P_REQ_ENDPOINT_KEY = 'key';
  const P_REQ_MODE        = 'mode';
  const P_REQ_METHOD      = 'method';
  const P_REQ_AMOUNT      = 'amount';
  const P_REQ_CURRENCY    = 'currency';
  const P_REQ_DESCRIPTION = 'description';
  const P_REQ_ORDER       = 'order_id';
  const P_REQ_PRODUCTS    = 'products';
  const P_REQ_ORDER_3DS_BYPASS   = 'order_3ds_bypass';
  const P_REQ_CC_NUMBER   = 'cc_number';
  const P_OPT_PAYLOAD     = 'payload';
  const P_REQ_PAYWAY     = 'payway';

  const P_REQ_CUSTOMER_ID = 'customer_id';
  const P_REQ_CUSTOMER_EMAIL  = 'customer_email';
  const P_REQ_CUSTOMER_FNAME  = 'customer_fname';
  const P_REQ_CUSTOMER_LNAME  = 'customer_lname';
  const P_REQ_CUSTOMER_PHONE  = 'customer_phone';

  const P_REQ_SERVER_URL  = 'server_url';
  const P_REQ_RESULT_URL  = 'result_url';

  const P_REQ_SANDBOX     = 'sandbox';

  //Response params
  const P_RES_PAYMENT_ID  = 'payment_id';
  const P_RES_TRSACT_ID   = 'transaction_id';
  const P_RES_STATUS      = 'status';
  const P_RES_CODE        = 'code';
  const P_RES_RESP_CODE   = 'response_code';
  const P_RES_DESC        = 'code_description';

  const P_TRZ_ST_SUCCESS      = 'success';
  const P_TRZ_ST_PENDING      = 'pending';
  const P_TRZ_ST_CANCEL       = 'rejected';
  const P_TRZ_ST_UNSUCCESSFUL = 'unsuccessful';
  const P_TRZ_ST_ANTIFRAUD    = 'antifraud';

  //Request method
  const R_METHOD_GET  = 'GET';
  const R_METHOD_POST = 'POST';

  //URI method
  const U_METHOD_PAYMENT = '/payment';
  const U_METHOD_POS = '/pos';



  /**
   * @var string
   */
  private $apiUrl = 'https://cpay.tranzzo.com/api/v1';

  /**
   * @var string
   */
  private $posId;

  /**
   * @var string
   */
  private $apiKey;

  /**
   * @var string
   */
  private $apiSecret;

  /**
   * @var string
   */
  private $endpointsKey;

  /**
   * @var array $headers
   */
  private $headers;


  /**
   * Service_Tranzzo_Api constructor.
   * @param $posId
   * @param $apiKey
   * @param $apiSecret
   * @param $endpointKey
   */
  public function __construct($posId, $apiKey, $apiSecret, $endpointKey)
  {

    $this->posId = $posId;
    $this->apiKey = $apiKey;
    $this->apiSecret = $apiSecret;
    $this->endpointsKey = $endpointKey;
  }

  /**
   * @param array $params
   * @return mixed
   */
  public function createCreditPayment($params = array())
  {
    $params[self::P_REQ_METHOD] = 'credit';
    $params[self::P_REQ_POS_ID] = $this->posId;

    $uri = self::U_METHOD_PAYMENT;
    $this->setHeader('Content-Type:application/json');

    return $this->request($params, self::R_METHOD_POST, $uri);
  }

  /**
   * @param array $params
   * @return mixed
   */
  public function createPaymentHosted($params = array())
  {
    $params[self::P_REQ_POS_ID] = $this->posId;
    $params[self::P_REQ_MODE] = self::P_MODE_HOSTED;
    $params[self::P_REQ_METHOD] = 'purchase';
    $params[self::P_REQ_ORDER_3DS_BYPASS] = 'supported';

    $this->setHeader('Accept: application/json');
    $this->setHeader('Content-Type: application/json');

    return $this->request($params, self::R_METHOD_POST, self::U_METHOD_PAYMENT);
  }

  /**
   * @param $params
   * @return mixed
   */
  public function checkPaymentStatus($params)
  {
    $uri = self::U_METHOD_POS. '/' . $this->posId . '/orders/' . $params[self::P_REQ_ORDER];

    return $this->request([], self::R_METHOD_GET, $uri);
  }

  /**
   * @param $params
   * @return mixed
   */
  private function request($params, $method, $uri)
  {
    $url    = $this->apiUrl . $uri;
    $data   = json_encode($params);

    $this->setHeader('accept: application/json');
    $this->setHeader('content-type: application/json');
    $this->setHeader('X-API-Auth: CPAY '.$this->apiKey.':'.$this->apiSecret);
    $this->setHeader('X-API-KEY: ' . $this->endpointsKey);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if($method === self::R_METHOD_POST){
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

    $server_output = curl_exec($ch);
    $http_code = curl_getinfo($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    $header_size = $http_code['header_size'];
    $body = substr($server_output, $header_size, strlen($server_output) - $header_size );

    if(!$errno && empty($body))
      return $http_code;
    else
      return (json_decode($body, true))? json_decode($body, true) : $body;
  }

  /**
   * @param $data
   * @param $requestSign
   * @return bool
   */
  public function validateSignature($data, $requestSign)
  {
    $signStr = $this->apiSecret . $data . $this->apiSecret;
    $sign = self::base64url_encode(sha1($signStr, true));

    if ($requestSign !== $sign) {
      return false;
    }

    return true;
  }

  /**
   * @param $params
   * @return string
   */
  private function createSign($params)
  {
    $json      = self::base64url_encode( json_encode($params) );
    $signature = $this->strToSign($this->apiSecret . $json . $this->apiSecret);
    return $signature;
  }

  /**
   * @param $str
   * @return string
   */
  private function strToSign($str)
  {
    return self::base64url_encode(sha1($str,1));
  }

  /**
   * @param $data
   * @return string
   */
  public static function base64url_encode($data)
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

  /**
   * @param $header
   */
  private function setHeader($header)
  {
    $this->headers[] = $header;
  }

  /**
   * @param $key
   * @return mixed
   */
  private function getHeader($key)
  {
    return $this->headers[$key];
  }

  /**
   * @param string $value
   * @param int $round
   * @return float
   */
  static function amountToDouble($value = '', $round = null)
  {
    $val = floatval($value);
    return is_null($round)? $val : round($value, (int)$round);
  }

}