<?php

namespace Drupal\google_analytics_counter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates the google analytics counters.
 *
 * @QueueWorker(
 *   id = "google_analytics_counter_worker",
 *   title = @Translation("Google Analytics Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class GoogleAnalyticsWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface   {

  /**
   * The common service to run all the things.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon
   */
  protected $common;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, GoogleAnalyticsCounterCommon $common) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->common = $common;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('google_analytics_counter.common')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if ($data['type'] == 'fetch') {
      $this->common->updatePathCounts($data['index']);
    }
    elseif($data['type'] == 'count') {
      $this->common->updateStorage($data['nid']);
    }
  }

}
