<?php

/**
 * @file
 * Provide views data for google_analytics_counter.module.
 */

/**
 * Implements hook_views_data().
 */
function google_analytics_counter_views_data() {
  $data['google_analytics_counter_storage']['table']['group']  = t('Google Analytics Counter');

  $data['google_analytics_counter_storage']['table']['join'] = array(
    'node_field_data' => array(
      'left_field' => 'nid',
      'field' => 'nid',
    ),
  );

  $data['google_analytics_counter_storage']['pageview_total'] = array(
    'title' => t('Google Analytics views count'),
    'help' => t('The total number of times the node has been viewed as tracked by Google analytics.'),
    'field' => array(
      'id' => 'numeric',
      'click sortable' => TRUE,
     ),
    'filter' => array(
      'id' => 'numeric',
    ),
    'argument' => array(
      'id' => 'numeric',
    ),
    'sort' => array(
      'id' => 'standard',
    ),
  );

  return $data;
}
