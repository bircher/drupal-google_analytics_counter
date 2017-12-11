<?php

namespace Drupal\google_analytics_counter\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Google Analytics Counter block'.
 *
 * @Block(
 *  id = "google_analytics_counter_form_block",
 *  admin_label = @Translation("Google Analytics Counter"),
 *  category = @Translation("Block")
 * )
 */
class GoogleAnalyticsCounterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon definition.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon
   */
  protected $common;

  /**
   * Constructs a new SiteMaintenanceModeForm.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon $common
   *   Google Analytics Counter Common object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CurrentPathStack $current_path, GoogleAnalyticsCounterCommon $common) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->currentPath = $current_path;
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
      $container->get('path.current'),
      $container->get('google_analytics_counter.common')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'google_analytics_counter',
      '#pageviews' => $this->common->displayGaCount($this->currentPath->getPath()),
    ];
  }

}
