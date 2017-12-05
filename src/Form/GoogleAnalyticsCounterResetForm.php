<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a confirmation form to reset module configuration and states.
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
    return t('Are you sure that you want to revoke Google Analytics Counter\'s authentication with Google?');
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
  public function getDescription() {
    // The number of hours it will take to reindex the site.
    return $this->t('Clicking <strong>Yes</strong> means you will have to reauthenticate with Google. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Todo: Reset configuration values

    // Revoke the state values
    if (\Drupal::state()->get('google_analytics_counter.refresh_token') != NULL) {
      \Drupal::service('google_analytics_counter.common')->revoke();
    }

    // Delete any records from the queue that haven't been processed yet.
    \Drupal::database()->delete('queue')
      ->condition('name', 'google_analytics_counter_worker')
      ->execute();

    $form_state->setRedirectUrl($this->getCancelUrl());

  }

}
