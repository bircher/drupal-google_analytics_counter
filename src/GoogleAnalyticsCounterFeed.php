<?php

namespace Drupal\google_analytics_counter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Utility\UrlHelper;

/**
 * Authorize access and request data from Google Analytics Core Reporting API.
 */
class GoogleAnalyticsCounterFeed {

  use StringTranslationTrait;

  const OAUTH2_REVOKE_URI = 'https://accounts.google.com/o/oauth2/revoke';
  const OAUTH2_TOKEN_URI = 'https://accounts.google.com/o/oauth2/token';
  const OAUTH2_AUTH_URL = 'https://accounts.google.com/o/oauth2/auth';
  const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly https://www.google.com/analytics/feeds/';

  // Response object.
  public $response;

  // Formatted array of request results.
  public $results;

  // URL to Google Analytics Core Reporting API.
  public $queryPath;

  // Translated error message.
  public $error;

  // Boolean TRUE if data is from the cache tables.
  public $fromCache = FALSE;

  // OAuth access token.
  public $accessToken;

  // OAuth refresh token.
  public $refreshToken;

  // OAuth expiration time.
  public $expiresAt;

  // Host and endpoint of Google Analytics API.
  protected $host = 'www.googleapis.com/analytics/v3';

  // Request header source.
  protected $source = 'drupal';

  // Google authorize callback verifier string.
  protected $verifier;

  // OAuth host.
  protected $oAuthHost = 'www.google.com';

  /**
   * Check if object is authenticated with Google.
   */
  public function isAuthenticated() {
    return !empty($this->accessToken);
  }

  /**
   * The constructor.
   */
  public function __construct($token = NULL) {
    $this->accessToken = $token;
  }

  /**
   * Get the current page url.
   */
  public static function currentUrl() {
    if (!empty($_SERVER['HTTPS'])) {
      $https = $_SERVER['HTTPS'] == 'on';
    }
    else {
      $https = FALSE;
    }
    $url = $https ? 'https://' : 'http://';
    $url .= $_SERVER['SERVER_NAME'];
    if ((!$https && $_SERVER['SERVER_PORT'] != '80') ||
      ($https && $_SERVER['SERVER_PORT'] != '443')
    ) {
      $url .= ':' . $_SERVER['SERVER_PORT'];
    }
    return $url . $_SERVER['REQUEST_URI'];
  }

  /**
   * Create a URL to obtain user authorization.
   *
   * The authorization endpoint allows the user to first
   * authenticate, and then grant/deny the access request.
   *
   * @param string $client_id
   *   Client ID for Web application from Google API Console.
   *
   * @return string
   *   The url to authorize.
   */
  public function createAuthUrl($client_id, $redirect_uri) {
    $params = array(
      'response_type=code',
      'redirect_uri=' . $redirect_uri,
      'client_id=' . urlencode($client_id),
      'scope=' . self::SCOPE,
      'access_type=offline',
      'approval_prompt=force',
    );

    $params = implode('&', $params);
    return self::OAUTH2_AUTH_URL . "?$params";
  }

  /**
   * Authenticate with the Google API.
   *
   * @param string $client_id
   *   Client ID for Web application from Google API Console.
   * @param string $client_secret
   *   Client secret for Web application from Google API Console.
   * @param string $redirect_uri
   *   Callback uri.
   */
  protected function fetchToken($client_id, $client_secret, $redirect_uri, $refresh_token = NULL) {
    if ($refresh_token) {
      $params = array(
        'client_id=' . $client_id,
        'client_secret=' . $client_secret,
        'refresh_token=' . $refresh_token,
        'grant_type=refresh_token',
      );
    }
    else {
      $params = array(
        'code=' . $_GET['code'],
        'grant_type=authorization_code',
        'redirect_uri=' . $redirect_uri,
        'client_id=' . $client_id,
        'client_secret=' . $client_secret,
      );
    }

    try {
      $client = \Drupal::httpClient();
      $this->response = $client->post(self::OAUTH2_TOKEN_URI, array(
        'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
        'body' => implode('&', $params),
      ));
    }
    catch (RequestException $e) {
      $this->response = $e->getResponse();
    }

    if (substr($this->response->getStatusCode(), 0, 1) == '2') {
      $decoded_response = json_decode($this->response->getBody()
        ->__toString(), TRUE);
      $this->accessToken = $decoded_response['access_token'];
      $this->expiresAt = time() + $decoded_response['expires_in'];
      if (!$this->refreshToken) {
        $this->refreshToken = $decoded_response['refresh_token'];
      }
    }
    else {
      $error_vars = [
        ':code' => $this->response->getStatusCode(),
        ':message' => $this->response->getReasonPhrase(),
        ':details' => strip_tags($this->response->getbody()->__toString()),
      ];
      $this->error = $this->t('Code: :code - Error: :message - Message: :details', $error_vars);
      \Drupal::logger('Google Analytics Counter')
        ->error('Code: :code - Error: :message - Message: :details', $error_vars);
    }
  }

