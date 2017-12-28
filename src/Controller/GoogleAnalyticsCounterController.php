<?php

namespace Drupal\google_analytics_counter\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatter;
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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

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
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue collection to use.
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, DateFormatter $date_formatter, GoogleAnalyticsCounterCommon $common) {
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->state = $state;
    $this->dateFormatter = $date_formatter;
    $this->time = \Drupal::service('datetime.time');
    $this->common = $common;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('date.formatter'),
      $container->get('google_analytics_counter.common')
    );
  }

  public function dashboard() {
    $config = $this->config;

    $build = [];
    $build['intro'] = [
      '#markup' => '<h4>' . $this->t('Information on this page is updated during cron runs.') . '</h4>',
    ];

    // The Google section
    $build['google_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Information from Google Analytics API'),
      '#open' => TRUE,
    ];

    $t_args = $this->getStartDateEndDate();
    $t_args += ['%total_pageviews' => number_format($this->state->get('google_analytics_counter.total_pageviews'))];
    $build['google_info']['total_pageviews'] = [
      '#markup' => $this->t('%total_pageviews pageviews were recorded by Google Analytics for this profile between %start_date - %end_date.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $t_args = $this->getStartDateEndDate();
    $t_args += [
      '%total_paths' => number_format($this->state->get('google_analytics_counter.total_paths')),
      '%chunk_to_fetch' => number_format($config->get('general_settings.chunk_to_fetch')),
      '%total_paths_over_chunk_to_fetch' => ceil($this->state->get('google_analytics_counter.total_paths') / $config->get('general_settings.chunk_to_fetch')),
    ];
    $build['google_info']['total_paths'] = [
      '#markup' => $this->t('%total_paths paths were recorded by Google Analytics for this profile between %start_date - %end_date.<br /><strong>Note:</strong> Fetching %chunk_to_fetch items items from Google Analytics in one request means cron needs to run %total_paths_over_chunk_to_fetch times to completely process all %total_paths paths into Drupal.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    if ($this->state->get('google_analytics_counter.most_recent_query') == '') {
      $t_args = ['%most_recent_query' => 'No query has been run.'];
    }
    else {
      $t_args = ['%most_recent_query' => $this->state->get('google_analytics_counter.most_recent_query')];
    }

    // Google Query
    $build['google_info']['google_query'] = [
      '#type' => 'details',
      '#title' => $this->t('most recent query to Google'),
      '#open' => FALSE,
    ];

    $build['google_info']['google_query']['most_recent_query'] = [
      '#markup' => $this->t('%most_recent_query', $t_args) . '<br /><br />' . $this->t('The access_token needs to be included with the query. Get the access_token with <em>drush state-get google_analytics_counter.access_token</em>'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $data_last_refreshed = $this->state->get('google_analytics_counter.data_last_refreshed') ? $this->dateFormatter->format($this->state->get('google_analytics_counter.data_last_refreshed'), 'custom', 'M d, Y h:i:sa') : 0;
    $build['google_info']['data_last_refreshed'] = [
      '#markup' => $this->t('%data_last_refreshed is when Google last refreshed its data.', ['%data_last_refreshed' => $data_last_refreshed]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['google_info']['dayquota'] = [
      '#markup' => $this->t('%data_step is the number of requests made to Google Analytics in the current 24-hour period.', ['%data_step' => number_format($this->state->get('google_analytics_counter.dayquota_request'))]) . '<br />' . $this->t('<strong>Note:</strong> Only calls made by this module are counted here. Other modules and apps may be making requests. The quota is reset at midnight PST.'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $remaining_requests = $config->get('general_settings.api_dayquota') - $this->state->get('google_analytics_counter.dayquota_request');
    $remaining_requests < 1 ? $remaining_requests = '?' : $remaining_requests = number_format($remaining_requests);
    $build['google_info']['remaining_requests'] = [
      '#markup' => $this->t('%remainingcalls requests to Google Analytics remain available in the current 24-hour period.', ['%remainingcalls' => $remaining_requests]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $this->state->get('google_analytics_counter.dayquota_timestamp') == 0 ? $seconds = 60 * 60 * 24 : $seconds = 60 * 60 * 24 - ($this->time->getRequestTime() - $this->state->get('google_analytics_counter.dayquota_timestamp'));
    $build['google_info']['period_ends'] = [
      '#markup' => $this->t('%sec2hms until the current 24-hour period ends.', ['%sec2hms' => $this->common->sec2hms($seconds)]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $seconds = $this->state->get('google_analytics_counter.chunk_process_time') + $this->state->get('google_analytics_counter.chunk_node_process_time');
    if ($seconds < 0) {
      $seconds = 0;
    }
    $t_args = [
      '%chunk_to_fetch' => number_format($config->get('general_settings.chunk_to_fetch')),
      '%sec2hms' => $this->common->sec2hms($seconds),
      '%chunk_process_time' => $this->state->get('google_analytics_counter.chunk_process_time') . 's',
      '%chunk_node_process_time' => $this->state->get('google_analytics_counter.chunk_node_process_time') . 's',
    ];

    $build['google_info']['most_recent_retrieval'] = [
      '#markup' => $this->t('%sec2hms (%chunk_process_time + %chunk_node_process_time) is the time it took for the most recent retrieval from Google Analytics.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    // The Drupal section
    $build['drupal_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Information from this site'),
      '#open' => TRUE,
    ];

    $build['drupal_info']['number_paths_stored'] = [
      '#markup' => $this->t('%num_of_results is the number of paths currently stored in the local database table.', ['%num_of_results' => number_format($this->common->getCount('google_analytics_counter'))]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['drupal_info']['total_nodes'] = [
      '#markup' => $this->t('%totalnodes is the total number of published nodes.', ['%totalnodes' => number_format($this->state->get('google_analytics_counter.total_nodes'))]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    // See https://www.drupal.org/node/2275575
    \Drupal::moduleHandler()->moduleExists('statistics') ? $table = 'node_counter' : $table = 'google_analytics_counter_storage';
    $build['drupal_info']['total_nodes_with_pageviews'] = [
      '#markup' => $this->t('%num_of_results is the total number of nodes with pageview counts that are <em>greater than zero</em>.', ['%num_of_results' => number_format($this->common->getCount($table))]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $t_args = [
      '%num_of_results' => number_format($this->common->getCount('google_analytics_counter_storage_all_nodes')),
      '%total_paths_over_chunk_to_fetch' => ceil($this->state->get('google_analytics_counter.total_paths') / $config->get('general_settings.chunk_to_fetch')),
    ];
    $build['drupal_info']['total_nodes_equal_zero'] = [
      '#markup' => $this->t('%num_of_results is the total number of nodes with pageview counts.<br /><strong>Note:</strong> The total number of nodes with pageview counts should equal the total number of published nodes on or before %total_paths_over_chunk_to_fetch cron runs.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $t_args = [
      '%queue_count' => number_format($this->common->getCount('queue')),
      ':href' => Url::fromRoute('google_analytics_counter.admin_settings_form', [], ['absolute' => TRUE])
        ->toString(),
      '@href' => 'settings form',
    ];
    $build['drupal_info']['queue_count'] = [
      '#markup' => $this->t('%queue_count is the number of items in the queue. The number of items in the queue should be 0 after cron runs.<br /><strong>Note:</strong> Having 0 items in the queue confirms that pageview counts are up to date. Increase Queue Time on the <a href=:href>@href</a> to process all the queued items.', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['drupal_info']['last_cron_run'] = [
      '#markup' => $this->t('%time ago is when cron last run.', ['%time' => $this->dateFormatter->formatTimeDiffSince($this->state->get('system.cron_last'))]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $temp = $this->state->get('google_analytics_counter.cron_next_execution') - $this->time->getRequestTime();
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
   * Calculates total pageviews for fixed start and end date or for time ago, like '-1 day'.
   *
   * @return array
   */
  protected function getStartDateEndDate() {
    $config = $this->config;

    if (!empty($config->get('general_settings.fixed_start_date'))) {
      $t_args = [
        '%start_date' => $this->dateFormatter
          ->format(strtotime($config->get('general_settings.fixed_start_date')), 'custom', 'M j, Y'),
        '%end_date' => $this->dateFormatter
          ->format(strtotime($config->get('general_settings.fixed_end_date')), 'custom', 'M j, Y'),
      ];
      return $t_args;
    }
    else {
      $t_args = [
        '%start_date' => $this->state->get('system.cron_last') ? $this->dateFormatter
          ->format($this->state->get('system.cron_last') - strtotime(ltrim($config->get('general_settings.start_date'), '-'), 0), 'custom', 'M j, Y') : 'N/A',
          '%end_date' => $this->state->get('system.cron_last') ? $this->dateFormatter
        ->format($this->state->get('system.cron_last'), 'custom', 'M j, Y') : 'N/A',
      ];
      return $t_args;
    }
  }

}
