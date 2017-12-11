<?php

/**
 * @file
 * Parsing and writing the fetched data.
 */

namespace Drupal\google_analytics_counter;

use Drupal\Component\Utility\Unicode;
use \Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use \Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\SafeMarkup;

class GoogleAnalyticsCounterDashboard {

  use StringTranslationTrait;

  /**
   * The google_analytics_counter.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $connection;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon definition.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon
   */
  protected $common;

  /**
   * Constructs a GoogleAnalyticsCounterDashboard object.
   *
   * @param \Drupal\Core\Database\Driver\mysql\Connection $connection
   *   A database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $connection, LoggerInterface $logger, GoogleAnalyticsCounterCommon $common) {
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->connection = $connection;
    $this->logger = $logger;
    $this->common = $common;
  }


  /**
   * Find how many distinct paths does Google Analytics have for this profile.
   * This service is triggered by hook_cron().
   */
  public function updateDashboard() {
    $config = $this->config;

    // Record how long this chunk took to process.
    $chunk_process_begin = time();

    // Stay under the Google Analytics API quota by counting how many
    // API retrievals were made in the last 24 hours.
    // Todo We should take into consideration that the quota is reset at midnight PST (note: time() always returns UTC).
    $dayquota = $config->get('general_settings.dayquota.timestamp');
    if (\Drupal::time()->getRequestTime() - $dayquota >= 86400) {
      // If last API request was more than a day ago, set monitoring time to now.
      // This code smells bad.
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.dayquota.timestamp', \Drupal::time()
          ->getRequestTime())
        ->save();
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.dayquota.requests', 0)
        ->save();

      // This code smells better but doesn't work.
      // \Drupal::configFactory()
      //   ->getEditable('google_analytics_counter.settings')
      //   ->set('general_settings.dayquota', [\Drupal::time()->getRequestTime(), 0])
      //   ->save();
    }

