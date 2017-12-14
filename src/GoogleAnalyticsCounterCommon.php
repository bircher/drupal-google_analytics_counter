<?php

namespace Drupal\google_analytics_counter;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Class GoogleAnalyticsCounterCommon.
 *
 * @package Drupal\google_analytics_counter
 */
class GoogleAnalyticsCounterCommon {

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
   * The database connection to save the counters.
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
   * Constructs an Importer object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state of the drupal site.
   * @param \Drupal\Core\Database\Connection $connection
   *   A database connection for reading and writing the path counts.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The path alias manager to find aliased resources.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, Connection $connection, AliasManagerInterface $alias_manager, LanguageManagerInterface $language, LoggerInterface $logger) {
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->state = $state;
    $this->connection = $connection;
    $this->aliasManager = $alias_manager;
    $this->languageManager = $language;
    $this->logger = $logger;

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
    return time() + \Drupal::config('google_analytics_counter.settings')
      ->get('general_settings.cache_length');
  }

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

  public function beginAuthentication() {
    $gafeed = new GoogleAnalyticsCounterFeed();
    $gafeed->beginAuthentication($this->config->get('general_settings.client_id'), $this->getRedirectUri());
  }

  /**
   * Programatically revoke token.
   */
  public function revoke() {
    $this->state->deleteMultiple([
      'google_analytics_counter.access_token',
      'google_analytics_counter.expires_at',
      'google_analytics_counter.refresh_token',
    ]);
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

    // Look up the count via the hash of the path.
    $aliases = array_unique($aliases);
    $hashes = array_map('md5', $aliases);
    $pathcounts = $this->connection->select('google_analytics_counter', 'gac')
      ->fields('gac', ['pageviews'])
      ->condition('pagepath_hash', $hashes, 'IN')
      ->execute();
    $sum_of_pageviews = 0;
    foreach ($pathcounts as $pathcount) {
      $sum_of_pageviews += $pathcount->pageviews;
    }

    // Always save the data in our table.
    $this->connection->merge('google_analytics_counter_storage')
      ->key(['nid' => $nid])
      ->fields(['pageview_total' => $sum_of_pageviews])
      ->execute();

    // If we selected to override the storage of the statistics module.
    if ($this->config->get('general_settings.overwrite_statistics')) {
      $this->connection->merge('node_counter')
        ->key(['nid' => $nid])
        ->fields([
          'totalcount' => $sum_of_pageviews,
          'timestamp' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }

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

    // Non-DRY code.
    $step = $config->get('general_settings.data_step');
    $chunk = $config->get('general_settings.chunk_to_fetch');

    // Set the start_index .
    $pointer = $step * $chunk + 1;

    // Set the pointer.
    $pointer += $chunk;

    $parameters = [
      'profile_id' => 'ga:' . $config->get('general_settings.profile_id'),
      'metrics' => ['ga:pageviews'],
      'dimensions' => ['ga:pagePath'],
      'start_date' => !empty($config->get('general_settings.fixed_start_date')) ? strtotime($config->get('general_settings.fixed_start_date')) : strtotime($config->get('general_settings.start_date')),
      // If fixed dates are not in use, use 'tomorrow' to offset any timezone
      // shift between the hosting and Google servers.
      'end_date' => !empty($config->get('general_settings.fixed_end_date')) ? strtotime($config->get('general_settings.fixed_end_date')) : strtotime('tomorrow'),
//      'start_index' => ($config->get('general_settings.chunk_to_fetch') * $config->get('general_settings.data_step')) + 1,
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
      // http://drupal.org/node/310085
      $this->connection->merge('google_analytics_counter')
        ->key(['pagepath_hash' => md5($value['pagePath'])])
        ->fields([
          // Escape the path see https://www.drupal.org/node/2381703
          'pagepath' => htmlspecialchars($value['pagePath'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
          'pageviews' => htmlspecialchars($value['pageviews'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ])
        ->execute();
    }

    // Log the results.
    $this->logger->info($this->t('Saved @count paths from Google Analytics into the database.', ['@count' => count($feed->results->rows)]));
  }

  /**
   * Get the count of pageviews for a path.
   *
   * @param string $path
   *   The path to look up
   * @return string
   *   The count wrapped in a span.
   */
  public function displayGaCount($path) {

    // Make sure the path starts with a slash
    $path = '/'. trim($path, ' /');

    $path = $this->aliasManager->getAliasByPath($path);

    $query = $this->connection->select('google_analytics_counter', 'gac');
    $query->fields('gac', ['pageviews']);
    $query->condition('pagepath', $path);
    $pageviews = $query->execute()->fetchField();

    return number_format($pageviews);
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

    // The total number of nodes.
    $query = $this->connection->select('node', 'n');
    $query->addExpression('COUNT(nid)');
    $total_nodes = $query->execute()->fetchField();
    \Drupal::configFactory()
      ->getEditable('google_analytics_counter.settings')
      ->set('general_settings.total_nodes', $total_nodes)
      ->save();


    // Stay under the Google Analytics API quota by counting how many
    // API retrievals were made in the last 24 hours.
    // Todo We should take into consideration that the quota is reset at midnight PST (note: time() always returns UTC).
    $dayquota = $config->get('general_settings.dayquota.timestamp');
    if (\Drupal::time()->getRequestTime() - $dayquota >= 86400) {
      // If last API request was more than a day ago, set monitoring time to now.
      // Todo: The next two lines are not DRY.
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')->set('general_settings.dayquota.timestamp', \Drupal::time()->getRequestTime())->save();
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')->set('general_settings.dayquota.requests', 0)->save();
    }

    // Are we over the GA API limit?
    // See https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas
    $max_daily_requests = $config->get('general_settings.api_dayquota');
    if ($config->get('general_settings.dayquota.requests') > $max_daily_requests) {
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.settings', [], ['absolute' => TRUE])->toString(),
        '@href' => 'the Google Analytics Counter settings page',
        '%max_daily_requests' => $max_daily_requests,
        '%day_quota' => ($dayquota + 86400 - \Drupal::time()->getRequestTime()),
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

      // This was a live request. Increase the Google Analytics request limit tracker.
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')->set('general_settings.dayquota.timestamp', $config->get('general_settings.dayquota.timestamp'))->save();
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')->set('general_settings.dayquota.requests', ($config->get('general_settings.dayquota.requests') + 1))->save();

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
      else {
        foreach ($ga_feed->results->rows as $value) {
          $value['pagePath'] = SafeMarkup::checkPlain(utf8_encode($value['pagePath']));
          $value['pagePath'] = Unicode::substr($value['pagePath'], 0, 2048);

          db_merge('google_analytics_counter')
            ->key(['pagepath_hash' => md5($value['pagePath'])])
            ->fields([
              'pagepath' => $value['pagePath'],
              'pageviews' => SafeMarkup::checkPlain($value['pageviews']),
            ])
            ->execute();
        }
      }
    }

    // The total number of pagePaths for this profile from start_date to end_date
    $total_paths = $ga_feed->results->totalResults;
    // Store it in configuration.
    \Drupal::configFactory()->getEditable('google_analytics_counter.settings')->set('general_settings.total_paths', $total_paths)->save();

    // The total number of pageViews for this profile from start_date to end_date
    $total_pageviews = $ga_feed->results->totalsForAllResults['pageviews'];
    \Drupal::configFactory()->getEditable('google_analytics_counter.settings')->set('general_settings.total_pageviews', $total_pageviews)->save();

    // How many results to ask from Google Analytics in one request.
    // Default of 1000 to fit most systems (for example those with no external cron).
    $chunk = $config->get('general_settings.chunk_to_fetch');

    // In case there are more than $chunk path/counts to retrieve from
    // Google Analytics, do one chunk at a time and register that in $step.
    $step = $config->get('general_settings.data_step');

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
    if ($pointer <= $total_paths) {
      // If there are more results than what we've reached with this chunk,
      // increase step to look further during the next run.
      $new_step = $step + 1;
    }
    else {
      $new_step = 0;
    }

    \Drupal::configFactory()->getEditable('google_analytics_counter.settings')->set('general_settings.data_step', $new_step)->save();

    // Record how long this chunk took to process.
    \Drupal::configFactory()->getEditable('google_analytics_counter.settings')->set('general_settings.chunk_process_time', time() - $chunk_process_begin)->save();

    return $ga_feed;
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
    drupal_set_message($this->t('No Google Analytics profile has been authenticated! Google Analytics Counter cannot fetch any new data. Please <a href=:href>@href</a>.', $t_args), 'warning');
  }


}
