<?php

namespace Drupal\google_analytics_counter\Plugin\QueueWorker;

/**
 * Updates the google analytics counters.
 *
 * @QueueWorker(
 *   id = "google_analytics_counter_worker",
 *   title = @Translation("Import Data from Google Analytics"),
 *   cron = {"time" = 120}
 * )
 */
class GoogleAnalyticsCounterQueue extends GoogleAnalyticsCounterQueueBase {
}
