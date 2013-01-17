<?php

/**
 * Find how many distinct paths does Google Analytics have for this profile. This function is triggered by hook_cron().
 */
function google_analytics_counter_update_path_counts(){

  // Record how long did this chunk take to process.
  $chunkprocessbegin = time();

  // Needing to stay under the Google Analytics API quota, let's count how many API retrievals were made in the last 24 hours.
  // @todo We should better take into consideration that the quota is reset at midnight PST (note: time() always returns UTC).
  $dayquota = variable_get('google_analytics_counter_dayquota', array(0, 0));
  if (REQUEST_TIME - $dayquota[0] >= 86400) {
    // If last API request was more than a day ago, set monitoring time to now.
    $dayquota[0] = REQUEST_TIME;
    $dayquota[1] = 0;
    variable_set('google_analytics_counter_dayquota', array($dayquota[0], $dayquota[1]));
  }
  //dpm($dayquota);
  // Are we over the GA API limit?
  $maxdailyrequests = variable_get('google_analytics_counter_api_dayquota', 10000); // see http://code.google.com/apis/analytics/docs/gdata/gdataDeveloperGuide.html#quota
  if ($dayquota[1] > $maxdailyrequests) {
    //dpm('limit hit!');
    watchdog('Google Analytics Counter', 'Google Analytics API quota of '.$maxdailyrequests.' requests has been reached. Will NOT fetch data from Google Analytics for the next '.($dayquota[0]+86400-REQUEST_TIME).' seconds. See <a href="/admin/config/system/google_analytics_counter">the Google Analytics Counter settings page</a> for more info.', NULL, WATCHDOG_ERROR);
    return;
  }

  // How many results to ask from GA in one request. Default on 1000 to fit most systems (e.g. those with no external cron).
  $chunk = variable_get('google_analytics_counter_chunk_to_fetch', 1000);
  // In case there are more than $chunk path/counts to retrieve from GA, do just one chunk at a time and register that in $step.
  $step = variable_get('google_analytics_counter_data_step', 0);
  // Which GA result to look for first. Must be between 1 - infinity.
  $pointer = $step*$chunk+1; 
  //dpm('START chunk '.$chunk);
  //dpm('START step '.$step);
  //dpm('START pointer '.$pointer);

  // The earliest valid start-date for Google Analytics is 2005-01-01.
  $request = array(
    'dimensions' => array('ga:pagePath'), // date would not be necessary for totals, but we also calculate stats of views per day, so we need it
    'metrics' => array('ga:pageviews'),
    'start_date' => strtotime('2005-01-01'),
      'end_date' => strtotime('tomorrow'), // Using 'tomorrow' to offset any timezone shift between the hosting and Google servers.
      'start_index' => $pointer,
      'max_results' => $chunk,
      //'filters' => 'ga:pagePath==/node/3',
      //'#start_date' => strtotime('2005-01-01'), // We want to retrieve all page views for this path. The earliest valid start-date for Google Analytics is 2005-01-01.
      //'sort_metric' => array('ga:date'),
      );

  $resultcount = FALSE;
  $cachehere = array(
    'cid' => 'google_analytics_counter_' . md5(serialize($request)),
    'expire' => google_analytics_counter_cache_time(),
      'refresh' => FALSE, //@todo
      );
  $new_data = @google_analytics_counter_report_data($request, $cachehere);
  //dpm($new_data);return;exit();
  if(!empty($new_data->error)){ // If NULL then there is no error.
    watchdog('Google Analytics Counter', 'Problem fetching data from Google Analytics: ' . $new_data->error . ' Did you authenticate any Google Analytics profille? See <a href="/admin/config/system/google_analytics_counter/authentication">here</a>.', NULL, WATCHDOG_ERROR);
    // Nothing to do; return.
    //return;
  }
  else {
    $resultsretrieved = $new_data->results->rows;
    foreach($resultsretrieved as $val){

      // http://drupal.org/node/310085
      db_merge('google_analytics_counter')
      ->key(array('pagepath_hash' => md5($val['pagePath'])))
      ->fields(array(
        'pagepath' => $val['pagePath'],
        'pageviews' => $val['pageviews'],
        ))
      ->execute();

    }
  }
  //dpm($new_data);
  
  // The total number of records for this profile.
  $resultcount = @$new_data->results->totalResults;
  // Store it in a variable.
  variable_set('google_analytics_counter_totalpaths', $resultcount);

  // The total number of hits for all records for this profile.
  $totalhits = @$new_data->results->totalsForAllResults['pageviews']; 
  variable_set('google_analytics_counter_totalhits', $totalhits);
  //dpm('totalhits: '.$totalhits);

  // Set the pointer.
  $pointer += $chunk;

  // Increase the GA request limit tracker.
  variable_set('google_analytics_counter_dayquota', array($dayquota[0], ($dayquota[1]+1)));

  //dpm('step: '.$step.' | '.$pointer . ' out of total ' .$resultcount);
  watchdog('Google Analytics Counter', 'Retrieved '.sizeof(@$new_data->results->rows).' items from Google Analytics data for paths '.($pointer-$chunk).'-'.($pointer-$chunk-1+sizeof(@$new_data->results->rows)).'.', NULL, WATCHDOG_INFO);

  // OK now increase or zero $step
  if ($pointer <= $resultcount){
    // If there are more results than what we've reached with this chunk, increase step to look further during the next run.
    $newstep = $step+1;
  }
  else {
    $newstep = 0;
  }
  //dpm('newstep: '.$newstep);
  variable_set('google_analytics_counter_data_step', $newstep);

  // Record how long did this chunk take to process.
  variable_set('google_analytics_counter_chunk_process_time', time()-$chunkprocessbegin);

}