    // Are we over the GA API limit?
    // See https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas
    $max_daily_requests = $config->get('general_settings.api_dayquota');
    if ($config->get('general_settings.dayquota.requests') > $max_daily_requests) {
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.settings', [], ['absolute' => TRUE])
          ->toString(),
        '@href' => 'the Google Analytics Counter settings page',
        '%max_daily_requests' => $max_daily_requests,
        '%day_quota' => ($dayquota + 86400 - \Drupal::time()->getRequestTime()),
      ];
      $this->logger->error('Google Analytics API quota of %max_daily_requests requests has been reached. The system will not fetch data from Google Analytics for the next %day_quota seconds. See <a href=:href>@href</a> for more info.', $t_args);
      return;
    }

    // How many results to ask from Google Analytics in one request.
    // Default of 1000 to fit most systems (for example those with no external cron).
    $chunk = $config->get('general_settings.chunk_to_fetch');

    // In case there are more than $chunk path/counts to retrieve from
    // Google Analytics, do one chunk at a time and register that in $step.
    $step = $config->get('general_settings.data_step');

    // Which node to look for first. Must be between 1 - infinity.
    $pointer = $step * $chunk + 1;
    $start_date = $config->get('general_settings.start_date');

    // The earliest valid start-date for Google Analytics is 2005-01-01.
    $request = [
      'dimensions' => ['ga:pagePath'],
      'metrics' => ['ga:pageviews'],
      // start_date would not be necessary for totals,
      // but we also calculate stats of views per day, so we need it.
      'start_date' => strtotime($start_date),
      // Using 'tomorrow' to offset any timezone shift between the hosting
      // and Google servers.
      'end_date' => strtotime('tomorrow'),
      'start_index' => $pointer,
      'max_results' => $chunk,
      //'filters' => 'ga:pagePath==/node/3',
      // We want to retrieve all page views for this path.
      // The earliest valid start-date for Google Analytics is 2005-01-01.
      //'#start_date' => strtotime('2005-01-01'),
      //'sort_metric' => array('ga:date'),
    ];

    $result_count = FALSE;
    $cache_here = [
      'cid' => 'google_analytics_counter_' . md5(serialize($request)),
      'expire' => time() + $config->get('general_settings.cache_length'),
      'refresh' => FALSE,
    ];

    $new_data = $this->common->reportData($request, $cache_here);
    // DEBUG:
    // echo '<pre>';
    // print_r($new_data);
    // echo '</pre>';

    // Don't write anything to google_analytics_counter if this Google Analytics
    // data comes from cache (would be writing the same again).
    if (!$new_data->fromCache) {
      // This was a live request. Increase the Google Analytics request limit tracker.

      // This code smells bad.
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.dayquota.timestamp', $config->get('general_settings.dayquota.timestamp'))
        ->save();
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.dayquota.requests', ($config->get('general_settings.dayquota.requests') + 1))
        ->save();

      // This code smells better but doesn't work.
//      \Drupal::configFactory()
//        ->getEditable('google_analytics_counter.settings')
//        ->set('general_settings.dayquota', [
//          $config->get('general_settings.dayquota.timestamp'),
//          $config->get('general_settings.dayquota.requests') + 1
//        ])
//        ->save();

      // If NULL then there is no error.
      if (!empty($new_data->error)) {
        $t_args = [
          ':href' => Url::fromRoute('google_analytics_counter.authentication', [], ['absolute' => TRUE])
            ->toString(),
          '@href' => 'here',
          '%new_data_error' => $new_data->error,
        ];
        $this->logger->error('Problem fetching data from Google Analytics: %new_data_error. Did you authenticate any Google Analytics profile? See <a href=:href>@href</a>.', $t_args);
      }
      else {
        $results_retrieved = $new_data->results->rows;

        foreach ($results_retrieved as $val) {

          $val['pagePath'] = SafeMarkup::checkPlain(utf8_encode($val['pagePath']));
          $val['pagePath'] = Unicode::substr($val['pagePath'], 0, 2048);

          db_merge('google_analytics_counter')
            ->key(['pagepath_hash' => md5($val['pagePath'])])
            ->fields([
              'pagepath' => $val['pagePath'],
              'pageviews' => SafeMarkup::checkPlain($val['pageviews']),
            ])
            ->execute();
        }
      }

      // Record how long did this chunk take to process.
      $chunk_process_begin = time();

      // The total number of nodes.
      $query = $this->connection->select('node', 'n');
      $query->addExpression('COUNT(nid)');
      $result_count = $query->execute()->fetchField();
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.total_nodes', $result_count)
        ->save();

      // How many node counts to update one cron run.
      // We use the same chunk size as when getting paths in google_analytics_counter_update_path_counts().
      $chunk = $config->get('general_settings.chunk_to_fetch');
      // In case there are more than $chunk nodes to process, do just one chunk at a time and register that in $step.
      $step = $config->get('general_settings.node_data_step');
      // Which node to look for first. Must be between 0 - infinity.
      $pointer = $step * $chunk;

      $query = $this->connection->select('node', 'n');
      $query->fields('n', ['nid']);
      $query->range($pointer, $chunk);
      $result = $query->execute();

      $storage = '';
      while ($record = $result->fetchAssoc()) {
        $path = 'node/' . $record['nid'];
//      echo $path . "\n";

      }

      // The total number of records for this profile.
      $result_count = $new_data->results->totalResults;
      // Store it in configuration.
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.total_paths', $result_count)
        ->save();

      // The total number of hits for all records for this profile.
      $total_hits = $new_data->results->totalsForAllResults['pageviews'];
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.total_hits', $total_hits)
        ->save();

      // Set the pointer.
      $pointer += $chunk;

      $t_args = [
        '%size_of' => sizeof($new_data->results->rows),
        '%first' => ($pointer - $chunk),
        '%second' => ($pointer - $chunk - 1 + sizeof($new_data->results->rows)),
      ];
      $this->logger->info('Retrieved %size_of items from Google Analytics data for paths %first - %second.', $t_args);

      // OK now increase or zero $step
      if ($pointer <= $result_count) {
        // If there are more results than what we've reached with this chunk,
        // increase step to look further during the next run.
        $new_step = $step + 1;
      }
      else {
        $new_step = 0;
      }
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.data_step', $new_step)
        ->save();

      // Record how long this chunk took to process.
      \Drupal::configFactory()
        ->getEditable('google_analytics_counter.settings')
        ->set('general_settings.chunk_process_time', time() - $chunk_process_begin)
        ->save();
    }
  }

