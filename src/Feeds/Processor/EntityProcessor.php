<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Processor\EntityProcessor.
 */

namespace Drupal\feeds\Feeds\Processor;

use Doctrine\Common\Inflector\Inflector;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Field\FieldItemInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\feeds\Entity\Importer;
use Drupal\feeds\Exception\EntityAccessException;
use Drupal\feeds\Exception\ValidationException;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Item\ItemInterface;
use Drupal\feeds\Plugin\Type\AdvancedFormPluginInterface;
use Drupal\feeds\Plugin\Type\ClearableInterface;
use Drupal\feeds\Plugin\Type\ConfigurablePluginBase;
use Drupal\feeds\Plugin\Type\LockableInterface;
use Drupal\feeds\Plugin\Type\Processor\ProcessorInterface;
use Drupal\feeds\Plugin\Type\Scheduler\SchedulerInterface;
use Drupal\feeds\StateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines an entity processor.
 *
 * Creates entities from feed items.
 *
 * @Plugin(
 *   id = "entity",
 *   title = @Translation("Entity processor"),
 *   description = @Translation("Creates entities from feed items."),
 *   deriver = "\Drupal\feeds\Plugin\Derivative\EntityProcessor"
 * )
 */
class EntityProcessor extends ConfigurablePluginBase implements ProcessorInterface, ClearableInterface, LockableInterface, AdvancedFormPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity storage controller for the entity type being processed.
   *
   * @var
   */
  protected $storageController;

  /**
   * The entity query factory object.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $queryFactory;

  /**
   * The entity info for the selected entity type.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityInfo;

  /**
   * Whether or not we should continue processing existing items.
   *
   * @var bool
   */
  protected $skipExisting;

  /**
   * A cache of the existing entity ids, keyed by item delta.
   *
   * @var array
   */
  protected $existingEntityIds = array();

  /**
   * A cache of the existing entities, keyed by entity id.
   *
   * @var array
   */
  protected $existingEntities;

  /**
   * Flag indicating that this processor is locked.
   *
   * @var bool
   */
  protected $isLocked;

  protected $uniqueQueries = array();

  /**
   * Constructs an EntityProcessor object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_info
   *   The entity info.
   * @param $storage_controller
   *   The storage controller for this processor.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, EntityTypeInterface $entity_info, EntityStorageInterface $storage_controller, QueryFactory $query_factory) {
    // $entityInfo has to be assinged before $this->loadHandlers() is called.
    $this->entityInfo = $entity_info;
    $this->storageController = $storage_controller;
    $this->queryFactory = $query_factory;
    $this->pluginDefinition = $plugin_definition;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->skipExisting = $this->configuration['update_existing'] == ProcessorInterface::SKIP_EXISTING;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $entity_manager = $container->get('entity.manager');
    $entity_info = $entity_manager->getDefinition($plugin_definition['entity type']);
    $storage_controller = $entity_manager->getStorage($plugin_definition['entity type']);

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_info,
      $storage_controller,
      $container->get('entity.query')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(FeedInterface $feed, ItemInterface $item) {
    $existing_entity_id = $this->existingEntityId($feed, $item);
    // Bulk load existing entities to save on db queries.
    if ($this->skipExisting && $existing_entity_id) {
      return;
    }

    $hash = $this->hash($item);
    $changed = $existing_entity_id && ($hash !== $entity->get('feeds_item')->hash);

    // Do not proceed if the item exists, has not changed, and we're not
    // forcing the update.
    if ($existing_entity_id && !$changed && !$this->configuration['skip_hash_check']) {
      return;
    }

    $state = $feed->getState(StateInterface::PROCESS);

    try {
      // Build a new entity.
      if (!$existing_entity_id) {
        $entity = $this->newEntity($feed);
      }
      else {
        $entity = $this->storageController->load($existing_entity_id);
      }

      // Set field values.
      $this->map($feed, $entity, $item);
      $this->entityValidate($entity);

      // This will throw an exception on failure.
      $this->entitySaveAccess($entity);

      // Set the values that we absolutely need.
      $entity->get('feeds_item')->target_id = $feed->id();
      $entity->get('feeds_item')->hash = $hash;

      // And... Save! We made it.
      $this->storageController->save($entity);

      // Track progress.
      $existing_entity_id ? $state->updated++ : $state->created++;
    }

    // Something bad happened, log it.
    catch (\Exception $e) {
      $state->failed++;
      drupal_set_message($e->getMessage(), 'warning');
      $feed->log('import', $this->createLogMessage($e, $entity, $item), array(), WATCHDOG_ERROR);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clear(FeedInterface $feed) {
    $state = $feed->getState(StateInterface::CLEAR);

    // Build base select statement.
    $query = $this->queryFactory->get($this->entityType())
      ->condition('feeds_item.target_id', $feed->id());

    // If there is no total, query it.
    if (!$state->total) {
      $count_query = clone $query;
      $state->total = (int) $count_query->count()->execute();
    }

    // Delete a batch of entities.
    $entity_ids = $query->range(0, $this->importer->getLimit())->execute();

    if ($entity_ids) {
      $this->entityDeleteMultiple($entity_ids);
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }

    // Report results when done.
    if ($feed->progressClearing() === StateInterface::BATCH_COMPLETE) {
      if ($state->deleted) {
        $message = format_plural(
          $state->deleted,
          'Deleted @number @entity from %title.',
          'Deleted @number @entities from %title.',
          array(
            '@number' => $state->deleted,
            '@entity' => Unicode::strtolower($this->entityLabel()),
            '@entities' => Unicode::strtolower($this->entityLabelPlural()),
            '%title' => $feed->label(),
          )
        );
        $feed->log('clear', $message, array(), WATCHDOG_INFO);
        drupal_set_message($message);
      }
      else {
        drupal_set_message($this->t('There are no @entities to delete.', array('@entities' => Unicode::strtolower($this->entityLabelPlural()))));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMessages(FeedInterface $feed) {
    $state = $feed->getState(StateInterface::PROCESS);

    $tokens = array(
      '@entity' => Unicode::strtolower($this->entityLabel()),
      '@entities' => Unicode::strtolower($this->entityLabelPlural()),
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
    if (!$messages) {
      $messages[] = array(
        'message' => $this->t('There are no new @entities.', array('@entities' => Unicode::strtolower($this->entityLabelPlural()))),
      );
    }
    foreach ($messages as $message) {
      drupal_set_message($message['message']);
      $feed->log('import', $message['message'], array(), isset($message['level']) ? $message['level'] : WATCHDOG_INFO);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityType() {
    return $this->pluginDefinition['entity type'];
  }

  /**
   * The entity's bundle key.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   */
  public function bundleKey() {
    return $this->entityInfo->getKey('bundle');
  }

  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   *
   * @todo We should be more careful about missing bundles.
   */
  public function bundle() {
    if (!$bundle_key = $this->bundleKey()) {
      return $this->entityType();
    }
    if (isset($this->configuration['values'][$bundle_key])) {
      return $this->configuration['values'][$bundle_key];
    }
  }

  /**
   * Returns the bundle label for the entity being processed.
   *
   * @return string
   *   The bundle label.
   */
  protected function bundleLabel() {
    if ($label = $this->entityInfo->getBundleLabel()) {
      return $label;
    }
    return $this->t('Bundle');
  }

  /**
   * Provides a list of bundle options for use in select lists.
   *
   * @return array
   *   A keyed array of bundle => label.
   */
  protected function bundleOptions() {
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

  /**
   * Returns the label of the entity being processed.
   *
   * @return string
   *   The label of the entity.
   */
  protected function entityLabel() {
    return $this->entityInfo->getLabel();
  }

  /**
   * Returns the plural label of the entity being processed.
   *
   * This will return the singular label if the plural label does not exist.
   *
   * @return string
   *   The plural label of the entity.
   */
  protected function entityLabelPlural() {
    return Inflector::pluralize($this->entityLabel());
  }

  /**
   * {@inheritdoc}
   */
  protected function newEntity(FeedInterface $feed) {
    $values = $this->configuration['values'];
    $entity = $this->storageController->create($values);
    $entity->enforceIsNew();

    if ($entity instanceof EntityOwnerInterface) {
      $entity->setOwnerId($this->configuration['owner_id']);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityValidate(EntityInterface $entity) {
    $violations = $entity->validate();
    if (count($violations)) {
      $args = array(
        '@entity' => Unicode::strtolower($this->entityLabel()),
        '%label' => $entity->label(),
        '@url' => $this->url('feeds.importer_mapping', array('feeds_importer' => $this->importer->id())),
      );
      throw new ValidationException(String::format('The @entity %label failed to validate. Please check your <a href="@url">mappings</a>.', $args));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function entitySaveAccess(EntityInterface $entity) {
    if ($this->configuration['authorize'] && !empty($entity->uid->value)) {

      // If the uid was mapped directly, rather than by email or username, it
      // could be invalid.
      if (!($account = $entity->uid->entity)) {
        $message = 'User %uid is not a valid user.';
        throw new EntityAccessException(String::format($message, array('%uid' => $entity->uid->value)));
      }

      $op = $entity->isNew() ? 'create' : 'update';

      if (!$entity->access($op, $account)) {
        $args = array(
          '%name' => $account->getUsername(),
          '%op' => $op,
          '@bundle' => Unicode::strtolower($this->bundleLabel()),
          '%bundle' => $entity->bundle(),
        );
        throw new EntityAccessException(String::format('User %name is not authorized to %op @bundle %bundle.', $args));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function entityDeleteMultiple(array $entity_ids) {
    $entities = $this->storageController->loadMultiple($entity_ids);
    $this->storageController->delete($entities);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $defaults = array(
      'update_existing' => ProcessorInterface::SKIP_EXISTING,
      'skip_hash_check' => FALSE,
      'values' => [$this->bundleKey() => NULL],
      'authorize' => TRUE,
      'expire' => SchedulerInterface::EXPIRE_NEVER,
      'process_limit' => ProcessorInterface::PROCESS_LIMIT,
      'owner_id' => 0,
    );

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $tokens = array('@entity' => Unicode::strtolower($this->entityLabel()), '@entities' => Unicode::strtolower($this->entityLabelPlural()));

    $form['update_existing'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Update existing @entities', $tokens),
      '#description' => $this->t('Existing @entities will be determined using mappings that are <strong>unique</strong>.', $tokens),
      '#options' => array(
        ProcessorInterface::SKIP_EXISTING => $this->t('Do not update existing @entities', $tokens),
        ProcessorInterface::REPLACE_EXISTING => $this->t('Replace existing @entities', $tokens),
        ProcessorInterface::UPDATE_EXISTING => $this->t('Update existing @entities', $tokens),
      ),
      '#default_value' => $this->configuration['update_existing'],
    );
    $times = array(SchedulerInterface::EXPIRE_NEVER, 3600, 10800, 21600, 43200, 86400, 259200, 604800, 2592000, 2592000 * 3, 2592000 * 6, 31536000);
    $period = array_map(array($this, 'formatExpire'), array_combine($times, $times));
    $form['expire'] = array(
      '#type' => 'select',
      '#title' => $this->t('Expire @entities', $tokens),
      '#options' => $period,
      '#description' => $this->t('Select after how much time @entities should be deleted.', $tokens),
      '#default_value' => $this->configuration['expire'],
    );
    if ($this->entityInfo->isSubclassOf('Drupal\user\EntityOwnerInterface')) {
      $owner = user_load($this->configuration['owner_id']);
      $form['owner_id'] = array(
        '#type' => 'textfield',
        '#title' => $this->t('Owner'),
        '#description' => $this->t('Select the owner of the entities to be created. Leave blank for %anonymous.', array('%anonymous' => \Drupal::config('user.settings')->get('anonymous'))),
        '#autocomplete_route_name' => 'user.autocomplete',
        '#default_value' => String::checkPlain($owner->getUsername()),
      );
    }
    $form['advanced'] = array(
      '#title' => $this->t('Advanced settings'),
      '#type' => 'details',
      '#collapsed' => TRUE,
      '#collapsible' => TRUE,
      '#weight' => 10,
    );
    $form['advanced']['authorize'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Authorize'),
      '#description' => $this->t('Check that the author has permission to create the @entity.', $tokens),
      '#default_value' => $this->configuration['authorize'],
      '#parents' => array('processor', 'configuration', 'authorize'),
    );
    $form['advanced']['skip_hash_check'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Force update'),
      '#description' => $this->t('Forces the update of items even if the feed did not change.'),
      '#default_value' => $this->configuration['skip_hash_check'],
      '#parents' => array('processor', 'configuration', 'skip_hash_check'),
    );
    $form['advanced']['process_limit'] = array(
      '#type' => 'number',
      '#title' => $this->t('Process limit'),
      '#description' => $this->t('The number of items to process in one request.'),
      '#default_value' => $this->configuration['process_limit'],
      '#parents' => array('processor', 'configuration', 'process_limit'),
      '#min' => 1,
      '#max' => 1000,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values =& $form_state->getValue(['processor', 'configuration']);

    if ($owner = user_load_by_name($values['owner_id'])) {
      $values['owner_id'] = $owner->id();
    }
    else {
      $values['owner_id'] = 0;
    }
  }

  /**
   * {@inheritdoc}
   *
   * @todo We need an importer save/update/delete API.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values =& $form_state->getValue(array('processor', 'configuration'));
    if ($this->configuration['expire'] != $values['expire']) {
      $this->importer->reschedule($this->importer->id());
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function onImporterSave($update = TRUE) {
    $this->prepareFeedsItemField();
  }

  /**
   * {@inheritdoc}
   */
  public function onImporterDelete() {
    $this->removeFeedItemField();
  }

  /**
   * Prepares the feeds_item field.
   *
   * @todo How does ::load() behave for deleted fields?
   */
  protected function prepareFeedsItemField() {
    // Create field if it doesn't exist.
    if (!FieldStorageConfig::loadByName($this->entityType(), 'feeds_item')) {
      FieldStorageConfig::create([
        'field_name' => 'feeds_item',
        'entity_type' => $this->entityType(),
        'type' => 'feeds_item',
        'translatable' => FALSE,
      ])->save();
    }
    // Create field instance if it doesn't exist.
    if (!FieldConfig::loadByName($this->entityType(), $this->bundle(), 'feeds_item')) {
      FieldConfig::create([
        'label' => 'Feeds item',
        'description' => '',
        'field_name' => 'feeds_item',
        'entity_type' => $this->entityType(),
        'bundle' => $this->bundle(),
      ])->save();
    }
  }

  /**
   * Deletes the feeds_item field.
   */
  protected function removeFeedItemField() {
    $storage_in_use = FALSE;
    $instance_in_use = FALSE;

    foreach (Importer::loadMultiple() as $importer) {
      if ($importer->id() === $this->importer->id()) {
        continue;
      }
      $processor = $importer->getProcessor();
      if (!$processor instanceof EntityProcessor) {
        continue;
      }

      if ($processor->entityType() === $this->entityType()) {
        $storage_in_use = TRUE;

        if ($processor->bundle() === $this->bundle()) {
          $instance_in_use = TRUE;
          break;
        }
      }
    }

    if ($instance_in_use) {
      return;
    }

    // Delete the field instance.
    if ($config = FieldConfig::loadByName($this->entityType(), $this->bundle(), 'feeds_item')) {
      $config->delete();
    }

    if ($storage_in_use) {
      return;
    }

    // Delte the field storage.
    if ($storage = FieldStorageConfig::loadByName($this->entityType(), 'feeds_item')) {
      $storage->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingTargets() {
    return array();
  }

  /**
   * {@inheritdoc}
   */
  public function expiryTime() {
    return $this->configuration['expire'];
  }

  /**
   * {@inheritdoc}
   */
  public function expire(FeedInterface $feed, $time = NULL) {
    $state = $feed->getState(StateInterface::EXPIRE);

    if ($time === NULL) {
      $time = $this->expiryTime();
    }
    if ($time == SchedulerInterface::EXPIRE_NEVER) {
      return;
    }

    $query = $this->queryFactory
      ->get($this->entityType())
      ->condition('feeds_item.target_id', $feed->id())
      ->condition('feeds_item.imported', REQUEST_TIME - $time, '<');

    // If there is no total, query it.
    if (!$state->total) {
      $count_query = clone $query;
      $state->total = (int) $count_query->count()->execute();
    }

    // Delete a batch of entities.
    if ($entity_ids = $query->range(0, $this->importer->getLimit())->execute()) {
      $this->entityDeleteMultiple($entity_ids);
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }
    else {
      $state->progress($state->total, $state->total);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getItemCount(FeedInterface $feed) {
    return $this->queryFactory->get($this->entityType())
      ->condition('feeds_item.target_id', $feed->id())
      ->count()
      ->execute();
  }

  /**
   * Returns an existing entity id.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed being processed.
   * @param \Drupal\feeds\Feeds\Item\ItemInterface $item
   *   The item to find existing ids for.
   *
   * @return int|false
   *   The integer of the entity, or false if not found.
   */
  protected function existingEntityId(FeedInterface $feed, ItemInterface $item) {
    foreach ($this->importer->getMappings() as $delta => $mapping) {
      if (empty($mapping['unique'])) {
        continue;
      }

      foreach ($mapping['unique'] as $key => $true) {
        $plugin = $this->importer->getTargetPlugin($delta);
        $entity_id = $plugin->getUniqueValue($feed, $mapping['target'], $key, $item->get($mapping['map'][$key]));
        if ($entity_id) {
          return $entity_id;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildAdvancedForm(array $form, FormStateInterface $form_state) {
    $form['values']['#tree'] = TRUE;

    if ($bundle_key = $this->bundleKey()) {
      $form['values'][$bundle_key] = array(
        '#type' => 'select',
        '#options' => $this->bundleOptions(),
        '#title' => $this->bundleLabel(),
        '#required' => TRUE,
        '#default_value' => $this->bundle() ?: key($this->bundleOptions()),
        '#disabled' => $this->isLocked(),
      );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function isLocked() {
    if ($this->isLocked === NULL) {
      // Look for feeds.
      $this->isLocked = (bool) $this->queryFactory->get('feeds_feed')
        ->condition('importer', $this->importer->id())
        ->range(0, 1)
        ->execute();
    }

    return $this->isLocked;
  }

  /**
   * Creates an MD5 hash of an item.
   *
   * Includes mappings so that items will be updated if the mapping
   * configuration has changed.
   *
   * @param \Drupal\feeds\Feeds\Item\ItemInterface $item
   *   The item to hash.
   *
   * @return string
   *   An MD5 hash.
   */
  protected function hash(ItemInterface $item) {
    return hash('md5', serialize($item) . serialize($this->importer->getMappings()));
  }

  /**
   * Creates a log message when an exception occured during import.
   *
   * @param \Exception $e
   *   The exception that was thrown during processing.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object that was being processed.
   * @param arary $item
   *   The parser result for this entity.
   *
   * @return string
   *   The message to log.
   *
   * @todo This no longer works due to circular references.
   */
  protected function createLogMessage(\Exception $e, EntityInterface $entity, ItemInterface $item) {
    include_once DRUPAL_ROOT . '/core/includes/utility.inc';
    $message = $e->getMessage();
    $message .= '<h3>Original item</h3>';
    $message .= '<pre>' . drupal_var_export($item) . '</pre>';
    $message .= '<h3>Entity</h3>';
    $message .= '<pre>' . drupal_var_export($entity->toArray()) . '</pre>';
    return $message;
  }

  /**
   * Formats UNIX timestamps to readable strings.
   *
   * @param int $timestamp
   *   A UNIX timestamp.
   *
   * @return string
   *   A string in the format, "After (time)" or "Never."
   */
  public function formatExpire($timestamp) {
    if ($timestamp == SchedulerInterface::EXPIRE_NEVER) {
      return $this->t('Never');
    }
    return $this->t('after !time', array('!time' => \Drupal::service('date.formatter')->formatInterval($timestamp)));
  }

  /**
   * {@inheritdoc}
   */
  public function getLimit() {
    return $this->configuration['process_limit'];
  }

  /**
   * Execute mapping on an item.
   *
   * This method encapsulates the central mapping functionality. When an item is
   * processed, it is passed through map() where the properties of $source_item
   * are mapped onto $target_item following the processor's mapping
   * configuration.
   */
  protected function map(FeedInterface $feed, EntityInterface $entity, ItemInterface $item) {
    $parser = $this->importer->getParser();
    $mappings = $this->importer->getMappings();

    // Mappers add to existing fields rather than replacing them. Hence we need
    // to clear target elements of each item before mapping in case we are
    // mapping on a prepopulated item such as an existing node.
    foreach ($mappings as $mapping) {
      unset($entity->{$mapping['target']});
    }

    // Gather all of the values for this item.
    $source_values = array();
    foreach ($mappings as $mapping) {
      $target = $mapping['target'];

      foreach ($mapping['map'] as $column => $source) {

        if (!isset($source_values[$target][$column])) {
          $source_values[$target][$column] = array();
        }

        $value = $item->get($source);
        if (!is_array($value)) {
          $source_values[$target][$column][] = $value;
        }
        else {
          $source_values[$target][$column] = array_merge($source_values[$target][$column], $value);
        }
      }
    }

    // Rearrange values into Drupal's field structure.
    $field_values = array();
    foreach ($source_values as $field => $field_value) {
      $field_values[$field] = [];
      foreach ($field_value as $column => $values) {
        // Use array_values() here to keep our $delta clean.
        foreach (array_values($values) as $delta => $value) {
          $field_values[$field][$delta][$column] = $value;
        }
      }
    }

    // Set target values.
    foreach ($mappings as $delta => $mapping) {
      $plugin = $this->importer->getTargetPlugin($delta);
      $plugin->setTarget($feed, $entity, $mapping['target'], $field_values[$mapping['target']]);
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Sort this out so that we aren't calling db_delete() here.
   */
  public function onFeedDeleteMultiple(array $feeds) {
    $fids = array();
    foreach ($feeds as $feed) {
      $fids[] = $feed->id();
    }
    $table = $this->entityType() . '__feeds_item';
    db_delete($table)
      ->condition('feeds_item_target_id', $fids)
      ->execute();
  }

}