/**
 * Calculate pageviews for one path (with any aliases).
 */
function google_analytics_counter_get_sum_per_path($path, $cacheon = TRUE) {

  // Recognize special path 'all' to get the sum of all pageviews for the profile.
  if ($path == 'all') {
    //dpm('yep: '.variable_get('google_analytics_counter_totalhits', 0));
    return variable_get('google_analytics_counter_totalhits', 0);
  }

  $cacheid = md5($path);
  if ($cache = cache_get('google_analytics_counter_page_'.$cacheid) AND $cacheon) {
    $sum_of_pageviews = $cache->data;
    //dpm('CACHED');
  }
  else {
    // Do your expensive calculations here, and populate $my_data

    // Get pageviews for this path and all its aliases.
    // NOTE: Here $path does NOT have an initial slash because it's coming from either check_plain($_GET['q']) (block) or from a tag like [gac|node/N].
    //dpm('path: '.$path);
    $unprefixedaliases = _google_analytics_counter_path_aliases($path);
    //dpm($unprefixedaliases);
    $allpaths = array();
    foreach($unprefixedaliases as $val){
      // Google Analytics stores initial slash as well, so let's prefix them.
      $allpaths[] = md5('/'.$val);
    }
    //dpm($allpaths);

    // Get path counts for each of the path aliases.
    // Search hash values of path -- faster (primary key). E.g. SELECT pageviews FROM `google_analytics_counter` where pagepath_hash IN ('ee1c787bc14bec9945de3240101e919c', 'd884e66c2316317ef6294dc12aca9cef')
    $pathcounts = db_select('google_analytics_counter', 'gac')
    //->fields('gac', array('pagepath', 'pageviews'))
    ->fields('gac', array('pageviews'))
    //->condition('pagepath', array('/bg', '/node/3'), 'IN')
    ->condition('pagepath_hash', $allpaths, 'IN')
    ->execute();
    $sum_of_pageviews = 0;
    foreach($pathcounts as $pathcount){
      //dpm($pathcount);
      $sum_of_pageviews += $pathcount->pageviews;
    }
    //dpm($sum_of_pageviews);

    cache_set('google_analytics_counter_page_'.$cacheid, $sum_of_pageviews, 'cache', CACHE_TEMPORARY);
    //dpm('UNCACHED');
  }

  //dpm($sum_of_pageviews);
  return $sum_of_pageviews;
}

/*
 * Return a list of paths that are aliased with the given path (including the given path).
 */
function _google_analytics_counter_path_aliases($node_path) {

  // Get the normal node path if it is a node.
  $node_path = drupal_get_normal_path($node_path);
  //dpm($node_path);

  // Grab all aliases.
  $aliases = array($node_path);
  $result = db_query("SELECT * FROM {url_alias} WHERE source = :source", array(':source' => $node_path));
  foreach ($result as $row) {
    $aliases[] = $row->alias;
  }

  // If this is the front page, add the base path too, and index.php for good measure. There may be other ways that the user is accessing the front page but we can't account for them all.
  if ($node_path == drupal_get_normal_path(variable_get('site_frontpage', 'node'))) {
    $aliases[] = '';
    $aliases[] = 'index.php';
  }

  return $aliases;
}

