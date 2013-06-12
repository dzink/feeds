<?php

/**
 * @file
 * Test case for Filefield mapper mappers/filefield.inc.
 */

namespace Drupal\feeds\Tests;

use Drupal\feeds\FeedsEnclosure;

/**
 * Class for testing Feeds file mapper.
 */
class FeedsMapperFileTest extends FeedsMapperTestBase {
  public static function getInfo() {
    return array(
      'name' => 'Mapper: File',
      'description' => 'Test Feeds Mapper support for file fields.',
      'group' => 'Feeds',
    );
  }

  /**
   * Basic test loading a single entry CSV file.
   */
  public function test() {
    // If this is unset (or FALSE) http_request.inc will use curl, and will
    // generate a 404 for this feel url provided by feeds_tests. However, if
    // feeds_tests was enabled in your site before running the test, it will
    // work fine. Since it is truly screwy, lets just force it to use
    // drupal_http_request for this test case.
    // variable_set('feeds_never_use_curl', TRUE);

    // Only download simplepie if the plugin doesn't already exist somewhere.
    // People running tests locally might have it.
    if (!feeds_simplepie_exists()) {
      $this->downloadExtractSimplePie('1.3');
      $this->assertTrue(feeds_simplepie_exists());
      // Reset all the caches!
      $this->resetAll();
    }
    $typename = $this->createContentType(array(), array('files' => 'file'));

    // 1) Test mapping remote resources to file field.

    // Create importer configuration.
    $this->createImporterConfiguration();
    $this->setPlugin('syndication', 'simplepie');
    $this->setSettings('syndication', 'node', array('bundle' => $typename));
    $this->addMappings('syndication', array(
      0 => array(
        'source' => 'title',
        'target' => 'title',
      ),
      1 => array(
        'source' => 'timestamp',
        'target' => 'created',
      ),
      2 => array(
        'source' => 'enclosures',
        'target' => 'field_files:uri',
      ),
    ));
    $nid = $this->createFeedNode('syndication', url('testing/feeds/flickr.xml', array('absolute' => TRUE)));
    $this->assertText('Created 5 nodes');

    $files = $this->listTestFiles();
    $entities = db_select('feeds_item')
      ->fields('feeds_item', array('entity_id'))
      ->condition('id', 'syndication')
      ->execute();

    foreach ($entities as $entity) {
      $this->drupalGet('node/' . $entity->entity_id . '/edit');
      $f = new FeedsEnclosure(array_shift($files), NULL);
      $this->assertText($f->getLocalValue());
    }

    // 2) Test mapping local resources to file field.

    // Copy directory of files, CSV file expects them in public://images, point
    // file field to a 'resources' directory. Feeds should copy files from
    // images/ to resources/ on import.
    $this->copyDir($this->absolutePath() . '/tests/feeds/assets', 'public://images');
    $edit = array(
      'instance[settings][file_directory]' => 'resources',
    );
    $this->drupalPost("admin/structure/types/manage/$typename/fields/node.$typename.field_files", $edit, t('Save settings'));

    // Create a CSV importer configuration.
    $this->createImporterConfiguration('Node import from CSV', 'node');
    $this->setPlugin('node', 'csv');
    $this->setSettings('node', 'node', array('bundle' => $typename));
    $this->setSettings('node', NULL, array('content_type' => ''));
    $this->addMappings('node', array(
      0 => array(
        'source' => 'title',
        'target' => 'title',
      ),
      1 => array(
        'source' => 'file',
        'target' => 'field_files:uri',
      ),
    ));

    // Import.
    $edit = array(
      'feeds[Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher][source]' => url('testing/feeds/files.csv', array('absolute' => TRUE)),
    );
    $this->drupalPost('import/node', $edit, 'Import');
    $this->assertText('Created 5 nodes');

    // Assert: files should be in resources/.
    $files = $this->listTestFiles();
    $entities = db_select('feeds_item')
      ->fields('feeds_item', array('entity_id'))
      ->condition('id', 'node')
      ->execute();

    foreach ($entities as $entity) {
      $this->drupalGet('node/' . $entity->entity_id . '/edit');
      $f = new FeedsEnclosure(array_shift($files), NULL);
      $this->assertRaw('resources/' . $f->getUrlEncodedValue());
    }

    // 3) Test mapping of local resources, this time leave files in place.
    $this->drupalPost('import/node/delete-items', array(), 'Delete');
    // Setting the fields file directory to images will make copying files
    // obsolete.
    $edit = array(
      'instance[settings][file_directory]' => 'images',
    );
    $this->drupalPost('admin/structure/types/manage/' . $typename . '/fields/field_files', $edit, t('Save settings'));
    $edit = array(
      'feeds[Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher][source]' => $GLOBALS['base_url'] . '/testing/feeds/files.csv',
    );
    $this->drupalPost('import/node', $edit, 'Import');
    $this->assertText('Created 5 nodes');

    // Assert: files should be in images/ now.
    $files = $this->listTestFiles();
    $entities = db_select('feeds_item')
      ->fields('feeds_item', array('entity_id'))
      ->condition('id', 'node')
      ->execute();

    foreach ($entities as $entity) {
      $this->drupalGet('node/' . $entity->entity_id . '/edit');
      $f = new FeedsEnclosure(array_shift($files), NULL);
      $this->assertRaw('images/' . $f->getUrlEncodedValue());
    }

    // Deleting all imported items will delete the files from the images/ dir.
    $this->drupalPost('import/node/delete-items', array(), 'Delete');
    foreach ($this->listTestFiles() as $file) {
      $this->assertFalse(is_file("public://images/$file"));
    }
  }

