<?php
/**
 * @file
 * Parsing and writing the fetched data.
 */

namespace Drupal\google_analytics_counter;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Config\Config;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Exception;

/**
 * Class GoogleAnalyticsCounterCommon.
 *
 * @package Drupal\google_analytics_counter
 */
class GoogleAnalyticsCounterCommon {

  /**
   * Instantiate a new GoogleAnalyticsCounterFeed object.
   *
   * @return object
   *   GoogleAnalyticsCounterFeed object to authorize access and request data
   *   from the Google Analytics Core Reporting API.
   */
  public static function newGaFeed() {
    $config = \Drupal::config('google_analytics_counter.settings');

    if (\Drupal::state()->get('google_analytics_counter.access_token') && time() < \Drupal::state()->get('google_analytics_counter.expires_at')) {
      // If the access token is still valid, return an authenticated GAFeed.
      return new GoogleAnalyticsCounterFeed(\Drupal::state()->get('google_analytics_counter.access_token'));
    }
    elseif (\Drupal::state()->get('google_analytics_counter.refresh_token')) {
      // If the site has an access token and refresh token, but the access
      // token has expired, authenticate the user with the refresh token.
      $client_id = $config->get('client_id');
      $client_secret = $config->get('client_secret');
      $refresh_token = \Drupal::state()->get('google_analytics_counter.refresh_token');

      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->refreshToken($client_id, $client_secret, $refresh_token);
        \Drupal::state()->setMultiple([
          'google_analytics_counter.access_token' => $gac_feed->accessToken,
          'google_analytics_counter.expires_at' => $gac_feed->expiresAt,
        ]);
        return $gac_feed;
      }
      catch (Exception $e) {
        drupal_set_message(t("There was an authentication error. Message: %message",
          array('%message' => $e->getMessage())), 'error', FALSE
        );
        return NULL;
      }
    }
    elseif (isset($_GET['code'])) {
      // If there is no access token or refresh token and client is returned
      // to the config page with an access code, complete the authentication.
      $client_id = $config->get('client_id');
      $client_secret = $config->get('client_secret');
      $redirect_uri = $config->get('redirect_uri');

      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->finishAuthentication($client_id, $client_secret, $redirect_uri);

        \Drupal::state()->setMultiple([
          'google_analytics_counter.access_token' => $gac_feed->accessToken,
          'google_analytics_counter.expires_at' => $gac_feed->expiresAt,
          'google_analytics_counter.refresh_token' => $gac_feed->refreshToken,
        ]);
        \Drupal::state()->delete('google_analytics_counter.redirect_uri');
        drupal_set_message(t('You have been successfully authenticated.'), 'status', FALSE);
        $redirect = new RedirectResponse($redirect_uri);
        $redirect->send();
      }
      catch (Exception $e) {
        drupal_set_message(t("There was an authentication error. Message: %message",
          array('%message' => $e->getMessage())), 'error', FALSE
        );
        return NULL;
      }
    }
    else {
      return NULL;
    }
  }

  /**
   * Displays the count.
   */
  public static function displayGaCount($path = '') {
    if ($path == '') {
      // We need a path that includes the language prefix, if any.
      // E.g. en/my/path (of /en/my/path - the initial slash will be dealt with
      // later).
      // @TODO: Works OK on non-Apache servers?
      $path = parse_url("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", PHP_URL_PATH);
    }
    // Check all paths, to be sure.
    // $path = check_plain($path);
    $block_content = '';
    $block_content .= '<span class="google-analytics-counter">';
    $count = self::getSumPerPath($path);
    if ($count == '') {
      // If unknown, for some reason.
      // Better than t('N/A').
      $block_content .= 0;
    }
    else {
      $block_content .= $count;
    }
    $block_content .= '</span>';

    return $block_content;
  }

  /**
   * Sets the expiry timestamp for cached queries.Default is 1 day.
   *
   * @return int
   *   The UNIX timestamp to expire the query at.
   */
  public static function cacheTime() {
    return time() + \Drupal::config('google_analytics_counter.settings')
      ->get('cache_length');
  }

  /**
   * Convert seconds to hours, minutes and seconds.
   */
  public static function sec2hms($sec, $pad_hours = FALSE) {

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
   * Programatically revoke token.
   */
  public static function revoke() {
    $gac_feed = self::newGaFeed();
    if ($gac_feed->revokeToken()) {
      \Drupal::state()->setMultiple([
        'google_analytics_counter.access_token' => '',
        'google_analytics_counter.expires_at' => '',
        'google_analytics_counter.refresh_token' => '',
      ]);
      $url = '/admin/config/system/google-analytics-counter/dashboard';
      $redirect = new RedirectResponse($url);
      $redirect->send();
    }
    \Drupal::state()->setMultiple([
      'google_analytics_counter.access_token' => '',
      'google_analytics_counter.expires_at' => '',
      'google_analytics_counter.refresh_token' => '',
    ]);
  }

  /**
   * Get pageviews for nodes and write them either to the Drupal core table.
   *
   * Table: node_counter, or to the google_analytics_counter_storage.
   * This function is triggered by hook_cron().
   */
  public static function updateStorage() {
    $config = \Drupal::config('google_analytics_counter.settings');
    if ($config->get('storage') == 0
      && \Drupal::moduleHandler()->moduleExists('statistics')
      // See also https://www.drupal.org/node/2275575
    ) {
      // Using core node_counter table.
      $storage = 'node_counter';
    }
    else {
      // Using table google_analytics_counter_storage.
      $storage = 'google_analytics_counter_storage';
    }

    // @TODO: batch the node path processing.
    $db_results = db_select('node', 'n')
      ->fields('n', array('nid'))
      ->execute();
    foreach ($db_results as $db_result) {
      $path = '/node/' . $db_result->nid;

      // Get the count for this node (uncached).
      $sum_of_pageviews = self::getSumPerPath($path, FALSE);

      // Don't write zeroes.
      if ($sum_of_pageviews == 0) {
        continue;
      }

      // Write the count to the current storage table.
      if ($storage == 'node_counter') {
        db_merge('node_counter')
          ->key(array('nid' => $db_result->nid))
          ->fields(array(
            'daycount' => 0,
            'totalcount' => $sum_of_pageviews,
            'timestamp' => REQUEST_TIME,
          ))
          ->execute();
      }
      else {
        db_merge('google_analytics_counter_storage')
          ->key(array('nid' => $db_result->nid))
          ->fields(array(
            'pageview_total' => $sum_of_pageviews,
          ))
          ->execute();
      }
    }

    // @TODO: log the results..

  }

  /**
   * Find how many distinct paths does Google Analytics have for this profile.
   *
   * This function is triggered by hook_cron().
   */
  public static function updatePathCounts() {
    $config = \Drupal::config('google_analytics_counter.settings');

    // Needing to stay under the Google Analytics API quota,
    // let's count how many API retrievals were made in the last 24 hours.
    // @todo We should better take into consideration that the quota is reset at midnight PST (note: time() always returns UTC).
//    $dayquota = $config->get('dayquota');
//    if (REQUEST_TIME - $dayquota[0] >= 86400) {
//      // If last API request was more than a day ago,set monitoring time to now.
//      $dayquota[0] = REQUEST_TIME;
//      $dayquota[1] = 0;
//      $config_edit->set('dayquota', array(
//        $dayquota[0],
//        $dayquota[1],
//      ))
//        ->save();
//    }
    // Are we over the GA API limit?
//    $maxdailyrequests = $config->get('api_dayquota');
//    if ($dayquota[1] > $maxdailyrequests) {
//      \Drupal::logger('Google Analytics Counter')
//        ->error(t('Google Analytics API quota of %maxdailyrequests requests has been reached. Will NOT fetch data from Google Analytics for the next %dayquota seconds. See <a href="/admin/config/system/google_analytics_counter">the Google Analytics Counter settings page</a> for more info.', array(
//          '%maxdailyrequests' => SafeMarkup::checkPlain($maxdailyrequests),
//          '%dayquota' => SafeMarkup::checkPlain(($dayquota[0] + 86400 - REQUEST_TIME)),
//        ))->render());
//      return;
//    }

    // The earliest valid start-date for Google Analytics is 2005-01-01.
    $date_cycle = $config->get('date_cycle');
    $start_date = $date_cycle == 0
      ? strtotime('2015-01-01') : strtotime(date('Y-m-d', time())) - $date_cycle;

    // @TODO: make this configurable.
    $start_date = strtotime('-1 week');

    // @TODO: paginate the results.
    $request = array(
      'dimensions' => array('ga:pagePath'),
      // Date would not be necessary for totals, but we also calculate stats of
      // views per day, so we need it.
      'metrics' => array('ga:pageviews'),
      'start_date' => $start_date,
      'end_date' => strtotime('tomorrow'),
      // Using 'tomorrow' to offset any timezone shift
      // between the hosting and Google servers.
//      'start_index' => $pointer,
//      'max_results' => $chunk,
    );

    $cachehere = array(
      'cid' => 'google_analytics_counter_' . md5(serialize($request)),
      'expire' => self::cacheTime(),
      'refresh' => FALSE,
    );
    $new_data = @self::reportData($request, $cachehere);

    // Don't write anything to google_analytics_counter if this GA data comes
    // from cache (would be writing the same again).
    if (!$new_data->fromCache) {

      // This was a live request. Increase the GA request limit tracker.
      // @TODO: keep track of quota.

      // If NULL then there is no error.
      if (!empty($new_data->error)) {
        \Drupal::logger('Google Analytics Counter')
          ->error(t('Problem fetching data from Google Analytics: %new_dataerror.Did you authenticate any Google Analytics profile? See<a href="/admin/config/system/google-analytics-counter/authentication">here</a>.',
            array('%new_dataerror' => $new_data->error))->render()
          );
        // Nothing to do; return.
      }
      else {
        $resultsretrieved = $new_data->results->rows;
        foreach ($resultsretrieved as $val) {
          // http://drupal.org/node/310085
          db_merge('google_analytics_counter')
            ->key(array('pagepath_hash' => md5($val['pagePath'])))
            ->fields(array(
              'pagepath' => SafeMarkup::checkPlain($val['pagePath']),
              // Added check_plain; see https://www.drupal.org/node/2381703
              'pageviews' => SafeMarkup::checkPlain($val['pageviews']),
              // Added check_plain; see https://www.drupal.org/node/2381703
            ))
            ->execute();
        }
      }
    }

    // @TODO: log the results.
  }

  /**
   * Calculate pageviews for one path (with any aliases).
   */
  protected static function getSumPerPath($path, $cacheon = TRUE) {
    // Recognize special path 'all' to get the sum of all pageviews
    // for the profile.
    if ($path == 'all') {
      return \Drupal::config('google_analytics_counter.settings')->get('totalhits');
    }

    // Esp. in case function is called directly.
    $path = SafeMarkup::checkPlain($path)->jsonSerialize();

    // Get list of allowed languages to detect front pages
    // such as http://mydomain.tld/en.
    // Must come AFTER the possible initial slash is removed!
    $langs = \Drupal::languageManager()->getLanguages();
    $frontpages = array();
    foreach ($langs as $lang => $object) {
      $frontpages[] = $lang;
    }
    $frontpages[] = '';
    $frontpages[] = '/';

    if (in_array($path, $frontpages)) {
      $path = \Drupal::config('system.site')->get('page.front');
    }

    // If it's a node we'll distinguish the language part of it, if any.
    // Either format en/node/55 or node/55.
    $split_path = explode('/', trim($path, '/'));
    $lang_prefix = '';
    if ((count($split_path) == 3 and strlen($split_path[0]) == 2
        and $split_path[1] == 'node' and is_numeric($split_path[2]))
      or
      (count($split_path) == 2 and $split_path[0] == 'node' and is_numeric($split_path[1]))
    ) {
      if (count($split_path) == 3) {
        $nidhere = $split_path[2];
      }
      else {
        if (count($split_path) == 2) {
          $nidhere = $split_path[1];
        }
      }
      $db_results = db_select('node', 'n')
        ->fields('n', array('nid', 'langcode'))
        ->condition('nid', $nidhere, '=')
        ->execute();
      foreach ($db_results as $db_result) {
        if ($db_result->langcode <> 'und' and $db_result->langcode <> '') {
          $lang_prefix = $db_result->langcode;
          // If this is a language-prefixed node we need its path without
          // the prefix for later.
          if (count($split_path) == 3) {
            $path = '/' . $split_path[1] . '/' . $split_path[2];
          }
        }
        // Is just 1 result anyway.
        break;
      }
    }

    if ($lang_prefix == '') {
      // E.g. en/view or nl/my/view or xx/view.
      if (count($split_path) > 1 and strlen($split_path[0]) == 2 and !is_numeric($split_path[0])) {

        // Now we need to find which nid does it correspond
        // (the language prefix + the alias).
        $without_prefix = $split_path;
        $lang = array_shift($without_prefix);
        $without_prefix = '/' . implode('/', $without_prefix);
        $node_path = \Drupal::service('path.alias_manager')
          ->getPathByAlias($without_prefix);
        if ($node_path !== FALSE) {
          $path = $node_path;
          $lang_prefix = $lang;
        }
      }

      // Now, it's also possible that it's a node alias but without prefix!
      // E.g. my/path but in fact it's en/node/nid!
      $node_path = \Drupal::service('path.alias_manager')
        ->getPathByAlias($path);
      if ($node_path !== FALSE) {
        $path = $node_path;
        $split_node_path = explode('/', trim($node_path, "/"));
        if (count($split_node_path) == 2 and $split_node_path[0] == 'node' and is_numeric($split_node_path[1])) {
          $db_results = db_select('node', 'n')
            ->fields('n', array('nid', 'langcode'))
            ->condition('nid', $split_node_path[1], '=')
            ->execute();
          foreach ($db_results as $db_result) {
            if ($db_result->langcode <> 'und' and $db_result->langcode <> '') {
              $lang_prefix = $db_result->langcode;
            }
            // Is just 1 result anyway.
            break;
          }
        }
      }
    }

    // But it also could be a redirect path!
    // @todo The module don't has drupal 8 revision.
    if (function_exists('redirect_load_by_source')) {
      $path_no_slashes_at_ends = trim($path, '/');
      $redirect_object = redirect_load_by_source($path_no_slashes_at_ends, $GLOBALS['language']->language, drupal_get_query_parameters());
      if (is_object($redirect_object)) {
        if (is_string($redirect_object->redirect)) {
          $path = $redirect_object->redirect;
        }
        if (is_string($redirect_object->language)) {
          $lang_prefix = $redirect_object->language;
        }
      }
    }

    // All right, finally we can calculate the sum of pageviews.
    // This process is cached.
    $cacheid = md5($lang_prefix . $path);
    if ($cache = \Drupal::cache()
        ->get('google_analytics_counter_page_' . $cacheid) and $cacheon
    ) {
      $sum_of_pageviews = $cache->data;
    }
    else {
      // Get pageviews for this path and all its aliases.
      // NOTE: Here $path does NOT have an initial slash because it's coming
      // from either check_plain($_GET['q']) (block) or from a tag like
      // [gac|node/N]. Remove a trailing slash (e.g. from node/3/) otherwise
      // _google_analytics_counter_path_aliases() does not find anything.
      $unprefixedaliases = self::pathAliases($path);
      $allpaths = array();
      $allpaths_dpm = array();
      foreach ($unprefixedaliases as $val) {
        // Google Analytics stores initial slash as well, so let's prefix them.
        // With language prefix, if available, e.g. /en/node/55.
        $url_lang = empty($lang_prefix) ? '' : '/' . $lang_prefix;
        $allpaths[] = md5($url_lang . $val);
        $allpaths_dpm[] = $url_lang . $val;
        // And its variant with trailing slash
        // (https://www.drupal.org/node/2396057).
        // With language prefix, if available, e.g. /en/node/55.
        $allpaths[] = md5($url_lang . $val . '/');
        $allpaths_dpm[] = $url_lang . $val . '/';
        if ($lang_prefix <> '') {
          // Now, if we are counting NODE with language prefix, we also need to
          // count the pageviews for that node without the prefix --
          // it could be that before it had no language prefix
          // but it still was the same node!
          // BUT this will not work for non-nodes, e.g. views.
          // There we depend on the path
          // e.g. /en/myview because it would be tricky to get a valid language
          // prefix out of the path. E.g. /en/myview could be a path of a view
          // where "en" does not mean the English language. In other words,
          // while prefix before node/id does not change the page
          // (it's the same node), with views or other custom pages the prefix
          // may actually contain completely different content.
          $allpaths[] = md5($val);
          $allpaths_dpm[] = $val;
          // And its variant with trailing slash
          // (https://www.drupal.org/node/2396057).
          $allpaths[] = md5($val . '/');
          $allpaths_dpm[] = $val . '/';
          // @TODO ... obviously, here we should treat the possibility of the NODE/nid having a different language prefix. A niche case (how often do existing NODES change language?)
        }
      }

      // Find possible redirects for this path using redirect_load_multiple()
      // from module Redirect http://drupal.org/project/redirect.
      // @todo Redirect module is currently being ported to Drupal 8,
      // @todo but is not usable yet.
      if (function_exists('redirect_load_multiple')) {
        $redirectobjects = redirect_load_multiple(FALSE, array('redirect' => $path));
        foreach ($redirectobjects as $redirectobject) {
          $allpaths[] = md5('/' . $redirectobject->source);
          $allpaths_dpm[] = '/' . $redirectobject->source;
          // And its variant with trailing slash
          // (https://www.drupal.org/node/2396057).
          $allpaths[] = md5('/' . $redirectobject->source . '/');
          $allpaths_dpm[] = '/' . $redirectobject->source . '/';
          $allpaths[] = md5('/' . $redirectobject->language . '/' . $redirectobject->source);
          $allpaths_dpm[] = '/' . $redirectobject->language . '/' . $redirectobject->source;
          // And its variant with trailing slash
          // (https://www.drupal.org/node/2396057).
          $allpaths[] = md5('/' . $redirectobject->language . '/' . $redirectobject->source . '/');
          $allpaths_dpm[] = '/' . $redirectobject->language . '/' . $redirectobject->source . '/';
        }
      }

      // Very useful for debugging. In face each variant: node/NID, alias,
      // redirect, non-node ... with or without trailing slash,
      // with or without language ... should always give the same count
      // (sum of counts of all path variants).
      // Get path counts for each of the path aliases.
      // Search hash values of path -- faster (primary key). E.g.
      // SELECT pageviews FROM `google_analytics_counter` where pagepath_hash
      // IN ('ee1c787bc14bec9945de3240101e99','d884e66c2316317ef6294dc12aca9c').
      $pathcounts = db_select('google_analytics_counter', 'gac')
        ->fields('gac', array('pageviews'))
        ->condition('pagepath_hash', $allpaths, 'IN')
        ->execute();
      $sum_of_pageviews = 0;
      foreach ($pathcounts as $pathcount) {
        $sum_of_pageviews += $pathcount->pageviews;
      }

      \Drupal::cache()
        ->set('google_analytics_counter_page_' . $cacheid, $sum_of_pageviews);
    }

    return $sum_of_pageviews;
  }

  /**
   * Return a list of paths that are aliased with the given path.
   */
  private static function pathAliases($node_path) {

    // Get the normal node path if it is a node.].
    $node_path = \Drupal::service('path.alias_manager')
      ->getPathByAlias($node_path);

    // Grab all aliases.
    $aliases = array($node_path);

    $result = db_query("SELECT * FROM {url_alias} WHERE source = :source",
      array(':source' => $node_path)
    )->fetchAll();
    foreach ($result as $row) {
      $aliases[] = $row->alias;
    }

    // If this is the front page, add the base path too,
    // and index.php for good measure.
    // There may be other ways that the user is accessing the front page
    // but we can't account for them all.
    if ($node_path == \Drupal::config('system.site')->get('page.front')) {
      $aliases[] = '';
      $aliases[] = '/';
      $aliases[] = 'index.php';
    }

    return $aliases;
  }

  /**
   * Request report data.
   *
   * @param array $params
   *   An associative array containing:
   *   - profile_id: required [default=config('profile_id')]
   *   - metrics: required.
   *   - dimensions: optional [default=none]
   *   - sort_metric: optional [default=none]
   *   - filters: optional [default=none]
   *   - segment: optional [default=none]
   *   - start_date: optional [default=GA release date]
   *   - end_date: optional [default=today]
   *   - start_index: optional [default=1]
   *   - max_results: optional [default=10,000].
   * @param array $cache_options
   *   An optional associative array containing:
   *   - cid: optional [default=md5 hash]
   *   - expire: optional [default=CACHE_TEMPORARY]
   *   - refresh: optional [default=FALSE].
   *
   * @return object
   *   A new GoogleAnalyticsCounterFeed object
   */
  private static function reportData($params = array(), $cache_options = array()) {
    $params_defaults = array(
      'profile_id' => 'ga:' . \Drupal::config('google_analytics_counter.settings')->get('profile_id'),
    );

    $params += $params_defaults;

    $ga_feed = self::newGaFeed();
    $ga_feed->queryReportFeed($params, $cache_options);

    return $ga_feed;
  }

}
