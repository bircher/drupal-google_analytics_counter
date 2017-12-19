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

    $form['cron_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum time to wait before fetching Google Analytics data'),
      '#default_value' => $config->get('general_settings.cron_interval'),
      '#description' => $this->t('Google Analytics data is fetched and processed during cron. If cron runs too frequently, the Google Analytics daily quota may be <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas" target="_blank">exceeded</a>.<br />Set the minimum number of <em>minutes</em> that need to pass before the Google Analytics Counter cron runs. Default: 30 minutes.'),
      '#required' => TRUE,
    ];

    $form['chunk_to_fetch'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items to fetch from Google Analytics in one request'),
      '#default_value' => $config->get('general_settings.chunk_to_fetch'),
      '#min' => 1,
      '#max' => 10000,
      '#description' => $this->t('How many items will be fetched from Google Analytics in one request (during a cron run). The maximum allowed by Google is 10000. Default: 1000 items.'),
      '#required' => TRUE,
    ];

    $form['api_dayquota'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum GA API requests per day'),
      '#default_value' => $config->get('general_settings.api_dayquota'),
      '#size' => 9,
      '#maxlength' => 9,
      '#description' => $this->t('This is the daily limit of requests <strong>per view (profile)</strong> per day (cannot be increased). You don\'t need to change this value until Google changes their quota policy. <br />See <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas" target="_blank">Limits and Quotas on API Requests</a> for information on Google\'s quota policies. To exceed Google\'s quota limits, look for <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#full_quota" target="_blank">Exceeding quota limits</a> on the same page.'),
      '#required' => TRUE,
    ];

    $form['cache_length'] = [
      '#type' => 'number',
      '#title' => t('Google Analytics query cache'),
      '#description' => t('Limit the minimum time in hours to elapse between getting fresh data for the same query from Google Analytics. Defaults to 1 day.'),
      '#default_value' => $config->get('general_settings.cache_length') / 3600,
      '#required' => TRUE,
    ];

    // Google Analytics start date settings.
    $form['start_date_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Query Dates for Google Analytics'),
      '#open' => TRUE,
    ];

    $start_date = [
      '-1 day' => $this->t('-1 day'),
      '-1 week' => $this->t('-1 week'),
      '-1 month' => $this->t('-1 month'),
      '-3 months' => $this->t('-3 months'),
      '-6 months' => $this->t('-6 months'),
      '-1 year' => $this->t('-1 year'),
      '2005-01-01' => $this->t('Since 2005-01-01'),
    ];

    $form['start_date_details']['start_date'] = [
      '#type' => 'select',
      '#title' => $this->t('Start date for Google Analytics queries'),
      '#default_value' => $config->get('general_settings.start_date'),
      '#description' => $this->t('The earliest valid start date for Google Analytics is 2005-01-01.'),
      '#options' => $start_date,
      '#states' => [
        'disabled' => [
          ':input[name="advanced_date_checkbox"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="advanced_date_checkbox"]' => ['checked' => FALSE],
        ],
        'visible' => [
          ':input[name="advanced_date_checkbox"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['start_date_details']['advanced_date'] = [
      '#type' => 'details',
      '#title' => $this->t('Query with fixed dates'),
      '#states' => [
        'open' => [
          ':input[name="advanced_date_checkbox"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['start_date_details']['advanced_date']['advanced_date_checkbox'] = [
      '#type' => 'checkbox',
      '#title' => '<strong>' . $this->t('FIXED DATES') . '</strong>',
      '#default_value' => $config->get('general_settings.advanced_date_checkbox'),
      '#description' => t('Select if you wish to query Google Analytics with a fixed start date and a fixed end date.'),
    ];

    $form['start_date_details']['advanced_date']['fixed_start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Fixed start date'),
      '#description' => $this->t('Set a fixed start date for Google Analytics queries. Disabled if FIXED DATES is <strong>unchecked</strong>.'),
      '#default_value' => $config->get('general_settings.fixed_start_date'),
      '#states' => [
        'disabled' => [
          ':input[name="advanced_date_checkbox"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['start_date_details']['advanced_date']['fixed_end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Fixed end date'),
      '#description' => $this->t('Set a fixed end date for Google Analytics queries. Disabled if FIXED DATES is <strong>unchecked</strong>.'),
      '#default_value' => $config->get('general_settings.fixed_end_date'),
      '#states' => [
        'disabled' => [
          ':input[name="advanced_date_checkbox"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['overwrite_statistics'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override the counter of the core statistics module'),
      '#default_value' => $config->get('general_settings.overwrite_statistics'),
      '#disabled' => !\Drupal::moduleHandler()->moduleExists('statistics'),
      '#description' => $this->t('Overwriting the total count of cores statistics module is not advised but may be useful in some situations.')
    ];

    $t_args = [
      ':href' => Url::fromRoute('google_analytics_counter.admin_auth_form', [], ['absolute' => TRUE])
        ->toString(),
      '@href' => 'authenticated',
    ];
    $form['profile_id_prefill'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefill a Google view ID'),
      '#default_value' => $config->get('general_settings.profile_id_prefill'),
      '#size' => 20,
      '#maxlength' => 20,
      '#description' => $this->t('If you know which Google view you will be using, you may enter it now. Otherwise, you <em>must</em> come back to this form after you have <a href=:href>@href</a> and select a view from the list in <strong>Google Views IDs</strong>.<br />Find your Google Views in your <a href="https://360suite.google.com/orgs?authuser=0" target="_blank">Google Analytics 360 Suite</a>. Currently Google Views IDs are eight digit numbers, e.g. 32178653', $t_args),
      '#states' => [
        'visible' => [
          ':input[name="profile_id"]' => ['empty' => TRUE],
        ],
      ],
    ];

    $options = $this->common->getWebPropertiesOptions();
    if (!$options) {
      $options = [$config->get('general_settings.profile_id') => 'Un-authenticated (' . $config->get('general_settings.profile_id') . ')'];
    }
    $form['profile_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Google Views IDs'),
      '#options' => $options,
      '#default_value' => $config->get('general_settings.profile_id'),
      '#description' => $this->t('Choose your Google Analytics view. The options depend on the authenticated account.'),
    ];

    $form['setup'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Initial setup'),
      '#description' => $this->t("The google key details can only be changed when not authenticated."),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#disabled' => $this->common->isAuthenticated() === TRUE,
    ];
    $form['setup']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('general_settings.client_id'),
      '#size' => 30,
      '#description' => $this->t('Client ID created for the app in the access tab of the <a href="http://code.google.com/apis/console" target="_blank">Google API Console</a>'),
      '#weight' => -9,
    ];
    $form['setup']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('general_settings.client_secret'),
      '#size' => 30,
      '#description' => $this->t('Client Secret created for the app in the Google API Console'),
      '#weight' => -8,
    ];

    $form['setup']['redirect_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect URI'),
      '#default_value' => $config->get('general_settings.redirect_uri'),
      '#size' => 30,
      '#description' => $this->t('Use to override the host for the callback uri (necessary on some servers, e.g. when using SSL and Varnish). Leave blank to use default (blank will work for most cases).<br /> Default: <strong>@default_uri/authentication</strong>', ['@default_uri' => GoogleAnalyticsCounterFeed::currentUrl()]),
      '#weight' => -7,
    ];

    // It's a weak test but better than none.
    if (empty($config->get('general_settings.profile_id'))) {
        $this->common->notAuthenticatedMessage();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('google_analytics_counter.settings');

    $config->set('general_settings.cron_interval', $form_state->getValue('cron_interval'))
      ->set('general_settings.chunk_to_fetch', $form_state->getValue('chunk_to_fetch'))
      ->set('general_settings.api_dayquota', $form_state->getValue('api_dayquota'))
      ->set('general_settings.cache_length', $form_state->getValue('cache_length') * 3600)
      ->set('general_settings.start_date', $form_state->getValue('start_date'))
      ->set('general_settings.advanced_date_checkbox', $form_state->getValue('advanced_date_checkbox'))
      ->set('general_settings.fixed_start_date', $form_state->getValue('advanced_date_checkbox') == 1 ? $form_state->getValue('fixed_start_date') : '')
      ->set('general_settings.fixed_end_date', $form_state->getValue('advanced_date_checkbox') == 1 ? $form_state->getValue('fixed_end_date') : '')
      ->set('general_settings.overwrite_statistics', $form_state->getValue('overwrite_statistics'))
      ->set('general_settings.profile_id', $form_state->getValue('profile_id'))
      ->set('general_settings.profile_id_prefill', $form_state->getValue('profile_id_prefill'))
      ->set('general_settings.client_id', $form_state->getValue('client_id'))
      ->set('general_settings.client_secret', $form_state->getValue('client_secret'))
      ->set('general_settings.redirect_uri', $form_state->getValue('redirect_uri'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