  /**
   * Complete the authentication process.
   *
   * We got here after being redirected from a successful authorization grant.
   * Fetch the access token.
   *
   * @param string $client_id
   *   Client ID for Web application from Google API Console.
   * @param string $client_secret
   *   Client secret for Web application from Google API Console.
   * @param string $redirect_uri
   *   Callback uri.
   */
  public function finishAuthentication($client_id, $client_secret, $redirect_uri) {
    $this->fetchToken($client_id, $client_secret, $redirect_uri);
  }

  /**
   * Redirect to Google authentication page.
   *
   * Begin authentication by allowing the user to grant/deny access to
   * the Google account.
   *
   * @param string $client_id
   *   Client ID for Web application from Google API Console.
   * @param string $redirect_uri
   *   Callback uri.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect header.
   */
  public function beginAuthentication($client_id, $redirect_uri) {
    $response = new RedirectResponse($this->createAuthUrl($client_id, $redirect_uri));
    return $response->send();
  }

  /**
   * Fetches a fresh access token with the given refresh token.
   *
   * @param string $client_id
   *   Client ID for Web application from Google API Console.
   * @param string $client_secret
   *   Client secret for Web application from Google API Console.
   * @param string $refresh_token
   *   Refresh token for Web application from Google API Console.
   */
  public function refreshToken($client_id, $client_secret, $refresh_token) {
    $this->refreshToken = $refresh_token;
    $this->fetchToken($client_id, $client_secret, '', $refresh_token);
  }

  /**
   * OAuth step #1: Fetch request token.
   *
   * Revoke an OAuth2 access token or refresh token. This method will revoke
   * the current access token, if a token isn't provided.
   *
   * @param string|NULL $token
   *   The token (access token or a refresh token) that should be revoked.
   *
   * @return bool
   *   Returns True if the revocation was successful, otherwise False.
   */
  public function revokeToken($token = NULL) {
    if (!$token) {
      $token = $this->refreshToken ? $this->refreshToken : $this->accessToken;
    }

    try {
      $client = \Drupal::httpClient();
      $this->response = $client->post(self::OAUTH2_REVOKE_URI, array(
        'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
        'body' => 'token=' . $token,
      ));
    }
    catch (RequestException $e) {
      $this->response = $e->getResponse();
    }

    if ($this->response->getStatusCode() == 200) {
      $this->accessToken = NULL;
      return TRUE;
    }

    return FALSE;
  }

  /**
   * OAuth step #2: Authorize request token.
   *
   * Generate authorization token header for all requests.
   */
  public function generateAuthHeader($token = NULL) {
    if ($token == NULL) {
      $token = $this->accessToken;
    }
    return array('Authorization' => 'Bearer ' . $token);
  }

  /**
   * OAuth step #3: Fetch access token.
   *
   * Set the verifier property.
   */
  public function setVerifier($verifier) {
    $this->verifier = $verifier;
  }

  /**
   * Set the host property.
   */
  public function setHost($host) {
    $this->host = $host;
  }

  /**
   * Set the queryPath property.
   */
  protected function setQueryPath($path) {
    $this->queryPath = 'https://' . $this->host . '/' . $path;
  }

