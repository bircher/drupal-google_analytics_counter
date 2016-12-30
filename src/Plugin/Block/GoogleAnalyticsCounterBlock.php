<?php

namespace Drupal\google_analytics_counter\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;

/**
 * Provides a 'count form' block.
 *
 * @Block(
 *   id = "google_analytics_counter_form_block",
 *   admin_label = @Translation("Google Analytics Counter")
 * )
 */
class GoogleAnalyticsCounterBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    // TODO: use dependency injection.
    $block_content = \Drupal::service('google_analytics_counter.common')->displayGaCount(\Drupal::service('path.current')->getPath());
    return array(
      '#markup' => $block_content,
    );
  }

}
