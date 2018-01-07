<?php

namespace Drupal\google_analytics_counter;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Class GoogleAnalyticsCounterManager.
 *
 * @package Drupal\google_analytics_counter
 */
class GoogleAnalyticsCounterManager {

  use StringTranslationTrait;

  /**
   * The google_analytics_counter.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The state where all the tokens are saved.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * The language manager to get all languages for to get all aliases.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var
   */
  protected $prefixes;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs an Importer object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state of the drupal site.
   * @param \Drupal\Core\Database\Connection $connection
   *   A database connection.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The path alias manager to find aliased resources.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, Connection $connection, AliasManagerInterface $alias_manager, PathMatcherInterface $path_matcher, LanguageManagerInterface $language, LoggerInterface $logger) {
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->state = $state;
    $this->connection = $connection;
    $this->aliasManager = $alias_manager;
    $this->pathMatcher = $path_matcher;
    $this->languageManager = $language;
    $this->logger = $logger;
    $this->time = \Drupal::service('datetime.time');

    $this->prefixes = [];
    // The 'url' will return NULL when it is not a multilingual site.
    $language_url = $config_factory->get('language.negotiation')->get('url');
    if ($language_url) {
      $this->prefixes = $language_url['prefixes'];
    }

  }

  /**
   * Check to make sure we are authenticated with google.
   *
   * @return bool
   *   True if there is a refresh token set.
   */
  public function isAuthenticated() {
    return $this->state->get('google_analytics_counter.access_token') != NULL ? TRUE : FALSE;
  }

  /**
   * Instantiate a new GoogleAnalyticsCounterFeed object.
   *
   * @return object
   *   GoogleAnalyticsCounterFeed object to authorize access and request data
   *   from the Google Analytics Core Reporting API.
   */
  public function newGaFeed() {
    $config = $this->config;

    if ($this->state->get('google_analytics_counter.access_token') && time() < $this->state->get('google_analytics_counter.expires_at')) {
      // If the access token is still valid, return an authenticated GAFeed.
      return new GoogleAnalyticsCounterFeed($this->state->get('google_analytics_counter.access_token'));
    }
    elseif ($this->state->get('google_analytics_counter.refresh_token')) {
      // If the site has an access token and refresh token, but the access
      // token has expired, authenticate the user with the refresh token.
      $client_id = $config->get('general_settings.client_id');
      $client_secret = $config->get('general_settings.client_secret');
      $refresh_token = $this->state->get('google_analytics_counter.refresh_token');

      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->refreshToken($client_id, $client_secret, $refresh_token);
        $this->state->setMultiple([
          'google_analytics_counter.access_token' => $gac_feed->accessToken,
          'google_analytics_counter.expires_at' => $gac_feed->expiresAt,
        ]);
        return $gac_feed;
      }
      catch (Exception $e) {
        drupal_set_message($this->t("There was an authentication error. Message: %message",
          ['%message' => $e->getMessage()]), 'error', FALSE
        );
        return NULL;
      }
    }
    elseif (isset($_GET['code'])) {
      // If there is no access token or refresh token and client is returned
      // to the config page with an access code, complete the authentication.
      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->finishAuthentication($config->get('general_settings.client_id'), $config->get('general_settings.client_secret'), $this->getRedirectUri());

        $this->state->setMultiple([
          'google_analytics_counter.access_token' => $gac_feed->accessToken,
          'google_analytics_counter.expires_at' => $gac_feed->expiresAt,
          'google_analytics_counter.refresh_token' => $gac_feed->refreshToken,
        ]);

        // ISSUE: Authentication is being lost when 'redirect_uri' is deleted.
        // WORK-AROUND: Don't delete the redirect_uri.
        // $this->state->delete('google_analytics_counter.redirect_uri');

        drupal_set_message(t('You have been successfully authenticated.'), 'status', FALSE);
      }
      catch (Exception $e) {
        drupal_set_message($this->t("There was an authentication error. Message: %message",
          ['%message' => $e->getMessage()]), 'error', FALSE
        );
        return NULL;
      }
    }

    return NULL;

  }

  /**
   * Get the redirect uri to redirect the google oauth request back to.
   *
   * @return string
   *   The redirect Uri from the configuration or the path.
   */
  public function getRedirectUri() {

    if ($this->config->get('general_settings.redirect_uri')) {
      return $this->config->get('general_settings.redirect_uri');
    }

    $https = FALSE;
    if (!empty($_SERVER['HTTPS'])) {
      $https = $_SERVER['HTTPS'] == 'on';
    }
    $url = $https ? 'https://' : 'http://';
    $url .= $_SERVER['SERVER_NAME'];
    if ((!$https && $_SERVER['SERVER_PORT'] != '80') || ($https && $_SERVER['SERVER_PORT'] != '443')) {
      $url .= ':' . $_SERVER['SERVER_PORT'];
    }

    return $url . Url::fromRoute('google_analytics_counter.admin_auth_form')->toString();
  }

  /**
   * Get the list of available web properties.
   *
   * @return array
   */
  public function getWebPropertiesOptions() {
    if ($this->isAuthenticated() !== TRUE) {
      // When not authenticated, there is nothing to get.
      return [];
    }

    $feed = $this->newGaFeed();

    $webprops = $feed->queryWebProperties()->results->items;
    $profiles = $feed->queryProfiles()->results->items;
    $options = [];

    // Add optgroups for each web property.
    if (!empty($profiles)) {
      foreach ($profiles as $profile) {
        $webprop = NULL;
        foreach ($webprops as $webprop_value) {
          if ($webprop_value->id == $profile->webPropertyId) {
            $webprop = $webprop_value;
            break;
          }
        }

        $options[$webprop->name][$profile->id] = $profile->name . ' (' . $profile->id . ')';
      }
    }

    return $options;
  }


  /**
   * Sets the expiry timestamp for cached queries. Default is 1 day.
   *
   * @return int
   *   The UNIX timestamp to expire the query at.
   */
  public static function cacheTime() {
    $config = \Drupal::config('google_analytics_counter.settings');
    return time() + $config->get('general_settings.cache_length');
  }

  public function beginAuthentication() {
    $gafeed = new GoogleAnalyticsCounterFeed();
    $gafeed->beginAuthentication($this->config->get('general_settings.client_id'), $this->getRedirectUri());
  }

  /**
   * Get the results from google.
   *
   * @param int $index
   *   The index of the chunk to fetch so that it can be queued.
   *
   * @return \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed
   *   The returned feed after the request has been made.
   */
  public function getChunkedResults($index = 0) {
    $config = $this->config;

    $step = $this->state->get('google_analytics_counter.data_step');
    $chunk = $config->get('general_settings.chunk_to_fetch');

    // Set the pointer.
    $pointer = $step * $chunk + 1;

    $parameters = [
      'profile_id' => 'ga:' . $config->get('general_settings.profile_id'),
      'metrics' => ['ga:pageviews'],
      'dimensions' => ['ga:pagePath'],
      'start_date' => !empty($config->get('general_settings.fixed_start_date')) ? strtotime($config->get('general_settings.fixed_start_date')) : strtotime($config->get('general_settings.start_date')),
      // If fixed dates are not in use, use 'tomorrow' to offset any timezone shift between the hosting and Google servers.
      'end_date' => !empty($config->get('general_settings.fixed_end_date')) ? strtotime($config->get('general_settings.fixed_end_date')) : strtotime('tomorrow'),
      'start_index' => $pointer,
      'max_results' => $config->get('general_settings.chunk_to_fetch'),
    ];

    $cachehere = [
      'cid' => 'google_analytics_counter_' . md5(serialize($parameters)),
      'expire' => self::cacheTime(),
      'refresh' => FALSE,
    ];

    return $this->reportData($parameters, $cachehere);
  }

  /**
   * Update the path counts.
   *
   * @param int $index
   *   The index of the chunk to fetch and update.
   *
   * This function is triggered by hook_cron().
   */
  public function updatePathCounts($index = 0) {
    $feed = $this->getChunkedResults($index);

    foreach ($feed->results->rows as $value) {

      // Google Analytics pagepaths that are extremely long are meaningless.
      $page_path = substr(htmlspecialchars($value['pagePath'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 0, 2047);
      $this->connection->merge('google_analytics_counter')
        ->key(['pagepath_hash' => md5($page_path)])
        ->fields([
          // Escape the path see https://www.drupal.org/node/2381703
          'pagepath' => $page_path,
          'pageviews' => SafeMarkup::checkPlain($value['pageviews']),
        ])
        ->execute();
    }

    // Log the results.
    $this->logger->info($this->t('Saved @count paths from Google Analytics into the database.', ['@count' => count($feed->results->rows)]));
  }

  /**
   * Save the pageview count for a given node.
   *
   * @param integer $nid
   *   The node id of the node for which to save the data.
   */
  public function updateStorage($nid) {

    // Get all the aliases for a given node id.
    $aliases = [];
    $path = '/node/' . $nid;
    $aliases[] = $path;
    foreach ($this->languageManager->getLanguages() as $language) {
      $alias = $this->aliasManager->getAliasByPath($path, $language->getId());
      $aliases[] = $alias;
      if (array_key_exists($language->getId(), $this->prefixes) && $this->prefixes[$language->getId()]) {
        $aliases[] = '/' . $this->prefixes[$language->getId()] . $path;
        $aliases[] = '/' . $this->prefixes[$language->getId()] . $alias;
      }
    }

    // Add also all versions with a trailing slash.
    $aliases = array_merge($aliases, array_map(function ($path) {
      return $path . '/';
    }, $aliases));

    // It's the front page
    // Todo: Could be brittle
    if ($nid == substr(\Drupal::configFactory()->get('system.site')->get('page.front'), 6)) {
      $sum_of_pageviews = $this->sumPageviews(['/']);
      $this->mergeGoogleAnalyticsCounterStorage($nid, $sum_of_pageviews);
    }
    else {
      $sum_of_pageviews = $this->sumPageviews(array_unique($aliases));
      $this->mergeGoogleAnalyticsCounterStorage($nid, $sum_of_pageviews);
    }

    // If we selected to override the storage with the statistics module.
    if ($this->config->get('general_settings.overwrite_statistics') == true) {

      // It's the front page
      // Todo: Could be brittle
      if ($nid == substr(\Drupal::configFactory()->get('system.site')->get('page.front'), 6)) {
        $sum_of_pageviews = $this->sumPageviews(['/']);
        $this->mergeNodeCounter($nid, $sum_of_pageviews);
      }
      else {
        $sum_of_pageviews = $this->sumPageviews(array_unique($aliases));
        $this->mergeNodeCounter($nid, $sum_of_pageviews);
      }
    }
  }

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
  public function reportData($params = array(), $cache_options = array()) {
    $config = $this->config;

    // Record how long this chunk took to process.
    $chunk_process_begin = time();

    // The total number of published nodes.
    $query = \Drupal::entityQuery('node');
    $query->condition('status', NodeInterface::PUBLISHED);
    $total_nodes = $query->count()->execute();
    $this->state->set('google_analytics_counter.total_nodes', $total_nodes);

    // Stay under the Google Analytics API quota by counting how many
    // API retrievals were made in the last 24 hours.
    // Todo We should take into consideration that the quota is reset at midnight PST (note: time() always returns UTC).
    $dayquota = $this->state->get('google_analytics_counter.dayquota_timestamp');
    if ($this->time->getRequestTime() - $dayquota >= 86400) {
      // If last API request was more than a day ago, set monitoring time to now.
      $this->state->set('google_analytics_counter.dayquota_timestamp', $this->time->getRequestTime());
      $this->state->set('google_analytics_counter.dayquota_request', 0);
    }

    // Are we over the GA API limit?
    // See https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas
    $max_daily_requests = $config->get('general_settings.api_dayquota');
    if ($this->state->get('google_analytics_counter.dayquota_request') > $max_daily_requests) {
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.admin_settings_form', [], ['absolute' => TRUE])->toString(),
        '@href' => 'the Google Analytics Counter settings page',
        '%max_daily_requests' => $max_daily_requests,
        '%day_quota' => ($dayquota + 86400 - $this->time->getRequestTime()),
      ];
      $this->logger->error('Google Analytics API quota of %max_daily_requests requests has been reached. The system will not fetch data from Google Analytics for the next %day_quota seconds. See <a href=:href>@href</a> for more info.', $t_args);
    }

    /* @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed $ga_feed */
    $ga_feed = $this->newGaFeed();
    if (!$ga_feed) {
      throw new \RuntimeException($this->t('The GoogleAnalyticsCounterFeed could not be initialized, is it authenticated?'));
    }

    $ga_feed->queryReportFeed($params, $cache_options);

//    DEBUG:
//    echo '<pre>';
//    // The returned object.
//    // print_r($ga_feed);
//    // Current Google Query.
//    print_r($ga_feed->results->selfLink);
//    echo '</pre>';
//    exit;

    // Handle errors here too.
    if (!empty($ga_feed->error)) {
      throw new \RuntimeException($ga_feed->error);
    }

    // Don't write anything to google_analytics_counter if this Google Analytics
    // data comes from cache (would be writing the same again).
    if (!$ga_feed->fromCache) {

      // This was a live request. Timestamp it.
      $this->state->set('google_analytics_counter.dayquota_timestamp', $this->time->getRequestTime());
      // Add the request to the dayquota_request.
      $this->state->set('google_analytics_counter.dayquota_request', $this->state->get('google_analytics_counter.dayquota_request') + 1);

      // If NULL then there is no error.
      if (!empty($ga_feed->error)) {
        $t_args = [
          ':href' => Url::fromRoute('google_analytics_counter.authentication', [], ['absolute' => TRUE])
            ->toString(),
          '@href' => 'here',
          '%new_data_error' => $ga_feed->error,
        ];
        $this->logger->error('Problem fetching data from Google Analytics: %new_data_error. Did you authenticate any Google Analytics profile? See <a href=:href>@href</a>.', $t_args);
      }
    }

    // The total number of pageViews for this profile from start_date to end_date
    $this->state->set('google_analytics_counter.total_pageviews', $ga_feed->results->totalsForAllResults['pageviews']);

    // The total number of pagePaths for this profile from start_date to end_date
    $total_paths = $ga_feed->results->totalResults;
    $this->state->set('google_analytics_counter.total_paths', $ga_feed->results->totalResults);

    // The most recent query to Google. Helpful for debugging.
    $this->state->set('google_analytics_counter.most_recent_query', $ga_feed->results->selfLink);

    // The last time the Data was refreshed by Google.
    $this->state->set('google_analytics_counter.data_last_refreshed', $ga_feed->results->dataLastRefreshed);

    // How many results to ask from Google Analytics in one request.
    // Default of 1000 to fit most systems (for example those with no external cron).
    $chunk = $config->get('general_settings.chunk_to_fetch');

    // In case there are more than $chunk path/counts to retrieve from
    // Google Analytics, do one chunk at a time and register that in $step.
    $step = $this->state->get('google_analytics_counter.data_step');

    // Which node to look for first. Must be between 1 - infinity.
    $pointer = $step * $chunk + 1;

    // Set the pointer.
    $pointer += $chunk;

    $t_args = [
      '@size_of' => sizeof($ga_feed->results->rows),
      '@first' => ($pointer - $chunk),
      '@second' => ($pointer - $chunk - 1 + sizeof($ga_feed->results->rows)),
    ];
    $this->logger->info('Retrieved @size_of items from Google Analytics data for paths @first - @second.', $t_args);

    // OK now increase or zero $step
    if ($pointer <= $ga_feed->results->totalResults) {
      // If there are more results than what we've reached with this chunk,
      // increase step to look further during the next run.
      $new_step = $step + 1;
    }
    else {
      $new_step = 0;
    }

    $this->state->set('google_analytics_counter.data_step', $new_step);

    // Record how long this chunk took to process.
    $this->state->set('google_analytics_counter.chunk_process_time', time() - $chunk_process_begin);

    return $ga_feed;
  }

  /**
   * Get the count of pageviews for a path.
   *
   * @param string $path
   *   The path to look up
   * @return string
   */
  public function displayGaCount($path) {
    // Make sure the path starts with a slash
    $path = '/'. trim($path, ' /');

    // It's the front page

    if ($this->pathMatcher->isFrontPage()) {
      $aliases = ['/'];
      $sum_of_pageviews = $this->sumPageviews($aliases);
    }
    else {
      // Look up the alias, with, and without trailing slash.
      $aliases = [
        $this->aliasManager->getAliasByPath($path),
        $path,
        $path . '/',
      ];

      $sum_of_pageviews = $this->sumPageviews($aliases);
    }

    return number_format($sum_of_pageviews);
  }

  /**
   * Look up the count via the hash of the pathes.
   *
   * @param $aliases
   * @return string
   */
  protected function sumPageviews($aliases) {

    // $aliases can make pageview_total greater than pageviews because $aliases
    // can include page aliases, node/id, and node/id/ URIs.

    $hashes = array_map('md5', $aliases);
    $path_counts = $this->connection->select('google_analytics_counter', 'gac')
      ->fields('gac', ['pageviews'])
      ->condition('pagepath_hash', $hashes, 'IN')
      ->execute();
    $sum_of_pageviews = 0;
    foreach ($path_counts as $path_count) {
      $sum_of_pageviews += $path_count->pageviews;
    }
    return $sum_of_pageviews;
  }

  /**
   * Programatically revoke token.
   */
  public function revoke() {
    $this->state->deleteMultiple([
      'google_analytics_counter.access_token',
      'google_analytics_counter.chunk_process_time',
      'google_analytics_counter.cron_next_execution',
      'google_analytics_counter.data_last_refreshed',
      'google_analytics_counter.data_step',
      'google_analytics_counter.dayquota_request',
      'google_analytics_counter.dayquota_timestamp',
      'google_analytics_counter.expires_at',
      'google_analytics_counter.most_recent_query',
      'google_analytics_counter.refresh_token',
      'google_analytics_counter.total_nodes',
      'google_analytics_counter.total_pageviews',
      'google_analytics_counter.total_paths',
    ]);
  }

  /****************************************************************************/
  // Query functions.
  /****************************************************************************/

  /**
   * Merge the sum of pageviews into google_analytics_counter_storage.
   *
   * @param $nid
   * @param $sum_of_pageviews
   */
  protected function mergeGoogleAnalyticsCounterStorage($nid, $sum_of_pageviews) {
    // Always save the data in our table.
    $this->connection->merge('google_analytics_counter_storage')
      ->key(['nid' => $nid])
      ->fields(['pageview_total' => $sum_of_pageviews])
      ->execute();
  }

  /**
   * Merge the sum of pageviews into statistics module's node_counter.

   * @param $nid
   * @param $sum_of_pageviews
   */
  protected function mergeNodeCounter($nid, $sum_of_pageviews) {
    $this->connection->merge('node_counter')
      ->key(['nid' => $nid])
      ->fields([
        'totalcount' => $sum_of_pageviews,
        'timestamp' => $this->time->getRequestTime(),
      ])
      ->execute();
  }
  /**
   * Get the row count of a table, sometimes with conditions.
   *
   * @param string $table
   * @return mixed
   */
  public function getCount($table) {
    switch ($table) {
      case 'google_analytics_counter_storage':
        $query = $this->connection->select($table, 't');
        $query->addField('t', 'field_pageview_total');
        $query->condition('pageview_total', 0, '>');
        break;
      case 'node_counter':
        $query = $this->connection->select($table, 't');
        $query->addField('t', 'field_totalcount');
        $query->condition('totalcount', 0, '>');
        break;
      case 'google_analytics_counter_storage_all_nodes':
        $query = $this->connection->select('google_analytics_counter_storage', 't');
        break;
      case 'queue':
        $query = $this->connection->select('queue', 'q');
        $query->condition('name', 'google_analytics_counter_worker', '=');
        break;
      default:
        $query = $this->connection->select($table, 't');
        break;
    }
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Get the the top twenty results for pageviews and pageview_totals.
   *
   * @param string $table
   * @return mixed
   */
  public function getTopTwentyResults($table) {
    $query = $this->connection->select($table, 't');
    $query->range(0, 20);
    $rows = [];
    switch ($table) {
      case 'google_analytics_counter':
        $query->fields('t', ['pagepath', 'pageviews']);
        $query->orderBy('pageviews', 'DESC');
        $result = $query->execute()->fetchAll();
        $rows = [];
        foreach ($result as $value) {
          $rows[] = [
            $value->pagepath,
            $value->pageviews,
          ];
        }
        break;
      case 'google_analytics_counter_storage':
        $query->fields('t', ['nid', 'pageview_total']);
        $query->orderBy('pageview_total', 'DESC');
        $result = $query->execute()->fetchAll();
        foreach ($result as $value) {
          $rows[] = [
            $value->nid,
            $value->pageview_total,
          ];
        }
        break;
      default:
        break;
    }

    return $rows;
  }

  /****************************************************************************/
  // Message functions.
  /****************************************************************************/

  /**
   * Convert seconds to hours, minutes and seconds.
   */
  public function sec2hms($sec, $pad_hours = FALSE) {

    // Start with a blank string.
    $hms = "";

    // Do the hours first: there are 3600 seconds in an hour, so if we divide
    // the total number of seconds by 3600 and throw away the remainder, we're
    // left with the number of hours in those seconds.
    $hours = intval(intval($sec) / 3600);

    // Add hours to $hms (with a leading 0 if asked for).
    $hms .= ($pad_hours)
      ? str_pad($hours, 2, "0", STR_PAD_LEFT) . "h "
      : $hours . "h ";

    // Dividing the total seconds by 60 will give us the number of minutes
    // in total, but we're interested in *minutes past the hour* and to get
    // this, we have to divide by 60 again and then use the remainder.
    $minutes = intval(($sec / 60) % 60);

    // Add minutes to $hms (with a leading 0 if needed).
    $hms .= str_pad($minutes, 2, "0", STR_PAD_LEFT) . "m ";

    // Seconds past the minute are found by dividing the total number of seconds
    // by 60 and using the remainder.
    $seconds = intval($sec % 60);

    // Add seconds to $hms (with a leading 0 if needed).
    $hms .= str_pad($seconds, 2, "0", STR_PAD_LEFT);

    // done!
    return $hms . 's';
  }

  /**
   * Prints a warning message when not authenticated.
   */
  public function notAuthenticatedMessage() {
    $t_args = [
      ':href' => Url::fromRoute('google_analytics_counter.admin_auth_form', [], ['absolute' => TRUE])
        ->toString(),
      '@href' => 'authenticate here',
    ];
    drupal_set_message($this->t('Google Analytics have not been authenticated! Google Analytics Counter cannot fetch any new data. Please <a href=:href>@href</a>.', $t_args), 'warning');
  }

  /**
   * Revoke Google Authentication Message.
   *
   * @param $build
   * @return mixed
   */
  public function revokeAuthenticationMessage($build) {
    $t_args = [
      ':href' => Url::fromRoute('google_analytics_counter.admin_auth_revoke', [], ['absolute' => TRUE])
        ->toString(),
      '@href' => 'Revoke Google authentication',
    ];
    $build['drupal_info']['revoke_authentication'] = [
      '#markup' => $this->t('<a href=:href>@href</a>. Useful in some cases, if in trouble with OAuth authentication.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
    return $build;
  }



}
