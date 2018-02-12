<?php

namespace Drupal\google_analytics_counter\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\google_analytics_counter\GoogleAnalyticsCounterManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add filter to show google analytics counter number.
 *
 * @Filter(
 *   id = "google_analytics_counter_filter",
 *   title = @Translation("Google Analytics Counter tag"),
 *   description = @Translation("Substitutes a special Google Analytics Counter token [gac|/my-path] which prints the pageview count."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class GoogleAnalyticsCounterFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Drupal\google_analytics_counter\GoogleAnalyticsCounterCommon definition.
   *
   * @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterManager
   */
  protected $manager;

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
   * @param \Drupal\google_analytics_counter\GoogleAnalyticsCounterManager $manager
   *   Google Analytics Counter Manager object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CurrentPathStack $current_path, GoogleAnalyticsCounterManager $manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->currentPath = $current_path;
    $this->manager = $manager;
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
      $container->get('google_analytics_counter.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $text = $this->handleText($text);
    return new FilterProcessResult($text);
  }

  /**
   * Finds [gac|path/to/page] tags and replaces them by actual values.
   */
  private function handleText($string) {
    // [gac|path/to/page].
    $matchlink = '';
    $orig_match = '';
    $matches = '';
    // This allows more than one pipe sign (|) ...
    // does not hurt and leaves room for possible extension.
    preg_match_all("/(\[)gac[^\]]*(\])/s", $string, $matches);

    foreach ($matches[0] as $match) {
      // Keep original value.
      $orig_match[] = $match;

      // Remove wrapping [].
      $match = substr($match, 1, (strlen($match) - 2));

      // Create an array of parameter attributions.
      $match = explode("|", $match);

      $path = trim(SafeMarkup::checkPlain(@$match[1]));

      // So now we can display the count based on the path.
      // If no path was defined, the function will detect the
      // current node's count.
      // Todo: make pageview totals and pagepaths equal to avoid confusion.
      $matchlink[] = $this->manager->displayGaCount($this->currentPath->getPath());
    }

    $string = str_replace($orig_match, $matchlink, $string);
    return $string;
  }

}
