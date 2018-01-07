<?php

namespace Drupal\google_analytics_counter\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\Tests\Traits\Core\CronRunTrait;


/**
 * Tests the google analytics counter settings form.
 *
 * @group statistics
 */
class GoogleAnalyticsCounterSettingsFormTest extends WebTestBase {

  use CronRunTrait;

  /**
   * Disabled config schema checking temporarily until all errors are resolved.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['statistics', 'google_analytics_counter'];

  /**
   * A test user with administrative privileges.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Setup the test.
   */
  public function setUp() {
    parent::setUp();
    // Create page content type.
    $this->drupalCreateContentType([
      'type' => 'page',
      'name' => 'Basic page'
    ]);

    // Create and log in an administrative user.
    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer content types',
      'administer site configuration',
      'create page content',
      'edit any page content',
      'delete any page content',
      'administer google analytics counter',
    ]);
    $this->drupalLogin($this->adminUser);

  }

  /**
   * Verifies that the google analytics counter settings page works.
   */
  public function testForm() {
    $config = $this->config('google_analytics_counter.settings');
    $this->assertFalse($config->get('general_settings.overwrite_statistics'), 'Override the counter of the core statistics module is disabled by default.');

    // Enable counter on content view.
    $edit['general_settings.overwrite_statistics'] = 1;
    $this->drupalPostForm('admin/config/system/google-analytics-counter', $edit, t('Save configuration'));
    $config = $this->config('google_analytics_counter.settings');
    $this->assertTrue($config->get('general_settings.overwrite_statistics'), 'Count content view log is enabled.');
  }
}
