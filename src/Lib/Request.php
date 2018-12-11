<?php
/**
 * Request class
 */

namespace Lib;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;

/**
 * Class encapsulating logic to fire HTTP GET & POST Requests
 */
class Request
{
  /** @var array OST REST base URL */
  private $baseUrl;
  /** @var string OST KYC API key */
  private $apiKey;
  /** @var string OST KYC API secret */
  private $apiSecret;

  private $timeout;

  /**
   * Constructor
   *
   * @param array $params Array containing the necessary params {
   * @type string $apiKey API Key.
   * @type string $apiSecret API Secret.
   * @type string $baseUrl Base API URL.
   * @type int $timeout Timeout For Socket Connection.
   * }
   *
   * @throws \Exception
   *
   */
  public function __construct(array $params)
  {
    if (!Validate::isPresent($params['apiKey']) ||
        !Validate::isPresent($params['apiSecret']) ||
        !Validate::isPresent($params['apiBaseUrl'])) {

      throw new \Exception('mandatory param missing in constructor args');

    }

    $this->apiKey = $params['apiKey'];
    $this->apiSecret = $params['apiSecret'];

    $this->baseUrl = $this->sanitizeApiBaseUrl($params['apiBaseUrl']);
    $this->timeout = 10;
    if (array_key_exists("config", $params)) {
      $config = $params["config"];
      if (array_key_exists("timeout", $config) && is_array($config)) {
        $this->timeout = $config["timeout"];
      }
    }
  }

  /**
   * Send a GET request
   *
   * @param string $endpoint endpoint to the GET request
   * @param array $arguments optional object containing params which are to be sent across in the GET request
   *
   * @return object
   *
   */
  public function get($endpoint, array &$arguments = array())
  {

    $argsCopy = $this->copyAndSanitizeArgs($arguments);

    // build Path to hit by appending query params and signature
    $urlPath = $endpoint . '?' . $argsCopy;

    $urlPath = $urlPath . '&signature=' . hash_hmac('sha256', $urlPath, $this->apiSecret);

    /** @var Promise $promise */
    $promise = $this->getRequestClient()->getAsync($urlPath, $this->getCommonRequestParams());

    return $promise->then(
    // $onFulfilled
        function ($response) {
          return $this->parseResponse($response);
        },
        // $onRejected
        function ($reason) {
          if (get_class($reason) == "GuzzleHttp\Exception\ConnectException") {
            return $this->customGenericErrorResponse('socket_timeout_exception');
          } else {
            return $this->customGenericErrorResponse('g_1');
          }
        }
    );

  }

  /**
   * Send a POST request
   *
   * @param string $endpoint endpoint to the GET request
   * @param array $arguments optional object containing params which are to be sent across in the POST request
   *
   * @return object
   *
   */
  public function post($endpoint, array $arguments = array())
  {
    $argsCopy = $this->copyAndSanitizeArgs($arguments);

    // sanitize request params
    $query = $endpoint . '?' . $argsCopy;

    $argsCopy = $argsCopy . "&signature=" . hash_hmac('sha256', $query, $this->apiSecret);

    $postParams = $this->getCommonRequestParams();
    $postParams['body'] = $argsCopy;

    /** @var Promise $promise */
    $promise = $this->getRequestClient()->postAsync($endpoint, $postParams);

    return $promise->then(
    // $onFulfilled
        function ($response) {
          return $this->parseResponse($response);
        },
        // $onRejected
        function ($reason) {
          if (get_class($reason) == "GuzzleHttp\Exception\ConnectException") {
            return $this->customGenericErrorResponse('socket_timeout_exception');
          } else {
            return $this->customGenericErrorResponse('p_1');
          }
        }
    );
  }


  /**
   * sanitize API Base URL
   *
   * @param string $apiBaseUrl api base url
   *
   * @return string
   *
   */
  private function sanitizeApiBaseUrl($apiBaseUrl)
  {
    // remove a trailing / to apiEndpoint
    if ($apiBaseUrl[strlen($apiBaseUrl) - 1] === '/') {
      $apiBaseUrl = substr_replace($apiBaseUrl, "", -1);
    }
    return $apiBaseUrl;

  }

