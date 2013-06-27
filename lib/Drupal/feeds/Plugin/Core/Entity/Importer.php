<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\Core\Entity\Importer.
 */

namespace Drupal\feeds\Plugin\Core\Entity;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Annotation\EntityType;
use Drupal\Core\Form\FormInterface;
use Drupal\feeds\ImporterInterface;

/**
 * Defines the feeds importer entity.
 *
 * @EntityType(
 *   id = "feeds_importer",
 *   label = @Translation("Feed importer"),
 *   module = "feeds",
 *   controllers = {
 *     "storage" = "Drupal\feeds\ImporterStorageController",
 *     "access" = "Drupal\feeds\ImporterAccessController",
 *     "list" = "Drupal\feeds\ImporterListController",
 *     "form" = {
 *       "delete" = "Drupal\feeds\Form\ImporterDeleteForm",
 *       "default" = "Drupal\feeds\ImporterFormController"
 *     }
 *   },
 *   config_prefix = "feeds.importer",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid"
 *   }
 * )
 */
class Importer extends ConfigEntityBase implements ImporterInterface, FormInterface {

  /**
   * The importer ID.
   *
   * @var string
   */
  public $id;

  /**
   * Name of the importer.
   *
   * @var string
   */
  public $name;

  /**
   * Description of the importer.
   *
   * @var string
   */
  public $description;

  /**
   * The disabled status.
   *
   * @var bool
   */
  public $disabled = FALSE;

  // Every feed has a fetcher, a parser and a processor.
  public $fetcher, $parser, $processor;

  // This array defines the variable names of the plugins above.
  protected $pluginTypes = array('fetcher', 'parser', 'processor');
  public $config = array();

  /**
   * Instantiate class variables, initialize and configure
   * plugins.
   */
  public function __construct(array $values, $entity_type) {
    parent::__construct($values, $entity_type);

    $this->config += $this->configDefaults();

    // Instantiate fetcher, parser and processor, set their configuration if
    // stored info is available.

    foreach ($this->getPluginTypes() as $type) {
      $plugin_key = $this->config[$type]['plugin_key'];

      $config = array();
      if (isset($this->config[$type]['config'])) {
        $config = $this->config[$type]['config'];
      }
      $config['importer'] = $this;

      $plugin = \Drupal::service('plugin.manager.feeds.' . $type)->createInstance($plugin_key, $config);

      $this->$type = $plugin;
    }
  }

  /**
   * Report how many items *should* be created on one page load by this
   * importer.
   *
   * Note:
   *
   * It depends on whether parser implements batching if this limit is actually
   * respected. Further, if no limit is reported it doesn't mean that the
   * number of items that can be created on one page load is actually without
   * limit.
   *
   * @return
   *   A positive number defining the number of items that can be created on
   *   one page load. 0 if this number is unlimited.
   */
  public function getLimit() {
    return $this->processor->getLimit();
  }

  /**
   * Deletes configuration.
   *
   * Removes configuration information from database, does not delete
   * configuration itself.
   */
  public function delete() {
    parent::delete();

    $this->reschedule($this->id());
  }

  /**
   * Set plugin.
   *
   * @param string $plugin_type
   *   The type of plugin. Either fetcher, parser, or processor.
   * @param $plugin_key
   *   A id key.
   */
  public function setPlugin($plugin_type, $plugin_key) {
    $plugin = \Drupal::service('plugin.manager.feeds.' . $plugin_type)->createInstance($plugin_key, array('importer' => $this));
    // Unset existing plugin, switch to new plugin.
    unset($this->$plugin_type);
    $this->$plugin_type = $plugin;
    // Set configuration information, blow away any previous information on
    // this spot.
    $this->config[$plugin_type] = array(
      'plugin_key' => $plugin_key,
      'config' => $plugin->getConfig(),
    );
  }

  /**
   * Similar to setConfig but adds to existing configuration.
   *
   * @param $config
   *   Array containing configuration information. Will be filtered by the keys
   *   returned by configDefaults().
   */
  public function addConfig($config) {
    $this->config = is_array($this->config) ? array_merge($this->config, $config) : $config;
    $default_keys = $this->configDefaults();
    $this->config = array_intersect_key($this->config, $default_keys);
  }

  /**
   * Get configuration of this feed.
   */
  public function getConfig() {
    foreach ($this->getPluginTypes() as $type) {
      $this->config[$type]['config'] = $this->$type->getConfig();
    }

    return $this->config;
  }