  /**
   * Public query method for all Core Reporting API features.
   */
  public function query($url, $params, $method, $headers, $cache_options = array()) {
    $params_defaults = array(
      'start-index' => 1,
      'max-results' => 1000,
    );
    $params += $params_defaults;

    // Provide cache defaults if a developer did not override them.
    $cache_defaults = array(
      'cid' => NULL,
      'expire' => GoogleAnalyticsCounterCommon::cacheTime(),
      'refresh' => FALSE,
    );
    $cache_options += $cache_defaults;

    // Provide a query MD5 for the cid if the developer did not provide one.
    if (empty($cache_options['cid'])) {
      $cache_options['cid'] = 'GoogleAnalyticsCounterFeed:' . md5(serialize(array_merge($params, array(
        $url,
        $method,
      ))));
    }

    $cache = \Drupal::cache()->get($cache_options['cid']);

    if (!$cache_options['refresh'] && isset($cache) && !empty($cache->data)) {
      // $this->response = $cache;.
      $this->results = $cache->data;
      $this->fromCache = TRUE;
    }
    else {
      $this->request($url, $params, $headers);
    }

    if (empty($this->error)) {
      // @todo remove cache,use default cache('default')
      // Don't save $this->results, because the object will lose steam resource
      // when caching, but it will lose response.
      \Drupal::cache()
        ->set($cache_options['cid'], $this->results, $cache_options['expire']);
    }

    return (empty($this->error));
  }

  /**
   * Execute a query.
   */
  protected function request($url, $params = array(), $headers = array(), $method = 'GET') {
    $options = array(
      'method' => $method,
      'headers' => $headers,
    );

    if (count($params) > 0) {
      if ($method == 'GET') {
        $url .= '?' . UrlHelper::buildQuery($params);
      }
      else {
        $options['body'] = UrlHelper::buildQuery($params);
      }
    }

    $client = \Drupal::httpClient();
    $this->response = $client->request($method, $url, $options);

    if ($this->response->getStatusCode() == '200') {
      $this->results = json_decode($this->response->getBody()->__toString());
    }
    else {
      // Data is undefined if the connection failed.
      if (empty($this->response->getBody()->__toString())) {
        // @todo check it!!! it's temp code.
        $this->response->setBody('');
      }
      $error_vars = array(
        '@code' => $this->response->getStatusCode(),
        '@message' => $this->response->getReasonPhrase(),
        '@details' => strip_tags($this->response->getBody()->__toString()),
      );
      $this->error = t('Code: @code, Error: @message, Message: @details', $error_vars);
      \Drupal::logger('Google Analytics Counter')
        ->error('Code: @code, Error: @message, Message: @details', []);
    }
  }

  /**
   * Query Management API - Accounts.
   */
  public function queryAccounts($params = array(), $cache_options = array()) {
    $this->setQueryPath('management/accounts');
    $this->query($this->queryPath, $params, 'GET', $this->generateAuthHeader(), $cache_options);
    return $this;
  }

  /**
   * Query Management API - WebProperties.
   */
  public function queryWebProperties($params = array(), $cache_options = array()) {
    $params += array(
      'account-id' => '~all',
    );
    $this->setQueryPath('management/accounts/' . $params['account-id'] . '/webproperties');
    $this->query($this->queryPath, $params, 'GET', $this->generateAuthHeader(), $cache_options);
    return $this;
  }

  /**
   * Query Management API - Profiles.
   */
  public function queryProfiles($params = array(), $cache_options = array()) {
    $params += array(
      'account-id' => '~all',
      'web-property-id' => '~all',
    );
    $this->setQueryPath('management/accounts/' . $params['account-id'] . '/webproperties/' . $params['web-property-id'] . '/profiles');
    $this->query($this->queryPath, $params, 'GET', $this->generateAuthHeader(), $cache_options);

    return $this;
  }

  /**
   * Query Management API - Segments.
   */
  public function querySegments($params = array(), $cache_options = array()) {
    $this->setQueryPath('management/segments');
    $this->query($this->queryPath, $params, 'GET', $this->generateAuthHeader(), $cache_options);
    return $this;
  }

  /**
   * Query Management API - Goals.
   */
  public function queryGoals($params = array(), $cache_options = array()) {
    $params += array(
      'account-id' => '~all',
      'web-property-id' => '~all',
      'profile-id' => '~all',
    );
    $this->setQueryPath('management/accounts/' . $params['account-id'] . '/webproperties/' . $params['web-property-id'] . '/profiles/' . $params['profile-id'] . '/goals');
    $this->query($this->queryPath, $params, 'GET', $this->generateAuthHeader(), $cache_options);
    return $this;
  }

