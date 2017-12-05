<?php

/**
 * @file
 * Parsing and writing the fetched data.
 */

namespace Drupal\google_analytics_counter;

use \Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use \Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
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
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $connection, GoogleAnalyticsCounterCommon $common) {
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->connection = $connection;
    $this->common = $common;
  }

  /**
   * Return a list of paths that are aliased with the given path (including the given path).
   *
   * @param $node_path
   * @param null $langcode
   * @return array
   */
  protected static function googleAnalyticsCounterPathAliases($node_path, $langcode = NULL) {
    // Get the normal node path if it is a node.
    $node_path = \Drupal::service('path.alias_manager')->getPathByAlias($node_path, $langcode);
    //dpm('nodepath: '.$node_path);

    // Grab all aliases.
    $aliases = array($node_path);
    $result = db_query("SELECT * FROM {url_alias} WHERE source = :source", array(':source' => $node_path));
    foreach ($result as $row) {
      $aliases[] = $row->alias;
    }

    // If this is the front page, add the base path too, and index.php for good measure. There may be other ways that the user is accessing the front page but we can't account for them all.
    if ($node_path == \Drupal::configFactory()->getEditable('system.site')->get('name')) {
      $aliases[] = '';
      $aliases[] = '/';
      $aliases[] = 'index.php';
    }

    return $aliases;
  }

  /**
   * Request report data.
   *
   * @param $params
   *   An associative array containing:
   *   - profile_id: required [default=$this->configFactory->getEditable('google_analytics_counter.settings')->get('google_analytics_counter_profile_id')]
   *   - metrics: required.
   *   - dimensions: optional [default=none]
   *   - sort_metric: optional [default=none]
   *   - filters: optional [default=none]
   *   - segment: optional [default=none]
   *   - start_date: optional [default=GA release date]
   *   - end_date: optional [default=today]
   *   - start_index: optional [default=1]
   *   - max_results: optional [default=10,000]
   * @param $cache_options
   *   An optional associative array containing:
   *   - cid: optional [default=md5 hash]
   *   - expire: optional [default=CACHE_TEMPORARY]
   *   - refresh: optional [default=FALSE]
   *
   * @return object
   */
  public function reportData($params = array(), $cache_options = array()) {
    $config = $this->config;
    $params_defaults = [
    'profile_id' => 'ga:' . $config->get('profile_id'),
    ];

    $params += $params_defaults;

    $GAFeed = $this->common->newGaFeed();
    $GAFeed->queryReportFeed($params, $cache_options);

    return $GAFeed;
  }
  /**
   * Get pageviews for nodes and write them either to the Drupal core table
   * node_counter, or to the google_analytics_counter_storage table.
   * This function is triggered by hook_cron().
   */
  public function updateDashboard() {
    $config = $this->config;

    // Record how long did this chunk take to process.
    $chunk_process_begin = time();

    // The total number of nodes.
    $query = $this->connection->select('node', 'n');
    $query->addExpression('COUNT(nid)');
    $result_count = $query->execute()->fetchField();
    \Drupal::configFactory()
      ->getEditable('google_analytics_counter.settings')
      ->set('total_nodes', $result_count)
      ->save();

    // How many node counts to update one cron run.
    // We use the same chunk size as when getting paths in google_analytics_counter_update_path_counts().
    $chunk = $config->get('chunk_to_fetch');
    // In case there are more than $chunk nodes to process, do just one chunk at a time and register that in $step.
    $step = $config->get('node_data_step');
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
  }
}
