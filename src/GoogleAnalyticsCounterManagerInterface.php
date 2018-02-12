<?php

namespace Drupal\google_analytics_counter;


/**
 * Class GoogleAnalyticsCounterManagerInterface.
 *
 * @package Drupal\google_analytics_counter
 */
interface GoogleAnalyticsCounterManagerInterface {
  /**
   * Check to make sure we are authenticated with google.
   *
   * @return bool
   *   True if there is a refresh token set.
   */
  public function isAuthenticated();

  /**
   * Instantiate a new GoogleAnalyticsCounterFeed object.
   *
   * @return object
   *   GoogleAnalyticsCounterFeed object to authorize access and request data
   *   from the Google Analytics Core Reporting API.
   */
  public function newGaFeed();

  /**
   * Get the redirect uri to redirect the google oauth request back to.
   *
   * @return string
   *   The redirect Uri from the configuration or the path.
   */
  public function getRedirectUri();

  /**
   * Get the list of available web properties.
   *
   * @return array
   */
  public function getWebPropertiesOptions();

  /**
   * Begin authentication to Google authentication page with the client_id
   */
  public function beginAuthentication();

  /**
   * Get the results from google.
   *
   * @param int $index
   *   The index of the chunk to fetch so that it can be queued.
   *
   * @return \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed
   *   The returned feed after the request has been made.
   */
  public function getChunkedResults($index = 0);

  /**
   * Update the path counts.
   *
   * @param int $index
   *   The index of the chunk to fetch and update.
   *
   * This function is triggered by hook_cron().
   */
  public function updatePathCounts($index = 0);

  /**
   * Save the pageview count for a given node.
   *
   * @param integer $nid
   *   The node id of the node for which to save the data.
   */
  public function updateStorage($nid);

  /**
   * Request report data.
   *
   * @param array $params
   *   An associative array containing:
   *   - profile_id: required [default='ga:profile_id']
   *   - metrics: required [ga:pageviews]
   *   - dimensions: optional [default=none]
   *   - sort_metric: optional [default=none]
   *   - filters: optional [default=none]
   *   - segment: optional [default=none]
   *   - start_date: [default=-1 week]
   *   - end_date: optional [default=tomorrow]
   *   - start_index: [default=1]
   *   - max_results: optional [default=10,000].
   * @param array $cache_options
   *   An optional associative array containing:
   *   - cid: optional [default=md5 hash]
   *   - expire: optional [default=CACHE_TEMPORARY]
   *   - refresh: optional [default=FALSE].
   *
   * @return \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed
   *   A new GoogleAnalyticsCounterFeed object
   */
  public function reportData($params = [], $cache_options = []);

  /**
   * Get the count of pageviews for a path.
   *
   * @param string $path
   *   The path to look up
   * @return string
   */
  public function displayGaCount($path);

  /**
   * Programatically revoke token.
   */
  public function revoke();

  /**
   * Get the row count of a table, sometimes with conditions.
   *
   * @param string $table
   * @return mixed
   */
  public function getCount($table);

  /**
   * Get the the top twenty results for pageviews and pageview_totals.
   *
   * @param string $table
   * @return mixed
   */
  public function getTopTwentyResults($table);

  /**
   * Convert seconds to hours, minutes and seconds.
   */
  public function sec2hms($sec, $pad_hours = FALSE);

  /**
   * Prints a warning message when not authenticated.
   */
  public function notAuthenticatedMessage();

  /**
   * Revoke Google Authentication Message.
   *
   * @param $build
   * @return mixed
   */
  public function revokeAuthenticationMessage($build);
}