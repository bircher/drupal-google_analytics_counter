<?php

namespace Drupal\google_analytics_counter\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\Form\GoogleAnalyticsCounterResetForm;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterDashboard;
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
   * Drupal\google_analytics_counter\GoogleAnalyticsCounterDashboard definition.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterDashboard
   */
  protected $dashboard;

  /**
   * Constructs a Dashboard object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Database\Connection $database
   *   A database connection.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterDashboard $dashboard
   *   Google Analytics Counter Dashboard object.
   */
  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, GoogleAnalyticsCounterCommon $common, GoogleAnalyticsCounterDashboard $dashboard) {
    $this->database = $database;
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->common = $common;
    $this->dashboard = $dashboard;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('google_analytics_counter.common'),
      $container->get('google_analytics_counter.dashboard')
    );
  }

  /**
   * Confirmation callback function.
   */
  public function reset() {
    $form = new GoogleAnalyticsCounterResetForm();
    return \Drupal::formBuilder()->getForm($form);
  }

  public function dashboard() {
    $config = $this->config;
    $result = '';
    $result .='<h4>' . $this->t('Relevant Information about Google Analytics and this site</h3>');

    if ($this->common->isAuthenticated()) {
      // Todo: Use a fieldset here.
      $result .= '<h2><hr><br />' . $this->t('Information from Google') . '</h2>';
      $result .= '<h6>' . $this->t('The values in bold are updated during cron runs.') . '</h6>';

      // Todo: Why use state for last_cron_run, not configuration?
      $t_args = [
        ':last_cron_run' => \Drupal::service('date.formatter')->format(\Drupal::state()->get('google_analytics_counter.last_cron_run'), 'medium'),
        ':start_date' => \Drupal::service('date.formatter')->format(\Drupal::state()->get('google_analytics_counter.last_cron_run') - strtotime(ltrim($config->get('general_settings.start_date'), '-'), 0), 'medium'),
        ':total_pageviews' => number_format($config->get('general_settings.total_hits')),
      ];
      $result .= '<p>' . $this->t('The total number of pageviews registered by Google Analytics for this profile between :last_cron_run, and :start_date: <strong>:total_pageviews</strong>', $t_args) . '</p>';

      $total_paths = $config->get('general_settings.total_paths');
      $result .= '<p>' . $this->t('The total number of paths recorded by Google Analytics on this site: <strong>:total_paths</strong>.', [':total_paths' => number_format($total_paths)]) . '<br />' . $this->t('The total number of paths is cumulative and may include paths that no longer exist on the site but are still in Google.') . '</p>';


      $apicalls = $config->get('general_settings.dayquota');
      $result .= '<p>' . $this->t('Number of requests made to Google Analytics in the current 24-hour period: <strong>:apicalls_requests</strong>.', [':apicalls_requests' => number_format($apicalls['requests'])]) . '<br />' . $this->t('Only calls made by this module are counted here. Other modules and apps may also be making requests.') . '</p>';

      $remainingcalls = $config->get('general_settings.api_dayquota') - $apicalls['requests'];
      $remainingcalls < 1 ? $remainingcalls = '?' : $remainingcalls = number_format($remainingcalls);
      $result .= '<p>' . $this->t('Remaining requests available in the current 24-hour period: <strong>:remainingcalls</strong>.', [':remainingcalls' => $remainingcalls]) . '</p>';

      $apicalls['timestamp'] == 0 ? $seconds = 60 * 60 * 24 : $seconds = 60 * 60 * 24 - (\Drupal::time()->getRequestTime() - $apicalls['timestamp']);
      $result .= '<p>' . $this->t('The current 24-hour period ends in: <strong>:sec2hms</strong>.', [':sec2hms' => $this->common->sec2hms($seconds)]) . '</p>';

      $seconds = $config->get('general_settings.chunk_process_time') + $config->get('general_settings.chunk_node_process_time');
      if ($seconds < 0) {
        $seconds = 0;
      }
      $result .= '<p>' . $this->t('The most recent retrieval of <strong>:chunk_to_fetch</strong> paths from Google Analytics and node counts from its local mirror took <strong>:sec2hms</strong> (:chunk_process_time + :chunk_node_process_time).',
          [
            ':chunk_to_fetch' => number_format($config->get('general_settings.chunk_to_fetch')),
            ':sec2hms' => $this->common->sec2hms($seconds),
            ':chunk_process_time' => $config->get('general_settings.chunk_process_time') . 's',
            ':chunk_node_process_time' => $config->get('general_settings.chunk_node_process_time') . 's',
          ]
        ) . '</p>';

      $result .= '<p>' . $this->t('Assuming cron runs every hour, the next cron run will take place at <strong>:sec2hms</strong>.',
        // WTH Drupal. Won't print a custom time when there is a colon in format?
//      [':sec2hms' => \Drupal::service('date.formatter')->format($config->get('general_settings.cron_next_execution') + 3600, 'custom', 'g:i a')]) . '</p>';
        [':sec2hms' => \Drupal::service('date.formatter')->format($config->get('general_settings.cron_next_execution') + 3600, 'medium')]) . '</p>';

      // Todo: Use a fieldset here.
      $result .= '<h2><hr><br>' . $this->t('Information from this site') . '</h2>';
      $result .= '<h6>' . $this->t('The values in bold are updated during cron runs.') . '</h6>';

      $num_of_results = $this->getCount('google_analytics_counter');
      $result .= '<p>' . $this->t('Number of paths currently stored in local database table: <strong>:num_of_results</strong>.', [':num_of_results' => number_format($num_of_results)]) . '</p>';

      $result .= '<p>' . $this->t('Total number of nodes on this site: <strong>:totalnodes</strong>.', [':totalnodes' => number_format($config->get('general_settings.total_nodes'))]) . '</p>';

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
      $result .= '<p>' . $this->t('Number of nodes with known pageview counts on this site: <strong>:num_of_results</strong>.', [':num_of_results' => number_format($num_of_results)]) . '</p>';

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
        $result .= '<p>' . $this->t('<a href=:href>@href</a>.', $t_args) . '</p>';

        // Revoke Google authentication.
        $t_args = [
          ':href' => Url::fromRoute('google_analytics_counter.admin_dashboard_reset', [], ['absolute' => TRUE])
            ->toString(),
          '@href' => 'Revoke Google authentication',
        ];
        $result .= '<p>' . $this->t('<a href=:href>@href</a>. Useful in some cases, e.g. if in trouble with OAuth authentication.', $t_args);
      }

      return ['#markup' => $result];
    }
    else {
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.admin_auth_form', [], ['absolute' => TRUE])
          ->toString(),
        '@href' => 'authenticate here',
      ];
      $result .= '<p>' . $this->t('No Google Analytics profile has been authenticated! Google Analytics Counter can not fetch any new data. Please <a href=:href>@href</a>.', $t_args) . '</p>';

      return ['#markup' => $result];
    }
  }

  /**
   * Get the row count of table.
   */
  private function getCount($table) {
    $query = $this->database->select($table, 't');
    return $query->countQuery()->execute()->fetchField();
  }

}