  /**
   * Parse response of GET / POST requests
   *
   * @param object $response response obj of HTTP request
   *
   * @return object
   *
   */
  private function parseResponse($response)
  {
    $jsonObject = $this->parseJsonString($response->getBody());
    if ($this->isInternalResponse($jsonObject)) {
      return $jsonObject;
    }

    return $this->customErrorResponse($response->getStatusCode());
  }

  /**
   * Generic Something Went Wrong Response
   *
   * @param string $internal_id
   *
   * @return object
   *
   */
  private function customGenericErrorResponse($internal_id)
  {
    return $this->parseJsonString(json_encode(new CustomErrorResponse("", $internal_id)));
  }

  /**
   * returns error Response depending on HTTP status code of response
   *
   * @param integer $statusCode HTTP response code
   *
   * @return object
   *
   */
  private function customErrorResponse($statusCode)
  {
    return $this->parseJsonString(json_encode(new CustomErrorResponse($statusCode)));
  }

  /**
   * parse string to a JSON object
   *
   * @param string $strResponse string which would be type casted to json object
   *
   * @return object
   *
   */
  private function parseJsonString($strResponse)
  {
    $jsonObject = json_decode($strResponse, true);
    if ($jsonObject === null && json_last_error() !== JSON_ERROR_NONE) {
      $jsonObject = $this->customGenericErrorResponse('');
    }
    return $jsonObject;
  }

  /**
   * check if json object was created from an internal response string
   *
   * @param object $jsonObject json object
   *
   * @return boolean
   *
   */
  private function isInternalResponse($jsonObject)
  {
    return isset($jsonObject['success']);
  }

  /**
   * copy over the passed input args and process
   *
   * @param array $arguments json object
   *
   * @return array
   *
   */
  private function copyAndSanitizeArgs(array &$arguments)
  {

    // create copy of input array to not modify it
    $argsCopy = &$arguments;
    // append basic params
    $argsCopy['api_key'] = $this->apiKey;
    $argsCopy['request_timestamp'] = time();

    $argsCopy = $this->build_nested_query($argsCopy);

    return $argsCopy;

  }

  /**
   * gives nested query string
   *
   * @return string
   *
   */
  private function build_nested_query($array, $prefix = '')
  {
    if (is_array($array) || is_object($array)) {
      if ($this->check_for_int_key($array)) {
        $temp_array = array();
        foreach ($array as $k => $v) {
          array_push($temp_array, $this->build_nested_query($v, $prefix . "[]"));
        }
        return join("&", array_filter($temp_array));
      } else {
        $temp_array = array();
        ksort($array);
        foreach ($array as $k => $v) {
          array_push($temp_array, $this->build_nested_query($v, $prefix ? $prefix . "[" . $k . "]" : $k));
        }
        return join("&", array_filter($temp_array));
      }
    } else {
      return $this->escape($prefix) . "=" . $this->escape($array);
    }
  }

  /**
   * encodes a string to be used in a query part of a URL
   *
   * @return string
   *
   */
  private function escape($string)
  {
    return urlencode($string);
  }

  /**
   * differentiate given input between array or hash
   * (if first index of $array is integer then it is array else it is to be sent as a hash)
   *
   * @return string
   *
   */
  private function check_for_int_key($array)
  {
    if (!empty($array) && (is_array($array) || is_object($array))) {
      reset($array);
      $first_key = key($array);
      if (is_int($first_key)) {
        return true;
      } else {
        return false;
      }
    }
  }

  /**
   * create a client object for firing HTTP request
   *
   * @return object
   *
   */
  private function getRequestClient()
  {
    return new Client([
        'base_uri' => $this->baseUrl,
        'timeout' => $this->timeout
    ]);

  }

  /**
   * returns common params for GET & POST requests
   *
   * @return array
   *
   */
  private function getCommonRequestParams()
  {
    return [
        'headers' => [
            'User-Agent' => 'ost-kyc-sdk-php',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'http_errors' => false,
        'connect_timeout' => 10,
        'open_timeout' => 10
    ];

  }
}
