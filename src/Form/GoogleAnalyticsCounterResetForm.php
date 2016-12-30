<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;

/**
 * Defines a confirmation form for reset module data.
 */
class GoogleAnalyticsCounterResetForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_analytics_counter_admin_reset';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure that you wish to reset all configuration variables of module Google Analytics Counter to their default values?');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Yes');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return t('No');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('google_analytics_counter.admin_dashboard_form');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: reset even more settings..
    \Drupal::service('google_analytics_counter.common')->revoke();
  }

}
