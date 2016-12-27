<?php

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;

/**
 * Class GoogleAnalyticsCounterAdminAuthForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterAdminAuthForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_analytics_counter_admin_auth';
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
    $form = array();
    $config = $this->config('google_analytics_counter.settings');
    $account = GoogleAnalyticsCounterCommon::newGaFeed();

    if ($account && $account->isAuthenticated()) {
      $webprops = $account->queryWebProperties()->results->items;
      $profiles = $account->queryProfiles()->results->items;
      $options = array();
      $profile_id = $config->get('profile_id');
      $set_default = FALSE;

      // Add optgroups for each web property.
      if (!empty($profiles)) {
        foreach ($profiles as $profile) {
          $webprop = NULL;
          foreach ($webprops as $webprop_value) {
            if ($webprop_value->id == $profile->webPropertyId) {
              $webprop = $webprop_value;
              break;
            }
          }

          $options[$webprop->name][$profile->id] = $profile->name . ' (' . $profile->id . ')';
          // Rough attempt to see if the current site is in the account list.
          if (empty($profile_id) && (parse_url($webprop->websiteUrl, PHP_URL_PATH) == $_SERVER['HTTP_HOST'])) {
            $profile_id = $profile->id;
            $set_default = TRUE;
          }
        }
      }

      // If no profile ID is set yet, set the first profile in the list.
      if (empty($profile_id)) {
        $profile_id = key($options[key($options)]);
        $set_default = TRUE;
      }

      if ($set_default) {
        $config->set('profile_id', $profile_id)->save();
      }

      // Load current profile object.
      foreach ($profiles as $profile) {
        if ($profile->id == $profile_id) {
          $current_profile = $profile;
          $config->set('default_page', isset($current_profile->defaultPage) ? '/' . $current_profile->defaultPage : '/')
            ->save();
          break;
        }
      }

      $form['ga'] = array(
        '#type' => 'fieldset',
        '#title' => $this->t('Settings'),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
        '#weight' => 1,
      );

      $form['ga']['profile_id'] = array(
        '#type' => 'select',
        '#title' => $this->t('Reports profile'),
        '#options' => $options,
        '#default_value' => $profile_id,
        '#description' => $this->t('Choose your Google Analytics profile. The currently active profile is: %profile',
          array('%profile' => $current_profile->name . ' (' . $current_profile->id . ')')
        ),
        '#required' => TRUE,
      );

      if (!empty($options) || $options[0]) {
        $form['ga']['profile_id']['#required'] = TRUE;
      }
      $form['ga']['settings_submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Save settings'),
      );
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
    // Else, there are no profiles, and we should just leave it at setup.
    else {
      $form['setup'] = array(
        '#type' => 'fieldset',
        '#title' => $this->t('Initial setup'),
        '#description' => $this->t("When you submit this form, you will be redirected to Google for authentication. Login with the account that has credentials to the Google Analytics profile you'd like to use."),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
      );
      $form['setup']['client_id'] = array(
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#default_value' => $config->get('client_id'),
        '#size' => 30,
        '#description' => $this->t('Client ID created for the app in the access tab of the %google_link', array(
          '%google_link' => \Drupal::l($this->t('Google API Console'), \Drupal\Core\Url::fromUri('http://code.google.com/apis/console')),
        )),
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

      $redirect_uri = $config->get('redirect_uri');
      if (empty($redirect_uri)) {
        $redirect_uri = GoogleAnalyticsCounterFeed::currentUrl();
      }
      $form['setup']['redirect_host'] = array(
        '#type' => 'textfield',
        '#title' => $this->t('Redirect host'),
        '#default_value' => $config->get('redirect_host'),
        '#size' => 30,
        '#description' => $this->t('Use to override the host for the callback uri (necessary on some servers, e.g. when using SSL and Varnish). Include schema and host, but not uri path. Example: http://example.com. Current redirect URI is %redirect_uri. Leave blank to use default (blank will work for most cases).',
          array(
            '%redirect_uri' => $redirect_uri,
          )
        ),
        '#weight' => -7,
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
    \Drupal::cache()->delete('GoogleAnalyticsCounterFeed');
    $config = $this->config('google_analytics_counter.settings');
    switch ($op) {
      case 'Start setup and authorize account':
        $client_id = $form_state->getValue('client_id');
        $client_secret = $form_state->getValue('client_secret');
        $redirect_uri = GoogleAnalyticsCounterFeed::currentUrl();
        if (!empty($form_state->getValue('redirect_host'))) {
          $config->set('redirect_host', $form_state->getValue('redirect_host'))
            ->save();
          $redirect_uri = $form_state->getValue('redirect_host') . $_SERVER['REQUEST_URI'];
        }

        $config->set('client_id', $client_id)
          ->set('client_secret', $client_secret)
          ->set('redirect_uri', $redirect_uri)
          ->save();

        $gafeed = new GoogleAnalyticsCounterFeed();
        $gafeed->beginAuthentication($client_id, $redirect_uri);
        break;

      case 'Save settings':
        $config->set('profile_id',
          $form_state->getValue('profile_id'))
          ->save();
        drupal_set_message($this->t('Settings have been saved successfully.'));
        break;

      case 'Revoke access token':
        GoogleAnalyticsCounterCommon::revoke();
        break;
    }

    parent::submitForm($form, $form_state);
  }

}
