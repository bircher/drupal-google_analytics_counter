<?php

/**
 * @file
 * Settings form.
 */

/**
 * Config form shown at admin/config/system/google_analytics_counter
 */
function google_analytics_counter_admin($form, &$form_state) {

  $defaultcroninterval = 30;
  $chunk = 1000; // Could be up to 10000 but keeping the default low so that it works even for people without external cron.
  $dayquota = 10000;

  $times = array();
  $intervals = array(1, 3, 5, 10, 20, 30, 60, 180, 360, 720, 1440);
  foreach ($intervals as $interval) {
    $times[] = $interval;
  }
  $form['google_analytics_counter_cron_interval'] = array(
    '#type' => 'select',
    '#title' => t('Minimum time between Google Analytics data fetching'),
    '#default_value' => variable_get('google_analytics_counter_cron_interval', $defaultcroninterval),
    '#description' => t('Google Analytics statistical data is fetched and processed via a cron job. If your cron runs too frequently, you may waste your GA daily quota too fast. Set here the minimum time that needs to elapse before the Google Analytics Counter cron runs (even if your cron job runs more frequently). Specify the time in <em>minutes</em>. Default: %defaultcroninterval minutes.', array('%defaultcroninterval' => check_plain($defaultcroninterval))),
    '#options' => drupal_map_assoc($times),
    '#required' => TRUE,
    );

  $times = array();
  $curquota = variable_get('google_analytics_counter_api_dayquota', $dayquota);
  for ($chunks = 1; $chunks <= $curquota/1000; $chunks++) {
    $times[] = $chunks * 1000;
  }
  $form['google_analytics_counter_chunk_to_fetch'] = array(
    '#type' => 'select',
    '#title' => t('Number of items to fetch from Google Analytics in one request'),
    '#default_value' => variable_get('google_analytics_counter_chunk_to_fetch', $chunk),
    '#description' => t('How many items will be fetched from Google Analytics in one request (during a cron run). The maximum allowed by Google is 10000. Default: %chunk items.', array('%chunk' => check_plain($chunk))),
    '#options' => drupal_map_assoc($times),
    '#required' => TRUE,
    );

  $form['google_analytics_counter_api_dayquota'] = array(
    '#type' => 'textfield',
    '#title' => t('Maximum GA API requests per day'),
    '#default_value' => variable_get('google_analytics_counter_api_dayquota', $dayquota),
    '#size' => 9,
    '#maxlength' => 9,
    '#description' => t('This is the <em>daily limit</em> of requests <em>per profile</em> to the Google Analytics API. You don\'t need to change this value until Google relaxes their quota policy. Current value: %dayquota.<br />It is reasonable to expect that Google will increase this low number sooner rather than later, so watch the <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#discovery" target="_blank">quota</a> page for changes.<br />To get the full quota, you must <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#full_quota" target="_blank">register your Analytics API</a>.', array('%dayquota' => check_plain($dayquota))),
    '#required' => TRUE,
    );

  // GA response cache options
  $times = array();
  for ($hours = 1; $hours <= 24; $hours++) {
    $times[] = $hours * GOOGLE_ANALYTICS_COUNTER_HOUR;
  }
  for ($days = 1; $days <= 6; $days++) {
    $times[] = $days * GOOGLE_ANALYTICS_COUNTER_DAY;
  }
  for ($weeks = 1; $weeks <= 4; $weeks++) {
    $times[] = $weeks * GOOGLE_ANALYTICS_COUNTER_WEEK;
  }

  $form['google_analytics_counter_cache_length'] = array(
    '#type' => 'select',
    '#title' => t('Google Analytics query cache'),
    '#description' => t('Limit the minimum time to elapse between getting fresh data for the same query from Google Analytics. Defaults to 1 day.'),
    '#options' => drupal_map_assoc($times, 'format_interval'),
    '#default_value' => variable_get('google_analytics_counter_cache_length', GOOGLE_ANALYTICS_COUNTER_DAY),
    '#required' => TRUE,
  );

  // GA starting date settings.
  $form['google_analytics_counter_start_date'] = array(
    '#type' => 'fieldset',
    '#title' => t('Start date for GA queries'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $variable_date = array(
    date('Y-m-d', REQUEST_TIME-GOOGLE_ANALYTICS_COUNTER_YEAR) => t('Last 365 days'),
    date('Y-m-d', REQUEST_TIME-GOOGLE_ANALYTICS_COUNTER_MONTH) => t('Last 31 days'),
    date('Y-m-d', REQUEST_TIME-GOOGLE_ANALYTICS_COUNTER_WEEK) => t('Last 7 days'),
    date('Y-m-d', REQUEST_TIME-GOOGLE_ANALYTICS_COUNTER_DAY) => t('Last day'),
  );
  //dpm($variable_date);
  $form['google_analytics_counter_start_date']['google_analytics_counter_variable_start_date'] = array(
    '#type' => 'select',
    '#title' => t('GA variable starting date'),
    '#default_value' => variable_get('google_analytics_counter_variable_start_date', date('Y-m-d', REQUEST_TIME-GOOGLE_ANALYTICS_COUNTER_YEAR)),
    '#description' => t('Set a period for the GA queries. This allows you to get only the recent values from GA. For example, only for the last month.'),
    '#options' => $variable_date,
    //'#required' => TRUE,
    //'#attributes' => array('disabled' => 'disabled'),
  );

  // Leaves open advanced settings panel if checkbox is active.
  if (variable_get('google_analytics_counter_advanced_date_checkbox', 1) == 1){
    $advanced_date_settings = FALSE;
    $form['google_analytics_counter_start_date']['google_analytics_counter_variable_start_date']['#attributes'] = array('disabled' => TRUE);
  }
  else {
    $advanced_date_settings = TRUE;
  }

  $form['google_analytics_counter_start_date']['google_analytics_counter_advanced_date'] = array(
    '#type' => 'fieldset',
    '#title' => t('Override by a fixed start date'),
    '#collapsible' => TRUE,
    '#collapsed' => $advanced_date_settings,
  );

  $form['google_analytics_counter_start_date']['google_analytics_counter_advanced_date']['google_analytics_counter_advanced_date_checkbox'] = array(
    '#type' => 'checkbox',
    '#title' => t('<b>OVERRIDE</b>'),
    '#default_value' => variable_get('google_analytics_counter_advanced_date_checkbox', 1),
    '#description' => t('Select if you wish to override the above variable date with the <b>GA fixed starting date</b> below.'),
  );

  if (module_exists('date_api')) {
    $form['google_analytics_counter_start_date']['google_analytics_counter_advanced_date']['google_analytics_counter_fixed_start_date'] = array(
      '#type' => 'date_select',
      '#title' => t('GA fixed starting date'),
      '#description' => t('Set a fixed starting date for the GA queries. To retrieve all statistics, use the date when Google Analytics was launched: 2005-01-01 (this is the default option).'),
      '#date_format' => 'Y-m-d',
      '#default_value' => variable_get('google_analytics_counter_fixed_start_date', '2005-01-01'),
      '#date_year_range' => '2005:now',
      '#required' => FALSE,
    );
  }
  else {
    $form['google_analytics_counter_start_date']['google_analytics_counter_advanced_date']['google_analytics_counter_error_date_api'] = array(
      '#markup' => '<b style="color:red">Can\'t set a fixed Date, for this function you need to install and enable the Date API module!</b>'
    );
  }

  // If the statistics module is off, the own table storage MUST be used. See also https://www.drupal.org/node/2275575
  if (!module_exists('statistics')){
    $default_value = 1;
  }
  else {
    $default_value = variable_get('google_analytics_counter_storage', '0');
  }
  $form['google_analytics_counter_storage'] = array(
    '#type' => 'radios',
    '#title' => t('Data storage location'),
    '#options' => array(
      '1' => t('Use this module\'s database table. This is the recommended option.'),
      '0' => t('Overwrite total pageview values in table node_counter provided by the core module Statistics. If the Statistics module is disabled, this option gets deactivated as well. Only really useful for backward compatibility on sites that previously used version 7.2 or older and do not wish to change configuration (e.g. views) immediately. This option is deprecated.'),
    ),
    '#default_value' => $default_value, // For backward compatibility keeping it on the deprecated option.
    '#required' => TRUE,
    //'#description' => t("")
  );
  // Disable the Statistics option if the module is off. And force the own database table option.
  if (!module_exists('statistics')){
    $form['google_analytics_counter_storage'][0]['#disabled'] = TRUE; // See http://drupal.stackexchange.com/a/17550/196
  }

  return system_settings_form($form);
}
