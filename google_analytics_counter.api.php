<?php

/**
 * @file
 * Hooks provided by the Google Analytics Counter module.
 */

/**
 * Alter Select query before it gets executed
 *
 * Here you can customize the select query which writes into the proper storage table
 * the number of pageviews for each node
 *
 * @param SelectQuery $query
 *   Query builder for SELECT statements.
 */
function hook_google_analytics_counter_query_alter(&$query) {
  // e.g. Restrict node pageview storage to node type: blog
  $query->condition('type', 'blog', 'LIKE');
}

/**
 * Alter $request array before retrieving data from Google.
 *
 * Alter request query submitted to google and provide custom filters or parameters
 * For more information on how to customize requests to Google Analytics:
 * @see https://ga-dev-tools.appspot.com/query-explorer/
 *
 * @param array $request
 *   Array with request options.
 */
function hook_google_analytics_counter_request_alter(&$request) {
  // e.g. Only get stats for records from 15th of February, 2014 onwards (instead of 2005, which is the default date)
  $request['start_date'] = strtotime('2014-02-15');
  // e.g. Grab only stats for URLs which start with /blog/
  $request['filters'] = "ga:pagePath=~^/blog/";
}

/**
 * Informs other modules about which nodes have been updated.
 *
 * @param array $updated_nids
 *   Associative array with the new pageview total keyed by the nid.
 */
function hook_google_analytics_counter_update($updated_nids) {

}
