<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterManager;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GoogleAnalyticsCounterAuthForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterAuthForm extends FormBase {

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
    $this->config = $config_factory->get('google_analytics_counter.settings');
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
    return 'google_analytics_counter_admin_auth';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Initialize the feed to trigger the fetching of the tokens.
    $this->manager->newGaFeed();

    if ($this->manager->isAuthenticated() === TRUE) {
      $form['revoke'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Revoke authentication'),
        '#description' => $this->t('This action will revoke authentication from Google Analytics.'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#weight' => 5,
      ];
      $form['revoke']['revoke_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Revoke authentication'),
      ];
    }
    else {
      $form['setup'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Set up authentication'),
        '#description' => $this->t("This action will redirect you to Google. Login with the account you'd like to use."),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      ];
      $form['setup']['setup_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Authenticate'),
      ];
    }

    return $form;
  }

  /**
   * Steps through the OAuth process, revokes tokens and saves profiles.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config;
    if (empty($op = $form_state->getValue('op')->getUntranslatedString())) {
      $op = '';
    }
    switch ($op) {
      case 'Authenticate':
        $this->manager->beginAuthentication();
        if (!empty($config->get('general_settings.profile_id_prefill'))) {
          \Drupal::configFactory()
            ->getEditable('google_analytics_counter.settings')
            ->set('general_settings.profile_id', $config->get('general_settings.profile_id_prefill'))
            ->save();
        }

        break;
      case 'Revoke authentication':
        $form_state->setRedirectUrl(Url::fromRoute('google_analytics_counter.admin_auth_revoke'));
        break;
    }
  }

}
