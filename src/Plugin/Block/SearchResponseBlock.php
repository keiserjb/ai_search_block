<?php

namespace Drupal\ai_search_block\Plugin\Block;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_search_block\Form\SearchForm;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\Form\UpdateBlockForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an AI form block.
 *
 * @Block(
 *   id = "ai_search_block_response",
 *   admin_label = @Translation("AI Search response"),
 *   category = @Translation("AI")
 * )
 */
class SearchResponseBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The form builder.
   *
   * @var FormBuilderInterface
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * Current user.
   *
   * @var AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The AI Assistant API runner.
   *
   * @var AiAssistantApiRunner
   */
  protected $aiAssistantRunner;

  /**
   * The file url generator.
   *
   * @var FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * @var EntityDisplayRepositoryInterface
   *   The entity display repository.
   */
  protected $entityDisplayRepository;

  /**
   * @var
   *   The ai provider manager.
   */
  protected $aiProviderManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = new static($configuration, $plugin_id, $plugin_definition);
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $block = [];
    $block['#settings'] = $this->configuration;
    $block['#theme'] = 'ai_search_block_response';
    $block['#output'] = ' ';
    return $block;
  }

}
