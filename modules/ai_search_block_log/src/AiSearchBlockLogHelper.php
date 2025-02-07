<?php

namespace Drupal\ai_search_block_log;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AiSearchBlockLogHelper implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  protected $database;

  /**
   * The configuration parameters passed in.
   *
   * @var array
   */
  private $configuration;

  private $logId;

  private $blockId;

  private $user;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory, Connection $connection) {
    $this->configFactory = $configFactory;
    $this->database = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('database')
    );
  }

  /**
   * @param $block_id
   * @param $user
   * @param $query
   *
   * @return void
   */
  public function cron() {
    $now = time();
    // delete from table where expired < now.
    $query = 'DELETE from {ai_search_block_log} where ai_search_block_log.expiry < :param';
    // delete the record associated with this id
    $this->database->query($query, [':param' => (int) $now]);
  }

  /**
   * @param $block_id
   * @param $user
   * @param $query
   *
   * @return int|mixed|string|null
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function start($block_id, $uid, $query) {
    $storage = $this->entityTypeManager->getStorage('ai_search_block_log');
    $expiry = $this->configFactory->get('ai_search_block_log.settings')
      ->get('expiry');
    $expiry = (isset($expiry) ? $expiry : 'week');

    /** @var \Drupal\ai_search_block_log\Entity\AISearchBlockLog $log */
    $log = $storage->create([
      'uid' => $uid,
      'block_id' => $block_id,
      'created' => time(),
      'expiry' => strtotime('now + 1 ' . $expiry),
      'question' => [
        'value' => $query,
        'format' => 'plain_text',
      ],
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
   *
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
   *
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
