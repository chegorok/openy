<?php

/**
 * @file
 * OpenyDrupalContext for OpenY project.
 */

use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\file\Entity\File;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\TableNode;

class OpenyDrupalContext extends RawDrupalContext implements SnippetAcceptingContext {

  /**
   * Entities by entity type and key.
   *
   * $entities array
   *   To delete after scenario.
   *
   * @var array
   */
  protected $entities = [];

  /**
   * Creates content of a given type provided in the form:
   * | KEY         | title    | status | created           | field_reference_name |
   * | my node key | My title | 1      | 2014-10-17 8:00am | text key             |
   * | ...         | ...      | ...    | ...               | ...                  |
   *
   * @Given I create :bundle content:
   */
  public function iCreateNodes($bundle, TableNode $table) {
    $this->createNodes($bundle, $table->getHash());
  }

  /**
   * Creates content of a given type provided in the form:
   * | KEY                   | my node key       | ... |
   * | title                 | My title          | ... |
   * | status                | 1                 | ... |
   * | created               | 2014-10-17 8:00am | ... |
   * | field_reference_name  | text key          | ... |
   *
   * @Given I create large :bundle content:
   */
  public function iCreateLargeNodes($bundle, TableNode $table) {
    $this->createNodes($bundle, $this->getColumnHashFromRows($table));
  }

  /**
   * Creates content of the given type, provided in the form:
   * | KEY                  | my node key    |
   * | title                | My node        |
   * | Field One            | My field value |
   * | author               | Joe Editor     |
   * | status               | 1              |
   * | field_reference_name | text key       |
   * | ...                  | ...            |
   *
   * @Given I view a/an :bundle content:
   */
  public function iViewNode($bundle, TableNode $table) {
    $saved_array = $this->createNodes($bundle, $this->getColumnHashFromRows($table));
    // createNodes() returns array of saved nodes we are only concerned about
    // the last one created for this.
    $saved = array_pop($saved_array);
    $this->goToEntity($saved);
  }

  /**
   * View an existing entity/node by key value.
   *
   * @Given /^I view node "(?P<key>[^"]*)"$/
   * @Given /^I view entity "(?P<key>[^"]*)"$/
   */
  public function iViewKey($key) {
    $saved = $this->getEntityByKey($key);
    $this->goToEntity($saved);
  }

  /**
   * Creates entity of given entity type and bundle.
   * | KEY  | name | field_color  | field_reference_name  |
   * | Blue | Blue | 0000FF       | text key              |
   * | ...  | ...  | ...          | ...                   |
   *
   * @Given I create :entity_type of type :bundle:
   */
  public function iCreateEntity($entity_type, $bundle, TableNode $table) {
    $this->createEntities($entity_type, $bundle, $table->getHash());
  }

  /**
   * Creates entity of given entity type and bundle.
   * | KEY                   | Blue     | ... |
   * | name                  | Blue     | ... |
   * | field_color           | 0000FF   | ... |
   * | field_reference_name  | text key | ... |
   *
   * @Given I create large :entity_type of type :bundle:
   */
  public function iCreateLargeEntity($entity_type, $bundle, TableNode $table) {
    $this->createEntities($entity_type, $bundle, $this->getColumnHashFromRows($table));
  }

  /**
   * Process fields from entity hash to allow referencing by key.
   *
   * @param $entity_hash array
   *   Array of field value pairs.
   * @param $entity_type string
   *   String entity type.
   */
  protected function preProcessFields(&$entity_hash, $entity_type) {
    foreach ($entity_hash as $field_name => $field_value) {
      // Get field info.
      $fiend_info = FieldStorageConfig::loadByName($entity_type, $field_name);
      if ($fiend_info == NULL || !in_array(($field_type = $fiend_info->getType()), ['entity_reference', 'entity_reference_revisions', 'image'])) {
        continue;
      }

      // Explode field value on ', ' to get values/keys.
      $field_values = explode(', ', $field_value);
      unset($entity_hash[$field_name]);
      $value_id = [];
      $target_revision_id = [];
      foreach ($field_values as $value_or_key) {
        if ($field_type == 'image') {
          $file = File::create([
            'filename' => $value_or_key,
            'uri' => 'public://' . $value_or_key,
            'status' => 1,
          ]);
          $file->save();
          $this->saveEntity($file);
          $value_id[] = $file->id();
        }
        else {
          $entity_id = $this->getEntityIDByKey($value_or_key);
          $entity_revision_id = $this->getEntityRevisionIDByKey($value_or_key);
          if ($field_type == 'entity_reference') {
            // Set the target id.
            $value_id[] = $entity_id;
          }
          elseif ($field_type == 'entity_reference_revisions') {
            // Set target revision id.
            $target_id[] = $entity_id;
            $target_revision_id[] = $entity_revision_id;
          }
        }
      }
      if (!empty($value_id)) {
        $entity_hash[$field_name] = implode(', ', $value_id);
      }
      if (!empty($target_revision_id) && !empty($target_id)) {
        $entity_hash[$field_name . ':target_id'] = implode(', ', $target_id);
        $entity_hash[$field_name . ':target_revision_id'] = implode(', ', $target_revision_id);
      }
    }
  }

