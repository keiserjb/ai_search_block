<?php

namespace Drupal\ai_search_block_log;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Helper for the searches.
 */
class AiSearchBlockLogHelper implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The configuration parameters passed in.
   *
   * @var array
   */
  private $configuration;

  /**
   * @var int
   */
  private $logId;

  /**
   * @var string
   */
  private $blockId;

  /**
   * @var \Drupal\user\Entity\User
   */
  private $user;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Create the initial log row.
   *
   * @param $block_id
   * @param $user
   * @param $query
   *
   * @return int|mixed|string|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function start($block_id, $user, $query) {
    $storage = $this->entityTypeManager->getStorage('ai_search_block_log');
    /** @var \Drupal\ai_search_block_log\Entity\AISearchBlockLog $log */
    $log = $storage->create([
      'uid' => 1,
      'block_id' => $block_id,
      'created' => time(),
      'expiry' => strtotime('now + 1 month'),
      'question' => $query,
    ]);
    $log->save();
    return $log->id();
  }

  /**
   * Log the response to the DB.
   *
   * @param \Drupal\ai_search_block_log\int $id
   * @param \Drupal\ai_search_block_log\string $response
   *
   * @return void|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function logResponse(int $id, string $response) {
    $entity = \Drupal::entityTypeManager()
      ->getStorage('ai_search_block_log')
      ->load($id);
    if (!$entity) {
      return NULL;
    }
    $entity->set('response_given', $response);
    $entity->save();
  }

  /**
   * Update the log with fields.
   *
   * @param \Drupal\ai_search_block_log\int $id
   * @param array $fields
   *
   * @return void|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function update(int $id, array $fields) {
    $entity = \Drupal::entityTypeManager()
      ->getStorage('ai_search_block_log')
      ->load($id);
    if (!$entity) {
      return NULL;
    }
    foreach ($fields as $key => $field) {
      $entity->set($key, $field);
    }
    $entity->save();
  }

}
