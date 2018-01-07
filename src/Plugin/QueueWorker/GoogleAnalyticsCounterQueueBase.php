<?php

namespace Drupal\google_analytics_counter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides base functionality for Google Analytics Counter workers.
 *
 * @see See https://www.drupal.org/forum/support/module-development-and-code-questions/2017-03-20/queue-items-not-processed
 * @see https://drupal.stackexchange.com/questions/206838/documentation-or-tutorial-on-using-batch-or-queue-services-api-programmatically
 *
 */
abstract class GoogleAnalyticsCounterQueueBase extends QueueWorkerBase implements ContainerFactoryPluginInterface   {

  // Here we don't use the Dependency Injection,
  // but the __construct() and create() methods are necessary.

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static();
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if ($data['type'] == 'fetch') {
      \Drupal::service('google_analytics_counter.manager')->updatePathCounts($data['index']);
    }
    elseif($data['type'] == 'count') {
      \Drupal::service('google_analytics_counter.manager')->updateStorage($data['nid']);
    }
  }

}
