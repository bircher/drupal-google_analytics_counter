<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Class GoogleAnalyticsCounterAdminAuthForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterAdminAuthForm extends FormBase {

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
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue collection to use.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(StateInterface $state, GoogleAnalyticsCounterCommon $common) {
    $this->state = $state;
    $this->common = $common;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state'),
      $container->get('google_analytics_counter.common')
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
    $this->common->newGaFeed();

    if ($this->common->isAuthenticated()) {
      $form['revoke'] = array(
        '#type' => 'fieldset',
        '#title' => $this->t('Revoke access and logout'),
        '#description' => $this->t('Revoke your access token to Google Analytics. This action will log you out of your Google Analytics account and stop all reports from displaying on your site.'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#weight' => 5,
      );
      $form['revoke']['revoke_submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Revoke access token'),
      );
    }
    else {
      $form['setup'] = array(
        '#type' => 'fieldset',
        '#title' => $this->t('Initial setup'),
        '#description' => $this->t("When you submit this form, you will be redirected to Google for authentication. Login with the account that has credentials to the Google Analytics profile you'd like to use."),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      );
      $form['setup']['setup_submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Start setup and authorize account'),
      );
    }

    return $form;
  }

  /**
   * Steps through the OAuth process, revokes tokens and saves profiles.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (empty($op = $form_state->getValue('op')->getUntranslatedString())) {
      $op = '';
    }
    switch ($op) {
      case 'Start setup and authorize account':
        $this->common->beginAuthentication();
        break;
      case 'Revoke access token':
        $form_state->setRedirectUrl(Url::fromRoute('google_analytics_counter.admin_dashboard_reset'));
        break;
    }
  }

}