  /**
   * Create Nodes from bundle and TableNode column hash.
   *
   * @param $bundle string
   *   Bundle type id.
   * @param $hash array
   *   Table hash
   *
   * @return array
   *   Saved entities.
   */
  protected function createNodes($bundle, $hash) {
    return $this->createEntities('node', $bundle, $hash);
  }

  /**
   * Create Keyed Entities
   *
   * @param $entity_type string
   *   Entity type id.
   * @param $bundle string
   *   Bundle type id.
   * @param $hash array
   *   Table hash
   *
   * @return array
   *   Saved entities.
   */
  protected function createEntities($entity_type, $bundle, $hash) {
    $saved = [];
    foreach ($hash as $entity_hash) {
      $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
      $entity_storage_keys = $entity_storage->getEntityType()->getKeys();
      if (!empty($entity_storage_keys['bundle']) && is_string($entity_storage_keys['bundle'])) {
        $bundle_key = $entity_storage_keys['bundle'];
        $entity_hash[$bundle_key] = $bundle;
      }
      // Allow KEY as optional.
      $entity_key = NULL;
      if (!empty($entity_hash['KEY'])) {
        $entity_key = $entity_hash['KEY'];
        unset($entity_hash['KEY']);
      }
      $this->preProcessFields($entity_hash, $entity_type);
      $entity_obj = (object) $entity_hash;
      $this->parseEntityFields($entity_type, $entity_obj);
      // Create entity.
      $entity = $entity_storage->create((array) $entity_obj);
      $entity->save();
      $saved[] = $entity;
      $this->saveEntity($entity, $entity_key);
    }
    return $saved;
  }

  /**
   * Saves entity by entity key.
   *
   * @param $entity_key
   *   Entity key value.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity object.
   */
  protected function saveEntity(EntityInterface $entity, $entity_key = NULL) {
    $entity_type = $entity->getEntityTypeId();
    if ($entity_key != NULL) {
      $this->entities[$entity_type][$entity_key] = $entity;
    }
    else {
      $this->entities[$entity_type][] = $entity;
    }
  }

  /**
   * Get entity by key from created test scenario entities.
   *
   * @param $key string
   *   Key string
   *
   * @return mixed|\Drupal\Core\Entity\EntityInterface
   *   Entity.
   *
   * @throws \Exception
   */
  protected function getEntityByKey($key) {
    foreach ($this->entities as $entities) {
      if (!empty($entities[$key])) {
        return $entities[$key];
      }
    }
    $msg = 'Key "' . $key . '" does not match existing entity key';
    throw new \Exception($msg);
  }

  /**
   * Get entity id by key.
   *
   * @param $key string
   *   Key string to lookup saved entity.
   * @return mixed
   *   Entity id.
   */
  protected function getEntityIDByKey($key) {
    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    if (($entity = $this->getEntityByKey($key)) != NULL) {
      return $entity->id();
    }
  }

  /**
   * Get entity revision id by key.
   *
   * @param $key string
   *   Key string to lookup saved entity.
   * @return mixed
   *   Entity revision id.
   */
  protected function getEntityRevisionIDByKey($key) {
    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    if (($entity = $this->getEntityByKey($key)) != NULL) {
      if (!method_exists($entity, 'getRevisionId')) {
        $msg = 'Entity with Key "' . $key . '" entity does not have method getRevisionId()';
        throw new \Exception($msg);
      }
      return $entity->getRevisionId();
    }
  }

  /**
   * Get TableNode column hash from rows based TableNode table.
   *
   * @param \Behat\Gherkin\Node\TableNode $table
   *   From pipe delimited table input.
   * @return array
   *   A TableNode column hash.
   */
  public function getColumnHashFromRows(TableNode $table) {
    $hash = [];
    $rows = $table->getRowsHash();
    foreach ($rows as $field => $values) {
      if (is_array($values)) {
        foreach ($values as $key => $value) {
          $hash[$key][$field] = $value;
        }
      }
      elseif (empty($hash)) {
        $hash[] = $rows;
      }
    }
    return $hash;
  }

  /**
   * Load the page belonging to the entity provided.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity object.
   */
  public function goToEntity($entity) {
    // Set internal browser on the node.
    $this->getSession()->visit($this->locatePath($entity->toUrl()->toString()));
  }

  /**
   * Deletes all entities created during the scenario.
   *
   * @AfterScenario
   */
  public function cleanEntities() {
    foreach ($this->entities as $entity_type => $entities) {
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      foreach ($entities as $entity) {
        // Clean up the entity's alias, if there is one.
        if (method_exists($entity, 'tourl')) {
          try {
            $path = '/' . $entity->toUrl()->getInternalPath();
            $alias = \Drupal::service('path.alias_manager')
              ->getAliasByPath($path);
            if ($alias != $path) {
              \Drupal::service('path.alias_storage')
                ->delete(['alias' => $alias]);
            }
          } catch (Exception $e) {
            // do nothing
          }
        }
      }

      $storage_handler = \Drupal::entityTypeManager()->getStorage($entity_type);

      // If this is a Multiversion-aware storage handler, call purge() to do a
      // hard delete.
      if (method_exists($storage_handler, 'purge')) {
        $storage_handler->purge($entities);
      }
      else {
        $storage_handler->delete($entities);
      }
    }
  }

}
