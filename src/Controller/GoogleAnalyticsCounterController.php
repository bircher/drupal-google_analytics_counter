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
      $result .= '<h4><hr><br>' . $this->t('Information from Google') . '</h4>';

      $total_hits = $this->dashboard->reportData();
      $result .= '<p>' . $this->t('The total number of hits registered by Google Analytics under this profile: <strong>:total_hits</strong>.', [':total_hits' => number_format(0)]) . '<br />' . $this->t('The total number of hits is cumulative.') . '</p>';
      $result .= '<p>' . $this->t('Number of paths on this site as currently recorded by Google Analytics: <strong>:total_paths</strong>.', [':total_paths' => number_format(0)]) . '<br />' . $this->t('The total number of paths is cumulative and may include paths that no longer exist on the site but still are in Google.') . '</p>';


      $apicalls = $config->get('dayquota');
      $result .= '<p>' . $this->t('Number of requests made to Google Analytics: <strong>:apicalls1</strong>.', [':apicalls1' => number_format(0)]) . '<br />' . $this->t('Only calls made by this module are counted here. Other modules and apps may also be making requests.') . '</p>';

      $remainingcalls = $config->get('api_dayquota') - $apicalls[1];
      if ($remainingcalls < 1) {
        $remainingcalls = '?';
      }
      else {
        $remainingcalls = number_format($remainingcalls);
      }
      $result .= '<p>' . $this->t('Remaining requests available in the current 24-hour period: <strong>:remainingcalls</strong>.', [':remainingcalls' => number_format(0)]) . '</p>';

      if ($apicalls[0] == 0) {
        $seconds = 60 * 60 * 24;
      }
      else {
        $seconds = 60 * 60 * 24 - (REQUEST_TIME - $apicalls[0]);
      }
      $result .= '<p>' . $this->t('The current 24-hour period ends in: <strong>:sec2hms</strong>.', [':sec2hms' => $this->common->sec2hms($seconds)]) . '</p>';

      $seconds = $config->get('chunk_process_time') + $config->get('chunk_node_process_time');
      if ($seconds < 0) {
        $seconds = 0;
      }
      $result .= '<p>' . $this->t('The most recent retrieval of <strong>:chunk_to_fetch</strong> paths from Google Analytics and node counts from its local mirror took <strong>:sec2hms</strong> (:chunk_process_time + :chunk_node_process_time).',
          [
            ':chunk_to_fetch' => number_format($config->get('chunk_to_fetch')),
            ':sec2hms' => $this->common->sec2hms($seconds),
            ':chunk_process_time' => $config->get('chunk_process_time'),
            ':chunk_node_process_time' => $config->get('chunk_node_process_time'),
          ]
        ) . '</p>';

      $seconds_until_cron = $config->get('cron_next_execution') - REQUEST_TIME;
      if ($seconds_until_cron < 0) {
        $seconds_until_cron = 0;
        $result .= $this->t('The next cron run will take place in <strong>:sec2hms</strong>.',
          [':sec2hms' => $this->common->sec2hms($seconds_until_cron)]
        );
      }

      // Todo: Use a fieldset here.
      $result .= '<h4><hr><br>' . $this->t('Information from this site') . '</h4>';

      $num_of_results = $this->getCount('google_analytics_counter');
      $result .= '<p>' . $this->t('Number of paths currently stored in local database table: <strong>:num_of_results</strong>.', [':num_of_results' => number_format($num_of_results)]) . '<br />' . $this->t('This value is updated during cron runs.') . '</p>';

      $result .= '<p>' . $this->t('Total number of nodes on this site: <strong>:totalnodes</strong>.', [':totalnodes' => number_format($config->get('total_nodes'))]) . '</p>';

      if ($config->get('storage') == '' && \Drupal::moduleHandler()
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

      $temp = $config->get('cron_next_execution') - REQUEST_TIME;
      if ($temp < 0) {
        $temp = 0;

        // Run cron immediately.
        $destination = \Drupal::destination()->getAsArray();
        $t_args = [
          ':href' => Url::fromRoute('system.run_cron', [], [
            'absolute' => TRUE,
            'query' => $destination
          ])->toString(),
          '@href' => 'Run cron immediately',
        ];
        $result .= '<p>' . $this->t('<a href=:href>@href</a>.', $t_args);

        // Revoke Google authentication.
        $t_args = [
          ':href' => Url::fromRoute('google_analytics_counter.admin_dashboard_reset', [], ['absolute' => TRUE])
            ->toString(),
          '@href' => 'Revoke Google authentication',
        ];
        $result .= '<p>' . $this->t('[<a href=:href>@href</a>. Useful in some cases, e.g. if in trouble with OAuth authentication.]', $t_args);
      }
      return ['#markup' => $result];
    }
    else {
      $t_args = [
        ':href' => Url::fromRoute('google_analytics_counter.admin_auth_form', [], ['absolute' => TRUE])
          ->toString(),
        '@href' => 'authenticate here',
      ];
      $result .= '<p>' . $this->t('No Google Analytics profile has been authenticated! Google Analytics Counter can not fetch any new data. Please <a href=:href>@href</a>.', $t_args);
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
