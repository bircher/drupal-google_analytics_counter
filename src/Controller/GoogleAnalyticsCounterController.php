<?php

namespace Drupal\google_analytics_counter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\google_analytics_counter\Form\GoogleAnalyticsCounterResetForm;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class GoogleAnalyticsCounterController.
 *
 * @package Drupal\google_analytics_counter\Controller
 */
class GoogleAnalyticsCounterController extends ControllerBase {

  /**
   * Confirmation callback function.
   */
  public function reset() {
    $form = new GoogleAnalyticsCounterResetForm();
    return \Drupal::formBuilder()->getForm($form);
  }

  /**
   * Redirect to the module's permission settings.
   */
  public function permissions() {
    $url = '/admin/people/permissions#module-google_analytics_counter';
    $redirect = new RedirectResponse($url);
    return $redirect->send();
  }

}
