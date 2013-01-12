<?php

/**
 * Settings form.
 */
function google_analytics_counter_admin($form, &$form_state) {

$defaultcroninterval = 60;
$form['google_analytics_counter_cron_interval'] = array(
  '#type' => 'textfield',
  '#title' => t('Minimum time between Google Analytics data fetching'),
  '#default_value' => variable_get('google_analytics_counter_cron_interval', $defaultcroninterval),
  '#size' => 9,
  '#maxlength' => 9,
  '#description' => t('Google Analytics statistical data is fetched via cron. If your cron runs too frequently, you may waste your GA daily quota too fast. Set here the minimum time that needs to elapse before the Google Analytics Counter cron runs (even if your cronjob runs more frequently). Specify the time in <em>minutes</em>. Default: '.$defaultcroninterval.' minutes.'),
  );

$chunk = 1000;
$form['google_analytics_counter_chunk_to_fetch'] = array(
  '#type' => 'textfield',
  '#title' => t('Number of items to fetch from Google Analytics in one request'),
  '#default_value' => variable_get('google_analytics_counter_chunk_to_fetch', $chunk),
  '#size' => 9,
  '#maxlength' => 9,
  '#description' => t('How many items will be fetched from Google Analytics in one request (during a cron run). The maximum allowed by Google is 10000 but it may take too long and time out. Default: '.$chunk.' items.'),
  );

$dayquota = 10000;
$form['google_analytics_counter_api_dayquota'] = array(
  '#type' => 'textfield',
  '#title' => t('Maximum GA API requests per day'),
  '#default_value' => variable_get('google_analytics_counter_api_dayquota', $dayquota),
  '#size' => 9,
  '#maxlength' => 9,
  '#description' => t('This is the <em>daily limit</em> of requests <em>per profile</em> to the Google Analytics API. You don\'t need to change this value until Google relaxes their quota policy. Current value: '.$dayquota.'.<br />It is reasonable to expect that Google will increase this low number sooner rather than later, so watch the <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#discovery" target="_blank">quota</a> page for changes.<br />To get the full quota, you must <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#full_quota" target="_blank">register your Analytics API</a>.'),
  );

return system_settings_form($form);
}
