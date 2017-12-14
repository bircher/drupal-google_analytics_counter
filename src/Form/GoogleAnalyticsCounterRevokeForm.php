<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Class GoogleAnalyticsCounterRevokeForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterRevokeForm extends ConfirmFormBase {

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
   * Defines a confirmation form to revoke Google authentication.
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
    return 'google_analytics_counter_admin_revoke';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to revoke authentication?');
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
    // todo: Send the user back to the form from which he came.
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

    // Revoke the state values
    $this->common->revoke();

    // Set redirect.
    $form_state->setRedirectUrl($this->getCancelUrl());

    // Print message.
    $this->common->noProfileMessage();
  }

}
