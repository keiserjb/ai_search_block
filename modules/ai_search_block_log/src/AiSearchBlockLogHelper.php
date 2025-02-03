<?php

namespace Drupal\ai_search_block_log;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AiSearchBlockLogHelper implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The configuration parameters passed in.
   *
   * @var array
   */
  private $configuration;

  private $logId;

  private $blockId;

  private $user;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  public function start($block_id, $user, $query){
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

  public function logResponse(int $id, string $response) {
    $entity = \Drupal::entityTypeManager()->getStorage('ai_search_block_log')->load($id);
    if (!$entity) {
      return NULL;
    }
    $entity->set('response_given', $response);
    $entity->save();
  }

  public function update(int $id, array $fields) {
    $entity = \Drupal::entityTypeManager()->getStorage('ai_search_block_log')->load($id);
    if (!$entity) {
      return NULL;
    }
    foreach($fields as $key => $field) {
      $entity->set($key, $field);
    }
    $entity->save();
  }
}
