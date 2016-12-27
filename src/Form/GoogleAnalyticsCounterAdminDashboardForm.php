<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;

/**
 * Class GoogleAnalyticsCounterAdminDashboardForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterAdminDashboardForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_analytics_counter_admin_dashboard';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['google_analytics_counter.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('google_analytics_counter.settings');
    $result = '';
    $result .= $this->t('<p><h3>More information relevant to Google Analytics statistics for this site:</h3>');

    // It's a weak test but better than none.
    if ($config->get('profile_id') <> '') {
      $result .= $this->t('<p>Total number of hits registered by Google Analytics under this profile: %totalhits. This is cumulative; counts for paths that may no longer exist on the website still have historical traces in Google Analytics.',
        array('%totalhits' => SafeMarkup::checkPlain(number_format($config->get('totalhits'))))
      );
      $result .= $this->t('<p>Number of paths on this site as currently recorded by Google Analytics: %totalpaths. This is cumulative; paths that may no longer exist on the website still have historical traces in Google Analytics.',
        array('%totalpaths' => SafeMarkup::checkPlain(number_format($config->get('totalpaths'))))
      );

      $num_of_results = $this->getCount('google_analytics_counter');
      $result .= $this->t('<br />Number of paths currently stored in local database table: %num_of_results. This table is initially built and then regularly updated during cron runs.',
        array('%num_of_results' => number_format($num_of_results))
      );

      $result .= $this->t('<p>Total number of nodes on this site: %totalnodes.',
        array('%totalnodes' => SafeMarkup::checkPlain(number_format($config->get('totalnodes'))))
      );

      if ($config->get('storage', 0) == 0
        && \Drupal::moduleHandler()->moduleExists('statistics')
      ) {
        // See also https://www.drupal.org/node/2275575
        $table = 'node_counter';
      }
      else {
        $table = 'google_analytics_counter_storage';
      }
      $num_of_results = $this->getCount($table);
      $result .= $this->t('<br />Number of nodes with known pageview counts on this site: %num_of_results.',
        array('%num_of_results' => SafeMarkup::checkPlain(number_format($num_of_results)))
      );

      $apicalls = $config->get('dayquota');
      $result .= $this->t('<p>Number of requests made to Google Analytics: %apicalls1. Only calls made by this module are counted here. Other modules and apps may be making more requests.',
        array('%apicalls1' => SafeMarkup::checkPlain(number_format($apicalls[1])))
      );
      $remainingcalls = $config->get('api_dayquota', 10000) - $apicalls[1];
      if ($remainingcalls < 1) {
        $remainingcalls = '?';
      }
      else {
        $remainingcalls = number_format($remainingcalls);
      }
      $result .= $this->t('Remaining requests available in the current 24-hour period: %remainingcalls.',
        array('%remainingcalls' => SafeMarkup::checkPlain($remainingcalls))
      );
      if ($apicalls[0] == 0) {
        $temp = 60 * 60 * 24;
      }
      else {
        $temp = 60 * 60 * 24 - (REQUEST_TIME - $apicalls[0]);
      }
      $result .= $this->t('The current 24-hour period ends in: %sec2hms.',
        array('%sec2hms' => SafeMarkup::checkPlain(GoogleAnalyticsCounterCommon::sec2hms($temp)))
      );

      $temp = $config->get('chunk_process_time') + $config->get('chunk_node_process_time');
      if ($temp < 0) {
        $temp = 0;
      }
      $result .= $this->t('<br/>The most recent retrieval of %chunk_to_fetch paths from Google Analytics and node counts from its local mirror took %sec2hms (%chunk_process_time+%chunk_node_process_times).',
        array(
          '%chunk_to_fetch' => SafeMarkup::checkPlain(number_format($config->get('chunk_to_fetch'))),
          '%sec2hms' => SafeMarkup::checkPlain(GoogleAnalyticsCounterCommon::sec2hms($temp)),
          '%chunk_process_time' => SafeMarkup::checkPlain($config->get('chunk_process_time')),
          '%chunk_node_process_time' => SafeMarkup::checkPlain($config->get('chunk_node_process_time')),
        )
      );
      $temp = $config->get('cron_next_execution') - REQUEST_TIME;
      if ($temp < 0) {
        $temp = 0;
        $result .= $this->t('The next one will take place in %sec2hms.',
          array('%sec2hms' => SafeMarkup::checkPlain(GoogleAnalyticsCounterCommon::sec2hms($temp)))
        );

        $url = Url::fromRoute('system.run_cron');
        $url->setOptions(array(
          'query' => array(
            'destination' => array(
              'admin/config/system/google_analytics_counter/dashboard',
            ),
          ),
        ));
        $text = Link::fromTextAndUrl($this->t('Run cron immediately'), $url);
        $result .= '<p>' . $text->toString()->getGeneratedLink() . '.';

        $url = Url::fromRoute('google_analytics_counter.admin_dashboard_reset');
        $text = Link::fromTextAndUrl($this->t('Reset all module settings'), $url);
        $result .= $this->t('<p>[ %link. Useful in some cases, e.g. if in trouble with OAuth authentication.]',
          array('%link' => $text->toString())
        );
      }
    }
    else {
      $url = Url::fromRoute('google_analytics_counter.admin_auth_form');
      $result .= $this->t('<font color="red">No Google Analytics profile has been authenticated! Google Analytics Counter can not fetch any new data. Please %link.</font>',
        array('%link' => \Drupal::l($this->t('authenticate here'), $url))
      );
    }
    $form['description'] = array(
      '#markup' => $result,
    );
    $form['#theme'] = 'system_config_form';

    return $form;
  }

  /**
   * Get the row count of table.
   */
  private function getCount($table) {
    $db = \Drupal::database();
    return $db->select($table, 'alias')
      ->fields('alias')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