  /**
   * Tests mapping to an image field.
   */
  public function testImages() {
    // variable_set('feeds_never_use_curl', TRUE);

    $typename = $this->createContentType(array(), array('images' => 'image'));

    // Enable title and alt mapping.
    $edit = array(
      'instance[settings][alt_field]' => 1,
      'instance[settings][title_field]' => 1,
    );
    $this->drupalPost("admin/structure/types/manage/$typename/fields/field_images", $edit, t('Save settings'));

    // Create a CSV importer configuration.
    $this->createImporterConfiguration('Node import from CSV', 'image_test');
    $this->setPlugin('image_test', 'csv');
    $this->setSettings('image_test', 'node', array('bundle' => $typename));
    $this->setSettings('image_test', NULL, array('content_type' => ''));
    $this->addMappings('image_test', array(
      0 => array(
        'source' => 'title',
        'target' => 'title',
      ),
      1 => array(
        'source' => 'file',
        'target' => 'field_images:uri',
      ),
      2 => array(
        'source' => 'title2',
        'target' => 'field_images:title',
      ),
      3 => array(
        'source' => 'alt',
        'target' => 'field_images:alt',
      ),
    ));

    // Import.
    $edit = array(
      'feeds[Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher][source]' => url('testing/feeds/files-remote.csv', array('absolute' => TRUE)),
    );
    $this->drupalPost('import/image_test', $edit, 'Import');
    $this->assertText('Created 5 nodes');

    // Assert files exist.
    $files = $this->listTestFiles();
    $entities = db_select('feeds_item')
      ->fields('feeds_item', array('entity_id'))
      ->condition('id', 'image_test')
      ->execute();

    foreach ($entities as $i => $entity) {
      $this->drupalGet('node/' . $entity->entity_id . '/edit');
      $f = new FeedsEnclosure(array_shift($files), NULL);
      $this->assertRaw($f->getUrlEncodedValue());
      $this->assertRaw("Alt text $i");
      $this->assertRaw("Title text $i");
    }
  }

  /**
   * Lists test files.
   */
  protected function listTestFiles() {
    return array(
      'tubing.jpeg',
      'foosball.jpeg',
      'attersee.jpeg',
      'hstreet.jpeg',
      'la fayette.jpeg',
    );
  }

}