  /**
   * Query and sanitize report data.
   */
  public function queryReportFeed($params = array(), $cache_options = array()) {

    // Provide defaults if the developer did not override them.
    $params += array(
      'profile_id' => 0,
      'dimensions' => NULL,
      'metrics' => 'ga:visits',
      'sort_metric' => NULL,
      'filters' => NULL,
      'segment' => NULL,
      'start_date' => NULL,
      'end_date' => NULL,
      'start_index' => 1,
      'max_results' => 10000,
    );

    $parameters = array('ids' => $params['profile_id']);

    if (is_array($params['dimensions'])) {
      $parameters['dimensions'] = implode(',', $params['dimensions']);
    }
    elseif ($params['dimensions'] !== NULL) {
      $parameters['dimensions'] = $params['dimensions'];
    }

    if (is_array($params['metrics'])) {
      $parameters['metrics'] = implode(',', $params['metrics']);
    }
    else {
      $parameters['metrics'] = $params['metrics'];
    }

    if ($params['sort_metric'] == NULL && isset($parameters['metrics'])) {
      $parameters['sort'] = $parameters['metrics'];
    }
    elseif (is_array($params['sort_metric'])) {
      $parameters['sort'] = implode(',', $params['sort_metric']);
    }
    else {
      $parameters['sort'] = $params['sort_metric'];
    }
    $start_date = '';
    if (empty($params['start_date']) || !is_int($params['start_date'])) {
      // Use the day that Google Analytics was released (1 Jan 2005).
      $start_date = '2005-01-01';
    }
    elseif (is_int($params['start_date'])) {
      // Assume a Unix timestamp.
      $start_date = date('Y-m-d', $params['start_date']);
    }

    $parameters['start-date'] = $start_date;

    $end_date = '';
    if (empty($params['end_date']) || !is_int($params['end_date'])) {
      $end_date = date('Y-m-d');
    }
    elseif (is_int($params['end_date'])) {
      // Assume a Unix timestamp.
      $end_date = date('Y-m-d', $params['end_date']);
    }

    $parameters['end-date'] = $end_date;

    // Accept only strings, not arrays, for the following parameters.
    if (!empty($params['filters'])) {
      $parameters['filters'] = $params['filters'];
    }
    if (!empty($params['segment'])) {
      $parameters['segment'] = $params['segment'];
    }
    $parameters['start-index'] = $params['start_index'];
    $parameters['max-results'] = $params['max_results'];

    $this->setQueryPath('data/ga');
    if ($this->query($this->queryPath, $parameters, 'GET', $this->generateAuthHeader(), $cache_options)) {
      $this->sanitizeReport();
    }
    return $this;
  }

  /**
   * Sanitize report data.
   */
  protected function sanitizeReport() {
    // Named keys for report values.
    $this->results->rawRows = isset($this->results->rows) ? $this->results->rows : array();
    $this->results->rows = array();
    foreach ($this->results->rawRows as $row_key => $row_value) {
      foreach ($row_value as $item_key => $item_value) {
        $this->results->rows[$row_key][str_replace('ga:', '', $this->results->columnHeaders[$item_key]->name)] = $item_value;
      }
    }
    unset($this->results->rawRows);

    // Named keys for report totals.
    $this->results->rawTotals = $this->results->totalsForAllResults;
    $this->results->totalsForAllResults = array();
    foreach ($this->results->rawTotals as $row_key => $row_value) {
      $this->results->totalsForAllResults[str_replace('ga:', '', $row_key)] = $row_value;
    }
    unset($this->results->rawTotals);
  }

  /**
   * Addition replacements.
   */
  public function replaceData($type, $value) {
    switch ($type) {
      case 'userType':
        return ($value == 'New Visitor') ? t('New Visitor') : t('Returning Visitor');

      case 'date':
        return strtotime($value);

      case 'yearMonth':
        return strtotime($value . '01');

      case 'userGender':
        return ($value == 'male') ? t('Male') : t('Female');

      default:
        return $value;
    }
  }

}
