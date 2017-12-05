<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Exception\RuntimeException;



/**
 * Class GoogleAnalyticsCounterAdminSettingsForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterAdminSettingsForm extends ConfigFormBase {

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
   * Constructs a new SiteMaintenanceModeForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue collection to use.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, GoogleAnalyticsCounterCommon $common) {
    parent::__construct($config_factory);
    $this->state = $state;
    $this->common = $common;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('google_analytics_counter.common')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_analytics_counter_admin_settings';
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

    $form['cron_interval'] = array(
      '#type' => 'number',
      '#title' => $this->t('Minimum time between Google Analytics data fetching'),
      '#default_value' => $config->get('cron_interval'),
      '#description' => $this->t('Google Analytics statistical data is fetched and processed via a cron job. If your cron runs too frequently, you may waste your GA daily quota too fast. Set here the minimum time that needs to elapse before the Google Analytics Counter cron runs (even if your cron job runs more frequently). Specify the time in <em>minutes</em>. Default: 30 minutes.'),
      '#required' => TRUE,
    );

    $form['chunk_to_fetch'] = array(
      '#type' => 'number',
      '#title' => $this->t('Number of items to fetch from Google Analytics in one request'),
      '#default_value' => $config->get('chunk_to_fetch'),
      '#min' => 1,
      '#max' => 10000,
      '#description' => $this->t('How many items will be fetched from Google Analytics in one request (during a cron run). The maximum allowed by Google is 10000. Default: 1000 items.'),
      '#required' => TRUE,
    );

    $form['api_dayquota'] = array(
      '#type' => 'number',
      '#title' => $this->t('Maximum GA API requests per day'),
      '#default_value' => $config->get('api_dayquota'),
      '#size' => 9,
      '#maxlength' => 9,
      '#description' => $this->t('This is the <em>daily limit</em> of requests <em>per profile</em> to the Google Analytics API. You don\'t need to change this value until Google relaxes their quota policy. <br />It is reasonable to expect that Google will increase this low number sooner rather than later, so watch the <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#discovery" target="_blank">quota</a> page for changes.<br />To get the full quota, you must <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#full_quota" target="_blank">register your Analytics API</a>.'),
      '#required' => TRUE,
    );

    $form['cache_length'] = array(
      '#type' => 'number',
      '#title' => t('Google Analytics query cache'),
      '#description' => t('Limit the minimum time in hours to elapse between getting fresh data for the same query from Google Analytics. Defaults to 1 day.'),
      '#default_value' => $config->get('cache_length') / 3600,
      '#required' => TRUE,
    );

    // GA starting date settings.
    $form['start_date'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Start Date for GA queries'),
      '#default_value' => $config->get('start_date'),
      '#size' => 9,
      '#maxlength' => 9,
      '#description' => $this->t('Enter a value that <a href="http://www.php.net/manual/en/function.strtotime.php">strtotime</a> understands as a date between 2005-01-01 and now. For example "-1 week"'),
      '#required' => TRUE,
      '#element_validate' => [[self::class, 'validateStartDate']],
    );

    $form['overwrite_statistics'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Override the counter of the core statistics module'),
      '#default_value' => $config->get('overwrite_statistics'),
      '#disabled' => !\Drupal::moduleHandler()->moduleExists('statistics'),
      '#description' => $this->t('Overwriting the total count of cores statistics module is not advised but may be useful in some situations.')
    );

    $options = \Drupal::service('google_analytics_counter.common')->getWebPropertiesOptions();
    if (!$options) {
      $options = [$config->get('profile_id') => 'Un-authenticated (' . $config->get('profile_id') . ')'];
    }
    $form['profile_id'] = array(
      '#type' => 'select',
      '#title' => $this->t('Reports profile'),
      '#options' => $options,
      '#default_value' => $config->get('profile_id'),
      '#description' => $this->t('Choose your Google Analytics profile. The options depend on the authenticated account.'),
    );

    $form['setup'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Initial setup'),
      '#description' => $this->t("The google key details can only be changed when not authenticated."),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#disabled' => \Drupal::service('google_analytics_counter.common')->isAuthenticated(),
    );
    $form['setup']['client_id'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('client_id'),
      '#size' => 30,
      '#description' => $this->t('Client ID created for the app in the access tab of the <a href="http://code.google.com/apis/console" target="_blank">Google API Console</a>'),
      '#weight' => -9,
    );
    $form['setup']['client_secret'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('client_secret'),
      '#size' => 30,
      '#description' => $this->t('Client Secret created for the app in the Google API Console'),
      '#weight' => -8,
    );

    $form['setup']['redirect_uri'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Redirect URI'),
      '#default_value' => $config->get('redirect_uri'),
      '#size' => 30,
      '#description' => $this->t('Use to override the host for the callback uri (necessary on some servers, e.g. when using SSL and Varnish). Leave blank to use default (blank will work for most cases).<br /> Default: @default_uri/authentication', ['@default_uri' => GoogleAnalyticsCounterFeed::currentUrl()]),
      '#weight' => -7,
    );

    try {
      if ($this->common->isAuthenticated()) {
        return parent::buildForm($form, $form_state);
      }
      else {
        $t_args = [
          ':href' => Url::fromRoute('google_analytics_counter.admin_auth_form', [], ['absolute' => TRUE])
            ->toString(),
          '@href' => 'authenticate here',
        ];
        drupal_set_message($this->t('No Google Analytics profile has been authenticated! Google Analytics Counter can not fetch any new data. Please <a href=:href>@href</a>.', $t_args), 'warning');
        return parent::buildForm($form, $form_state);
      }
    }
    catch (RuntimeException $e) {
      \Drupal::logger('google_analytics_counter')->alert('Google Analytics Counter is not authenticated: ' . $e->getMessage());
    }
  }

  /**
   * @param $element
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param $complete_form
   */
  public static function validateStartDate(&$element, FormStateInterface $form_state, &$complete_form) {
    $value = trim($element['#value']);
    if (strtotime($value) < strtotime('2005-01-01') || strtotime($value) >= strtotime('now')) {
      $form_state->setError($element, t('The start date must be between 2005-01-01 and now.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('google_analytics_counter.settings');
    $config->set('cron_interval', $form_state->getValue('cron_interval'))
      ->set('chunk_to_fetch', $form_state->getValue('chunk_to_fetch'))
      ->set('api_dayquota', $form_state->getValue('api_dayquota'))
      ->set('cache_length', $form_state->getValue('cache_length') * 3600)
      ->set('start_date', $form_state->getValue('start_date'))
      ->set('overwrite_statistics', $form_state->getValue('overwrite_statistics'))
      ->set('profile_id', $form_state->getValue('profile_id'))
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('redirect_uri', $form_state->getValue('redirect_uri'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
