<?php

/**
 * @file
 * Drupal\google_analytics_counter\Plugin\Filter\GoogleAnalyticsCounterFilter.
 */

namespace Drupal\google_analytics_counter\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon;

/**
 * Add filter to show google analytics counter number.
 *
 * @Filter(
 *   id = "google_analytics_counter_filter",
 *   title = @Translation("Google Analytics Counter tag"),
 *   description = @Translation("Substitutes a special Google Analytics Counter tag [gac|...] with the actual content."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class GoogleAnalyticsCounterFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $text = $this->handleText($text);
    return new FilterProcessResult($text);
  }

  /**
   * Finds [gac|...] tags and replaces them by actual values.
   */
  private function handleText($str) {
    // [gac|path/to/page].
    $matchlink = '';
    $orig_match = '';
    $matches = '';
    // This allows more than one pipe sign (|) ...
    // does not hurt and leaves room for possible extension.
    preg_match_all("/(\[)gac[^\]]*(\])/s", $str, $matches);

    foreach ($matches[0] as $match) {
      // Keep original value.
      $orig_match[] = $match;

      // Remove wrapping [].
      $match = substr($match, 1, (strlen($match) - 2));

      // Create an array of parameter attributions.
      $match = explode("|", $match);

      $path = trim(SafeMarkup::checkPlain(@$match[1]));

      // So now we can display the count based on the path.
      // If no path was defined, the function will detect the current
      // page's count.
      $matchlink[] = GoogleAnalyticsCounterCommon::displayGaCount($path);
    }

    $str = str_replace($orig_match, $matchlink, $str);
    return $str;
  }

}