/**
 * Request report data.
 *
 * @param $params
 *   An associative array containing:
 *   - profile_id: required [default=variable_get('google_analytics_counter_profile_id')]
 *   - metrics: required.
 *   - dimensions: optional [default=none]
 *   - sort_metric: optional [default=none]
 *   - filters: optional [default=none]
 *   - segment: optional [default=none]
 *   - start_date: optional [default=GA release date]
 *   - end_date: optional [default=today]
 *   - start_index: optional [default=1]
 *   - max_results: optional [default=10,000]
 * @param $cache_options
 *   An optional associative array containing:
 *   - cid: optional [default=md5 hash]
 *   - expire: optional [default=CACHE_TEMPORARY]
 *   - refresh: optional [default=FALSE]
 */
function google_analytics_counter_report_data($params = array(), $cache_options = array()) {

  $params_defaults = array(
    'profile_id' => 'ga:' . variable_get('google_analytics_counter_profile_id', 0),
    );

  $params += $params_defaults;

  $GAFeed = google_analytics_counter_new_gafeed();
  $GAFeed->queryReportFeed($params, $cache_options);

  return $GAFeed;
}


/**
 * Get pageviews for nodes and write them to Drupal core table node_counter. This function is triggered by hook_cron().
 */
function google_analytics_counter_update_node_counter(){

  // Record how long did this chunk take to process.
  $chunkprocessbegin = time();

  // The total number of nodes.
  $dbresult = db_select('node', 'n')
  ->fields('n')
  ->execute();
  $resultcount = $dbresult->rowCount();
  //dpm('totalnodes: '.$resultcount);
  // Store it in a variable.
  variable_set('google_analytics_counter_totalnodes', $resultcount);

  // How many node counts to update one cron run. 
  // We use the same chunk size as when getting paths in google_analytics_counter_update_path_counts().
  $chunk = variable_get('google_analytics_counter_chunk_to_fetch', 1000);
  //$chunk = 2; // JUST TESTING! @todo
  // In case there are more than $chunk nodes to process, do just one chunk at a time and register that in $step.
  $step = variable_get('google_analytics_counter_node_data_step', 0);
  // Which node to look for first. Must be between 0 - infinity.
  $pointer = $step*$chunk; 
  //dpm('START chunk '.$chunk);
  //dpm('START step '.$step);
  //dpm('START pointer '.$pointer);

  $dbresults = db_select('node', 'n')
  ->fields('n', array('nid'))
  //->condition('pagepath', 'node/%', 'LIKE')
  ->range($pointer, $chunk)
  ->execute();
  foreach($dbresults as $dbresult){
    //dpm($dbresult);

    $path = 'node/'.$dbresult->nid;

    // Get the count for this node (uncached)
    $sum_of_pageviews = google_analytics_counter_get_sum_per_path($path, FALSE);
    //dpm($path.' ... '.$sum_of_pageviews);

    // Don't write zeroes.
    if ($sum_of_pageviews == 0) continue;

    // Write the count to Drupal core table node_counter
    db_merge('node_counter')
    ->key(array('nid' => $dbresult->nid))
    ->fields(array(
      'daycount' => 0,
      'totalcount' => $sum_of_pageviews,
      'timestamp' => REQUEST_TIME,
      ))
    ->execute();

  }

  // Set the pointer.
  $pointer += $chunk;
  //dpm('END pointer: '.$pointer);

  //dpm('step: '.$step.' | '.$pointer . ' out of total ' .$resultcount);
  watchdog('Google Analytics Counter', 'Attempted updating '. $dbresults->rowCount() .' records in node_counter from Google Analytics data ' . ($pointer-$chunk+1) . '-'.($pointer-$chunk+$dbresults->rowCount()).'.', NULL, WATCHDOG_INFO);

  // OK now increase or zero $step
  if ($pointer < $resultcount){
    // If there are more results than what we've reached with this chunk, increase step to look further during the next run.
    $newstep = $step+1;
    //dpm('step +1: '.$newstep);
  }
  else {
    $newstep = 0;
    //dpm('step zero: '.$newstep);
  }
  //dpm('newstep: '.$newstep);
  variable_set('google_analytics_counter_node_data_step', $newstep);

  // Record how long did this chunk take to process.
  variable_set('google_analytics_counter_chunk_node_process_time', time()-$chunkprocessbegin);

}
