<?php

namespace Drupal\google_analytics_counter\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\Form\GoogleAnalyticsCounterRevokeForm;
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
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, GoogleAnalyticsCounterCommon $common) {
    $this->database = $database;
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->common = $common;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('google_analytics_counter.common')
    );
  }

  public function dashboard() {
    $config = $this->config;

    $build = [];
    $build['intro'] = [
      '#markup' => '<h4>' . $this->t('Relevant Information about Google Analytics and this site') . '</h4>',
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

    // Todo: Why use state for last_cron_run, not configuration?
    if (!empty($config->get('general_settings.fixed_start_date'))) {
      $t_args = [
        ':start_date' => \Drupal::service('date.formatter')
          ->format(strtotime($config->get('general_settings.fixed_start_date')), 'custom', 'M j, Y'),
        ':end_date' => \Drupal::service('date.formatter')
          ->format(strtotime($config->get('general_settings.fixed_end_date')), 'custom', 'M j, Y'),
        ':total_pageviews' => number_format($config->get('general_settings.total_pageviews')),
      ];
    }
    else {
      $t_args = [
        ':start_date' => \Drupal::service('date.formatter')
          ->format(\Drupal::state()
            ->get('google_analytics_counter.last_cron_run'), 'custom', 'M j, Y'),
        ':end_date' => \Drupal::service('date.formatter')
          ->format(\Drupal::state()
              ->get('google_analytics_counter.last_cron_run') - strtotime(ltrim($config->get('general_settings.start_date'), '-'), 0), 'custom', 'M j, Y'),
        ':total_pageviews' => number_format($config->get('general_settings.total_pageviews')),
      ];
    }
    $build['google_info']['total_pageviews'] = [
      '#markup' => $this->t('The total number of pageviews recorded by Google Analytics for this profile between :start_date - :end_date: <strong>:total_pageviews</strong>', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $total_paths = $config->get('general_settings.total_paths');
    $build['google_info']['total_paths'] = [
      '#markup' => $this->t('The total number of paths recorded by Google Analytics on this site: <strong>:total_paths</strong>.', [':total_paths' => number_format($total_paths)]) . '<br />' . $this->t('The total number of paths is cumulative and may include paths that no longer exist on the site but are still in Google.'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $apicalls = $config->get('general_settings.dayquota');
    $build['google_info']['dayquota'] = [
      '#markup' => $this->t('Number of requests made to Google Analytics in the current 24-hour period: <strong>:apicalls_requests</strong>.', [':apicalls_requests' => number_format($apicalls['requests'])]) . '<br />' . $this->t('Only calls made by this module are counted here. Other modules and apps may also be making requests.'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $remaining_requests = $config->get('general_settings.api_dayquota') - $apicalls['requests'];
    $remaining_requests < 1 ? $remaining_requests = '?' : $remaining_requests = number_format($remaining_requests);
    $build['google_info']['remaining_requests'] = [
      '#markup' => $this->t('Remaining requests available in the current 24-hour period: <strong>:remainingcalls</strong>.', [':remainingcalls' => $remaining_requests]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $apicalls['timestamp'] == 0 ? $seconds = 60 * 60 * 24 : $seconds = 60 * 60 * 24 - (\Drupal::time()->getRequestTime() - $apicalls['timestamp']);
    $build['google_info']['period_ends'] = [
      '#markup' => $this->t('The current 24-hour period ends in: <strong>:sec2hms</strong>.', [':sec2hms' => $this->common->sec2hms($seconds)]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $seconds = $config->get('general_settings.chunk_process_time') + $config->get('general_settings.chunk_node_process_time');
    if ($seconds < 0) {
      $seconds = 0;
    }
    $t_args = [
      ':chunk_to_fetch' => number_format($config->get('general_settings.chunk_to_fetch')),
      ':sec2hms' => $this->common->sec2hms($seconds),
      ':chunk_process_time' => $config->get('general_settings.chunk_process_time') . 's',
      ':chunk_node_process_time' => $config->get('general_settings.chunk_node_process_time') . 's',
    ];
    $build['google_info']['period_ends'] = [
      '#markup' => $this->t('The most recent retrieval of <strong>:chunk_to_fetch</strong> paths from Google Analytics and node counts from its local mirror took <strong>:sec2hms</strong> (:chunk_process_time + :chunk_node_process_time).', $t_args),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $build['google_info']['assume_cron_frequency'] = [
      '#markup' => $this->t('Assuming cron runs every hour, the next cron run will take place at <strong>:sec2hms</strong>.',
        // WTH Drupal. Won't print a custom time when there is a colon in format?
//      [':sec2hms' => \Drupal::service('date.formatter')->format($config->get('general_settings.cron_next_execution') + 3600, 'custom', 'g:i a')]) . '</p>';
        [':sec2hms' => \Drupal::service('date.formatter')->format($config->get('general_settings.cron_next_execution') + 3600, 'medium')]),
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
      '#markup' => $this->t('Total number of nodes on this site: <strong>:totalnodes</strong>.', [':totalnodes' => number_format($config->get('general_settings.total_nodes'))]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    if ($config->get('general_settings.storage') == '' && \Drupal::moduleHandler()
        ->moduleExists('statistics')
    ) {
      // See also https://www.drupal.org/node/2275575
      $table = 'node_counter';
    }
    else {
      $table = 'google_analytics_counter_storage';
    }
    $num_of_results = $this->getCount($table);
    $build['drupal_info']['total_nodes_with_pageviews'] = [
      '#markup' => $this->t('Number of nodes with known pageview counts on this site: <strong>:num_of_results</strong>.', [':num_of_results' => number_format($num_of_results)]),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $temp = $config->get('general_settings.cron_next_execution') - \Drupal::time()->getRequestTime();
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
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.admin_auth_revoke', [], ['absolute' => TRUE])
          ->toString(),
        '@href' => 'Revoke authentication',
      ];
      $build['drupal_info']['revoke_authentication'] = [
        '#markup' => $this->t('<a href=:href>@href</a>. Useful in some cases, e.g. if in trouble with OAuth authentication.', $t_args),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
    }

    if ($this->common->isAuthenticated()) {
      return $build;
    }
    else {
      $build = [];
      // Revoke Google authentication.
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.admin_auth_revoke', [], ['absolute' => TRUE])
          ->toString(),
        '@href' => 'Try revoking authentication',
      ];
      $build['drupal_info']['revoke_authentication'] = [
        '#markup' => $this->t('<a href=:href>@href</a>. Useful in some cases, e.g. if in trouble with OAuth authentication.', $t_args),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];

      $this->common->noProfileMessage();
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

}
