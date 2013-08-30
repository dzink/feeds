<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\feeds\Processor\EntityProcessor.
 */

namespace Drupal\feeds\Plugin\feeds\Processor;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Form\FormInterface;
use Drupal\feeds\AdvancedFormPluginInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Result\ParserResultInterface;
use Drupal\feeds\Plugin\ProcessorBase;
use Drupal\feeds\Plugin\ProcessorInterface;
use Drupal\feeds\Plugin\SchedulerInterface;
use Drupal\feeds\StateInterface;

/**
 * Defines an entity processor.
 *
 * Creates entities from feed items.
 *
 * @Plugin(
 *   id = "entity",
 *   title = @Translation("Entity processor"),
 *   description = @Translation("Creates entities from feed items."),
 *   derivative = "\Drupal\feeds\Plugin\Derivative\EntityProcessor"
 * )
 */
class EntityProcessor extends ProcessorBase implements AdvancedFormPluginInterface, ProcessorInterface {

  /**
   * The plugin definition.
   *
   * @var array
   */
  protected $pluginDefinition;

  /**
   * The entity info for the selected entity type.
   *
   * @var array
   */
  protected $entityInfo;

  /**
   * The properties for this entity.
   *
   * @var array
   */
  protected $properties;

  /**
   * The extenders that apply to this entity type.
   *
   * @var array
   */
  protected $handlers = array();

