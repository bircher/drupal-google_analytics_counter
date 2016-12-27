<?php
/**
 * @file
 * Contains \Drupal\search\Plugin\Block\SearchBlock.
 */

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
    $block_content = GoogleAnalyticsCounterCommon::displayGaCount();
    if ($block_content == '') {
      // If unknown, for some reason.
      // Instead of t('N/A'). Suppose better to use 0 because it's true,
      // that path has been recorded zero times by GA.
      // Path may not exist or be private or too new.
      $block_content = 0;
    }
    return array(
      '#markup' => $block_content,
    );
  }

}