  /**
   * Return defaults for feed configuration.
   */
  public function configDefaults() {
    return array(
      'fetcher' => array(
        'plugin_key' => 'http',
        'config' => array(),
      ),
      'parser' => array(
        'plugin_key' => 'syndication',
        'config' => array(),
      ),
      'processor' => array(
        'plugin_key' => 'entity:node',
        'config' => array(),
      ),
      'update' => 0,
      'import_period' => 1800, // Refresh every 30 minutes by default.
      'expire_period' => 3600, // Expire every hour by default, this is a hidden setting.
      'import_on_create' => TRUE, // Import on submission.
      'process_in_background' => FALSE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'feeds_importer_form';
  }

  /**
   * {@inheritdoc}
   *
   * @todo Move this to ImporterFormController.
   */
  public function buildForm(array $form, array &$form_state) {
    $config = $this->getConfig();
    $form['name'] = array(
      '#type' => 'textfield',
      '#title' => t('Name'),
      '#description' => t('A human readable name of this importer.'),
      '#default_value' => $this->name,
      '#required' => TRUE,
    );
    $form['description'] = array(
      '#type' => 'textfield',
      '#title' => t('Description'),
      '#description' => t('A description of this importer.'),
      '#default_value' => $this->description,
    );
    $cron_required =  ' ' . l(t('Requires cron to be configured.'), 'http://drupal.org/cron', array('attributes' => array('target' => '_new')));
    $period = drupal_map_assoc(array(900, 1800, 3600, 10800, 21600, 43200, 86400, 259200, 604800, 2419200), 'format_interval');
    foreach ($period as &$p) {
      $p = t('Every !p', array('!p' => $p));
    }
    $period = array(
      FEEDS_SCHEDULE_NEVER => t('Off'),
      0 => t('As often as possible'),
    ) + $period;
    $form['import_period'] = array(
      '#type' => 'select',
      '#title' => t('Periodic import'),
      '#options' => $period,
      '#description' => t('Choose how often a source should be imported periodically.') . $cron_required,
      '#default_value' => $config['import_period'],
    );
    $form['import_on_create'] = array(
      '#type' => 'checkbox',
      '#title' => t('Import on submission'),
      '#description' => t('Check if import should be started at the moment a standalone form or node form is submitted.'),
      '#default_value' => $config['import_on_create'],
    );
    $form['process_in_background'] = array(
      '#type' => 'checkbox',
      '#title' => t('Process in background'),
      '#description' => t('For very large imports. If checked, import and delete tasks started from the web UI will be handled by a cron task in the background rather than by the browser. This does not affect periodic imports, they are handled by a cron task in any case.') . $cron_required,
      '#default_value' => $config['process_in_background'],
    );

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Save'),
      '#weight' => 100,
    );

    return $form;
  }

  public function validateForm(array &$form, array &$form_state) {}

  /**
   * Reschedule if import period changes.
   */
  public function submitForm(array &$form, array &$form_state) {
    if ($this->config['import_period'] != $form_state['values']['import_period']) {
      $this->reschedule($this->id());
    }
    $this->name = $form_state['values']['name'];
    $this->description = $form_state['values']['description'];
    $this->addConfig($form_state['values']);

    $this->save();
    drupal_set_message(t('Your changes have been saved.'));
  }

  public function getPluginTypes() {
    return $this->pluginTypes;
  }

  /**
   * {@inheritdoc}
   */
  public function uri() {
    return array(
      'path' => 'admin/structure/feeds/manage/' . $this->id(),
      'options' => array(
        'entity_type' => $this->entityType,
        'entity' => $this,
      ),
    );
  }

  /**
   * Reschedules one or all importers.
   *
   * @param string $importer_id
   *   If true, all importers will be rescheduled, if FALSE, no importers will
   *   be rescheduled, if an importer id, only importer of that id will be
   *   rescheduled.
   *
   * @return bool|array
   *   Returns true if all importers need rescheduling, or false if no
   *   rescheduling is required. An array of importers that need rescheduling.
   */
  public static function reschedule($importer_id = NULL) {
    $reschedule = \Drupal::state()->get('feeds.reschedule') ? : FALSE;

    if ($importer_id === TRUE || $importer_id === FALSE) {
      $reschedule = $importer_id;
    }
    elseif (is_string($importer_id) && $reschedule !== TRUE) {
      $reschedule = is_array($reschedule) ? $reschedule : array();
      $reschedule[$importer_id] = $importer_id;
    }

    \Drupal::state()->set('feeds.reschedule', $reschedule);
    if ($reschedule === TRUE) {
      return entity_load_multiple('feeds_importer');
    }

    return $reschedule;
  }

}
