<?php

namespace Drupal\google_analytics_counter\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GoogleAnalyticsCounterController.
 *
 * @package Drupal\google_analytics_counter\Controller
 */
class GoogleAnalyticsCounterController extends ControllerBase {

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The google_analytics_counter.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The state keyvalue collection.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon definition.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon
   */
  protected $common;

  /**
   * Constructs a Dashboard object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Database\Connection $database
   *   A database connection.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue collection to use.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, StateInterface $state, GoogleAnalyticsCounterCommon $common) {
    $this->database = $database;
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->state = $state;
    $this->common = $common;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('google_analytics_counter.common')
    );
  }

  public function dashboard() {
    $config = $this->config;

    $build = [];
    $build['intro'] = [
      '#markup' => '<h4>' . $this->t('Information about Google Analytics and this site') . '</h4>',
    ];

    // The Google section
    $build['google_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Information from Google'),
      '#open' => TRUE,
    ];
    $build['google_info']['intro'] = [
      '#markup' => '<h6>' . $this->t('The values in bold are updated during cron runs.') . '</h6>',
    ];

    $t_args = $this->getStartDateEndDate();
    $t_args += [':total_pageviews' => number_format($this->state->get('google_analytics_counter.total_pageviews'))];
    $build['google_info']['total_pageviews'] = [
      '#markup' => $this->t('The total number of pageviews recorded by Google Analytics for this profile between :start_date - :end_date: <strong>:total_pageviews</strong>', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $t_args = $this->getStartDateEndDate();
    $t_args += [':total_paths' => number_format($this->state->get('google_analytics_counter.total_paths'))];
    $build['google_info']['total_paths'] = [
      '#markup' => $this->t('The total number of paths recorded by Google Analytics for this profile between :start_date - :end_date: <strong>:total_paths</strong>.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    if ($this->state->get('google_analytics_counter.most_recent_query') == '') {
      $t_args = [':most_recent_query' => 'No query has been run.'];
    }
    else {
      $t_args = [':most_recent_query' => $this->state->get('google_analytics_counter.most_recent_query')];
    }

    // Google Query
    $build['google_info']['google_query'] = [
      '#type' => 'details',
      '#title' => $this->t('most recent query to Google'),
      '#open' => FALSE,
    ];

    $build['google_info']['google_query']['most_recent_query'] = [
      '#markup' => '<strong>' . $this->t(':most_recent_query', $t_args) . '</strong><br /><br />' . $this->t('The access_token needs to be included with the query. Get the access_token with <em>drush state-get google_analytics_counter.access_token</em>'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['google_info']['dayquota'] = [
      '#markup' => $this->t('Number of requests made to Google Analytics in the current 24-hour period: <strong>:data_step</strong>.', [':data_step' => number_format($this->state->get('google_analytics_counter.data_step'))]) . '<br /><em>' . $this->t('Only calls made by this module are counted here. Other modules and apps may be making requests. The quota is reset at midnight PST.'),
      '#prefix' => '<p>',
      '#suffix' => '</em></p>',
    ];

    $remaining_requests = $config->get('general_settings.api_dayquota') - $this->state->get('google_analytics_counter.data_step');
    $remaining_requests < 1 ? $remaining_requests = '?' : $remaining_requests = number_format($remaining_requests);
    $build['google_info']['remaining_requests'] = [
      '#markup' => $this->t('Remaining requests available in the current 24-hour period: <strong>:remainingcalls</strong>.', [':remainingcalls' => $remaining_requests]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $this->state->get('google_analytics_counter.dayquota_timestamp') == 0 ? $seconds = 60 * 60 * 24 : $seconds = 60 * 60 * 24 - (\Drupal::time()->getRequestTime() - $this->state->get('google_analytics_counter.dayquota_timestamp'));
    $build['google_info']['period_ends'] = [
      '#markup' => $this->t('The current 24-hour period ends in: <strong>:sec2hms</strong>.', [':sec2hms' => $this->common->sec2hms($seconds)]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $seconds = $this->state->get('google_analytics_counter.chunk_process_time') + $this->state->get('google_analytics_counter.chunk_node_process_time');
    if ($seconds < 0) {
      $seconds = 0;
    }
    $t_args = [
      ':chunk_to_fetch' => number_format($config->get('general_settings.chunk_to_fetch')),
      ':sec2hms' => $this->common->sec2hms($seconds),
      ':chunk_process_time' => $this->state->get('google_analytics_counter.chunk_process_time') . 's',
      ':chunk_node_process_time' => $this->state->get('google_analytics_counter.chunk_node_process_time') . 's',
    ];
    $build['google_info']['period_ends'] = [
      '#markup' => $this->t('The most recent retrieval of <strong>:chunk_to_fetch</strong> paths from Google Analytics and node counts from its local mirror took <strong>:sec2hms</strong> (:chunk_process_time + :chunk_node_process_time).', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['google_info']['assume_cron_frequency'] = [
      '#markup' => $this->t('Assuming cron runs every hour, the next cron run will take place at <strong>:sec2hms</strong>.',
      // WTH Drupal. Won't print a custom time when there is a colon in the format?
      [':sec2hms' => \Drupal::service('date.formatter')->format($this->state->get('google_analytics_counter.cron_next_execution') + 3600, 'custom', 'g i a')]) . '</p>',
    ];

    // The Drupal section
    $build['drupal_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Information from this site'),
      '#open' => TRUE,
    ];

    $build['drupal_info']['intro'] = [
      '#markup' => '<h6>' . $this->t('The values in bold are updated during cron runs.') . '</h6>',
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $num_of_results = $this->getCount('google_analytics_counter');
    $build['drupal_info']['number_paths_stored'] = [
      '#markup' => $this->t('Number of paths currently stored in local database table: <strong>:num_of_results</strong>.', [':num_of_results' => number_format($num_of_results)]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['drupal_info']['total_nodes'] = [
      '#markup' => $this->t('Total number of nodes on this site: <strong>:totalnodes</strong>.', [':totalnodes' => number_format($this->state->get('google_analytics_counter.total_nodes'))]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    // See https://www.drupal.org/node/2275575
    \Drupal::moduleHandler()->moduleExists('statistics') ? $table = 'node_counter' : $table = 'google_analytics_counter_storage';
    $num_of_results = $this->getCount($table);

    $build['drupal_info']['total_nodes_with_pageviews'] = [
      '#markup' => $this->t('Number of nodes with known pageview counts on this site: <strong>:num_of_results</strong>.', [':num_of_results' => number_format($num_of_results)]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $temp = $this->state->get('google_analytics_counter.cron_next_execution') - \Drupal::time()->getRequestTime();
    if ($temp < 0) {
      // Run cron immediately.
      $destination = \Drupal::destination()->getAsArray();
      $t_args = [
        ':href' => Url::fromRoute('system.run_cron', [], [
          'absolute' => TRUE,
          'query' => $destination
        ])->toString(),
        '@href' => 'Run cron immediately',
      ];
      $build['drupal_info']['run_cron'] = [
        '#markup' => $this->t('<a href=:href>@href</a>.', $t_args),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];

      // Revoke Google authentication.
      $build = $this->common->revokeAuthenticationMessage($build);
    }

    if ($this->common->isAuthenticated() === TRUE) {
      return $build;
    }
    else {
      $build = [];
      // Revoke Google authentication.
      $build = $this->common->revokeAuthenticationMessage($build);
      $this->common->notAuthenticatedMessage();
      return $build;
    }
  }

  /**
   * Get the row count of table.
   *
   * @param string $table
   * @return mixed
   */
  private function getCount($table) {
    $query = $this->database->select($table, 't');
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Calculates total pageviews for fixed start and end date or for time ago, like '-1 day'.
   *
   * @return array
   */
  protected function getStartDateEndDate() {
    $config = $this->config;

    if (!empty($config->get('general_settings.fixed_start_date'))) {
      $t_args = [
        ':start_date' => \Drupal::service('date.formatter')
          ->format(strtotime($config->get('general_settings.fixed_start_date')), 'custom', 'M j, Y'),
        ':end_date' => \Drupal::service('date.formatter')
          ->format(strtotime($config->get('general_settings.fixed_end_date')), 'custom', 'M j, Y'),
      ];
      return $t_args;
    }
    else {
      $t_args = [
        ':start_date' => $this->state->get('google_analytics_counter.last_cron_run') ? \Drupal::service('date.formatter')
          ->format($this->state->get('google_analytics_counter.last_cron_run') - strtotime(ltrim($config->get('general_settings.start_date'), '-'), 0), 'custom', 'M j, Y') : 'N/A',
          ':end_date' => $this->state->get('google_analytics_counter.last_cron_run') ? \Drupal::service('date.formatter')
        ->format($this->state->get('google_analytics_counter.last_cron_run'), 'custom', 'M j, Y') : 'N/A',
      ];
      return $t_args;
    }
  }

}