//    /**
//     * Calculate pageviews for one path (with any aliases).
//     *
//     * @param $path
//     * @param bool $cacheon
//     * @return int
//     */
//    public
//    function googleAnalyticsCounterGetSumPerPath($path, $cacheon = TRUE) {
//
//      dpm($path, 'requested path');
//
//      // Recognize special path 'all' to get the sum of all pageviews for the profile.
//      if ($path == 'all') {
//        return \Drupal::configFactory()
//          ->getEditable('google_analytics_counter.settings')
//          ->get('google_analytics_counter_totalhits');
//      }
//
//      $path = SafeMarkup::checkPlain($path); // Esp. in case function is called directly.
//
//      // Remove initial slash, if any.
//      if (substr($path, 0, 1) == '/') {
//        $path = substr($path, 1);
//      }
//
//      // Get list of allowed languages to detect front pages such as http://mydomain.tld/en
//      // Must come AFTER the possible initial slash is removed!
//      $languages = \Drupal::languageManager()->getLanguages();
//      $frontpages = [];
//      foreach ($languages as $language => $object) {
//        $frontpages[] = $language;
//      }
//      $frontpages[] = '';
//      $frontpages[] = '/';
////    dpm($frontpages, '$frontpages');
//      if (in_array($path, $frontpages)) {
//        // This is the home page!
//        $path = \Drupal::configFactory()
//          ->getEditable('system.site')
//          ->get('name');
//      }
//
//      //If it's a node we'll distinguish the language part of it, if any. Either format en/node/55 or node/55.
//      $path_no_slashes_at_ends = trim($path, '/');
//      $splitpath = explode('/', $path_no_slashes_at_ends);
//      //dpm($splitpath, '$splitpath');
//      $lang_prefix = '';
//      // Get the nid
//      $alias = \Drupal::service('path.alias_manager')->getPathByAlias($path);
//      $params = Url::fromUri("internal:/" . $alias)->getRouteParameters();
//      $entity_type = key($params);
//      $node = \Drupal::entityTypeManager()
//        ->getStorage($entity_type)
//        ->load($params[$entity_type]);
//      dpm($node->nid->value, '$nid');
//
//      if ($node->nid->value) {
//
//        $dbresults = db_select('node_field_data', 'nfd')
//          ->fields('nfd', ['nid', 'langcode'])
//          ->condition('nid', $node->nid->value, '=')
//          ->execute();
//        foreach ($dbresults as $dbresult) {
//          if ($dbresult->langcode != 'en' AND $dbresult->langcode != '') {
//            $lang_prefix = $dbresult->langcode . '/';
//            // If this is a language-prefixed node we need its path without the prefix for later.
//            if (sizeof($splitpath) == 3) {
//              $path = $splitpath[1] . '/' . $splitpath[2];
//            }
//          }
//          break; // Is just 1 result anyway.
//        }
//        //dpm($path, 'detected NODE path');
//        //dpm($lang_prefix, 'detected NODE prefix:');
//      }
//
//      // Now if it's a node but has a prefixed or unprefixed alias, e.g. en/my/path
//      // or my/path, we should also try to determine if it's a node and then count
//      // it's node/nid with it!
//      if ($lang_prefix == '') {
//        if (sizeof($splitpath) > 1 AND strlen($splitpath[0]) == 2 AND !is_numeric($splitpath[0])) { // E.g. en/view or nl/my/view or xx/view
//          // Now we need to find which nid does it correspond (the language prefix + the alias)
//          $withoutprefix = $splitpath;
//          $language = array_shift($withoutprefix);
//          $withoutprefix = implode('/', $withoutprefix);
//          dpm($withoutprefix, 'withoutprefix');
//
//          $nodepath = \Drupal::service('path.alias_manager')
//            ->getPathByAlias($withoutprefix);
//
//          dpm($nodepath, 'system path for alias');
//          if ($nodepath !== FALSE) {
//            $path = $nodepath;
//            $lang_prefix = $language . '/';
//          }
//          dpm($path, 'detected ALIAS path');
//          dpm($lang_prefix, 'detected ALIAS prefix');
//        }
//      }
//
//      //Now, it's also possible that it's a node alias but without prefix! E.g. my/path but in fact it's en/node/nid!
//      if ($lang_prefix == '') {
//        $path_no_slashes_at_ends = trim($path, '/');
//        $nodepath = \Drupal::service('path.alias_manager')
//          ->getPathByAlias($path_no_slashes_at_ends);
//        dpm($path_no_slashes_at_ends, 'path_no_slashes_at_ends');
//        dpm($nodepath, 'nodepath');
//        if (!empty($nodepath)) {
//          $path = $nodepath;
//          $splitnodepath = explode('/', $nodepath);
//          dpm($splitnodepath, 'splitnodepath');
//          if ($node->nid->value) {
//            $result = \Drupal::database()->select('node_field_data', 'n')
//              ->fields('n', ['nid', 'langcode'])
//              ->condition('nid', $node->nid->value, '=')
//              ->execute();
//            while ($record = $result->fetchAssoc()) {
//              if ($record['langcode'] != 'en' AND $record['langcode'] != '') {
//                $lang_prefix = $record['langcode'] . '/';
//              }
//              break; // Is just 1 result anyway.
//            }
//            //$lang_prefix = $lang.'/';
//          }
//          dpm($path, 'detected NODE path from ALIAS');
//          dpm($lang_prefix, 'detected NODE prefix from ALIAS');
//        }
//      }
//
//      // But it also could be a redirect path!
////    if (function_exists('redirect_load_by_source')){
////      $path_no_slashes_at_ends = trim($path, '/');
////      $redirect_object = redirect_load_by_source_alter($path_no_slashes_at_ends, $GLOBALS['language']->language, UrlHelper::filterQueryParameters());
////      //dpm($redirect_object);
////      if (is_object($redirect_object)){
////        //dpm('gotten from redirect object: '.$redirect_object->redirect);
////        if (is_string($redirect_object->redirect)){
////          $path = $redirect_object->redirect;
////        }
////        if (is_string($redirect_object->language)){
////          $lang_prefix = $redirect_object->language.'/';
////        }
////        //dpm('detected NODE path from REDIRECT: '.$path);
////        //dpm('detected NODE prefix from REDIRECT: '.$lang_prefix);
////      }
////    }
//
//      // All right, finally we can calculate the sum of pageviews. This process is cached.
//      $cacheid = md5($lang_prefix . $path);
//      // $cacheon = FALSE; // Useful for debugging.
//      $cache = \Drupal::cache('bootstrap');
//      if ($cache = $cache->get('google_analytics_counter_page_' . $cacheid) AND $cacheon) {
//        $sum_of_pageviews = $cache->data;
////      dpm('CACHED');
//      }
//      else {
//        // Get pageviews for this path and all its aliases.
//        /*
//         * NOTE: Here $path does NOT have an initial slash because it's coming from either SafeMarkup::checkPlain($_GET['q']) (block) or from a tag like [gac|node/N].
//         * Remove a trailing slash (e.g. from node/3/) otherwise googleAnalyticsCounterPathAliases() does not find anything.
//         */
//        $path_no_slashes_at_ends = trim($path, '/');
//        $unprefixedaliases = GoogleAnalyticsCounterData::googleAnalyticsCounterPathAliases($path_no_slashes_at_ends);
//        $allpaths = [];
//        $allpaths_dpm = [];
//        foreach ($unprefixedaliases as $val) {
//          // Google Analytics stores initial slash as well, so let's prefix them.
//          $allpaths[] = md5('/' . $lang_prefix . $val); // With language prefix, if available, e.g. /en/node/55
//          $allpaths_dpm[] = '/' . $lang_prefix . $val;
//          // And its variant with trailing slash (https://www.drupal.org/node/2396057)
//          $allpaths[] = md5('/' . $lang_prefix . $val . '/'); // With language prefix, if available, e.g. /en/node/55
//          $allpaths_dpm[] = '/' . $lang_prefix . $val . '/';
//          if ($lang_prefix <> '') {
//            // Now, if we are counting NODE with language prefix, we also need to count the pageviews for that node without the prefix -- it could be that before it had no language prefix but it still was the same node!
//            // BUT this will not work for non-nodes, e.g. views. There we depend on the path e.g. /en/myview because it would be tricky to get a valid language prefix out of the path. E.g. /en/myview could be a path of a view where "en" does not mean the English language. In other words, while prefix before node/id does not change the page (it's the same node), with views or other custom pages the prefix may actually contain completely different content.
//            $allpaths[] = md5('/' . $val);
//            $allpaths_dpm[] = '/' . $val;
//            // And its variant with trailing slash (https://www.drupal.org/node/2396057)
//            $allpaths[] = md5('/' . $val . '/');
//            $allpaths_dpm[] = '/' . $val . '/';
//            // @TODO ... obviously, here we should treat the possibility of the NODE/nid having a different language prefix. A niche case (how often do existing NODES change language?)
//          }
//        }
//
//        // Find possible redirects for this path using redirect_load_multiple()
//        // from module Redirect http://drupal.org/project/redirect
////      if (function_exists('redirect_load_multiple')) {
////        $path_no_slashes_at_ends = trim($path, '/');
////        $redirectobjects = redirect_load_multiple(FALSE, array('redirect' => $path_no_slashes_at_ends));
////        foreach($redirectobjects as $redirectobject){
////          $allpaths[] = md5('/' . $redirectobject->source);
////          $allpaths_dpm[] = '/' . $redirectobject->source;
////          // And its variant with trailing slash (https://www.drupal.org/node/2396057)
////          $allpaths[] = md5('/' . $redirectobject->source . '/');
////          $allpaths_dpm[] = '/' . $redirectobject->source . '/';
////          $allpaths[] = md5('/' . $redirectobject->language . '/' . $redirectobject->source);
////          $allpaths_dpm[] = '/' . $redirectobject->language . '/' . $redirectobject->source;
////          // And its variant with trailing slash (https://www.drupal.org/node/2396057)
////          $allpaths[] = md5('/' . $redirectobject->language . '/' . $redirectobject->source . '/');
////          $allpaths_dpm[] = '/' . $redirectobject->language . '/' . $redirectobject->source . '/';
////        }
////      }
//
//        // Very useful for debugging. In face each variant: node/NID, alias,
//        // redirect, non-node ... with or without trailing slash, with or without
//        // language ... should always give the same count (sum of counts of
//        // all path variants).
//        //dpm('allpaths_dpm:');
//        dpm($allpaths_dpm, '$allpaths_dpm');
//
//        // Get path counts for each of the path aliases.
//        // Search hash values of path -- faster (primary key). E.g. SELECT pageviews
//        // FROM `google_analytics_counter` where pagepath_hash IN
//        // ('ee1c787bc14bec9945de3240101e919c', 'd884e66c2316317ef6294dc12aca9cef')
//
//        $sum_of_pageviews = 0;
//        $pathcounts = \Drupal::database()
//          ->select('google_analytics_counter', 'gac')
//          ->fields('gac', ['pageviews'])
//          //->condition('pagepath', array('/bg', '/node/3'), 'IN')
//          ->condition('pagepath_hash', $allpaths, 'IN')
//          ->execute();
//        while ($record = $pathcounts->fetchAssoc()) {
//          dpm($pathcounts);
//          foreach ($pathcounts as $pathcount) {
//            //dpm($pathcount);
//            //dpm('partial: '.$pathcount->pageviews);
//            $sum_of_pageviews += $pathcount->pageviews;
//          }
//        }
//        dpm($sum_of_pageviews, 'sum');
//
////      $cache->set('google_analytics_counter_page_' . $cacheid, $sum_of_pageviews, 'cache', CacheBackendInterface::CACHE_PERMANENT, ['rendered']);
//        //dpm('UNCACHED');
//      }
//
//      //dpm('total sum: '.$sum_of_pageviews);
//      return $sum_of_pageviews;
//    }
//
//    /**
//     * Return a list of paths that are aliased with the given path (including the given path).
//     *
//     * @param $node_path
//     * @param null $langcode
//     * @return array
//     */
//    protected
//    function googleAnalyticsCounterPathAliases($node_path, $langcode = NULL) {
//      // Get the normal node path if it is a node.
//      $node_path = \Drupal::service('path.alias_manager')
//        ->getPathByAlias($node_path, $langcode);
//      //dpm('nodepath: '.$node_path);
//
//      // Grab all aliases.
//      $aliases = [$node_path];
//      $result = db_query("SELECT * FROM {url_alias} WHERE source = :source", [':source' => $node_path]);
//      foreach ($result as $row) {
//        $aliases[] = $row->alias;
//      }
//
//      // If this is the front page, add the base path too, and index.php for good measure.
//      // There may be other ways that the user is accessing the front page but we can't account for them all.
//      if ($node_path == \Drupal::configFactory()
//          ->getEditable('system.site')
//          ->get('name')
//      ) {
//        $aliases[] = '';
//        $aliases[] = '/';
//        $aliases[] = 'index.php';
//      }
//
//      return $aliases;
//    }



}