  /**
   * Whether or not we should continue processing existing items.
   *
   * @var bool
   */
  protected $skipExisting;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    $this->pluginDefinition = $plugin_definition;
    $this->loadHandlers($configuration);
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->skipExisting = $this->configuration['update_existing'] == ProcessorInterface::SKIP_EXISTING;
  }

  /**
   * {@inheritdoc}
   */
  public function process(FeedInterface $feed, StateInterface $state, ParserResultInterface $parser_result) {
    while ($item = $parser_result->shiftItem()) {
      $this->processItem($feed, $state, $item);
    }
  }

  /**
   * Processes a single item.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed being processed.
   * @param \Drupal\feeds\StateInterface $state
   *   The state object.
   * @param array $item
   *   The item being processed.
   */
  protected function processItem(FeedInterface $feed, StateInterface $state, array $item) {
    // Check if this item already exists.
    $entity_id = $this->existingEntityId($feed, $item);

    // If it exists, and we are not updating, pass onto the next item.
    if ($entity_id && $this->skipExisting) {
      return;
    }

    $hash = $this->hash($item);
    $changed = ($hash !== $this->getHash($entity_id));

    // Do not proceed if the item exists, has not changed, and we're not
    // forcing the update.
    if ($entity_id && !$changed && !$this->configuration['skip_hash_check']) {
      return;
    }

    try {
      // Load an existing entity.
      // @todo Clean this up.
      if ($entity_id) {
        $entity = $this->entityLoad($feed, $entity_id);
        $item_info = \Drupal::service('feeds.item_info')->load($this->entityType(), $entity_id);
        $item_info->fid = $feed->id();
        $item_info->hash = $hash;
        $item_info->url = '';
        $item_info->guid = '';
      }

      // Build a new entity.
      else {
        $entity = $this->newEntity($feed);
        $item_info = $this->newItemInfo($entity, $feed, $hash);
      }

      // Set property and field values.
      $this->map($feed, $item, $entity, $item_info);
      $this->entityValidate($entity);

      // This will throw an exception on failure.
      $this->entitySaveAccess($entity);
      $this->entitySave($entity);

      $item_info->entityId = $entity->id();
      \Drupal::service('feeds.item_info')->save($item_info);

      // Track progress.
      if ($entity_id) {
        $state->updated++;
      }
      else {
        $state->created++;
      }
    }

    // Something bad happened, log it.
    catch (\Exception $e) {
      $state->failed++;
      drupal_set_message($e->getMessage(), 'warning');
      $message = $this->createLogMessage($e, $entity, $item);
      $feed->log('import', $message, array(), WATCHDOG_ERROR);
    }
  }

  /**
   * Called after processing all items to display messages.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed being processed.
   */
  public function setMessages(FeedInterface $feed) {
    $state = $feed->state(StateInterface::PROCESS);

    $info = $this->entityInfo();
    $tokens = array(
      '@entity' => strtolower($info['label']),
      '@entities' => strtolower($info['label_plural']),
    );
    $messages = array();
    if ($state->created) {
      $messages[] = array(
       'message' => format_plural(
          $state->created,
          'Created @number @entity.',
          'Created @number @entities.',
          array('@number' => $state->created) + $tokens
        ),
      );
    }
    if ($state->updated) {
      $messages[] = array(
       'message' => format_plural(
          $state->updated,
          'Updated @number @entity.',
          'Updated @number @entities.',
          array('@number' => $state->updated) + $tokens
        ),
      );
    }
    if ($state->failed) {
      $messages[] = array(
       'message' => format_plural(
          $state->failed,
          'Failed importing @number @entity.',
          'Failed importing @number @entities.',
          array('@number' => $state->failed) + $tokens
        ),
        'level' => WATCHDOG_ERROR,
      );
    }
    if (empty($messages)) {
      $messages[] = array(
        'message' => $this->t('There are no new @entities.', array('@entities' => strtolower($info['label_plural']))),
      );
    }
    foreach ($messages as $message) {
      drupal_set_message($message['message']);
      $feed->log('import', $message['message'], array(), isset($message['level']) ? $message['level'] : WATCHDOG_INFO);
    }
  }

  protected function loadHandlers(array $configuration) {
    $definitions = \Drupal::service('plugin.manager.feeds.handler')->getDefinitions();

    foreach ($definitions as $definition) {
      $class = $definition['class'];
      if ($class::applies($this)) {
        $this->handlers[] = \Drupal::service('plugin.manager.feeds.handler')->createInstance($definition['id'], $configuration);
      }
    }
  }

  /**
   * Returns a new item info object.
   *
   * This is used to track entities created by Feeds.
   *
   * @param $entity
   *   The entity object to be populated with new item info.
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed that produces this entity.
   * @param $hash
   *   The fingerprint of the feed item.
   */
  protected function newItemInfo($entity, FeedInterface $feed, $hash = '') {
    $item_info = new \stdClass();
    $item_info->fid = $feed->id();
    $item_info->entityType = $entity->entityType();
    $item_info->imported = REQUEST_TIME;
    $item_info->hash = $hash;
    $item_info->url = '';
    $item_info->guid = '';

    return $item_info;
  }

  public function apply($action, &$arg1 = NULL, &$arg2 = NULL, &$arg3 = NULL, &$arg4 = NULL) {
    $return = array();

    foreach ($this->handlers as $handler) {
      if (method_exists($handler, $action)) {
        $callable = array($handler, $action);
        $result = $callable($arg1, $arg2, $arg3, $arg4);
        if (is_array($result)) {
          $return = array_merge($return, $result);
        }
        else {
          $return[] = $result;
        }
      }
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function entityType() {
    return $this->pluginDefinition['entity type'];
  }

  /**
   * {@inheritdoc}
   */
  protected function entityInfo() {
    if (!isset($this->entityInfo)) {
      $this->entityInfo = entity_get_info($this->entityType());
    }

    $this->apply('entityInfoAlter', $this->entityInfo);

    return $this->entityInfo;
  }

  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   */
  public function bundleKey() {
    $info = $this->entityInfo();
    if (!empty($info['entity_keys']['bundle'])) {
      return $info['entity_keys']['bundle'];
    }
  }

  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   */
  public function bundle() {
    if ($bundle_key = $this->bundleKey()) {
      if (isset($this->configuration['values'][$bundle_key])) {
        return $this->configuration['values'][$bundle_key];
      }
      return;
    }

    return $this->entityType();
  }

  /**
   * Provides a list of bundle options for use in select lists.
   *
   * @return array
   *   A keyed array of bundle => label.
   */
  public function bundleOptions() {
    $options = array();
    foreach (entity_get_bundles($this->entityType()) as $bundle => $info) {
      if (!empty($info['label'])) {
        $options[$bundle] = $info['label'];
      }
      else {
        $options[$bundle] = $bundle;
      }
    }

    return $options;
  }

  public function getProperties() {
    if (!isset($this->properties)) {
      $entity = entity_create($this->entityType(), $this->getConfiguration('values'))->getNGEntity();

      foreach ($entity as $id => $field) {

        $definition = $field->getItemDefinition();

        if (!empty($definition['read-only'])) {
          continue;
        }

        $this->properties[$id] = $definition;
        $this->properties[$id]['properties'] = $field->getPropertyDefinitions();

        // if (!empty($definition['configurable'])) {
        //   foreach ($field->getPropertyDefinitions() as $key => $info) {
        //     if (empty($info['computed'])) {
        //       $info['label'] = $definition['label'] . ': ' . $info['label'];
        //       $this->properties["$id:$key"] = $info;
        //     }
        //   }
        // }
      }
    }

    return $this->properties;
  }

  /**
   * {@inheritdoc}
   */
  protected function newEntity(FeedInterface $feed) {
    $values = $this->configuration['values'];
    $this->apply('newEntityValues', $feed, $values);
    return entity_create($this->entityType(), $values)->getBCEntity();
  }

  /**
   * {@inheritdoc}
   */
  protected function entityLoad(FeedInterface $feed, $entity_id) {
    $entity = entity_load($this->entityType(), $entity_id)->getBCEntity();
    $this->apply('entityPrepare', $feed, $entity);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityValidate($entity) {
    $this->apply('entityValidate', $entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function entitySaveAccess($entity) {
    $this->apply('entitySaveAccess', $entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function entitySave($entity) {
    $this->apply('entityPreSave', $entity);
    $entity->save();
    $this->apply('entityPostSave', $entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityDeleteMultiple($entity_ids) {
    entity_delete_multiple($this->entityType(), $entity_ids);
    $this->apply('entityDeleteMultiple', $entity_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration($key = NULL) {
    $this->configuration + $this->apply('getConfiguration');
    return parent::getConfiguration($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultConfiguration() {
    $defaults = array(
      'values' => array(
        $this->bundleKey() => NULL,
      ),
      'expire' => SchedulerInterface::EXPIRE_NEVER,
    ) + parent::getDefaultConfiguration();

    $defaults += $this->apply(__FUNCTION__);

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, array &$form_state) {
    $info = $this->entityInfo();

    $label_plural = isset($info['label_plural']) ? $info['label_plural'] : $info['label'];
    $tokens = array('@entities' => drupal_strtolower($label_plural));

    $form['update_existing'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Update existing @entities', $tokens),
      '#description' =>
        $this->t('Existing @entities will be determined using mappings that are a "unique target".', $tokens),
      '#options' => array(
        ProcessorInterface::SKIP_EXISTING => $this->t('Do not update existing @entities', $tokens),
        ProcessorInterface::REPLACE_EXISTING => $this->t('Replace existing @entities', $tokens),
        ProcessorInterface::UPDATE_EXISTING => $this->t('Update existing @entities', $tokens),
      ),
      '#default_value' => $this->configuration['update_existing'],
    );

    $form = parent::buildConfigurationForm($form, $form_state);

    $this->apply(__FUNCTION__, $form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, array &$form_state) {
    $this->apply(__FUNCTION__, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, array &$form_state) {
    $this->apply(__FUNCTION__, $form, $form_state);
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingTargets() {

    // The bundle has not been selected.
    if (!$this->bundle()) {
      $info = $this->entityInfo();
      $bundle_name = !empty($info['bundle_name']) ? drupal_strtolower($info['bundle_name']) : $this->t('bundle');
      $url = url('admin/structure/feeds/manage/' . $this->importer->id() . '/settings/processor');
      drupal_set_message($this->t('Please <a href="@url">select a @bundle_name</a>.', array('@url' => $url, '@bundle_name' => $bundle_name)), 'warning', FALSE);
    }

    $targets = parent::getMappingTargets();

    foreach ($this->getProperties() as $id => $field) {
      $targets[$id] = $field;
    }

    // $this->apply('getMappingTargets', $targets);

    // Let other modules expose mapping targets.
    // $definitions = \Drupal::service('plugin.manager.feeds.target')->getDefinitions();
    // foreach ($definitions as $definition) {
    //   $mapper = \Drupal::service('plugin.manager.feeds.target')->createInstance($definition['id'], array('importer' => $this->importer));
    //   $targets += $mapper->targets();
    // }

    return $targets;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetElement(FeedInterface $feed, $entity, $field_name, $values, $mapping, \stdClass $item_info) {
    $properties = $this->getProperties();
    if (isset($properties[$field_name])) {
      $entity->get($field_name)->setValue($values);
    }
    else {
      parent::setTargetElementFeedInterface($feed, $entity, $field_name, $values, $mapping, $item_info);
    }
  }

  /**
   * Return expiry time.
   */
  public function expiryTime() {
    return $this->configuration['expire'];
  }

  protected function expiryQuery(FeedInterface $feed, $time) {
    $select = parent::expiryQuery($feed, $time);
    $this->apply('expiryQuery', $feed, $select, $time);
    return $select;
  }

  protected function existingEntityId(FeedInterface $feed, array $item) {
    $query = db_select('feeds_item')
      ->fields('feeds_item', array('entity_id'))
      ->condition('fid', $feed->id())
      ->condition('entity_type', $this->entityType());

    // Iterate through all unique targets and test whether they do already
    // exist in the database.
    foreach ($this->uniqueTargets($feed, $item) as $target => $value) {
      switch ($target) {
        case 'url':
          $entity_id = $query->condition('url', $value)->execute()->fetchField();
          break;

        case 'guid':
          $entity_id = $query->condition('guid', $value)->execute()->fetchField();
          break;
      }
      if (isset($entity_id)) {
        // Return with the content id found.
        return $entity_id;
      }
    }

    $ids = array_filter($this->apply('existingEntityId', $feed, $item));

    if ($ids) {
      return reset($ids);
    }

    return 0;
  }

  public function buildAdvancedForm(array $form, array &$form_state) {
    $info = $this->entityInfo();

    $form['values']['#tree'] = TRUE;
    if ($bundle_key = $this->bundleKey()) {
      $form['values'][$bundle_key] = array(
        '#type' => 'select',
        '#options' => $this->bundleOptions(),
        '#title' => !empty($info['bundle_label']) ? $info['bundle_label'] : $this->t('Bundle'),
        '#required' => TRUE,
        '#default_value' => $this->bundle(),
      );
    }

    return $form;
  }

}
