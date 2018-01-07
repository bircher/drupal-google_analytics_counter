<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterManager;
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
   * Drupal\google_analytics_counter\GoogleAnalyticsCounterManager definition.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterManager
   */
  protected $manager;

  /**
   * Constructs a new SiteMaintenanceModeForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue collection to use.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterManager $manager
   *   Google Analytics Counter Manager object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, GoogleAnalyticsCounterManager $manager) {
    parent::__construct($config_factory);
    $this->state = $state;
    $this->manager = $manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('google_analytics_counter.manager')
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
      '#title' => $this->t('Minimum time to wait before fetching Google Analytics data (in minutes)'),
      '#default_value' => $config->get('general_settings.cron_interval'),
      '#min' => 0,
      '#max' => 10000,
      '#description' => $this->t('Google Analytics data is fetched and processed during cron. If cron runs too frequently, the Google Analytics daily quota may be <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas" target="_blank">exceeded</a>.<br />Set the minimum number of <em>minutes</em> that need to pass before the Google Analytics Counter cron runs. Default: 30 minutes.'),
      '#required' => TRUE,
    ];

    $form['chunk_to_fetch'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items to fetch from Google Analytics in one request'),
      '#default_value' => $config->get('general_settings.chunk_to_fetch'),
      '#min' => 1,
      '#max' => 10000,
      '#description' => $this->t('How many items will be fetched from Google Analytics in one request. The maximum allowed by Google is 10000. Default: 1000 items.'),
      '#required' => TRUE,
    ];

    $form['api_dayquota'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum GA API requests per day'),
      '#default_value' => $config->get('general_settings.api_dayquota'),
      '#min' => 1,
      '#max' => 10000,
      '#description' => $this->t('This is the daily limit of requests <strong>per view (profile)</strong> per day (cannot be increased). You don\'t need to change this value until Google changes their quota policy. <br />See <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas" target="_blank">Limits and Quotas on API Requests</a> for information on Google\'s quota policies. To exceed Google\'s quota limits, look for <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#full_quota" target="_blank">Exceeding quota limits</a> on the same page.'),
      '#required' => TRUE,
    ];

    $form['cache_length'] = [
      '#type' => 'number',
      '#title' => t('Google Analytics query cache (in hours)'),
      '#description' => t('Limit the minimum time in hours to elapse between getting fresh data for the same query from Google Analytics. Default: 24 hours.'),
      '#default_value' => $config->get('general_settings.cache_length') / 3600,
      '#min' => 1,
      '#max' => 10000,
      '#required' => TRUE,
    ];

    $t_args = [
      '%queue_count' => $this->manager->getCount('queue'),
    ];
    $form['queue_time'] = [
      '#type' => 'number',
      '#title' => $this->t('Queue Time (in seconds)'),
      '#default_value' => $config->get('general_settings.queue_time'),
      '#min' => 1,
      '#max' => 10000,
      '#required' => TRUE,
      '#description' => $this->t('%queue_count items are in the queue. The number of items in the queue should be 0 after cron runs.<br /><strong>Note:</strong> Having 0 items in the queue confirms that pageview counts are up to date. Increase Queue Time to process all the queued items. Default: 120 seconds.', $t_args),
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
      '#title' => $this->t('Prefill a Google View (Profile) ID'),
      '#default_value' => $config->get('general_settings.profile_id_prefill'),
      '#size' => 20,
      '#maxlength' => 20,
      '#description' => $this->t('If you know which Google view (profile) you will be using, you may enter its ID here. Otherwise, you <u>must</u> come back to this form after you have <a href=:href>@href</a> and select a view (profile) from the list in <strong>Google Views (Profiles) IDs</strong>.<br />Refer to your Google Views at <a href="https://360suite.google.com/orgs?authuser=0" target="_blank">Google Analytics 360 Suite</a>. Google Views (Profiles) IDs are eight digit numbers, e.g. 32178653', $t_args),
      '#states' => [
        'visible' => [
          ':input[name="profile_id"]' => ['empty' => TRUE],
        ],
      ],
    ];

    $options = $this->manager->getWebPropertiesOptions();
    if (!$options) {
      $options = [$config->get('general_settings.profile_id') => 'Unauthenticated'];
    }
    $form['profile_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Google Views (Profiles) IDs'),
      '#options' => $options,
      '#default_value' => $config->get('general_settings.profile_id'),
      '#description' => $this->t('Choose a Google Analytics view (profile). The options depend on the authenticated account.<br />If you are not authenticated, \'Unauthenticated\' is the only available option. See the README.md included with this module.'),
    ];

    $form['setup'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Initial setup'),
      '#description' => $this->t("The google key details can only be changed when not authenticated."),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#disabled' => $this->manager->isAuthenticated() === TRUE,
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
      '#title' => $this->t('Authorized redirect URI'),
      '#default_value' => $config->get('general_settings.redirect_uri'),
      '#size' => 30,
      '#description' => $this->t('Add the path that users are redirected to after they have authenticated with Google.<br /> Default: <strong>@default_uri/authentication</strong>', ['@default_uri' => GoogleAnalyticsCounterFeed::currentUrl()]),
      '#weight' => -7,
    ];

    if (empty($config->get('general_settings.profile_id'))) {
        $this->manager->notAuthenticatedMessage();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('google_analytics_counter.settings');

    // hook_queue_info_alter() requires a cache rebuild.
    if ($form_state->getValue('queue_time') != $config->get('general_settings.queue_time')) {
      drupal_flush_all_caches();
    }

    $config->set('general_settings.cron_interval', $form_state->getValue('cron_interval'))
      ->set('general_settings.chunk_to_fetch', $form_state->getValue('chunk_to_fetch'))
      ->set('general_settings.api_dayquota', $form_state->getValue('api_dayquota'))
      ->set('general_settings.cache_length', $form_state->getValue('cache_length') * 3600)
      ->set('general_settings.queue_time', $form_state->getValue('queue_time'))
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
